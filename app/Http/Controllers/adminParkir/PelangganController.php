<?php

namespace App\Http\Controllers\adminParkir;

use App\Http\Controllers\Controller;
use App\Models\FaceProfile;
use App\Models\Member;
use App\Models\MemberSubscription;
use App\Models\SubscriptionPlan;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;


class PelangganController extends Controller
{
    /** Kata sandi sementara setelah reset. Member wajib menggantinya di aplikasi. */
    private const DEFAULT_PASSWORD = '123456789';

    public function index(Request $request): View
    {
        $search = trim((string) $request->query('q', ''));
        $status = $request->query('status', '');

        // Plat disimpan tanpa spasi/tanda baca (B1234ABC), jadi pencarian plat memakai format yang sama
        $plate = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $search));

        $members = Member::with(['vehicles', 'faceProfile', 'latestSubscription.plan'])
            ->when($search !== '', function ($query) use ($search, $plate) {
                $query->where(function ($q) use ($search, $plate) {
                    $q->where('name', 'like', "%{$search}%")
                      ->orWhere('phone', 'like', "%{$search}%")
                      ->orWhere('email', 'like', "%{$search}%");

                    if ($plate !== '') {
                        $q->orWhereHas('vehicles', function ($v) use ($plate) {
                            $v->where('plate_number', 'like', "%{$plate}%");
                        });
                    }
                });
            })
            ->when(in_array($status, ['aktif', 'nonaktif'], true), function ($query) use ($status) {
                $query->where('is_active', $status === 'aktif');
            })
            ->latest()
            ->paginate(10)
            ->withQueryString();

        $stats = [
            'total'    => Member::count(),
            'aktif'    => Member::where('is_active', true)->count(),
            'nonaktif' => Member::where('is_active', false)->count(),
        ];

        // Daftar paket untuk popup Perpanjang / Mulai paket
        $paket = SubscriptionPlan::active()->ordered()->get();

        return view('admin_parkir.pelanggan', compact('members', 'stats', 'search', 'status', 'paket'));
    }

    /**
     * Perpanjang paket, atau mulai paket untuk pelanggan yang belum pernah berlangganan.
     *
     * - Masih berlaku : disambung dari tanggal berakhir (sisa hari tidak hangus).
     * - Sudah habis   : dihitung mulai hari ini.
     * - Belum punya   : dibuat langganan baru mulai hari ini.
     *
     * Baris langganan yang ada DIUBAH (bukan dibuat baru) supaya tidak ada baris lama
     * yang terus terhitung "kedaluwarsa" di Dashboard setelah pelanggan memperpanjang.
     */
    public function perpanjang(Request $request, Member $member): RedirectResponse
    {
        if (! $member->is_active) {
            return back()->with('error', 'Pelanggan nonaktif. Aktifkan dulu sebelum memperpanjang paket.');
        }

        $data = $request->validate([
            'subscription_plan_id' => [
                'required', 'integer',
                Rule::exists('subscription_plans', 'id')->where('is_active', true),
            ],
        ]);

        $plan = SubscriptionPlan::findOrFail($data['subscription_plan_id']);
        $hariIni = today();

        $sub = DB::transaction(function () use ($member, $plan, $hariIni) {
            $sub = $member->subscriptions()->orderByDesc('end_date')->lockForUpdate()->first();

            if ($sub && $sub->status === 'active' && $sub->end_date->gte($hariIni)) {
                // Masih berlaku: sambung dari tanggal berakhir, sisa hari tidak hangus.
                $sub->end_date = $sub->end_date->copy()->addDays($plan->duration_days);
            } elseif ($sub) {
                // Sudah habis atau tidak aktif: mulai lagi dari hari ini.
                $sub->start_date = $hariIni;
                $sub->end_date = $hariIni->copy()->addDays($plan->duration_days);
            } else {
                // Belum pernah berlangganan.
                $sub = new MemberSubscription([
                    'member_id'  => $member->id,
                    'start_date' => $hariIni,
                    'end_date'   => $hariIni->copy()->addDays($plan->duration_days),
                ]);
            }

            $sub->subscription_plan_id = $plan->id;
            $sub->status = 'active';
            $sub->save();

            return $sub;
        });

        return back()->with(
            'success',
            "Paket {$plan->name} untuk {$member->name} berlaku sampai {$sub->end_date->format('d M Y')}."
        );
    }

    public function resetPassword(Member $member): RedirectResponse
    {
        $member->password = Hash::make(self::DEFAULT_PASSWORD);
        $member->must_change_password = true;
        $member->save();

        return back()->with('reset', [
            'name' => $member->name,
            'phone' => $member->phone,
            'password' => self::DEFAULT_PASSWORD,
        ]);
    }

    public function toggleStatus(Member $member): RedirectResponse
    {
        $member->is_active = ! $member->is_active;
        $member->save();

        return back()->with('success', $member->is_active
            ? "{$member->name} diaktifkan kembali."
            : "{$member->name} dinonaktifkan.");
    }

    public function destroy(Member $member): RedirectResponse
    {
        // Riwayat langganan harus tetap ada (catatan pembayaran), jadi cukup dinonaktifkan
        if ($member->latestSubscription()->exists()) {
            return back()->with('error', "{$member->name} punya riwayat langganan sehingga tidak bisa dihapus. Nonaktifkan saja.");
        }

        $photoPath = $member->faceProfile?->photo_path;

        try {
            DB::transaction(function () use ($member) {
                $member->vehicles()->delete();
                $member->faceProfile()->delete();
                $member->delete();
            });
        } catch (QueryException $e) {
            report($e);

            return back()->with('error', "{$member->name} tidak bisa dihapus karena masih terkait data lain. Nonaktifkan saja.");
        }

        // File foto dihapus setelah database berhasil; coba kedua disk karena member lama mungkin di 'public'
        if ($photoPath) {
            Storage::disk('local')->delete($photoPath);
            Storage::disk('public')->delete($photoPath);
        }

        return back()->with('success', "Pelanggan {$member->name} dihapus beserta kendaraan dan foto wajahnya.");
    }

    /** Foto wajah ada di disk privat, jadi disajikan lewat route ini (hanya untuk yang sudah login). */
    public function foto(Member $member): StreamedResponse
    {
        $profile = FaceProfile::where('member_id', $member->id)->firstOrFail();

        // 'public' hanya untuk member yang didaftarkan sebelum foto dipindah ke disk privat
        foreach (['local', 'public'] as $disk) {
            if (Storage::disk($disk)->exists($profile->photo_path)) {
                return Storage::disk($disk)->response($profile->photo_path, null, [
                    'Cache-Control' => 'private, max-age=3600',
                ]);
            }
        }

        abort(404);
    }
}