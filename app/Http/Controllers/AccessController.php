<?php

namespace App\Http\Controllers;

use App\Models\AccessRequest;
use App\Models\FaceProfile;
use App\Models\ParkingGateState;
use App\Models\ParkingSession;
use App\Models\Vehicle;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AccessController extends Controller
{
    private string $fastApiUrl = 'http://127.0.0.1:5000';

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
            similar_text(
                $ocrNumbers,
                $registeredNumbers,
                $numberPercent
            );

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
                similar_text(
                    $ocrPrefix,
                    $registeredPrefix,
                    $prefixPercent
                );

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
                similar_text(
                    $ocrSuffix,
                    $registeredSuffix,
                    $suffixPercent
                );

                $suffixScore = $suffixPercent / 100;
            }
        }

        $edgeScore = ($prefixScore * 0.45) + ($suffixScore * 0.55);

        return
            ($similarTextScore * 0.35) +
            ($levenshteinScore * 0.25) +
            ($numberScore * 0.15) +
            ($edgeScore * 0.25);
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
            $registered = $this->normalizePlate(
                $vehicle->plate_number
            );

            foreach ($normalizedPlates as $ocrPlate) {
                if ($ocrPlate === $registered) {
                    return [$vehicle, 1.0, $ocrPlate];
                }

                $score = $this->plateSimilarity(
                    $ocrPlate,
                    $registered
                );

                if ($score > $bestScore) {
                    $bestScore = $score;
                    $bestVehicle = $vehicle;
                    $bestOcrPlate = $ocrPlate;
                }
            }
        }

        if ($bestVehicle && $bestScore >= 0.55) {
            return [
                $bestVehicle,
                $bestScore,
                $bestOcrPlate,
            ];
        }

        return [
            null,
            $bestScore,
            $bestOcrPlate,
        ];
    }

    private function getPlateCandidates(array $plateAi): array
    {
        $plates = [];

        if (! empty($plateAi['plate_text'])) {
            $plates[] = $plateAi['plate_text'];
        }

        if (! empty($plateAi['plate_candidates']) && is_array($plateAi['plate_candidates'])) {
            foreach ($plateAi['plate_candidates'] as $candidate) {
                if (is_array($candidate) && ! empty($candidate['text'])) {
                    $plates[] = $candidate['text'];
                }
            }
        }

        return $plates;
    }

    private function compareFace(
        int $memberId,
        array $faceEmbedding
    ): array {
        $faceDistance = null;

        $profiles = FaceProfile::where(
            'member_id',
            $memberId
        )->get();

        foreach ($profiles as $profile) {
            $storedEmbedding = $profile->embedding;

            if (! is_array($storedEmbedding)) {
                continue;
            }

            if (count($storedEmbedding) !== count($faceEmbedding)) {
                continue;
            }

            $sum = 0.0;

            for ($i = 0; $i < count($storedEmbedding); $i++) {
                $difference =
                    (float) $storedEmbedding[$i] -
                    (float) $faceEmbedding[$i];

                $sum += $difference * $difference;
            }

            $distance = sqrt($sum);

            if (
                $faceDistance === null ||
                $distance < $faceDistance
            ) {
                $faceDistance = $distance;
            }
        }

        $faceMatch =
            $faceDistance !== null &&
            $faceDistance <= 0.60;

        return [
            $faceMatch,
            $faceDistance,
        ];
    }

    private function getActiveSubscription($member)
    {
        return $member->subscriptions()
            ->where('status', 'active')
            ->whereDate(
                'start_date',
                '<=',
                now()->toDateString()
            )
            ->whereDate(
                'end_date',
                '>=',
                now()->toDateString()
            )
            ->latest('end_date')
            ->first();
    }

    public function detect(Request $request)
    {
        $request->validate([
            'photo' => ['required', 'image', 'max:10240'],
        ]);

        $photo = $request->file('photo');

        try {
            $response = Http::connectTimeout(5)
                ->timeout(120)
                ->attach(
                    'photo',
                    file_get_contents($photo->getRealPath()),
                    $photo->getClientOriginalName()
                )
                ->post(
                    $this->fastApiUrl.'/detect-access'
                );

            Log::info('AI RESPONSE', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
        } catch (\Throwable $e) {
            Log::error('AI SERVICE ERROR', [
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'status' => 'denied',
                'message' => 'AI service tidak dapat dihubungi.',
                'error' => $e->getMessage(),
            ], 503);
        }

        if ($response->failed()) {
            return response()->json([
                'success' => false,
                'status' => 'denied',
                'message' => 'AI gagal memproses gambar.',
                'ai_response' => $response->json(),
            ], 422);
        }

        $ai = $response->json();

        $plates = $this->getPlateCandidates($ai);

        [$vehicle, $plateSimilarity, $matchedPlate] =
            $this->findVehicleByPlateCandidates($plates);

        if (! $vehicle) {
            AccessRequest::create([
                'member_id' => null,
                'plate_detected' => $matchedPlate ?: 'UNKNOWN',
                'plate_match' => false,
                'face_match' => false,
                'status' => 'denied',
                'requested_at' => now(),
                'responded_at' => now(),
            ]);

            return response()->json([
                'success' => true,
                'status' => 'denied',
                'message' => 'Kendaraan tidak terdaftar.',
                'plate_text' => $matchedPlate,
                'plate_confidence' => $ai['plate_confidence'] ?? null,
                'face_detected' => $ai['face_detected'] ?? false,
                'plate_similarity' => $plateSimilarity,
            ]);
        }

        $member = $vehicle->member;

        if (! $member || ! $member->is_active) {
            return response()->json([
                'success' => true,
                'status' => 'denied',
                'message' => 'Member tidak aktif.',
                'plate_text' => $matchedPlate,
                'member_id' => $member?->id,
                'plate_match' => true,
                'plate_similarity' => $plateSimilarity,
            ]);
        }

        $subscription = $this->getActiveSubscription($member);

        if (! $subscription) {
            return response()->json([
                'success' => true,
                'status' => 'denied',
                'message' => 'Subscription parkir tidak aktif.',
                'plate_text' => $matchedPlate,
                'member_id' => $member->id,
                'plate_match' => true,
                'plate_similarity' => $plateSimilarity,
            ]);
        }

        $faceEmbedding = $ai['face_embedding'] ?? null;

        if (! is_array($faceEmbedding) || empty($faceEmbedding)) {
            return response()->json([
                'success' => true,
                'status' => 'denied',
                'message' => 'Embedding wajah tidak tersedia.',
                'plate_text' => $matchedPlate,
                'plate_similarity' => $plateSimilarity,
            ]);
        }

        [$faceMatch, $faceDistance] =
            $this->compareFace(
                $member->id,
                $faceEmbedding
            );

        $status = $faceMatch
            ? 'auto_approved'
            : 'denied';

        $accessRequest = AccessRequest::create([
            'member_id' => $member->id,
            'plate_detected' => $matchedPlate,
            'plate_match' => true,
            'face_match' => $faceMatch,
            'status' => $status,
            'requested_at' => now(),
            'responded_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'status' => $status,
            'message' => $faceMatch
                ? 'Akses diterima.'
                : 'Akses ditolak. Wajah tidak cocok.',
            'access_request_id' => $accessRequest->id,
            'plate_text' => $matchedPlate,
            'plate_confidence' => $ai['plate_confidence'] ?? null,
            'plate_similarity' => $plateSimilarity,
            'member' => [
                'id' => $member->id,
                'name' => $member->name,
            ],
            'vehicle' => [
                'id' => $vehicle->id,
                'plate_number' => $vehicle->plate_number,
                'vehicle_type' => $vehicle->vehicle_type,
            ],
            'subscription' => [
                'id' => $subscription->id,
                'start_date' => $subscription->start_date->toDateString(),
                'end_date' => $subscription->end_date->toDateString(),
            ],
            'face_detected' => $ai['face_detected'] ?? false,
            'face_match' => $faceMatch,
            'face_distance' => $faceDistance,
        ]);
    }

    public function verifyAccess(Request $request)
    {
        $request->validate([
            'face_image' => ['required', 'string'],
            'gate' => ['nullable', 'string'],
        ]);

        $gate = $request->input(
            'gate',
            'gate_utama'
        );

        $faceImage = $request->input('face_image');

        $state = ParkingGateState::firstOrCreate(
            ['gate' => $gate],
            [
                'trigger' => false,
                'izin' => 'SIAGA',
                'status' => 'SIAGA',
            ]
        );

        if (
            ! preg_match(
                '/^data:image\/(\w+);base64,(.+)$/',
                $faceImage,
                $matches
            )
        ) {
            $state->update([
                'trigger' => false,
                'izin' => 'TOLAK',
                'status' => 'FORMAT WAJAH INVALID',
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Format gambar wajah tidak valid.',
            ], 422);
        }

        $imageData = base64_decode(
            $matches[2],
            true
        );

        if ($imageData === false || strlen($imageData) < 100) {
            $state->update([
                'trigger' => false,
                'izin' => 'TOLAK',
                'status' => 'GAMBAR WAJAH INVALID',
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Gambar wajah tidak dapat dibaca.',
            ], 422);
        }

        try {
            $faceResponse = Http::connectTimeout(5)
                ->timeout(60)
                ->attach(
                    'photo',
                    $imageData,
                    'face.jpg',
                    [
                        'Content-Type' => 'image/jpeg',
                    ]
                )
                ->post(
                    $this->fastApiUrl.'/extract-embedding'
                );

            Log::info('FACE AI RESPONSE', [
                'status' => $faceResponse->status(),
                'body' => $faceResponse->body(),
            ]);
        } catch (\Throwable $e) {
            Log::error('FACE AI ERROR', [
                'message' => $e->getMessage(),
            ]);

            $state->update([
                'trigger' => false,
                'izin' => 'TOLAK',
                'status' => 'AI WAJAH ERROR',
            ]);

            return response()->json([
                'success' => false,
                'message' => 'AI wajah tidak dapat dihubungi.',
                'error' => $e->getMessage(),
            ], 503);
        }

        if ($faceResponse->failed()) {
            Log::error('FACE AI FAILED', [
                'status' => $faceResponse->status(),
                'body' => $faceResponse->body(),
            ]);

            $state->update([
                'trigger' => false,
                'izin' => 'TOLAK',
                'status' => 'WAJAH TIDAK TERDETEKSI',
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Wajah tidak dapat diproses.',
                'ai_status' => $faceResponse->status(),
                'ai_response' => $faceResponse->json(),
            ], 422);
        }

        $faceAi = $faceResponse->json();

        $faceEmbedding = $faceAi['embedding'] ?? null;

        if (
            ! is_array($faceEmbedding) ||
            empty($faceEmbedding)
        ) {
            $state->update([
                'trigger' => false,
                'izin' => 'TOLAK',
                'status' => 'EMBEDDING WAJAH TIDAK ADA',
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Embedding wajah tidak tersedia.',
                'ai_response' => $faceAi,
            ], 422);
        }

        try {
            $plateResponse = Http::connectTimeout(5)
                ->timeout(120)
                ->get(
                    $this->fastApiUrl.'/detect-phone-plate'
                );

            Log::info('PLATE AI RESPONSE', [
                'status' => $plateResponse->status(),
                'body' => $plateResponse->body(),
            ]);
        } catch (\Throwable $e) {
            Log::error('PLATE AI ERROR', [
                'message' => $e->getMessage(),
            ]);

            $state->update([
                'trigger' => false,
                'izin' => 'TOLAK',
                'status' => 'AI PLAT ERROR',
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Kamera HP/AI plat tidak dapat dihubungi.',
                'error' => $e->getMessage(),
            ], 503);
        }

        if ($plateResponse->failed()) {
            Log::error('PLATE AI FAILED', [
                'status' => $plateResponse->status(),
                'body' => $plateResponse->body(),
            ]);

            $state->update([
                'trigger' => false,
                'izin' => 'TOLAK',
                'status' => 'PLAT TIDAK TERDETEKSI',
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Plat kendaraan tidak dapat diproses.',
                'ai_status' => $plateResponse->status(),
                'ai_response' => $plateResponse->json(),
            ], 422);
        }

        $plateAi = $plateResponse->json();

        if (
            ! is_array($plateAi) ||
            ($plateAi['success'] ?? false) !== true
        ) {
            $state->update([
                'trigger' => false,
                'izin' => 'TOLAK',
                'status' => 'PLAT TIDAK TERDETEKSI',
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Kamera HP tidak menghasilkan plat.',
                'ai_response' => $plateAi,
            ], 422);
        }

        $plates = $this->getPlateCandidates($plateAi);

        Log::info('PLATE CANDIDATES', [
            'candidates' => $plates,
        ]);

        [$vehicle, $plateSimilarity, $matchedPlate] =
            $this->findVehicleByPlateCandidates($plates);

        if (! $vehicle) {
            AccessRequest::create([
                'member_id' => null,
                'plate_detected' => $matchedPlate ?: 'UNKNOWN',
                'plate_match' => false,
                'face_match' => false,
                'status' => 'denied',
                'requested_at' => now(),
                'responded_at' => now(),
            ]);

            $state->update([
                'trigger' => false,
                'izin' => 'TOLAK',
                'status' => 'KENDARAAN TIDAK TERDAFTAR',
            ]);

            return response()->json([
                'success' => true,
                'status' => 'denied',
                'message' => 'Kendaraan tidak terdaftar.',
                'plate_text' => $matchedPlate ?: null,
                'plate_detected' => $plateAi['plate_text'] ?? null,
                'plate_similarity' => $plateSimilarity,
                'plate_candidates' => $plates,
            ]);
        }

        $member = $vehicle->member;

        if (! $member || ! $member->is_active) {
            AccessRequest::create([
                'member_id' => $member?->id,
                'plate_detected' => $matchedPlate,
                'plate_match' => true,
                'face_match' => false,
                'status' => 'denied',
                'requested_at' => now(),
                'responded_at' => now(),
            ]);

            $state->update([
                'trigger' => false,
                'izin' => 'TOLAK',
                'status' => 'MEMBER TIDAK AKTIF',
            ]);

            return response()->json([
                'success' => true,
                'status' => 'denied',
                'message' => 'Member tidak aktif.',
                'plate_text' => $matchedPlate,
                'plate_similarity' => $plateSimilarity,
                'member_id' => $member?->id,
            ]);
        }

        $subscription = $this->getActiveSubscription($member);

        if (! $subscription) {
            AccessRequest::create([
                'member_id' => $member->id,
                'plate_detected' => $matchedPlate,
                'plate_match' => true,
                'face_match' => false,
                'status' => 'denied',
                'requested_at' => now(),
                'responded_at' => now(),
            ]);

            $state->update([
                'trigger' => false,
                'izin' => 'TOLAK',
                'status' => 'SUBSCRIPTION TIDAK AKTIF',
            ]);

            return response()->json([
                'success' => true,
                'status' => 'denied',
                'message' => 'Subscription parkir tidak aktif.',
                'plate_text' => $matchedPlate,
                'plate_similarity' => $plateSimilarity,
                'member_id' => $member->id,
            ]);
        }

        [$faceMatch, $faceDistance] =
            $this->compareFace(
                $member->id,
                $faceEmbedding
            );

        Log::info('FACE MATCH RESULT', [
            'member_id' => $member->id,
            'face_distance' => $faceDistance,
            'face_match' => $faceMatch,
        ]);

        if (! $faceMatch) {
            AccessRequest::create([
                'member_id' => $member->id,
                'plate_detected' => $matchedPlate,
                'plate_match' => true,
                'face_match' => false,
                'status' => 'denied',
                'requested_at' => now(),
                'responded_at' => now(),
            ]);

            $state->update([
                'trigger' => false,
                'izin' => 'TOLAK',
                'status' => 'WAJAH TIDAK COCOK',
            ]);

            return response()->json([
                'success' => true,
                'status' => 'denied',
                'message' => 'Akses ditolak. Wajah tidak cocok.',
                'plate_text' => $matchedPlate,
                'plate_detected' => $plateAi['plate_text'] ?? null,
                'plate_confidence' => $plateAi['plate_confidence'] ?? null,
                'plate_similarity' => $plateSimilarity,
                'plate_candidates' => $plates,
                'member' => [
                    'id' => $member->id,
                    'name' => $member->name,
                ],
                'vehicle' => [
                    'id' => $vehicle->id,
                    'plate_number' => $vehicle->plate_number,
                    'vehicle_type' => $vehicle->vehicle_type,
                ],
                'face_match' => false,
                'face_distance' => $faceDistance,
            ]);
        }

        $activeSession = ParkingSession::where('vehicle_id', $vehicle->id)
            ->where('status', 'active')
            ->latest('entry_time')
            ->first();

        $gateName = strtolower($gate ?? 'gate_utama');
        $direction = 'in';
        if (str_contains($gateName, 'keluar') || str_contains($gateName, 'exit')) {
            $direction = 'out';
        } elseif (str_contains($gateName, 'masuk') || str_contains($gateName, 'entry')) {
            $direction = 'in';
        } else {
            $direction = $activeSession ? 'out' : 'in';
        }

        if ($direction === 'in' && $activeSession) {
            AccessRequest::create([
                'member_id' => $member->id,
                'vehicle_id' => $vehicle->id,
                'gate' => $gate,
                'direction' => 'in',
                'reason' => 'already_inside',
                'plate_detected' => $matchedPlate,
                'plate_match' => true,
                'face_match' => true,
                'face_distance' => $faceDistance,
                'status' => 'denied',
                'requested_at' => now(),
                'responded_at' => now(),
            ]);

            $state->update([
                'trigger' => false,
                'izin' => 'TOLAK',
                'status' => 'AKSES DITOLAK: MOBIL SUDAH DI DALAM',
            ]);

            return response()->json([
                'success' => false,
                'status' => 'denied',
                'message' => 'Akses ditolak. Kendaraan sudah berada di dalam area parkir (belum keluar).',
                'reason' => 'already_inside',
            ], 422);
        }

        if ($direction === 'out' && ! $activeSession) {
            AccessRequest::create([
                'member_id' => $member->id,
                'vehicle_id' => $vehicle->id,
                'gate' => $gate,
                'direction' => 'out',
                'reason' => 'not_inside',
                'plate_detected' => $matchedPlate,
                'plate_match' => true,
                'face_match' => true,
                'face_distance' => $faceDistance,
                'status' => 'denied',
                'requested_at' => now(),
                'responded_at' => now(),
            ]);

            $state->update([
                'trigger' => false,
                'izin' => 'TOLAK',
                'status' => 'AKSES DITOLAK: MOBIL SUDAH KELUAR',
            ]);

            return response()->json([
                'success' => false,
                'status' => 'denied',
                'message' => 'Akses ditolak. Kendaraan tidak berada di dalam area parkir (belum masuk / sudah keluar).',
                'reason' => 'not_inside',
            ], 422);
        }

        $accessRequest = AccessRequest::create([
            'member_id' => $member->id,
            'vehicle_id' => $vehicle->id,
            'gate' => $gate,
            'direction' => $direction,
            'reason' => null,
            'plate_detected' => $matchedPlate,
            'plate_match' => true,
            'face_match' => true,
            'face_distance' => $faceDistance,
            'status' => 'auto_approved',
            'requested_at' => now(),
            'responded_at' => now(),
        ]);

        if ($direction === 'out') {
            ParkingSession::where('vehicle_id', $vehicle->id)
                ->where('status', 'active')
                ->update([
                    'exit_access_request_id' => $accessRequest->id,
                    'exit_time' => now(),
                    'status' => 'completed',
                ]);

            $gateStatus = 'PALANG TERBUKA - KENDARAAN KELUAR';
        } else {
            ParkingSession::create([
                'member_id' => $member->id,
                'vehicle_id' => $vehicle->id,
                'entry_access_request_id' => $accessRequest->id,
                'entry_time' => now(),
                'status' => 'active',
            ]);

            $gateStatus = 'PALANG TERBUKA - KENDARAAN MASUK';
        }

        $state->update([
            'trigger' => false,
            'izin' => 'BUKA',
            'status' => $gateStatus,
        ]);

        return response()->json([
            'success' => true,
            'status' => 'auto_approved',
            'message' => 'Akses diterima. Palang dapat dibuka.',
            'access_request_id' => $accessRequest->id,
            'plate_text' => $matchedPlate,
            'plate_detected' => $plateAi['plate_text'] ?? null,
            'plate_confidence' => $plateAi['plate_confidence'] ?? null,
            'plate_similarity' => $plateSimilarity,
            'plate_candidates' => $plates,
            'member' => [
                'id' => $member->id,
                'name' => $member->name,
            ],
            'vehicle' => [
                'id' => $vehicle->id,
                'plate_number' => $vehicle->plate_number,
                'vehicle_type' => $vehicle->vehicle_type,
            ],
            'subscription' => [
                'id' => $subscription->id,
                'start_date' => $subscription->start_date->toDateString(),
                'end_date' => $subscription->end_date->toDateString(),
            ],
            'face_match' => true,
            'face_distance' => $faceDistance,
        ]);
    }
}
