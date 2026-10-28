<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AccessRequest;
use App\Models\FaceProfile;
use App\Models\Member;
use App\Models\ParkingGateState;
use App\Models\ParkingSession;
use App\Models\Vehicle;
use Illuminate\Http\Request;

class ParkingGateController extends Controller
{
    private function normalizePlate(?string $plate): string
    {
        return strtoupper(preg_replace('/[^A-Z0-9]/', '', $plate ?? ''));
    }

    private function plateSimilarity(string $ocr, string $registered): float
    {
        $ocr = $this->normalizePlate($ocr);
        $registered = $this->normalizePlate($registered);

        if ($ocr === '' || $registered === '') {
            return 0.0;
        }

        if ($ocr === $registered) {
            return 1.0;
        }

        similar_text($ocr, $registered, $percent);
        $similarTextScore = $percent / 100;

        $distance = levenshtein($ocr, $registered);
        $maxLength = max(strlen($ocr), strlen($registered));
        $levenshteinScore = $maxLength > 0
            ? max(0, 1 - ($distance / $maxLength))
            : 0.0;

        $ocrNumbers = preg_replace('/[^0-9]/', '', $ocr);
        $registeredNumbers = preg_replace('/[^0-9]/', '', $registered);
        $numberScore = 0.0;

        if ($ocrNumbers !== '' && $registeredNumbers !== '') {
            similar_text($ocrNumbers, $registeredNumbers, $numberPercent);
            $numberScore = $numberPercent / 100;
        }

        $prefixScore = 0.0;
        $suffixScore = 0.0;

        $prefixLength = min(2, strlen($ocr), strlen($registered));

        if ($prefixLength > 0) {
            $ocrPrefix = substr($ocr, 0, $prefixLength);
            $registeredPrefix = substr($registered, 0, $prefixLength);

            if ($ocrPrefix === $registeredPrefix) {
                $prefixScore = 1.0;
            } else {
                similar_text($ocrPrefix, $registeredPrefix, $prefixPercent);
                $prefixScore = $prefixPercent / 100;
            }
        }

        $suffixLength = min(3, strlen($ocr), strlen($registered));

        if ($suffixLength > 0) {
            $ocrSuffix = substr($ocr, -$suffixLength);
            $registeredSuffix = substr($registered, -$suffixLength);

            if ($ocrSuffix === $registeredSuffix) {
                $suffixScore = 1.0;
            } else {
                similar_text($ocrSuffix, $registeredSuffix, $suffixPercent);
                $suffixScore = $suffixPercent / 100;
            }
        }

        $edgeScore = ($prefixScore * 0.45) + ($suffixScore * 0.55);

        return ($similarTextScore * 0.35)
            + ($levenshteinScore * 0.25)
            + ($numberScore * 0.15)
            + ($edgeScore * 0.25);
    }

    private function findVehicleByPlateCandidates(array $plates): array
    {
        $normalizedPlates = [];

        foreach ($plates as $plate) {
            if (is_array($plate)) {
                $plate = $plate['text'] ?? '';
            }

            $plate = $this->normalizePlate($plate);

            if ($plate !== '' && ! in_array($plate, $normalizedPlates, true)) {
                $normalizedPlates[] = $plate;
            }
        }

        if (empty($normalizedPlates)) {
            return [null, 0.0, null];
        }

        $vehicles = Vehicle::with('member')->get();
        $bestVehicle = null;
        $bestScore = 0.0;
        $bestOcrPlate = null;

        foreach ($vehicles as $vehicle) {
            $registered = $this->normalizePlate($vehicle->plate_number);

            foreach ($normalizedPlates as $ocrPlate) {
                if ($ocrPlate === $registered) {
                    return [$vehicle, 1.0, $ocrPlate];
                }

                $score = $this->plateSimilarity($ocrPlate, $registered);

                if ($score > $bestScore) {
                    $bestScore = $score;
                    $bestVehicle = $vehicle;
                    $bestOcrPlate = $ocrPlate;
                }
            }
        }

        if ($bestVehicle && $bestScore >= 0.55) {
            return [$bestVehicle, $bestScore, $bestOcrPlate];
        }

        return [null, $bestScore, $bestOcrPlate];
    }

