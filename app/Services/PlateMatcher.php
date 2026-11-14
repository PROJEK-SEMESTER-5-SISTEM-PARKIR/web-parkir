<?php

namespace App\Services;

/**
 * PlateMatcher — Pencocokan plat nomor dengan toleransi karakter tertukar.
 *
 * Hanya mengizinkan pergantian satu pasangan karakter yang sering salah baca OCR:
 *   0 ↔ O, 1 ↔ I, 8 ↔ B, 5 ↔ S, 2 ↔ Z, 6 ↔ G
 *
 * Panjang string harus sama. Maksimal 1 posisi berbeda.
 * TIDAK menggunakan levenshtein atau similar_text agar tidak lolos plat beda.
 */
class PlateMatcher
{
    // Pasangan karakter yang dianggap setara karena sering tertukar di OCR
    private const PASANGAN = [
        '0' => 'O', 'O' => '0',
        '1' => 'I', 'I' => '1',
        '8' => 'B', 'B' => '8',
        '5' => 'S', 'S' => '5',
        '2' => 'Z', 'Z' => '2',
        '6' => 'G', 'G' => '6',
    ];

    /**
     * Normalisasi plat: uppercase, hapus semua karakter selain A-Z 0-9.
     */
    public static function normalize(?string $plate): string
    {
        // Uppercase dulu, baru buang karakter selain A-Z 0-9
        return preg_replace('/[^A-Z0-9]/', '', strtoupper($plate ?? ''));
    }

    /**
     * Cocokkan hasil OCR dengan plat terdaftar.
     *
     * @return bool  true jika cocok persis atau hanya 1 karakter tertukar (dalam pasangan)
     */
    public static function cocok(string $ocr, string $terdaftar): bool
    {
        $ocr       = self::normalize($ocr);
        $terdaftar = self::normalize($terdaftar);

        // Harus sama panjang
        if (strlen($ocr) !== strlen($terdaftar)) {
            return false;
        }

        // Cocok persis
        if ($ocr === $terdaftar) {
            return true;
        }

        // Periksa posisi yang berbeda — izinkan max 1 perbedaan, dan hanya jika termasuk pasangan
        $beda = 0;
        $n    = strlen($ocr);
        for ($i = 0; $i < $n; $i++) {
            if ($ocr[$i] !== $terdaftar[$i]) {
                $beda++;
                if ($beda > 1) {
                    return false; // lebih dari 1 perbedaan → tidak cocok
                }
                // Periksa apakah perbedaan ini termasuk pasangan karakter tertukar
                $pasanganOcr = self::PASANGAN[$ocr[$i]] ?? null;
                if ($pasanganOcr !== $terdaftar[$i]) {
                    return false; // bukan pasangan yang diizinkan → tidak cocok
                }
            }
        }

        return true; // tepat 1 perbedaan dan termasuk pasangan
    }

    /**
     * Cari kendaraan yang cocok dari daftar plat OCR kandidat.
     *
     * @param  array   $platKandidatOcr  array string plat hasil OCR
     * @param  \Illuminate\Database\Eloquent\Collection  $kendaraan
     * @return array   [$vehicle|null, $platOcrTerbaik|null]
     */
    public static function cariKendaraan(array $platKandidatOcr, $kendaraan): array
    {
        $platKandidatOcr = array_filter(array_map([self::class, 'normalize'], $platKandidatOcr));

        if (empty($platKandidatOcr)) {
            return [null, null];
        }

        foreach ($kendaraan as $kend) {
            foreach ($platKandidatOcr as $ocr) {
                if (self::cocok($ocr, $kend->plate_number)) {
                    return [$kend, $ocr];
                }
            }
        }

        return [null, null];
    }
}
