<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AccessRequest;
use App\Models\GuestAccessPermission;
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
 *     "gate": "gate_masuk",        // ID gate (gate_masuk / gate_keluar / gate_utama)
 *     "direction": "in",           // 'in' | 'out' (opsional, diturunkan dari nama gate)
 *     "member_id": 3,              // ID member hasil pengenalan wajah YOLO (null jika tidak dikenali)
 *     "plate_text": "BD2541WH",    // Nomor plat hasil OCR (null jika tidak terbaca)
 *     "distance": 0.06,            // 1 - confidence YOLO (semakin kecil semakin yakin); hanya dicatat, tidak dipakai memutuskan
 *     "update_gate": true          // Apakah langsung mengupdate status gate ESP32 (default: true)
 *   }
 *
 * Pencocokan plat:
 *   1. Persis (tanpa spasi/tanda baca, huruf besar) ke semua kendaraan.
 *   2. Jika tidak persis: toleransi OCR terbatas, HANYA ke kendaraan milik member yang wajahnya
 *      dikenali, panjang plat harus sama, beda tepat 1 karakter, dan pasangan karakternya
 *      harus yang umum tertukar OCR (lihat KARAKTER_MIRIP).
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
    /**
     * Pasangan karakter yang sering tertukar saat OCR plat.
     * Dipakai untuk toleransi satu karakter pada pencocokan plat.
     */
        private const KARAKTER_MIRIP = [
        ['0', 'O'],
        ['0', 'D'],
        ['1', 'I'],
        ['8', 'B'],
        ['5', 'S'],
        ['2', 'Z'],
        ['6', 'G'],
    ];

    public function verifikasi(Request $request): JsonResponse
    {
        $gate = $request->input('gate', 'gate_utama');
        $direction = $request->input('direction'); // 'in', 'out', atau null
        $memberId = $request->filled('member_id') ? (int) $request->input('member_id') : null;
        $plateTeks = $request->filled('plate_text') ? (string) $request->input('plate_text') : null;
        $distance = $request->filled('distance') ? (float) $request->input('distance') : null;
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

        // 2. Cari Kendaraan dari plat nomor (persis, lalu toleransi OCR terbatas)
        $vehicle = null;
        $matchedPlate = null;
        if ($plateTeks !== null) {
            [$vehicle, $matchedPlate] = $this->findVehicle($plateTeks, $member);
        }

        // Tentukan arah gerbang (direction) jika belum ditentukan
        if (empty($direction)) {
            $gateLower = strtolower($gate);
            if (str_contains($gateLower, 'keluar') || str_contains($gateLower, 'exit')) {
                $direction = 'out';
            } elseif (str_contains($gateLower, 'masuk') || str_contains($gateLower, 'entry')) {
                $direction = 'in';
            } else {
                // Untuk gerbang dua arah (seperti gate_utama):
                // Jika sedang di dalam (ada sesi aktif), arahnya keluar ('out').
                // Jika sedang di luar (tidak ada sesi aktif), arahnya masuk ('in').
                $hasActiveSession = $vehicle
                    ? ParkingSession::where('vehicle_id', $vehicle->id)->where('status', 'active')->exists()
                    : false;
                $direction = $hasActiveSession ? 'out' : 'in';
            }
        }

        // 3. Evaluasi kecocokan dan hak akses
        [$status, $keterangan, $cocok, $reason, $izinTamu] = $this->evaluasiAkses(
            $memberId,
            $member,
            $plateTeks,
            $vehicle,
            $matchedPlate,
            $direction
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
                $plateTeks ?? $matchedPlate,   // simpan teks ASLI hasil OCR supaya bisa diaudit
                $distance,
                $direction,
                $reason,
                $izinTamu
            );
        }

        return response()->json([
            'success' => true,
            'status' => $status,
            'cocok' => $cocok,
            'izin_gate' => $izinGate,
            'keterangan' => $keterangan,
            'direction' => $direction,
            'member' => $member ? ['id' => $member->id, 'name' => $member->name] : null,
            'vehicle' => $vehicle ? ['id' => $vehicle->id, 'plate_number' => $vehicle->plate_number] : null,
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
        ?string $matchedPlate,
        string $direction = 'in'
    ): array {
        $wajahTerdeteksi = $memberId !== null;
        $platTerbaca = $plateTeks !== null;

        // Kondisi belum lengkap sama sekali
        if (! $wajahTerdeteksi && ! $platTerbaca) {
            return ['menunggu', 'Arahkan wajah dan plat kendaraan ke kamera', false, null, null];
        }

        // ── MODE TAMU: Jika plat terdaftar & memiliki izin tamu aktif ──────────
        // Cek SEBELUM pengecekan wajah, karena mode tamu bypass verifikasi wajah
        if ($platTerbaca && $vehicle) {
            $izinTamu = GuestAccessPermission::where('vehicle_id', $vehicle->id)
                ->where('is_active', true)
                ->where('valid_from', '<=', now())
                ->where('valid_until', '>=', now())
                ->first();

            if ($izinTamu) {
                // Cek subscription member PEMILIK kendaraan
                $pemilik = $vehicle->member;
                $subscription = $this->getActiveSubscription($pemilik);

                if (! $subscription) {
                    return [
                        'tolak',
                        "Langganan parkir pemilik kendaraan {$vehicle->plate_number} tidak aktif atau sudah habis masa berlakunya",
                        false,
                        'subscription_expired',
                        null,
                    ];
                }

                // Anti-passback: kendaraan masuk
                $activeSession = ParkingSession::where('vehicle_id', $vehicle->id)
                    ->where('status', 'active')
                    ->latest('entry_time')
                    ->first();

                if ($direction === 'in' && $activeSession) {
                    return [
                        'tolak',
                        "Kendaraan {$vehicle->plate_number} sudah berada di dalam area parkir (belum keluar)",
                        false,
                        'already_inside',
                        null,
                    ];
                }

                // Anti-passback: kendaraan keluar
                if ($direction === 'out' && ! $activeSession) {
                    return [
                        'tolak',
                        "Kendaraan {$vehicle->plate_number} tidak berada di dalam area parkir (belum masuk atau sudah keluar)",
                        false,
                        'not_inside',
                        null,
                    ];
                }

                $aksi = ($direction === 'out') ? 'Keluar' : 'Masuk';

                return [
                    'izin',
                    "Akses {$aksi} Tamu Diterima: {$izinTamu->guest_name} | Kendaraan: {$vehicle->plate_number}",
                    true,
                    null,
                    $izinTamu,
                ];
            }
        }

        // ── KASUS 1: Wajah TIDAK dikenali (Asing) ──────────────────────────
        if (! $wajahTerdeteksi || ! $member) {
            return [
                'tolak',
                'Wajah tidak dikenal atau belum terdaftar sebagai member',
                false,
                'face_unknown',
                null,
            ];
        }

        // ── KASUS 2: Member TIDAK AKTIF ────────────────────────────────────
        if (! $member->is_active) {
            return [
                'tolak',
                "Member {$member->name} saat ini berstatus tidak aktif",
                false,
                'member_inactive',
                null,
            ];
        }

        // ── KASUS 3: Plat TIDAK TERBACA ────────────────────────────────────
        if (! $platTerbaca) {
            return [
                'menunggu',
                "Wajah {$member->name} terkonfirmasi. Menunggu pembacaan plat nomor...",
                false,
                null,
                null,
            ];
        }

        // ── KASUS 4: Plat TIDAK TERDAFTAR ──────────────────────────────────
        if (! $vehicle) {
            return [
                'tolak',
                "Plat kendaraan {$plateTeks} tidak terdaftar di sistem",
                false,
                'plate_unregistered',
                null,
            ];
        }

        // ── KASUS 5: MISMATCH (Wajah milik A, plat milik B) ─────────────────
        if ((int) $vehicle->member_id !== (int) $member->id) {
            $pemilikPlat = $vehicle->member?->name ?? 'Member Lain';

            return [
                'mismatch',
                "Wajah {$member->name} tidak cocok dengan pemilik plat {$plateTeks} (milik {$pemilikPlat})",
                false,
                'driver_mismatch',
                null,
            ];
        }

        // ── KASUS 6: Cek Langganan Parkir Aktif (Subscription) ─────────────
        $subscription = $this->getActiveSubscription($member);
        if (! $subscription) {
            return [
                'tolak',
                "Langganan parkir untuk {$member->name} tidak aktif atau sudah habis masa berlakunya",
                false,
                'subscription_expired',
                null,
            ];
        }

        // ── KASUS 7: Cek Status Sesi Parkir (Anti-Passback) ────────────────
        $activeSession = ParkingSession::where('vehicle_id', $vehicle->id)
            ->where('status', 'active')
            ->latest('entry_time')
            ->first();

        // Ketika mobil mau MASUK tapi sudah berada di dalam
        if ($direction === 'in' && $activeSession) {
            return [
                'tolak',
                "Kendaraan {$vehicle->plate_number} sudah berada di dalam area parkir (belum keluar)",
                false,
                'already_inside',
                null,
            ];
        }

        // Ketika mobil mau KELUAR tapi tidak berada di dalam (sudah keluar / belum masuk)
        if ($direction === 'out' && ! $activeSession) {
            return [
                'tolak',
                "Kendaraan {$vehicle->plate_number} tidak berada di dalam area parkir (belum masuk atau sudah keluar)",
                false,
                'not_inside',
                null,
            ];
        }

        // ── KASUS 8: SEMUA COCOK -> AKSES DITERIMA (IZIN) ──────────────────
        $aksi = ($direction === 'out') ? 'Keluar' : 'Masuk';

        // Beri tahu bila plat cocok lewat toleransi OCR (teks terbaca berbeda dari yang terdaftar)
        $catatan = $this->normalizePlate($plateTeks) !== $this->normalizePlate($vehicle->plate_number)
            ? " (terbaca: {$plateTeks})"
            : '';

        return [
            'izin',
            "Akses {$aksi} Diterima: {$member->name} | Kendaraan: {$vehicle->plate_number}{$catatan}",
            true,
            null,
            null,
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
        ?float $distance,
        string $direction = 'in',
        ?string $reason = null,
        ?GuestAccessPermission $izinTamu = null
    ): void {
        $isApproved = ($izinGate === 'BUKA');
        $isModeТamu = $izinTamu !== null;

        // Untuk mode tamu: member yang dipakai di AccessRequest adalah pemilik kendaraan
        $effectiveMember = $isModeТamu ? $vehicle?->member : $member;

        if ($isApproved && $effectiveMember && $vehicle) {
            $isExit = ($direction === 'out');

            // Buat catatan AccessRequest (auto_approved)
            $accessRequest = AccessRequest::create([
                'member_id'                  => $effectiveMember->id,
                'vehicle_id'                 => $vehicle->id,
                'gate'                       => $state->gate,
                'direction'                  => $direction,
                'reason'                     => null,
                'guest_access_permission_id' => $izinTamu?->id,
                'plate_detected'             => $plateDetected ?? $vehicle->plate_number,
                'plate_match'                => true,
                'face_match'                 => ! $isModeТamu,   // false untuk mode tamu
                'face_distance'              => $isModeТamu ? null : $distance,
                'status'                     => 'auto_approved',
                'requested_at'               => now(),
                'responded_at'               => now(),
            ]);

            if ($isExit) {
                // Kendaraan Keluar: tutup seluruh sesi parkir aktif untuk kendaraan ini
                ParkingSession::where('vehicle_id', $vehicle->id)
                    ->where('status', 'active')
                    ->update([
                        'exit_access_request_id' => $accessRequest->id,
                        'exit_time'              => now(),
                        'status'                 => 'completed',
                    ]);

                // Update izin tamu: tandai sudah keluar
                if ($izinTamu) {
                    $izinTamu->update([
                        'used_exit' => true,
                        'exit_at'   => now(),
                    ]);
                }

                $gateStatus = 'PALANG TERBUKA - KENDARAAN KELUAR';
            } else {
                // Kendaraan Masuk: buat sesi parkir aktif baru
                ParkingSession::create([
                    'member_id'              => $effectiveMember->id,
                    'vehicle_id'             => $vehicle->id,
                    'entry_access_request_id' => $accessRequest->id,
                    'entry_time'             => now(),
                    'status'                 => 'active',
                ]);

                // Update izin tamu: tandai sudah masuk
                if ($izinTamu) {
                    $izinTamu->update([
                        'used_entry' => true,
                        'entry_at'   => now(),
                    ]);
                }

                $gateStatus = 'PALANG TERBUKA - KENDARAAN MASUK';
            }

            // Update state gerbang untuk dibaca ESP32
            $state->update([
                'trigger'          => false,
                'izin'             => 'BUKA',
                'status'           => $gateStatus,
                'face_match'       => ! $isModeТamu,
                'face_member_id'   => $isModeТamu ? null : $effectiveMember->id,
                'face_distance'    => $isModeТamu ? null : $distance,
                'plate_match'      => true,
                'plate_member_id'  => $effectiveMember->id,
                'plate_vehicle_id' => $vehicle->id,
                'plate_detected'   => $plateDetected ?? $vehicle->plate_number,
            ]);
        } else {
            // Akses Ditolak -> Buat catatan AccessRequest (denied)
            AccessRequest::create([
                'member_id'      => $member?->id,
                'vehicle_id'     => $vehicle?->id,
                'gate'           => $state->gate,
                'direction'      => $direction,
                'plate_detected' => $plateDetected ?? 'UNKNOWN',
                'plate_match'    => $vehicle !== null,
                'face_match'     => $member !== null,
                'face_distance'  => $distance,
                'status'         => 'denied',
                'reason'         => $reason ?? substr($keterangan, 0, 30),
                'requested_at'   => now(),
                'responded_at'   => now(),
            ]);

            // Format keterangan ringkas untuk status gate ESP32
            $gateText = 'AKSES DITOLAK: '.$keterangan;
            if ($reason === 'already_inside') {
                $gateText = 'AKSES DITOLAK: MOBIL SUDAH DI DALAM';
            } elseif ($reason === 'not_inside') {
                $gateText = 'AKSES DITOLAK: MOBIL SUDAH KELUAR';
            }

            // Update state gerbang untuk dibaca ESP32 (TOLAK)
            $state->update([
                'trigger'          => false,
                'izin'             => 'TOLAK',
                'status'           => $gateText,
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
    // Helper Pencarian Kendaraan (persis, lalu toleransi OCR terbatas)
    // ──────────────────────────────────────────────────────────────────────────
    private function findVehicle(string $rawPlate, ?Member $member = null): array
    {
        $clean = $this->normalizePlate($rawPlate);
        if ($clean === '') {
            return [null, null];
        }

        // 1. Persis, ke semua kendaraan (supaya pesan "milik member lain" tetap muncul)
        $vehicle = Vehicle::with('member')
            ->whereRaw("REPLACE(UPPER(plate_number), ' ', '') = ?", [$clean])
            ->first();

        if ($vehicle) {
            return [$vehicle, $clean];
        }

        // 2. Toleransi OCR: HANYA kendaraan milik member yang wajahnya dikenali
        if ($member) {
            $kendaraanMember = Vehicle::with('member')->where('member_id', $member->id)->get();

            foreach ($kendaraanMember as $v) {
                $reg = $this->normalizePlate($v->plate_number);

                if ($this->cocokToleran($clean, $reg)) {
                    return [$v, $reg];
                }
            }
        }

        return [null, $clean];
    }

    /**
     * Toleransi salah baca OCR: panjang sama, beda tepat 1 karakter,
     * dan pasangan karakternya termasuk yang sering tertukar.
     */
    private function cocokToleran(string $ocr, string $reg): bool
    {
        if (strlen($ocr) !== strlen($reg)) {
            return false;
        }

        $beda = 0;

        for ($i = 0, $n = strlen($ocr); $i < $n; $i++) {
            if ($ocr[$i] === $reg[$i]) {
                continue;
            }

            if (++$beda > 1 || ! $this->karakterMirip($ocr[$i], $reg[$i])) {
                return false;
            }
        }

        return $beda === 1;
    }

    private function karakterMirip(string $a, string $b): bool
    {
        foreach (self::KARAKTER_MIRIP as [$x, $y]) {
            if (($a === $x && $b === $y) || ($a === $y && $b === $x)) {
                return true;
            }
        }

        return false;
    }

    private function getActiveSubscription(?Member $member)
    {
        if (! $member) {
            return null;
        }

        return $member->subscriptions()
            ->where('status', 'active')
            ->whereDate('start_date', '<=', now()->toDateString())
            ->whereDate('end_date', '>=', now()->toDateString())
            ->latest('end_date')
            ->first();
    }

    private function normalizePlate(?string $plate): string
    {
        return strtoupper(preg_replace('/[^A-Z0-9]/', '', strtoupper($plate ?? '')));
    }
}