    private function getPlateCandidates(array $data): array
    {
        $plates = [];

        if (! empty($data['plate_text'])) {
            $plates[] = $data['plate_text'];
        }

        if (! empty($data['plate_candidates']) && is_array($data['plate_candidates'])) {
            foreach ($data['plate_candidates'] as $candidate) {
                if (is_array($candidate) && ! empty($candidate['text'])) {
                    $plates[] = $candidate['text'];
                }
            }
        }

        return $plates;
    }

    private function getActiveSubscription($member)
    {
        return $member->subscriptions()
            ->where('status', 'active')
            ->whereDate('start_date', '<=', now()->toDateString())
            ->whereDate('end_date', '>=', now()->toDateString())
            ->latest('end_date')
            ->first();
    }

    private function evaluateGate(ParkingGateState $state): void
    {
        if (! $state->face_match || ! $state->plate_match) {
            return;
        }

        if (
            ! $state->face_member_id ||
            ! $state->plate_member_id ||
            $state->face_member_id != $state->plate_member_id
        ) {
            $state->update([
                'trigger' => false,
                'izin' => 'TOLAK',
                'status' => 'WAJAH DAN PLAT TIDAK SESUAI',
            ]);

            return;
        }

        $member = Member::find($state->face_member_id);

        if (! $member || ! $member->is_active) {
            $state->update([
                'trigger' => false,
                'izin' => 'TOLAK',
                'status' => 'MEMBER TIDAK AKTIF',
            ]);

            return;
        }

        $subscription = $this->getActiveSubscription($member);

        if (! $subscription) {
            $state->update([
                'trigger' => false,
                'izin' => 'TOLAK',
                'status' => 'SUBSCRIPTION TIDAK AKTIF',
            ]);

            return;
        }

        $vehicleId = $state->plate_vehicle_id;

        if (! $vehicleId) {
            $state->update([
                'trigger' => false,
                'izin' => 'TOLAK',
                'status' => 'DATA KENDARAAN TIDAK DITEMUKAN',
            ]);

            return;
        }

        $activeSession = ParkingSession::where('vehicle_id', $vehicleId)
            ->where('status', 'active')
            ->latest('entry_time')
            ->first();

        $gateName = strtolower($state->gate ?? 'gate_utama');
        $direction = 'in';
        if (str_contains($gateName, 'keluar') || str_contains($gateName, 'exit')) {
            $direction = 'out';
        } elseif (str_contains($gateName, 'masuk') || str_contains($gateName, 'entry')) {
            $direction = 'in';
        } else {
            // Untuk gerbang dua arah (seperti gate_utama):
            // Jika ada sesi aktif di dalam, arahnya keluar ('out').
            // Jika tidak ada sesi aktif, arahnya masuk ('in').
            $direction = $activeSession ? 'out' : 'in';
        }

        // Cek: Mobil mau masuk tapi sudah berada di dalam
        if ($direction === 'in' && $activeSession) {
            AccessRequest::create([
                'member_id' => $member->id,
                'vehicle_id' => $vehicleId,
                'gate' => $state->gate,
                'direction' => 'in',
                'reason' => 'already_inside',
                'plate_detected' => $state->plate_detected ?? 'UNKNOWN',
                'plate_match' => true,
                'face_match' => true,
                'face_distance' => $state->face_distance,
                'status' => 'denied',
                'requested_at' => now(),
                'responded_at' => now(),
            ]);

            $state->update([
                'trigger' => false,
                'izin' => 'TOLAK',
                'status' => 'AKSES DITOLAK: MOBIL SUDAH DI DALAM',
            ]);

            return;
        }

        // Cek: Mobil mau keluar tapi tidak berada di dalam (sudah keluar)
        if ($direction === 'out' && ! $activeSession) {
            AccessRequest::create([
                'member_id' => $member->id,
                'vehicle_id' => $vehicleId,
                'gate' => $state->gate,
                'direction' => 'out',
                'reason' => 'not_inside',
                'plate_detected' => $state->plate_detected ?? 'UNKNOWN',
                'plate_match' => true,
                'face_match' => true,
                'face_distance' => $state->face_distance,
                'status' => 'denied',
                'requested_at' => now(),
                'responded_at' => now(),
            ]);

            $state->update([
                'trigger' => false,
                'izin' => 'TOLAK',
                'status' => 'AKSES DITOLAK: MOBIL SUDAH KELUAR',
            ]);

            return;
        }

        $accessRequest = AccessRequest::create([
            'member_id' => $member->id,
            'vehicle_id' => $vehicleId,
            'gate' => $state->gate,
            'direction' => $direction,
            'reason' => null,
            'plate_detected' => $state->plate_detected ?? 'UNKNOWN',
            'plate_match' => true,
            'face_match' => true,
            'face_distance' => $state->face_distance,
            'status' => 'auto_approved',
            'requested_at' => now(),
            'responded_at' => now(),
        ]);

        if ($direction === 'out') {
            ParkingSession::where('vehicle_id', $vehicleId)
                ->where('status', 'active')
                ->update([
                    'exit_access_request_id' => $accessRequest->id,
                    'exit_time' => now(),
                    'status' => 'completed',
                ]);

            $state->update([
                'trigger' => false,
                'izin' => 'BUKA',
                'status' => 'PALANG TERBUKA - KENDARAAN KELUAR',
            ]);

            return;
        }

        ParkingSession::create([
            'member_id' => $member->id,
            'vehicle_id' => $vehicleId,
            'entry_access_request_id' => $accessRequest->id,
            'entry_time' => now(),
            'status' => 'active',
        ]);

        $state->update([
            'trigger' => false,
            'izin' => 'BUKA',
            'status' => 'PALANG TERBUKA - KENDARAAN MASUK',
        ]);
    }

