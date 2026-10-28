<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AccessRequest;
use App\Models\ParkingGateState;
use App\Models\ParkingSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class NotifikasiController extends Controller
{
    private const BUZZER_DETIK = 10;          // lama buzzer menyala

    private const BUZZER_JEDA = 15;           // jeda sebelum boleh ditekan lagi

    private const JENDELA_INSIDEN_MENIT = 5;  // buzzer hanya untuk insiden yang masih baru

    private const STATUS_BOLEH_BUZZER = ['pending', 'rejected_by_member', 'timeout'];

    /** GET /api/notifikasi */
    public function index(Request $request): JsonResponse
    {
        $memberId = $request->user()->id;

        // Permintaan yang waktunya habis ditandai timeout.
        AccessRequest::where('member_id', $memberId)
            ->where('status', 'pending')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->update(['status' => 'timeout', 'responded_at' => now()]);

        $items = AccessRequest::where('member_id', $memberId)
            ->whereNotNull('reason') // hanya kejadian yang butuh perhatian, bukan akses otomatis biasa
            ->latest('requested_at')
            ->limit(30)
            ->get();

        return response()->json([
            'pending_count' => $items->where('status', 'pending')->count(),
            'data' => $items->map(fn (AccessRequest $r) => $this->payload($r))->values(),
        ]);
    }

    /** POST /api/notifikasi/{id}/respon  body: {"decision": "approve" | "reject"} */
    public function respond(Request $request, int $id): JsonResponse
    {
        $decision = $request->validate([
            'decision' => ['required', 'in:approve,reject'],
        ])['decision'];

        $memberId = $request->user()->id;

        return DB::transaction(function () use ($id, $memberId, $decision) {
            $req = AccessRequest::where('id', $id)
                ->where('member_id', $memberId)
                ->lockForUpdate()
                ->first();

            if (! $req) {
                return response()->json(['message' => 'Permintaan tidak ditemukan.'], 404);
            }

            if ($req->status !== 'pending') {
                return response()->json(['message' => 'Permintaan ini sudah diproses.'], 409);
            }

            if ($req->expires_at && $req->expires_at->isPast()) {
                $req->update(['status' => 'timeout', 'responded_at' => now()]);

                return response()->json(['message' => 'Waktu habis. Palang tetap tertutup.'], 410);
            }

            $state = ParkingGateState::where('gate', $req->gate)->lockForUpdate()->first();

            if (! $this->gerbangMasihTerkait($state, $req)) {
                $req->update(['status' => 'timeout', 'responded_at' => now()]);

                return response()->json(['message' => 'Gerbang sudah memproses kendaraan lain. Permintaan ini kedaluwarsa.'], 409);
            }

            if ($decision === 'approve') {
                $req->update(['status' => 'approved_by_member', 'responded_at' => now()]);
                $this->bukaPalang($req, $state);

                return response()->json(['message' => 'Akses diizinkan. Palang dibuka.']);
            }

            $req->update(['status' => 'rejected_by_member', 'responded_at' => now()]);
            $state->update(['trigger' => false, 'izin' => 'TOLAK', 'status' => 'DITOLAK PEMILIK']);

            // TODO: buat SecurityAlert untuk petugas (menunggu model & migrasi SecurityAlert).

            return response()->json(['message' => 'Akses ditolak. Palang tetap tertutup.']);
        });
    }

    /** POST /api/notifikasi/{id}/buzzer */
    public function buzzer(Request $request, int $id): JsonResponse
    {
        $req = AccessRequest::where('id', $id)
            ->where('member_id', $request->user()->id)
            ->first();

        if (! $req) {
            return response()->json(['message' => 'Permintaan tidak ditemukan.'], 404);
        }

        if (! $this->bolehBuzzer($req)) {
            return response()->json(['message' => 'Buzzer hanya bisa dinyalakan saat kejadian masih berlangsung.'], 409);
        }

        $state = ParkingGateState::where('gate', $req->gate)->first();

        if (! $state || (int) $state->plate_vehicle_id !== (int) $req->vehicle_id) {
            return response()->json(['message' => 'Gerbang sudah memproses kendaraan lain.'], 409);
        }

        // Jeda: sedang menyala, atau baru saja padam.
        if ($state->buzzer_until) {
            $bebasLagi = Carbon::parse($state->buzzer_until)->addSeconds(self::BUZZER_JEDA);

            if ($bebasLagi->isFuture()) {
                $tunggu = max(1, $bebasLagi->timestamp - now()->timestamp);

                return response()->json(['message' => "Buzzer baru saja dinyalakan. Coba lagi dalam {$tunggu} detik."], 429);
            }
        }

        // forceFill: tidak bergantung pada $fillable di ParkingGateState.
        $state->forceFill(['buzzer_until' => now()->addSeconds(self::BUZZER_DETIK)])->save();
        $req->forceFill(['buzzer_at' => now()])->save();

        Log::info('Buzzer gerbang dinyalakan pemilik', [
            'gate' => $req->gate,
            'member_id' => $req->member_id,
            'access_request_id' => $req->id,
        ]);

        return response()->json([
            'message' => 'Buzzer dinyalakan selama '.self::BUZZER_DETIK.' detik.',
            'seconds' => self::BUZZER_DETIK,
        ]);
    }

    // ───────────────────────────── bantu ─────────────────────────────

    /** Gerbang masih menunggu kendaraan yang sama dengan permintaan ini. */
    private function gerbangMasihTerkait(?ParkingGateState $state, AccessRequest $req): bool
    {
        return $state
            && $state->izin === 'MENUNGGU'
            && (int) $state->plate_vehicle_id === (int) $req->vehicle_id;
    }

    private function bolehBuzzer(AccessRequest $req): bool
    {
        return in_array($req->status, self::STATUS_BOLEH_BUZZER, true)
            && $req->requested_at
            && $req->requested_at->gte(now()->subMinutes(self::JENDELA_INSIDEN_MENIT));
    }

    /** Catat sesi parkir (masuk atau keluar) lalu buka palang. */
    private function bukaPalang(AccessRequest $req, ParkingGateState $state): void
    {
        $sesi = ParkingSession::where('vehicle_id', $req->vehicle_id)->active()->latest('entry_time')->first();

        $gateLower = strtolower($state->gate ?? '');
        $direction = $req->direction;
        if (empty($direction)) {
            if (str_contains($gateLower, 'keluar') || str_contains($gateLower, 'exit')) {
                $direction = 'out';
            } elseif (str_contains($gateLower, 'masuk') || str_contains($gateLower, 'entry')) {
                $direction = 'in';
            } else {
                $direction = $sesi ? 'out' : 'in';
            }
        }

        if ($direction === 'out' || $direction === 'exit') {
            ParkingSession::where('vehicle_id', $req->vehicle_id)
                ->where('status', 'active')
                ->update([
                    'exit_access_request_id' => $req->id,
                    'exit_time' => now(),
                    'status' => 'completed',
                ]);
            $status = 'PALANG TERBUKA - KENDARAAN KELUAR (DIIZINKAN PEMILIK)';
        } else {
            ParkingSession::create([
                'member_id' => $req->member_id,
                'vehicle_id' => $req->vehicle_id,
                'entry_access_request_id' => $req->id,
                'entry_time' => now(),
                'status' => 'active',
            ]);
            $status = 'PALANG TERBUKA - KENDARAAN MASUK (DIIZINKAN PEMILIK)';
        }

        // Buzzer yang sedang menyala ikut dimatikan.
        $state->forceFill(['buzzer_until' => null]);
        $state->update(['trigger' => false, 'izin' => 'BUKA', 'status' => $status]);
    }

    private function payload(AccessRequest $r): array
    {
        $menunggu = $r->status === 'pending';
        [$judul, $isi] = $this->teks($r);

        $aksi = [];
        if ($menunggu) {
            $aksi = ['approve', 'reject', 'buzzer'];
        } elseif ($this->bolehBuzzer($r)) {
            $aksi = ['buzzer'];
        }

        return [
            'id' => $r->id,
            'title' => $judul,
            'body' => $isi,
            'status' => $r->status,
            'plate' => $this->formatPlat((string) $r->plate_detected),
            'needs_action' => $menunggu,
            'seconds_left' => $menunggu && $r->expires_at
                ? max(0, $r->expires_at->timestamp - now()->timestamp)
                : 0,
            'requested_at' => optional($r->requested_at)->toIso8601String(),
            'direction' => $r->direction,
            'reason' => $r->reason,
            'actions' => $aksi,
        ];
    }

    private function teks(AccessRequest $r): array
    {
        $arah = $r->direction === 'exit' ? 'keluar' : 'masuk';
        $plat = $this->formatPlat((string) $r->plate_detected);
        $pengemudi = $r->reason === 'face_other_member'
            ? 'pengemudinya terdaftar sebagai member lain'
            : 'wajah pengemudinya tidak dikenali';

        return match ($r->status) {
            'pending' => [
                "Kendaraan {$arah}: pengemudi tidak sesuai",
                "Plat {$plat} cocok dengan kendaraan Anda, tetapi {$pengemudi}. Palang tetap tertutup sampai Anda merespons.",
            ],
            'approved_by_member' => ['Akses diizinkan', "Anda mengizinkan kendaraan {$plat} {$arah}."],
            'rejected_by_member' => ['Akses ditolak', "Anda menolak kendaraan {$plat} {$arah}. Palang tetap tertutup."],
            'timeout' => ['Permintaan kedaluwarsa', "Tidak ada respons untuk kendaraan {$plat}. Palang tetap tertutup."],
            default => ['Permintaan dibatalkan', "Permintaan akses untuk kendaraan {$plat} dibatalkan."],
        };
    }

    /** "P1234AB" -> "P 1234 AB" */
    private function formatPlat(string $plat): string
    {
        if (preg_match('/^([A-Z]{1,2})(\d{1,4})([A-Z]{0,3})$/', $plat, $m)) {
            return trim("{$m[1]} {$m[2]} {$m[3]}");
        }

        return $plat;
    }
}
