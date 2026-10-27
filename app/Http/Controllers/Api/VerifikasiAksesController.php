<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AccessRequest;
use App\Models\Member;
use App\Models\ParkingGateState;
use App\Models\ParkingSession;
use App\Models\Vehicle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Controller untuk verifikasi akses parkir terpadu (Kamera AI + ESP32 IoT).
 *
 * Endpoint:
 *   POST /api/verifikasi-akses
 *
 * Payload:
 *   {
 *     "gate": "gate_utama",        // ID gate
 *     "member_id": 3,              // ID member hasil pengenalan wajah LBPH (null jika asing)
 *     "plate_text": "BD2541WH",    // Nomor plat hasil OCR (null jika tidak terbaca)
 *     "distance": 42.5,            // Jarak LBPH (semakin kecil semakin mirip)
 *     "update_gate": true          // Apakah langsung mengupdate status gate ESP32 (default: true)
 *   }
 *
 * Response:
 *   {
 *     "success": true,
 *     "status": "izin" | "tolak" | "mismatch" | "menunggu",
 *     "cocok": true | false,
 *     "izin_gate": "BUKA" | "TOLAK" | "MENUNGGU",
 *     "keterangan": "...",
 *     "member": { id, name } | null,
 *     "vehicle": { id, plate_number } | null
 *   }
 */