    public function trigger(Request $request)
    {
        $gate = $request->input('gate', 'gate_utama');

        $state = ParkingGateState::updateOrCreate(
            ['gate' => $gate],
            [
                'trigger' => true,
                'izin' => 'MENUNGGU',
                'status' => 'MENUNGGU VERIFIKASI',
                'face_match' => false,
                'face_member_id' => null,
                'face_distance' => null,
                'plate_match' => false,
                'plate_member_id' => null,
                'plate_vehicle_id' => null,
                'plate_detected' => null,
            ]
        );

        return response()->json([
            'success' => true,
            'message' => 'Trigger parkir diterima.',
            'data' => $state,
        ]);
    }

    public function command(Request $request)
    {
        $gate = $request->input('gate', 'gate_utama');

        $state = ParkingGateState::firstOrCreate(
            ['gate' => $gate],
            [
                'trigger' => false,
                'izin' => 'SIAGA',
                'status' => 'SIAGA',
            ]
        );

        return response()->json([
            'success' => true,
            'data' => $state,
        ]);
    }

    public function reset(Request $request)
    {
        $gate = $request->input('gate', 'gate_utama');

        $state = ParkingGateState::updateOrCreate(
            ['gate' => $gate],
            [
                'trigger' => false,
                'izin' => 'SIAGA',
                'status' => 'SIAGA',
                'face_match' => false,
                'face_member_id' => null,
                'face_distance' => null,
                'plate_match' => false,
                'plate_member_id' => null,
                'plate_vehicle_id' => null,
                'plate_detected' => null,
            ]
        );

        return response()->json([
            'success' => true,
            'message' => 'Status gate direset.',
            'data' => $state,
        ]);
    }

