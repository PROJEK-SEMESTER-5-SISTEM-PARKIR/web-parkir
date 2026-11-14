<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AccessRequest;
use App\Models\FaceProfile;
use App\Models\Member;
use App\Models\ParkingGateState;
use App\Models\ParkingSession;
use App\Models\SecurityAlert;
use App\Models\Vehicle;
use App\Services\PlateMatcher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * ParkingGateController
 *
 * Aturan keputusan gerbang:
 * 1. BUKA hanya jika: plat cocok (toleransi char-swap), wajah cocok (≥3/5 frame,
 *    jarak di bawah ambang), wajah = pemilik plat, member aktif, langganan aktif.
 * 2. Voting wajah: minimal 3 dari 5 frame mengenali member yang sama.
 * 3. Maks 5 percobaan per sesi. Wajah tidak cocok / plat tidak terbaca = 1 percobaan.
 * 4. Gambar gelap/buram atau wajah tidak terdeteksi = kegagalan kualitas (maks 3 per
 *    sesi, lalu dihitung percobaan). LCD: "Kurang cahaya, dekatkan wajah".
 * 5. Plat terbaca tapi tidak terdaftar → langsung TOLAK, tanpa notifikasi.
 * 6. Setelah 5 kali gagal (plat terdaftar, wajah tidak cocok) → notifikasi NETRAL ke
 *    pemilik (60 detik untuk merespons). "Ini saya" → buka. "Bukan saya" / timeout
 *    → SecurityAlert, buzzer, tampil di halaman Darurat.
 * 7. Jeda 60 detik per kendaraan untuk notifikasi berikutnya.
 */
class ParkingGateController extends Controller
{
    // ── Ambang jarak wajah ─────────────────────────────────────────────────────
    private const FACE_THRESHOLD_EMBEDDING = 0.70; // Euclidean distance FastAPI
    private const FACE_THRESHOLD_LBPH      = 80.0; // LBPH distance (lebih tinggi = lebih jauh)

    // ── Batas percobaan ────────────────────────────────────────────────────────
    private const MAKS_PERCOBAAN     = 5;  // percobaan per sesi sebelum notifikasi
    private const MAKS_GAGAL_KUALITAS = 3; // kegagalan kualitas sebelum dihitung percobaan

    // ── Voting wajah ───────────────────────────────────────────────────────────
    private const FRAME_PER_SESI      = 5;  // total frame yang dikumpulkan per sesi
    private const FRAME_COCOK_MIN     = 3;  // minimal frame mengenali member yang sama

    // ── Jeda notifikasi per kendaraan ──────────────────────────────────────────
    private const JEDA_NOTIF_DETIK   = 60; // detik antara notifikasi untuk kendaraan sama

    // ── Waktu respons pemilik ──────────────────────────────────────────────────
    private const WAKTU_RESPONS_DETIK = 60;

