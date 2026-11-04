<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\StaffContact;
use App\Services\WhatsAppService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Member menekan "Panggil petugas" di aplikasi:
 *  1) buzzer di gerbang (ESP32) menyala lewat flag cache yang dibaca /api/parking/command
 *  2) pesan WhatsApp dikirim ke semua kontak petugas yang aktif
 */
class PanggilPetugasController extends Controller
{
    /** Kunci cache yang dibaca endpoint /api/parking/command (field "buzzer"). */
    public const CACHE_KEY = 'parking.panggil_petugas';

    /** Lama buzzer berbunyi kalau tidak dibatalkan. */
    private const BUZZER_DETIK = 30;

    /** Jeda minimal antar panggilan dari member yang sama. */
    private const COOLDOWN_DETIK = 60;

    public function store(Request $request, WhatsAppService $wa): JsonResponse
    {
        $member = $request->user();

        // Cegah spam: satu member hanya boleh memanggil sekali per COOLDOWN_DETIK
        $boleh = Cache::add(
            "panggil_petugas.cooldown.{$member->id}",
            true,
            now()->addSeconds(self::COOLDOWN_DETIK)
        );
        if (! $boleh) {
            return response()->json([
                'message' => 'Petugas sudah dipanggil. Mohon tunggu sebentar sebelum memanggil lagi.',
            ], 429);
        }

        $note = trim((string) $request->input('note', ''));
        $plates = $member->vehicles()->pluck('plate_number')->implode(', ');

        // 1) Nyalakan buzzer: ESP32 membaca flag ini saat polling
        Cache::put(self::CACHE_KEY, [
            'member_id' => $member->id,
            'name'      => $member->name,
            'at'        => now()->toDateTimeString(),
        ], now()->addSeconds(self::BUZZER_DETIK));

        // 2) Kirim WhatsApp SETELAH respons dikirim, supaya aplikasi tidak menunggu
        //    dan `php artisan serve` (satu proses) tidak tertahan sehingga polling ESP32 terganggu.
        $targets = StaffContact::where('is_active', true)->pluck('phone')->all();

        $pesan = "*PANGGILAN PETUGAS - ParkVisi*\n"
               . "Member : {$member->name}\n"
               . "No. HP : {$member->phone}\n"
               . ($plates !== '' ? "Kendaraan : {$plates}\n" : '')
               . ($note !== '' ? "Catatan : {$note}\n" : '')
               . 'Waktu : ' . now()->format('d M Y H:i') . "\n\n"
               . 'Mohon segera menuju gerbang parkir.';

        dispatch(function () use ($wa, $targets, $pesan) {
            try {
                if (! $wa->send($targets, $pesan)) {
                    Log::warning('Panggil petugas: WhatsApp tidak terkirim.', ['targets' => $targets]);
                }
            } catch (\Throwable $e) {
                report($e);
            }
        })->afterResponse();

        return response()->json([
            'message' => 'Petugas sedang dipanggil. Mohon tunggu di tempat.',
            'petugas_dihubungi' => count($targets),
        ]);
    }

    /** Member membatalkan panggilan (mematikan buzzer). */
    public function destroy(): JsonResponse
    {
        Cache::forget(self::CACHE_KEY);

        return response()->json(['message' => 'Panggilan dibatalkan.']);
    }
}