    public function verificationStatus(Request $request)
    {
        // 1. Jika gate spesifik diminta, cek gate tersebut
        if ($request->filled('gate')) {
            $gate = $request->input('gate');
            $state = ParkingGateState::firstOrCreate(
                ['gate' => $gate],
                ['trigger' => false, 'izin' => 'SIAGA', 'status' => 'SIAGA']
            );

            return response()->json([
                'success' => true,
                'data' => $state,
            ]);
        }

        // 2. Jika tidak ada gate spesifik, prioritaskan gate yang sedang menunggu verifikasi (trigger == true / izin == MENUNGGU)
        $waitingState = ParkingGateState::where('trigger', true)
            ->orWhere('izin', 'MENUNGGU')
            ->latest('updated_at')
            ->first();

        if ($waitingState) {
            return response()->json([
                'success' => true,
                'data' => $waitingState,
            ]);
        }

        // 3. Fallback: ambil gate yang paling terakhir diupdate atau gate_utama
        $state = ParkingGateState::latest('updated_at')->first()
            ?? ParkingGateState::firstOrCreate(
                ['gate' => 'gate_utama'],
                ['trigger' => false, 'izin' => 'SIAGA', 'status' => 'SIAGA']
            );

        return response()->json([
            'success' => true,
            'data' => $state,
        ]);
    }

    public function faceResult(Request $request)
    {
        $request->validate([
            'gate' => 'required|string',
            'embedding' => 'nullable|array',     // embedding FastAPI (opsional)
            'member_id' => 'nullable|integer',   // member_id LBPH (opsional)
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
                'izin' => $state->izin,
            ], 409);
        }

        // ── Mode 1: LBPH member_id langsung dari Python ───────────────────
        if ($request->filled('member_id') && ! $request->filled('embedding')) {
            $memberId = (int) $request->input('member_id');
            $distance = $request->filled('distance') ? (float) $request->input('distance') : null;
            $member = Member::find($memberId);

            $faceMatch = $member && $member->is_active;

            $state->update($faceMatch
                ? ['face_match' => true,  'face_member_id' => $member->id,
                    'face_distance' => $distance,
                    'status' => 'WAJAH COCOK - MENUNGGU PLAT']
                : ['face_match' => false, 'face_member_id' => null,
                    'face_distance' => $distance,
                    'status' => 'WAJAH TIDAK COCOK - MENUNGGU PLAT']
            );

            $this->evaluateGate($state->fresh());

            return response()->json([
                'success' => true,
                'face_match' => $faceMatch,
                'member_id' => $member?->id,
                'member_name' => $member?->name,
                'distance' => $distance,
                'message' => $faceMatch
                    ? 'Wajah cocok (LBPH). Menunggu hasil plat.'
                    : 'Wajah tidak cocok (LBPH). Menunggu hasil plat.',
            ]);
        }

        // ── Mode 2: Embedding 128-d dari FastAPI (lama, tetap didukung) ───
        $inputEmbedding = $request->input('embedding', []);

        if (count($inputEmbedding) !== 128) {
            return response()->json([
                'success' => false,
                'message' => 'Kirim member_id (LBPH) ATAU embedding 128 dimensi (FastAPI).',
            ], 422);
        }

        $profiles = FaceProfile::with('member')->get();
        $bestMember = null;
        $bestDistance = null;
        $bestProfile = null;

        foreach ($profiles as $profile) {
            if (! $profile->embedding || ! is_array($profile->embedding) ||
                count($profile->embedding) !== 128) {
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
                $bestMember = $profile->member;
                $bestProfile = $profile;
            }
        }

        $threshold = 0.70;
        $faceMatch = $bestMember && $bestDistance !== null && $bestDistance <= $threshold;

        $state->update($faceMatch
            ? ['face_match' => true,  'face_member_id' => $bestMember->id,
                'face_distance' => $bestDistance,
                'status' => 'WAJAH COCOK - MENUNGGU PLAT']
            : ['face_match' => false, 'face_member_id' => null,
                'face_distance' => $bestDistance,
                'status' => 'WAJAH TIDAK COCOK - MENUNGGU PLAT']
        );

        $this->evaluateGate($state->fresh());