    // ─────────────────────────────────────────────────────────────────────────
    // HELPER: Normalisasi plat
    // ─────────────────────────────────────────────────────────────────────────
    private function normalizePlate(?string $plate): string
    {
        return PlateMatcher::normalize($plate);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // HELPER: Cari kendaraan dari daftar kandidat plat OCR
    // ─────────────────────────────────────────────────────────────────────────
    private function findVehicleByPlateCandidates(array $plates): array
    {
        $plates = array_filter(array_map([$this, 'normalizePlate'], $plates));

        if (empty($plates)) {
            return [null, null];
        }

        $kendaraan = Vehicle::with('member')->get();
        [$vehicle, $platOcr] = PlateMatcher::cariKendaraan($plates, $kendaraan);

        return [$vehicle, $platOcr];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // HELPER: Ambil kandidat plat dari request
    // ─────────────────────────────────────────────────────────────────────────
    private function getPlateCandidates(array $data): array
    {
        $plates = [];

        if (!empty($data['plate_text'])) {
            $plates[] = $data['plate_text'];
        }

        if (!empty($data['plate_candidates']) && is_array($data['plate_candidates'])) {
            foreach ($data['plate_candidates'] as $candidate) {
                if (is_array($candidate) && !empty($candidate['text'])) {
                    $plates[] = $candidate['text'];
                } elseif (is_string($candidate)) {
                    $plates[] = $candidate;
                }
            }
        }

        return $plates;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // HELPER: Ambil langganan aktif member
    // ─────────────────────────────────────────────────────────────────────────
    private function getActiveSubscription($member)
    {
        return $member->subscriptions()
            ->where('status', 'active')
            ->whereDate('start_date', '<=', now()->toDateString())
            ->whereDate('end_date', '>=', now()->toDateString())
            ->latest('end_date')
            ->first();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // HELPER: Tambah percobaan, kembalikan apakah sudah melebihi batas
    // ─────────────────────────────────────────────────────────────────────────
    private function tambahPercobaan(ParkingGateState $state): bool
    {
        $jumlah = ($state->attempt_count ?? 0) + 1;
        $state->update([
            'attempt_count' => $jumlah,
        ]);

        return $jumlah >= self::MAKS_PERCOBAAN;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // HELPER: Reset semua counter percobaan saat gerbang kembali SIAGA
    // ─────────────────────────────────────────────────────────────────────────
    private function resetAttemptTracking(ParkingGateState $state): void
    {
        $state->update([
            'attempt_count'      => 0,
            'quality_fail_count' => 0,
            'last_failure_reason' => null,
            'face_votes'         => null,
            'face_frame_count'   => 0,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // HELPER: Kirim notifikasi face-mismatch ke pemilik (dengan jeda anti-spam)
    // ─────────────────────────────────────────────────────────────────────────
    private function kirimNotifikasiMismatch(
        ParkingGateState $state,
        Member $member,
        Vehicle $vehicle,
        string $direction,
        ?float $faceDistance
    ): void {
        // Jeda 60 detik per kendaraan agar tidak spam notifikasi
        $cacheKey = 'gate_notif_' . $vehicle->id;
        if (Cache::has($cacheKey)) {
            Log::info("[Gate] Notifikasi untuk kendaraan #{$vehicle->id} dilewati (masih dalam jeda).");
            return;
        }

        $accessRequest = AccessRequest::create([
            'member_id'    => $member->id,
            'vehicle_id'   => $vehicle->id,
            'gate'         => $state->gate,
            'direction'    => $direction,
            'reason'       => 'face_mismatch',
            'plate_detected' => $state->plate_detected,
            'plate_match'  => true,
            'face_match'   => false,
            'face_distance' => $faceDistance,
            'status'       => 'pending',
            'requested_at' => now(),
            'expires_at'   => now()->addSeconds(self::WAKTU_RESPONS_DETIK),
        ]);

        // Set jeda agar notifikasi berikutnya tidak muncul selama 60 detik
        Cache::put($cacheKey, true, self::JEDA_NOTIF_DETIK);

        // Perbarui status gerbang: tetap MENUNGGU agar bisa direspons pemilik
        $state->update([
            'status' => 'MENUNGGU RESPONS PEMILIK',
            'izin'   => 'MENUNGGU',
        ]);

        Log::info("[Gate] Notifikasi face-mismatch dikirim ke member #{$member->id} untuk kendaraan {$vehicle->plate_number}. AccessRequest #{$accessRequest->id}");
    }

    // ─────────────────────────────────────────────────────────────────────────
    // HELPER: Evaluasi apakah gerbang bisa dibuka (dipanggil saat KEDUA hasil masuk)
    // ─────────────────────────────────────────────────────────────────────────
    private function evaluateGate(ParkingGateState $state): void
    {
        // Hanya evaluasi jika kedua hasil sudah masuk
        if (!$state->face_match || !$state->plate_match) {
            return;
        }

        // Wajah dan plat harus milik orang yang sama
        if (
            !$state->face_member_id ||
            !$state->plate_member_id ||
            $state->face_member_id != $state->plate_member_id
        ) {
            $state->update([
                'trigger' => false,
                'izin'    => 'TOLAK',
                'status'  => 'WAJAH DAN PLAT TIDAK SESUAI',
                'last_failure_reason' => 'wajah_plat_beda_member',
            ]);

            return;
        }

        $member = Member::find($state->face_member_id);

        if (!$member || !$member->is_active) {
            $state->update([
                'trigger' => false,
                'izin'    => 'TOLAK',
                'status'  => 'MEMBER TIDAK AKTIF',
                'last_failure_reason' => 'member_tidak_aktif',
            ]);

            return;
        }

        $subscription = $this->getActiveSubscription($member);

        if (!$subscription) {
            $state->update([
                'trigger' => false,
                'izin'    => 'TOLAK',
                'status'  => 'SUBSCRIPTION TIDAK AKTIF',
                'last_failure_reason' => 'langganan_tidak_aktif',
            ]);

            return;
        }

        $vehicleId = $state->plate_vehicle_id;

        if (!$vehicleId) {
            $state->update([
                'trigger' => false,
                'izin'    => 'TOLAK',
                'status'  => 'DATA KENDARAAN TIDAK DITEMUKAN',
                'last_failure_reason' => 'kendaraan_tidak_ada',
            ]);

            return;
        }

        // Anti-passback: cek arah berdasarkan sesi parkir aktif
        $activeSession = ParkingSession::where('vehicle_id', $vehicleId)
            ->where('status', 'active')
            ->latest('entry_time')
            ->first();

        $gateName  = strtolower($state->gate ?? 'gate_utama');
        $direction = 'in';
        if (str_contains($gateName, 'keluar') || str_contains($gateName, 'exit')) {
            $direction = 'out';
        } elseif (str_contains($gateName, 'masuk') || str_contains($gateName, 'entry')) {
            $direction = 'in';
        } else {
            $direction = $activeSession ? 'out' : 'in';
        }

        // Mobil mau masuk tapi sudah di dalam
        if ($direction === 'in' && $activeSession) {
            AccessRequest::create([
                'member_id'     => $member->id,
                'vehicle_id'    => $vehicleId,
                'gate'          => $state->gate,
                'direction'     => 'in',
                'reason'        => 'already_inside',
                'plate_detected' => $state->plate_detected ?? 'UNKNOWN',
                'plate_match'   => true,
                'face_match'    => true,
                'face_distance' => $state->face_distance,
                'status'        => 'denied',
                'requested_at'  => now(),
                'responded_at'  => now(),
            ]);

            $state->update([
                'trigger' => false,
                'izin'    => 'TOLAK',
                'status'  => 'AKSES DITOLAK: MOBIL SUDAH DI DALAM',
            ]);

            return;
        }

        // Mobil mau keluar tapi tidak berada di dalam
        if ($direction === 'out' && !$activeSession) {
            AccessRequest::create([
                'member_id'     => $member->id,
                'vehicle_id'    => $vehicleId,
                'gate'          => $state->gate,
                'direction'     => 'out',
                'reason'        => 'not_inside',
                'plate_detected' => $state->plate_detected ?? 'UNKNOWN',
                'plate_match'   => true,
                'face_match'    => true,
                'face_distance' => $state->face_distance,
                'status'        => 'denied',
                'requested_at'  => now(),
                'responded_at'  => now(),
            ]);

            $state->update([
                'trigger' => false,
                'izin'    => 'TOLAK',
                'status'  => 'AKSES DITOLAK: MOBIL SUDAH KELUAR',
            ]);

            return;
        }

        // ✅ Semua validasi lolos → BUKA palang
        $accessRequest = AccessRequest::create([
            'member_id'     => $member->id,
            'vehicle_id'    => $vehicleId,
            'gate'          => $state->gate,
            'direction'     => $direction,
            'reason'        => null,
            'plate_detected' => $state->plate_detected ?? 'UNKNOWN',
            'plate_match'   => true,
            'face_match'    => true,
            'face_distance' => $state->face_distance,
            'status'        => 'auto_approved',
            'requested_at'  => now(),
            'responded_at'  => now(),
        ]);

        if ($direction === 'out') {
            ParkingSession::where('vehicle_id', $vehicleId)
                ->where('status', 'active')
                ->update([
                    'exit_access_request_id' => $accessRequest->id,
                    'exit_time'              => now(),
                    'status'                 => 'completed',
                ]);

            $state->update([
                'trigger' => false,
                'izin'    => 'BUKA',
                'status'  => 'PALANG TERBUKA - KENDARAAN KELUAR',
            ]);

            return;
        }

        ParkingSession::create([
            'member_id'              => $member->id,
            'vehicle_id'             => $vehicleId,
            'entry_access_request_id' => $accessRequest->id,
            'entry_time'             => now(),
            'status'                 => 'active',
        ]);

        $state->update([
            'trigger' => false,
            'izin'    => 'BUKA',
            'status'  => 'PALANG TERBUKA - KENDARAAN MASUK',
        ]);
    }

    // =========================================================================
    // ENDPOINT: Trigger gerbang (IoT — kendaraan terdeteksi di depan)
    // =========================================================================
    public function trigger(Request $request)
    {
        $gate = $request->input('gate', 'gate_utama');

        $state = ParkingGateState::updateOrCreate(
            ['gate' => $gate],
            [
                'trigger'       => true,
                'izin'          => 'MENUNGGU',
                'status'        => 'MENUNGGU VERIFIKASI',
                'face_match'    => false,
                'face_member_id' => null,
                'face_distance' => null,
                'plate_match'   => false,
                'plate_member_id' => null,
                'plate_vehicle_id' => null,
                'plate_detected' => null,
                // Reset counter percobaan saat sesi baru dimulai
                'attempt_count'       => 0,
                'quality_fail_count'  => 0,
                'last_failure_reason' => null,
                'face_votes'          => null,
                'face_frame_count'    => 0,
            ]
        );

        return response()->json([
            'success' => true,
            'message' => 'Trigger parkir diterima.',
            'data'    => $state,
        ]);
    }

    // =========================================================================
    // ENDPOINT: Polling status gerbang (ESP32)
    // =========================================================================
    public function command(Request $request)
    {
        $gate = $request->input('gate', 'gate_utama');

        $state = ParkingGateState::firstOrCreate(
            ['gate' => $gate],
            ['trigger' => false, 'izin' => 'SIAGA', 'status' => 'SIAGA']
        );

        return response()->json([
            'success' => true,
            'data'    => array_merge($state->toArray(), [
                'buzzer' => Cache::has(PanggilPetugasController::CACHE_KEY),
                // Pesan LCD untuk tampilan di gerbang
                'lcd_message' => $state->last_failure_reason
                    ? $this->pesanLcd($state->last_failure_reason)
                    : null,
            ]),
        ]);
    }

    // =========================================================================
    // ENDPOINT: Reset gerbang ke SIAGA
    // =========================================================================
    public function reset(Request $request)
    {
        $gate = $request->input('gate', 'gate_utama');

        $state = ParkingGateState::updateOrCreate(
            ['gate' => $gate],
            [
                'trigger'       => false,
                'izin'          => 'SIAGA',
                'status'        => 'SIAGA',
                'face_match'    => false,
                'face_member_id' => null,
                'face_distance' => null,
                'plate_match'   => false,
                'plate_member_id' => null,
                'plate_vehicle_id' => null,
                'plate_detected' => null,
                'attempt_count'       => 0,
                'quality_fail_count'  => 0,
                'last_failure_reason' => null,
                'face_votes'          => null,
                'face_frame_count'    => 0,
            ]
        );

        return response()->json([
            'success' => true,
            'message' => 'Status gate direset.',
            'data'    => $state,
        ]);
    }

    // =========================================================================
    // ENDPOINT: Cek status verifikasi (polling dari mobile/admin)
    // =========================================================================
    public function verificationStatus(Request $request)
    {
        if ($request->filled('gate')) {
            $gate  = $request->input('gate');
            $state = ParkingGateState::firstOrCreate(
                ['gate' => $gate],
                ['trigger' => false, 'izin' => 'SIAGA', 'status' => 'SIAGA']
            );

            return response()->json(['success' => true, 'data' => $state]);
        }

        $waitingState = ParkingGateState::where('trigger', true)
            ->orWhere('izin', 'MENUNGGU')
            ->latest('updated_at')
            ->first();

        if ($waitingState) {
            return response()->json(['success' => true, 'data' => $waitingState]);
        }

        $state = ParkingGateState::latest('updated_at')->first()
            ?? ParkingGateState::firstOrCreate(
                ['gate' => 'gate_utama'],
                ['trigger' => false, 'izin' => 'SIAGA', 'status' => 'SIAGA']
            );

        return response()->json(['success' => true, 'data' => $state]);
    }

    // =========================================================================
    // ENDPOINT: Hasil pengenalan wajah dari kamera (AI service)
    //
    // Mendukung dua mode:
    //   Mode 1: member_id langsung (LBPH dari Python)
    //   Mode 2: embedding 128-d (FastAPI)
    //   Mode kualitas: quality_fail=true jika gambar gelap/buram/wajah tidak terdeteksi
    // =========================================================================
    public function faceResult(Request $request)
    {
        $request->validate([
            'gate'         => 'required|string',
            'embedding'    => 'nullable|array',
            'member_id'    => 'nullable|integer',
            'distance'     => 'nullable|numeric',
            'quality_fail' => 'nullable|boolean', // true jika gambar gelap/buram/tidak ada wajah
        ]);

        $gate = $request->input('gate');

        $state = ParkingGateState::firstOrCreate(
            ['gate' => $gate],
            ['trigger' => false, 'izin' => 'SIAGA', 'status' => 'SIAGA']
        );

        if ($state->izin !== 'MENUNGGU') {
            return response()->json([
                'success' => false,
                'message' => 'Gate tidak sedang menunggu verifikasi.',
                'izin'    => $state->izin,
            ], 409);
        }

        // ── KASUS KUALITAS: gambar gelap/buram atau wajah tidak terdeteksi ──────
        if ($request->boolean('quality_fail')) {
            return $this->tanganiGagalKualitas($state);
        }

        // ── Mode 1: LBPH — member_id dikirim langsung dari Python ──────────────
        if ($request->filled('member_id') && !$request->filled('embedding')) {
            $memberId = (int) $request->input('member_id');
            $distance = $request->filled('distance') ? (float) $request->input('distance') : null;
            $member   = Member::find($memberId);

            // Jarak LBPH: semakin kecil semakin mirip (berbeda dari Euclidean embedding)
            $jarakCukup = $distance === null || $distance <= self::FACE_THRESHOLD_LBPH;
            $cocok      = $member && $member->is_active && $jarakCukup;

            return $this->prosesFrameWajah($state, $cocok ? $member : null, $distance, 'lbph');
        }

        // ── Mode 2: Embedding 128-d (FastAPI) ───────────────────────────────────
        $inputEmbedding = $request->input('embedding', []);

        if (count($inputEmbedding) !== 128) {
            return response()->json([
                'success' => false,
                'message' => 'Kirim member_id (LBPH) ATAU embedding 128 dimensi (FastAPI), atau quality_fail=true.',
            ], 422);
        }

        $profiles    = FaceProfile::with('member')->get();
        $bestMember  = null;
        $bestDistance = null;

        foreach ($profiles as $profile) {
            if (!$profile->embedding || !is_array($profile->embedding) || count($profile->embedding) !== 128) {
                continue;
            }

            $sum = 0.0;
            for ($i = 0; $i < 128; $i++) {
                $diff = (float) $inputEmbedding[$i] - (float) $profile->embedding[$i];
                $sum += $diff * $diff;
            }
            $distance = sqrt($sum);

            if ($bestDistance === null || $distance < $bestDistance) {
                $bestDistance = $distance;
                $bestMember   = $profile->member;
            }
        }

        $jarakCukup = $bestDistance !== null && $bestDistance <= self::FACE_THRESHOLD_EMBEDDING;
        $cocok      = $bestMember && $jarakCukup;

        return $this->prosesFrameWajah($state, $cocok ? $bestMember : null, $bestDistance, 'embedding');
    }

    // =========================================================================
    // ENDPOINT: Hasil pembacaan plat dari kamera (AI service)
    // =========================================================================
    public function plateResult(Request $request)
    {
        $request->validate([
            'gate'             => 'required|string',
            'plate_detected'   => 'nullable|string',
            'plate_candidates' => 'nullable|array',
        ]);

        $gate           = $request->input('gate');
        $plateDetected  = $request->input('plate_detected');
        $plateCandidates = $request->input('plate_candidates', []);

        if ($plateDetected) {
            $plateCandidates[] = $plateDetected;
        }

        $state = ParkingGateState::firstOrCreate(
            ['gate' => $gate],
            ['trigger' => false, 'izin' => 'SIAGA', 'status' => 'SIAGA']
        );

        if ($state->izin !== 'MENUNGGU') {
            return response()->json([
                'success' => false,
                'message' => 'Gate tidak sedang menunggu verifikasi.',
                'izin'    => $state->izin,
            ], 409);
        }

        $plates = $this->getPlateCandidates([
            'plate_text'       => $plateDetected,
            'plate_candidates' => $plateCandidates,
        ]);

        // Jika tidak ada plat terbaca sama sekali → gagal kualitas (Aturan 3)
        if (empty($plates)) {
            return $this->tanganiGagalPercobaan(
                $state,
                'plat_tidak_terbaca',
                null,
                null,
                $plateDetected
            );
        }

        [$vehicle, $matchedPlate] = $this->findVehicleByPlateCandidates($plates);

        // ── ATURAN 5: Plat terbaca tapi tidak terdaftar → langsung TOLAK tanpa notifikasi ──
        if (!$vehicle) {
            AccessRequest::create([
                'member_id'     => null,
                'vehicle_id'    => null,
                'gate'          => $state->gate,
                'direction'     => null,
                'reason'        => 'plat_tidak_terdaftar',
                'plate_detected' => $matchedPlate ?: $plateDetected,
                'plate_match'   => false,
                'face_match'    => false,
                'face_distance' => null,
                'status'        => 'denied',
                'requested_at'  => now(),
                'responded_at'  => now(),
            ]);

            $state->update([
                'trigger'       => false,
                'izin'          => 'TOLAK',
                'status'        => 'PLAT TIDAK TERDAFTAR',
                'plate_detected' => $matchedPlate ?: $plateDetected,
                'last_failure_reason' => 'plat_tidak_terdaftar',
            ]);

            return response()->json([
                'success'      => true,
                'plate_match'  => false,
                'plate_detected' => $matchedPlate ?: $plateDetected,
                'message'      => 'Plat tidak terdaftar. Akses ditolak.',
            ]);
        }

        $member = $vehicle->member;

        // Member tidak aktif
        if (!$member || !$member->is_active) {
            $state->update([
                'plate_match'    => false,
                'plate_member_id' => $member?->id,
                'plate_vehicle_id' => $vehicle->id,
                'plate_detected' => $matchedPlate,
                'trigger'        => false,
                'izin'           => 'TOLAK',
                'status'         => 'MEMBER TIDAK AKTIF',
                'last_failure_reason' => 'member_tidak_aktif',
            ]);

            return response()->json([
                'success'     => true,
                'plate_match' => false,
                'member_id'   => $member?->id,
                'message'     => 'Member tidak aktif.',
            ]);
        }

        // Langganan tidak aktif
        $subscription = $this->getActiveSubscription($member);

        if (!$subscription) {
            $state->update([
                'plate_match'    => false,
                'plate_member_id' => $member->id,
                'plate_vehicle_id' => $vehicle->id,
                'plate_detected' => $matchedPlate,
                'trigger'        => false,
                'izin'           => 'TOLAK',
                'status'         => 'SUBSCRIPTION TIDAK AKTIF',
                'last_failure_reason' => 'langganan_tidak_aktif',
            ]);

            return response()->json([
                'success'     => true,
                'plate_match' => false,
                'member_id'   => $member->id,
                'message'     => 'Subscription parkir tidak aktif.',
            ]);
        }

        // ✅ Plat cocok dan member valid
        $state->update([
            'plate_match'    => true,
            'plate_member_id' => $member->id,
            'plate_vehicle_id' => $vehicle->id,
            'plate_detected' => $matchedPlate,
            'status'         => 'PLAT COCOK - MENUNGGU WAJAH',
        ]);

        $this->evaluateGate($state->fresh());
        $state = $state->fresh();

        return response()->json([
            'success'          => true,
            'plate_match'      => (bool) $state->plate_match,
            'member_id'        => $member->id,
            'member_name'      => $member->name,
            'plate_detected'   => $matchedPlate,
            'face_match'       => (bool) $state->face_match,
            'face_member_id'   => $state->face_member_id,
            'izin'             => $state->izin,
            'status'           => $state->status,
            'message'          => $state->izin === 'BUKA'
                ? 'Wajah dan plat cocok. Palang dapat dibuka.'
                : 'Plat cocok. Menunggu hasil wajah.',
        ]);
    }

    // =========================================================================
    // PRIVAT: Proses satu frame wajah (voting 3/5) + logika percobaan
    // =========================================================================
    private function prosesFrameWajah(
        ParkingGateState $state,
        ?Member $member,
        ?float $distance,
        string $mode
    ) {
        $frameCount = ($state->face_frame_count ?? 0) + 1;
        $votes      = $state->face_votes ?? [];

        // Tambahkan suara untuk member ini (jika berhasil dikenali)
        if ($member) {
            $votes[(string) $member->id] = ($votes[(string) $member->id] ?? 0) + 1;
        }

        $state->update([
            'face_frame_count' => $frameCount,
            'face_votes'       => $votes,
            'face_distance'    => $distance,
        ]);

        // Belum 5 frame → kumpulkan terus
        if ($frameCount < self::FRAME_PER_SESI) {
            return response()->json([
                'success'       => true,
                'frame_count'   => $frameCount,
                'frames_needed' => self::FRAME_PER_SESI - $frameCount,
                'message'       => "Frame {$frameCount}/" . self::FRAME_PER_SESI . " diterima. Terus kirim.",
            ]);
        }

        // ── Sudah 5 frame → evaluasi voting ─────────────────────────────────
        arsort($votes); // urutkan: member dengan suara terbanyak di atas
        $topMemberId    = (int) array_key_first($votes);
        $topVoteCount   = (int) reset($votes);
        $wajahCocok     = $topVoteCount >= self::FRAME_COCOK_MIN;
        $topMember      = $wajahCocok ? Member::find($topMemberId) : null;

        // Reset frame counter untuk sesi berikutnya
        $state->update([
            'face_frame_count' => 0,
            'face_votes'       => null,
        ]);

        if ($wajahCocok && $topMember) {
            // ✅ Wajah cocok — set ke state
            $state->update([
                'face_match'    => true,
                'face_member_id' => $topMember->id,
                'face_distance'  => $distance,
                'status'         => 'WAJAH COCOK - MENUNGGU PLAT',
            ]);

            $this->evaluateGate($state->fresh());

            return response()->json([
                'success'      => true,
                'face_match'   => true,
                'member_id'    => $topMember->id,
                'member_name'  => $topMember->name,
                'vote_count'   => $topVoteCount,
                'distance'     => $distance,
                'message'      => "Wajah cocok ({$topVoteCount}/5 frame). Menunggu hasil plat.",
            ]);
        }

        // ❌ Wajah tidak cocok setelah 5 frame → hitung sebagai percobaan
        $state->update([
            'face_match'    => false,
            'face_member_id' => null,
            'status'         => 'WAJAH TIDAK COCOK',
            'last_failure_reason' => 'wajah_tidak_cocok',
        ]);

        return $this->tanganiGagalPercobaan(
            $state,
            'wajah_tidak_cocok',
            null,
            $distance,
            $state->plate_detected
        );
    }

    // =========================================================================
    // PRIVAT: Tangani kegagalan kualitas (gambar gelap/buram/wajah tidak terdeteksi)
    //
    // Aturan 4: maks 3 kali gagal kualitas per sesi, lalu dihitung sebagai percobaan
    // =========================================================================
    private function tanganiGagalKualitas(ParkingGateState $state)
    {
        $gagalKualitas = ($state->quality_fail_count ?? 0) + 1;

        if ($gagalKualitas < self::MAKS_GAGAL_KUALITAS) {
            // Belum melebihi batas → hanya catat, belum jadi percobaan
            $state->update([
                'quality_fail_count'  => $gagalKualitas,
                'last_failure_reason' => 'kualitas_buruk',
                'status'              => "KUALITAS BURUK ({$gagalKualitas}/" . self::MAKS_GAGAL_KUALITAS . ')',
            ]);

            return response()->json([
                'success'       => true,
                'quality_fail'  => true,
                'fail_count'    => $gagalKualitas,
                'max_allowed'   => self::MAKS_GAGAL_KUALITAS,
                'lcd_message'   => 'Kurang cahaya, dekatkan wajah',
                'message'       => "Kualitas gambar buruk. Gagal kualitas {$gagalKualitas}/" . self::MAKS_GAGAL_KUALITAS . ". Belum dihitung percobaan.",
            ]);
        }

        // Sudah 3 kali gagal kualitas → reset counter kualitas, hitung sebagai percobaan
        $state->update([
            'quality_fail_count'  => 0, // reset untuk sesi berikutnya
            'last_failure_reason' => 'kualitas_buruk',
        ]);

        return $this->tanganiGagalPercobaan(
            $state,
            'kualitas_buruk',
            null,
            null,
            $state->plate_detected
        );
    }

    // =========================================================================
    // PRIVAT: Tangani satu percobaan gagal (semua jenis kegagalan yang dihitung)
    //
    // Aturan 3 & 6: hitung percobaan, jika ≥5 dan plat terdaftar → notifikasi
    // =========================================================================
    private function tanganiGagalPercobaan(
        ParkingGateState $state,
        string $alasan,
        ?int $platVehicleId,
        ?float $faceDistance,
        ?string $plateDetected
    ) {
        $jumlahPercobaan = ($state->attempt_count ?? 0) + 1;

        $state->update([
            'attempt_count'       => $jumlahPercobaan,
            'last_failure_reason' => $alasan,
            'status'              => "GAGAL: {$alasan} ({$jumlahPercobaan}/" . self::MAKS_PERCOBAAN . ')',
        ]);

        // Jika belum melebihi batas → biarkan sesi lanjut
        if ($jumlahPercobaan < self::MAKS_PERCOBAAN) {
            return response()->json([
                'success'         => true,
                'failed'          => true,
                'attempt_count'   => $jumlahPercobaan,
                'attempts_left'   => self::MAKS_PERCOBAAN - $jumlahPercobaan,
                'reason'          => $alasan,
                'lcd_message'     => $this->pesanLcd($alasan),
                'message'         => "Percobaan {$jumlahPercobaan}/" . self::MAKS_PERCOBAAN . " gagal: {$alasan}.",
            ]);
        }

        // ── Sudah 5 kali gagal ────────────────────────────────────────────────
        // Jika plat terdaftar & wajah tidak cocok → kirim notifikasi ke pemilik (Aturan 6)
        $vehicleId = $platVehicleId ?? $state->plate_vehicle_id;

        if ($vehicleId && $alasan !== 'plat_tidak_terdaftar') {
            $vehicle = Vehicle::with('member')->find($vehicleId);

            if ($vehicle && $vehicle->member && $vehicle->member->is_active) {
                $member    = $vehicle->member;
                $direction = $this->tentukanArah($state);

                $this->kirimNotifikasiMismatch(
                    $state,
                    $member,
                    $vehicle,
                    $direction,
                    $faceDistance
                );

                return response()->json([
                    'success'       => true,
                    'failed'        => true,
                    'attempt_count' => $jumlahPercobaan,
                    'notified'      => true,
                    'reason'        => $alasan,
                    'lcd_message'   => 'Hubungi petugas atau tunggu konfirmasi pemilik',
                    'message'       => "5 kali gagal. Notifikasi dikirim ke pemilik kendaraan.",
                ]);
            }
        }

        // Jika plat tidak terdaftar atau tidak ada kendaraan → langsung TOLAK
        $state->update([
            'trigger' => false,
            'izin'    => 'TOLAK',
            'status'  => 'DITOLAK - MAKS PERCOBAAN TERCAPAI',
        ]);

        return response()->json([
            'success'       => true,
            'failed'        => true,
            'attempt_count' => $jumlahPercobaan,
            'notified'      => false,
            'reason'        => $alasan,
            'lcd_message'   => $this->pesanLcd($alasan),
            'message'       => "5 kali gagal. Akses ditolak.",
        ]);
    }

    // =========================================================================
    // PRIVAT: Tentukan arah masuk/keluar berdasarkan nama gate + sesi aktif
    // =========================================================================
    private function tentukanArah(ParkingGateState $state): string
    {
        $gateName = strtolower($state->gate ?? 'gate_utama');

        if (str_contains($gateName, 'keluar') || str_contains($gateName, 'exit')) {
            return 'out';
        }
        if (str_contains($gateName, 'masuk') || str_contains($gateName, 'entry')) {
            return 'in';
        }

        // Gate dua arah: cek sesi aktif kendaraan
        if ($state->plate_vehicle_id) {
            $aktif = ParkingSession::where('vehicle_id', $state->plate_vehicle_id)
                ->where('status', 'active')
                ->exists();
            return $aktif ? 'out' : 'in';
        }

        return 'in';
    }

    // =========================================================================
    // PRIVAT: Pesan untuk tampilan LCD di gerbang
    // =========================================================================
    private function pesanLcd(string $alasan): string
    {
        return match ($alasan) {
            'kualitas_buruk'       => 'Kurang cahaya, dekatkan wajah',
            'wajah_tidak_cocok'    => 'Wajah tidak dikenali',
            'wajah_plat_beda_member' => 'Wajah dan plat tidak sesuai',
            'plat_tidak_terbaca'   => 'Plat tidak terbaca, ulangi',
            'plat_tidak_terdaftar' => 'Plat tidak terdaftar',
            'member_tidak_aktif'   => 'Akun tidak aktif',
            'langganan_tidak_aktif' => 'Langganan habis',
            default                => 'Verifikasi gagal',
        };
    }
}
