<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Member;
use App\Models\Vehicle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Endpoint khusus untuk script Python test_akses_parkir.py.
 *
 * POST /api/verifikasi-akses
 *   {
 *     "member_id": 3,          // hasil LBPH (opsional, null bila wajah belum dikenali)
 *     "plate_text": "BD2541WH" // hasil OCR  (opsional, null bila plat belum terbaca)
 *   }
 *
 * Response:
 *   {
 *     "status":        "izin" | "tolak" | "mismatch" | "menunggu",
 *     "keterangan":    "...",
 *     "member":        { id, name } | null,
 *     "plat_pemilik":  { plate_number, vehicle_type, member_id } | null,
 *     "cocok":         true | false   // wajah & plat milik orang yang sama
 *   }
 */
class VerifikasiAksesController extends Controller
{
    public function verifikasi(Request $request): JsonResponse
    {
        $memberId  = $request->input('member_id');   // int|null
        $plateTeks = $request->input('plate_text');  // string|null

        $memberId  = $memberId  ? (int)  $memberId  : null;
        $plateTeks = $plateTeks ? $this->normalizePlate((string) $plateTeks) : null;

        // Bakal kita isi
        $member      = null;
        $platePemilik = null;

        // ── Cari member dari wajah ─────────────────────────────────────────
        if ($memberId !== null) {
            $member = Member::where('id', $memberId)
                ->where('is_active', true)
                ->first(['id', 'name']);
        }

        // ── Cari pemilik plat ──────────────────────────────────────────────
        if ($plateTeks !== null) {
            $kendaraan = Vehicle::with('member:id,name,is_active')
                ->whereRaw(
                    "REPLACE(UPPER(plate_number),' ','') = ?",
                    [$plateTeks]
                )
                ->first();

            if ($kendaraan) {
                $platePemilik = [
                    'plate_number'  => $kendaraan->plate_number,
                    'vehicle_type'  => $kendaraan->vehicle_type,
                    'member_id'     => $kendaraan->member_id,
                    'member_name'   => $kendaraan->member?->name,
                    'member_active' => (bool) ($kendaraan->member?->is_active),
                ];
            }
        }

        // ── Tentukan status ────────────────────────────────────────────────
        [$status, $keterangan, $cocok] = $this->tentukan(
            $memberId, $member, $plateTeks, $platePemilik
        );

        return response()->json([
            'status'       => $status,
            'keterangan'   => $keterangan,
            'cocok'        => $cocok,
            'member'       => $member ? ['id' => $member->id, 'name' => $member->name] : null,
            'plat_pemilik' => $platePemilik,
        ]);
    }

    // ── Logika pencocokan ────────────────────────────────────────────────────

    private function tentukan(
        ?int    $memberId,
        ?Member $member,
        ?string $plateTeks,
        ?array  $platePemilik
    ): array {
        $wajahOk = $memberId !== null;
        $platOk  = $plateTeks !== null;

        // Tidak ada data sama sekali
        if (! $wajahOk && ! $platOk) {
            return ['menunggu', 'Arahkan wajah dan plat kendaraan ke kamera', false];
        }

        // ── Keduanya ada ──────────────────────────────────────────────────
        if ($wajahOk && $platOk) {
            // Wajah dikenali tapi member tidak aktif / tidak ditemukan
            if ($member === null) {
                return ['tolak', 'Wajah tidak dikenal atau member tidak aktif', false];
            }

            // Plat tidak terdaftar
            if ($platePemilik === null) {
                return [
                    'tolak',
                    "Plat {$plateTeks} tidak terdaftar di sistem",
                    false,
                ];
            }

            // Member tidak aktif
            if (! $platePemilik['member_active']) {
                return ['tolak', 'Pemilik kendaraan tidak aktif', false];
            }

            // Wajah dan plat milik member yang SAMA → IZIN ✅
            if ((int) $platePemilik['member_id'] === $memberId) {
                return [
                    'izin',
                    "Akses diterima: {$member->name} | Plat {$plateTeks}",
                    true,
                ];
            }

            // Wajah milik A, plat milik B → mismatch ⚠
            return [
                'mismatch',
                "Wajah: {$member->name} | Plat {$plateTeks} milik {$platePemilik['member_name']}",
                false,
            ];
        }

        // ── Hanya wajah (plat belum terbaca) ─────────────────────────────
        if ($wajahOk) {
            if ($member === null) {
                return ['tolak', 'Wajah tidak dikenal atau member tidak aktif', false];
            }

            // Ambil daftar plat milik member ini
            $daftarPlat = Vehicle::where('member_id', $memberId)
                ->pluck('plate_number')
                ->map(fn($p) => $this->normalizePlate($p))
                ->implode(', ');

            return [
                'menunggu',
                "Wajah: {$member->name} | Menunggu plat... (terdaftar: {$daftarPlat})",
                false,
            ];
        }

        // ── Hanya plat (wajah belum terdeteksi) ──────────────────────────
        if ($platePemilik === null) {
            return [
                'tolak',
                "Plat {$plateTeks} tidak terdaftar di sistem",
                false,
            ];
        }

        if (! $platePemilik['member_active']) {
            return ['tolak', "Pemilik plat {$plateTeks} tidak aktif", false];
        }

        return [
            'menunggu',
            "Plat {$plateTeks} milik {$platePemilik['member_name']} | Menunggu verifikasi wajah...",
            false,
        ];
    }

    private function normalizePlate(?string $plate): string
    {
        return strtoupper(preg_replace('/[^A-Z0-9]/', '', $plate ?? ''));
    }
}
