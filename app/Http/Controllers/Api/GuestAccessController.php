<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\GuestAccessPermission;
use App\Models\Vehicle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class GuestAccessController extends Controller
{
    // ──────────────────────────────────────────────────────────────────────────
    // GET /api/izin-tamu?vehicle_id=X
    // Daftar izin tamu milik member yang sedang login
    // ──────────────────────────────────────────────────────────────────────────
    public function index(Request $request): JsonResponse
    {
        $member   = $request->user();
        $vehicleId = $request->query('vehicle_id');

        // Ambil semua vehicle_id milik member
        $ownedVehicleIds = Vehicle::where('member_id', $member->id)->pluck('id');

        $query = GuestAccessPermission::with('vehicle')
            ->whereIn('vehicle_id', $ownedVehicleIds)
            ->where(function ($q) {
                // created dalam 30 hari terakhir ATAU masih valid
                $q->where('created_at', '>=', now()->subDays(30))
                  ->orWhere(function ($q2) {
                      $q2->where('is_active', true)
                         ->where('valid_until', '>', now());
                  });
            });

        if ($vehicleId) {
            $query->where('vehicle_id', (int) $vehicleId);
        }

        // Urutkan: yang masih valid dulu (valid_until DESC), kemudian yang sudah lewat
        $records = $query
            ->orderByRaw('CASE WHEN is_active = 1 AND valid_until > NOW() THEN 0 ELSE 1 END')
            ->orderBy('valid_until', 'desc')
            ->get();

        $data = $records->map(function (GuestAccessPermission $p) {
            if (! $p->is_active) {
                $status = 'dibatalkan';
            } elseif ($p->isExpired()) {
                $status = 'kedaluwarsa';
            } else {
                $status = 'aktif';
            }

            return [
                'id'          => $p->id,
                'vehicle'     => [
                    'id'           => $p->vehicle->id,
                    'plat'         => $p->vehicle->plate_number,
                    'jenis'        => $p->vehicle->vehicle_type,
                ],
                'guest_name'  => $p->guest_name,
                'guest_phone' => $p->guest_phone,
                'valid_from'  => $p->valid_from?->toDateTimeString(),
                'valid_until' => $p->valid_until?->toDateTimeString(),
                'is_active'   => $p->is_active,
                'used_entry'  => $p->used_entry,
                'used_exit'   => $p->used_exit,
                'status'      => $status,
            ];
        });

        return response()->json(['success' => true, 'data' => $data]);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // POST /api/izin-tamu
    // Buat izin tamu baru
    // ──────────────────────────────────────────────────────────────────────────
    public function store(Request $request): JsonResponse
    {
        $member = $request->user();

        $validator = Validator::make($request->all(), [
            'vehicle_id'  => ['required', 'integer'],
            'guest_name'  => ['required', 'string', 'min:3', 'max:100'],
            'guest_phone' => ['required', 'string', 'regex:/^\d{8,13}$/'],
            'valid_from'  => ['required', 'date', 'after:now'],
            'valid_until' => ['required', 'date', 'after:valid_from'],
            'notes'       => ['nullable', 'string'],
        ], [
            'guest_phone.regex'   => 'Nomor HP tamu harus berupa 8–13 digit angka.',
            'valid_from.after'    => 'Waktu mulai izin harus setelah sekarang.',
            'valid_until.after'   => 'Waktu berakhir harus setelah waktu mulai.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi gagal.',
                'errors'  => $validator->errors(),
            ], 422);
        }

        // Pastikan kendaraan milik member yang login
        $vehicle = Vehicle::where('id', $request->vehicle_id)
            ->where('member_id', $member->id)
            ->first();

        if (! $vehicle) {
            return response()->json([
                'success' => false,
                'message' => 'Kendaraan tidak ditemukan atau bukan milik Anda.',
            ], 403);
        }

        $validFrom  = \Carbon\Carbon::parse($request->valid_from);
        $validUntil = \Carbon\Carbon::parse($request->valid_until);

        // Cek overlap: tidak boleh ada izin aktif lain untuk kendaraan yang sama
        // yang waktu valid_from-valid_until-nya tumpang tindih
        $overlap = GuestAccessPermission::where('vehicle_id', $vehicle->id)
            ->where('is_active', true)
            ->where('valid_from', '<', $validUntil)
            ->where('valid_until', '>', $validFrom)
            ->exists();

        if ($overlap) {
            return response()->json([
                'success' => false,
                'message' => 'Sudah ada izin tamu aktif untuk kendaraan ini pada rentang waktu yang sama.',
            ], 409);
        }

        $izin = GuestAccessPermission::create([
            'member_id'   => $member->id,
            'vehicle_id'  => $vehicle->id,
            'guest_name'  => $request->guest_name,
            'guest_phone' => $request->guest_phone,
            'valid_from'  => $validFrom,
            'valid_until' => $validUntil,
            'is_active'   => true,
            'used_entry'  => false,
            'used_exit'   => false,
            'notes'       => $request->notes,
        ]);

        $izin->load('vehicle', 'member');

        return response()->json([
            'success' => true,
            'message' => 'Izin tamu berhasil dibuat.',
            'data'    => $izin,
        ], 201);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // DELETE /api/izin-tamu/{id}
    // Batalkan / nonaktifkan izin tamu
    // ──────────────────────────────────────────────────────────────────────────
    public function destroy(Request $request, int $id): JsonResponse
    {
        $member = $request->user();

        // Hanya milik member sendiri
        $izin = GuestAccessPermission::where('id', $id)
            ->where('member_id', $member->id)
            ->first();

        if (! $izin) {
            return response()->json([
                'success' => false,
                'message' => 'Izin tamu tidak ditemukan.',
            ], 404);
        }

        // Tolak jika kendaraan sedang parkir (sudah masuk, belum keluar)
        if ($izin->used_entry && ! $izin->used_exit) {
            return response()->json([
                'success' => false,
                'message' => 'Izin tamu tidak dapat dibatalkan karena kendaraan sedang berada di dalam area parkir.',
            ], 409);
        }

        $izin->update(['is_active' => false]);

        return response()->json([
            'success' => true,
            'message' => 'Izin tamu berhasil dibatalkan.',
        ]);
    }
}