        return response()->json([
            'success' => true,
            'face_match' => (bool) $faceMatch,
            'member_id' => $bestMember?->id,
            'member_name' => $bestMember?->name,
            'face_profile_id' => $bestProfile?->id,
            'distance' => $bestDistance,
            'message' => $faceMatch
                ? 'Wajah cocok. Menunggu hasil plat.'
                : 'Wajah tidak cocok. Menunggu hasil plat.',
        ]);
    }

    public function plateResult(Request $request)
    {
        $request->validate([
            'gate' => 'required|string',
            'plate_detected' => 'nullable|string',
            'plate_candidates' => 'nullable|array',
        ]);

        $gate = $request->input('gate');
        $plateDetected = $request->input('plate_detected');
        $plateCandidates = $request->input('plate_candidates', []);

        if ($plateDetected) {
            $plateCandidates[] = $plateDetected;
        }

        $state = ParkingGateState::firstOrCreate(
            ['gate' => $gate],
            [
                'trigger' => false,
                'izin' => 'SIAGA',
                'status' => 'SIAGA',
            ]
        );

        if ($state->izin !== 'MENUNGGU') {
            return response()->json([
                'success' => false,
                'message' => 'Gate tidak sedang menunggu verifikasi.',
                'izin' => $state->izin,
            ], 409);
        }

        $plates = $this->getPlateCandidates([
            'plate_text' => $plateDetected,
            'plate_candidates' => $plateCandidates,
        ]);

        [$vehicle, $similarity, $matchedPlate] =
            $this->findVehicleByPlateCandidates($plates);

        if (! $vehicle) {
            $state->update([
                'plate_match' => false,
                'plate_member_id' => null,
                'plate_vehicle_id' => null,
                'plate_detected' => $matchedPlate ?: $plateDetected,
                'status' => 'PLAT TIDAK TERDAFTAR - MENUNGGU WAJAH',
            ]);

            if ($state->face_match) {
                $state->update([
                    'trigger' => false,
                    'izin' => 'TOLAK',
                    'status' => 'PLAT TIDAK COCOK',
                ]);
            }

            return response()->json([
                'success' => true,
                'plate_match' => false,
                'member_id' => null,
                'plate_detected' => $matchedPlate ?: $plateDetected,
                'plate_similarity' => $similarity,
                'message' => 'Plat tidak terdaftar.',
            ]);
        }

        $member = $vehicle->member;

        if (! $member || ! $member->is_active) {
            $state->update([
                'plate_match' => false,
                'plate_member_id' => $member?->id,
                'plate_vehicle_id' => $vehicle->id,
                'plate_detected' => $matchedPlate,
                'trigger' => false,
                'izin' => 'TOLAK',
                'status' => 'MEMBER TIDAK AKTIF',
            ]);

            return response()->json([
                'success' => true,
                'plate_match' => false,
                'member_id' => $member?->id,
                'message' => 'Member tidak aktif.',
            ]);
        }

        $subscription = $this->getActiveSubscription($member);

        if (! $subscription) {
            $state->update([
                'plate_match' => false,
                'plate_member_id' => $member->id,
                'plate_vehicle_id' => $vehicle->id,
                'plate_detected' => $matchedPlate,
                'trigger' => false,
                'izin' => 'TOLAK',
                'status' => 'SUBSCRIPTION TIDAK AKTIF',
            ]);

            return response()->json([
                'success' => true,
                'plate_match' => false,
                'member_id' => $member->id,
                'message' => 'Subscription parkir tidak aktif.',
            ]);
        }

        $state->update([
            'plate_match' => true,
            'plate_member_id' => $member->id,
            'plate_vehicle_id' => $vehicle->id,
            'plate_detected' => $matchedPlate,
            'status' => 'PLAT COCOK - MENUNGGU WAJAH',
        ]);

        $this->evaluateGate($state->fresh());

        $state = $state->fresh();

        return response()->json([
            'success' => true,
            'plate_match' => (bool) $state->plate_match,
            'member_id' => $member->id,
            'member_name' => $member->name,
            'plate_detected' => $matchedPlate,
            'plate_similarity' => $similarity,
            'face_match' => (bool) $state->face_match,
            'face_member_id' => $state->face_member_id,
            'izin' => $state->izin,
            'status' => $state->status,
            'message' => $state->izin === 'BUKA'
                ? 'Face dan plat cocok. Palang dapat dibuka.'
                : 'Plat cocok. Menunggu hasil wajah.',
        ]);
    }
}
