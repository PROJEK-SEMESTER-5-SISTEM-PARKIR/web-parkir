<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Pengirim WhatsApp lewat Fonnte (https://fonnte.com).
 * Kalau nanti ganti provider (Wablas, WhatsApp Cloud API, dll.), cukup ubah isi method send().
 */
class WhatsAppService
{
    /**
     * @param  string[]  $targets  nomor format 08xxxxxxxxxx (seperti yang disimpan StaffContact)
     */
    public function send(array $targets, string $message): bool
    {
        $token = config('services.fonnte.token');

        if (! $token || empty($targets)) {
            return false;
        }

        $res = Http::withHeaders(['Authorization' => $token])
            ->asForm()
            ->timeout(10)
            ->post('https://api.fonnte.com/send', [
                'target'      => implode(',', $targets),
                'message'     => $message,
                'countryCode' => '62',
            ]);

        $ok = $res->successful() && $res->json('status') === true;

        // Catat jawaban Fonnte kalau gagal, supaya penyebabnya terlihat di storage/logs/laravel.log
        if (! $ok) {
            Log::warning('Fonnte gagal mengirim WhatsApp.', [
                'http'   => $res->status(),
                'body'   => $res->body(),
                'target' => $targets,
            ]);
        }

        return $ok;
    }
}