class VerifikasiAksesController extends Controller
{
    public function verifikasi(Request $request): JsonResponse
    {
        $gate       = $request->input('gate', 'gate_utama');
        $memberId   = $request->filled('member_id') ? (int) $request->input('member_id') : null;
        $plateTeks  = $request->filled('plate_text') ? (string) $request->input('plate_text') : null;
        $distance   = $request->filled('distance') ? (float) $request->input('distance') : null;
        $updateGate = $request->boolean('update_gate', true);

        // Ambil atau buat state gate
        $gateState = ParkingGateState::firstOrCreate(
            ['gate' => $gate],
            ['trigger' => false, 'izin' => 'SIAGA', 'status' => 'SIAGA']
        );

        // 1. Cari Member dari wajah
        $member = null;
        if ($memberId !== null) {
            $member = Member::find($memberId);
        }

        // 2. Cari Kendaraan dari plat nomor (exact & fuzzy matching)
        $vehicle = null;
        $matchedPlate = null;
        if ($plateTeks !== null) {
            [$vehicle, $matchedPlate] = $this->findVehicle($plateTeks);
        }

        // 3. Evaluasi kecocokan dan hak akses
        [$status, $keterangan, $cocok] = $this->evaluasiAkses(
            $memberId,
            $member,
            $plateTeks,
            $vehicle,
            $matchedPlate
        );

        // 4. Update database ParkingGateState, AccessRequest, dan ParkingSession jika updateGate = true
        $izinGate = 'MENUNGGU';
        if ($updateGate && in_array($status, ['izin', 'tolak', 'mismatch'])) {
            $izinGate = ($status === 'izin') ? 'BUKA' : 'TOLAK';

            $this->prosesDatabaseGate(
                $gateState,
                $izinGate,
                $status,
                $keterangan,
                $member,
                $vehicle,
                $plateTeks ?? $matchedPlate,
                $distance
            );
        }

        return response()->json([
            'success'    => true,
            'status'     => $status,
            'cocok'      => $cocok,
            'izin_gate'  => $izinGate,
            'keterangan' => $keterangan,
            'member'     => $member ? ['id' => $member->id, 'name' => $member->name] : null,
            'vehicle'    => $vehicle ? ['id' => $vehicle->id, 'plate_number' => $vehicle->plate_number] : null,
            'gate_state' => $gateState->fresh(),
        ]);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Evaluasi Logika Akses
    // ──────────────────────────────────────────────────────────────────────────
    private function evaluasiAkses(
        ?int $memberId,
        ?Member $member,
        ?string $plateTeks,
        ?Vehicle $vehicle,
        ?string $matchedPlate
    ): array {
        $wajahTerdeteksi = $memberId !== null;
        $platTerbaca     = $plateTeks !== null;

        // Kondisi belum lengkap sama sekali
        if (!$wajahTerdeteksi && !$platTerbaca) {
            return ['menunggu', 'Arahkan wajah dan plat kendaraan ke kamera', false];
        }

        // ── KASUS 1: Wajah TIDAK dikenali (Asing) ──────────────────────────
        if (!$wajahTerdeteksi || !$member) {
            return [
                'tolak',
                'Wajah tidak dikenal atau belum terdaftar sebagai member',
                false,
            ];
        }

        // ── KASUS 2: Member TIDAK AKTIF ────────────────────────────────────
        if (!$member->is_active) {
            return [
                'tolak',
                "Member {$member->name} saat ini berstatus tidak aktif",
                false,
            ];
        }

        // ── KASUS 3: Plat TIDAK TERBACA ────────────────────────────────────
        if (!$platTerbaca) {
            return [
                'menunggu',
                "Wajah {$member->name} terkonfirmasi. Menunggu pembacaan plat nomor...",
                false,
            ];
        }

        // ── KASUS 4: Plat TIDAK TERDAFTAR ──────────────────────────────────
        if (!$vehicle) {
            return [
                'tolak',
                "Plat kendaraan {$plateTeks} tidak terdaftar di sistem",
                false,
            ];
        }

        // ── KASUS 5: MISMATCH (Wajah milik A, plat milik B) ─────────────────
        if ((int) $vehicle->member_id !== (int) $member->id) {
            $pemilikPlat = $vehicle->member?->name ?? 'Member Lain';
            return [
                'mismatch',
                "Wajah {$member->name} tidak cocok dengan pemilik plat {$plateTeks} (milik {$pemilikPlat})",
                false,
            ];
        }

        // ── KASUS 6: Cek Langganan Parkir Aktif (Subscription) ─────────────
        $subscription = $this->getActiveSubscription($member);
        if (!$subscription) {
            return [
                'tolak',
                "Langganan parkir untuk {$member->name} tidak aktif atau sudah habis masa berlakunya",
                false,
            ];
        }

        // ── KASUS 7: SEMUA COCOK -> AKSES DITERIMA (IZIN) ──────────────────
        return [
            'izin',
            "Akses Diterima: {$member->name} | Kendaraan: {$vehicle->plate_number}",
            true,
        ];
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Update State Gate, Sesi Parkir, dan Catatan Riwayat Akses
    // ──────────────────────────────────────────────────────────────────────────
    private function prosesDatabaseGate(
        ParkingGateState $state,
        string $izinGate,
        string $status,
        string $keterangan,
        ?Member $member,
        ?Vehicle $vehicle,
        ?string $plateDetected,
        ?float $distance
    ): void {
        $isApproved = ($izinGate === 'BUKA');

        if ($isApproved && $member && $vehicle) {
            // Cek apakah kendaraan sedang berada di dalam (sesi aktif)
            $activeSession = ParkingSession::where('vehicle_id', $vehicle->id)
                ->where('status', 'active')
                ->latest('entry_time')
                ->first();

            // Tentukan arah berdasarkan ID gerbang atau keberadaan sesi aktif
            $isExit = ($state->gate === 'gate_keluar') || ($activeSession !== null && $state->gate !== 'gate_masuk');
            $direction = $isExit ? 'out' : 'in';

            // Buat catatan AccessRequest (auto_approved)
            $accessRequest = AccessRequest::create([
                'member_id'      => $member->id,
                'vehicle_id'     => $vehicle->id,
                'gate'           => $state->gate,
                'direction'      => $direction,
                'plate_detected' => $plateDetected ?? $vehicle->plate_number,
                'plate_match'    => true,
                'face_match'     => true,
                'face_distance'  => $distance,
                'status'         => 'auto_approved',
                'requested_at'   => now(),
                'responded_at'   => now(),
            ]);

            if ($isExit) {
                // Kendaraan Keluar
                if ($activeSession) {
                    $activeSession->update([
                        'exit_access_request_id' => $accessRequest->id,
                        'exit_time'              => now(),
                        'status'                 => 'completed',
                    ]);
                }
                $gateStatus = 'PALANG TERBUKA - KENDARAAN KELUAR';
            } else {
                // Kendaraan Masuk
                ParkingSession::create([
                    'member_id'               => $member->id,
                    'vehicle_id'              => $vehicle->id,
                    'entry_access_request_id' => $accessRequest->id,
                    'entry_time'              => now(),
                    'status'                  => 'active',
                ]);
                $gateStatus = 'PALANG TERBUKA - KENDARAAN MASUK';
            }

            // Update state gerbang untuk dibaca ESP32
            $state->update([
                'trigger'          => false,
                'izin'             => 'BUKA',
                'status'           => $gateStatus,
                'face_match'       => true,
                'face_member_id'   => $member->id,
                'face_distance'    => $distance,
                'plate_match'      => true,
                'plate_member_id'  => $member->id,
                'plate_vehicle_id' => $vehicle->id,
                'plate_detected'   => $plateDetected ?? $vehicle->plate_number,
            ]);
        } else {
            // Akses Ditolak -> Buat catatan AccessRequest (denied)
            AccessRequest::create([
                'member_id'      => $member?->id,
                'vehicle_id'     => $vehicle?->id,
                'gate'           => $state->gate,
                'direction'      => 'in',
                'plate_detected' => $plateDetected ?? 'UNKNOWN',
                'plate_match'    => $vehicle !== null,
                'face_match'     => $member !== null,
                'face_distance'  => $distance,
                'status'         => 'denied',
                'reason'         => $keterangan,
                'requested_at'   => now(),
                'responded_at'   => now(),
            ]);

            // Update state gerbang untuk dibaca ESP32 (TOLAK)
            $state->update([
                'trigger'          => false,
                'izin'             => 'TOLAK',
                'status'           => 'AKSES DITOLAK: ' . $keterangan,
                'face_match'       => $member !== null,
                'face_member_id'   => $member?->id,
                'face_distance'    => $distance,
                'plate_match'      => $vehicle !== null,
                'plate_member_id'  => $vehicle?->member_id,
                'plate_vehicle_id' => $vehicle?->id,
                'plate_detected'   => $plateDetected,
            ]);
        }
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Helper Pencarian Kendaraan (Exact & Fuzzy Levenshtein)
    // ──────────────────────────────────────────────────────────────────────────
    private function findVehicle(string $rawPlate): array
    {
        $cleanPlate = $this->normalizePlate($rawPlate);
        if ($cleanPlate === '') {
            return [null, null];
        }

        // 1. Coba pencarian persis (exact match)
        $vehicle = Vehicle::with('member')
            ->whereRaw("REPLACE(UPPER(plate_number), ' ', '') = ?", [$cleanPlate])
            ->first();

        if ($vehicle) {
            return [$vehicle, $cleanPlate];
        }

        // 2. Coba pencarian kemiripan (fuzzy similarity untuk toleransi OCR)
        $vehicles = Vehicle::with('member')->get();
        $bestVehicle = null;
        $bestScore = 0.0;

        foreach ($vehicles as $v) {
            $regPlate = $this->normalizePlate($v->plate_number);
            $score    = $this->plateSimilarity($cleanPlate, $regPlate);

            if ($score > $bestScore) {
                $bestScore   = $score;
                $bestVehicle = $v;
            }
        }

        if ($bestVehicle && $bestScore >= 0.60) {
            return [$bestVehicle, $this->normalizePlate($bestVehicle->plate_number)];
        }

        return [null, $cleanPlate];
    }

    private function plateSimilarity(string $ocr, string $registered): float
    {
        if ($ocr === $registered) return 1.0;
        if ($ocr === '' || $registered === '') return 0.0;

        similar_text($ocr, $registered, $percent);
        $similarScore = $percent / 100;

        $distance  = levenshtein($ocr, $registered);
        $maxLength = max(strlen($ocr), strlen($registered));
        $levScore  = $maxLength > 0 ? max(0, 1 - ($distance / $maxLength)) : 0.0;

        return ($similarScore * 0.5) + ($levScore * 0.5);
    }

    private function getActiveSubscription(?Member $member)
    {
        if (!$member) return null;

        return $member->subscriptions()
            ->where('status', 'active')
            ->whereDate('start_date', '<=', now()->toDateString())
            ->whereDate('end_date', '>=', now()->toDateString())
            ->latest('end_date')
            ->first();
    }

    private function normalizePlate(?string $plate): string
    {
        return strtoupper(preg_replace('/[^A-Z0-9]/', '', $plate ?? ''));
    }
}
