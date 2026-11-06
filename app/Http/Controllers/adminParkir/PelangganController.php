<?php

namespace App\Http\Controllers\adminParkir;

use App\Exceptions\SlotPenuhException;
use App\Http\Controllers\Controller;
use App\Models\FaceProfile;
use App\Models\Member;
use App\Models\MemberSubscription;
use App\Models\SubscriptionPlan;
use App\Models\Vehicle;
use App\Services\SlotService;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;


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

        // Rincian biaya tambah kendaraan per pelanggan di halaman ini (untuk popup Tambah kendaraan)
        $tagihan = $members->getCollection()
            ->mapWithKeys(fn (Member $m) => [$m->id => $this->hitungTambahKendaraan($m)])
            ->all();

        return view('admin_parkir.pelanggan', compact('members', 'stats', 'search', 'status', 'paket', 'tagihan'));
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

    /**
     * Tambah kendaraan untuk pelanggan yang sudah terdaftar. Cukup plat dan jenis:
     * orangnya sama, jadi foto wajah tidak perlu diulang.
     *
     * Biaya prorata dari hari ini sampai tanggal berakhir langganan yang sedang berjalan.
     * Kendaraan dan pembayarannya dibuat dalam satu transaksi, jadi tidak mungkin ada
     * kendaraan yang bisa lewat gerbang tanpa pembayaran tercatat.
     */
    public function tambahKendaraan(Request $request, Member $member): RedirectResponse
    {
        if (! $member->is_active) {
            return back()->with('error', 'Pelanggan nonaktif. Aktifkan dulu sebelum menambah kendaraan.');
        }

        $data = $request->validate([
            'plate' => ['required', 'string', 'max:20'],
            'vehicle_type' => ['required', Rule::in(SlotService::JENIS)],
            'payment_method' => ['nullable', Rule::in(['tunai', 'transfer'])],
            'amount_paid' => ['nullable', 'integer', 'min:0'],
        ], [
            'plate.required' => 'Plat nomor wajib diisi.',
            'vehicle_type.in' => 'Jenis kendaraan tidak didukung di area parkir ini.',
            'amount_paid.integer' => 'Nominal dibayar harus berupa angka bulat.',
        ]);

        $plat = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $data['plate']));

        if (strlen($plat) < 3) {
            return back()->with('error', 'Plat nomor tidak valid.');
        }

        if (Vehicle::where('plate_number', $plat)->exists()) {
            return back()->with('error', 'Plat '.$this->formatPlate($plat).' sudah terdaftar.');
        }

        $jenis = $data['vehicle_type'];
        $adminId = $request->user()?->id;

        try {
            $hasil = DB::transaction(function () use ($member, $plat, $jenis, $data, $adminId) {
                // Data langganan dibaca ulang di dalam transaksi: bisa saja sudah berubah sejak halaman dibuka.
                $member->load('latestSubscription.plan');
                $info = $this->hitungTambahKendaraan($member);
                $memakaiSlot = in_array($info['status'], ['aktif', 'belum_mulai'], true);

                // Hanya member berlangganan yang memakai slot; yang sudah habis dicek saat perpanjangan.
                if ($memakaiSlot) {
                    $slot = app(SlotService::class);
                    $slot->kunci();
                    $slot->pastikanMuat([$jenis => 1]);
                }

                $biaya = $memakaiSlot ? (int) $info['harga'][$jenis] : 0;

                if ($biaya > 0 && (empty($data['payment_method']) || ! isset($data['amount_paid']))) {
                    return ['galat' => 'Isi metode pembayaran dan nominal yang dibayar.'];
                }

                $vehicle = Vehicle::create([
                    'member_id' => $member->id,
                    'plate_number' => $plat,
                    'vehicle_type' => $jenis,
                ]);

                if ($biaya > 0) {
                    $member->latestSubscription->payments()->create([
                        'amount_due' => $biaya,
                        'amount_paid' => (int) $data['amount_paid'],
                        'method' => $data['payment_method'],
                        'type' => 'tambah_kendaraan',
                        'vehicle_id' => $vehicle->id,
                        'paid_at' => now(),
                        'created_by' => $adminId,
                    ]);
                }

                return ['biaya' => $biaya, 'info' => $info];
            });
        } catch (SlotPenuhException $e) {
            return back()->with('error', $e->getMessage());
        } catch (UniqueConstraintViolationException) {
            return back()->with('error', 'Plat '.$this->formatPlate($plat).' sudah terdaftar.');
        } catch (Throwable $e) {
            report($e);

            return back()->with('error', 'Gagal menambah kendaraan. Silakan coba lagi.');
        }

        if (isset($hasil['galat'])) {
            return back()->withInput()->with('error', $hasil['galat']);
        }

        $plat = $this->formatPlate($plat);
        $nama = $member->name;

        if ($hasil['biaya'] > 0) {
            $info = $hasil['info'];
            $rupiah = number_format($hasil['biaya'], 0, ',', '.');

            return back()->with('success', "Kendaraan {$plat} ditambahkan untuk {$nama}. Tagihan prorata Rp {$rupiah} ({$info['sisa_hari']} hari sampai {$info['sampai']}) tercatat.");
        }

        return back()->with('success', "Kendaraan {$plat} ditambahkan untuk {$nama} tanpa biaya. Biayanya ikut dihitung saat paket diperpanjang.");
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

    /**
     * Rincian biaya menambah kendaraan untuk satu pelanggan.
     *
     * Tarif harian = harga paket per jenis / lama paket (hari). Yang ditagih = tarif harian
     * x sisa hari langganan, dibulatkan ke atas ke Rp1.000.
     *
     *  - aktif        : sisa hari dihitung dari hari ini sampai tanggal berakhir.
     *  - belum_mulai  : langganan belum berjalan, jadi seluruh periodenya ditagih.
     *  - habis        : tanpa biaya; biaya ikut dihitung saat perpanjangan.
     *  - tanpa_paket  : tanpa biaya (belum pernah berlangganan).
     *
     * @return array{status: string, sisa_hari: int, sampai: ?string, paket: ?string, harga: array<string,int>}
     */
    private function hitungTambahKendaraan(Member $member): array
    {
        $nol = array_fill_keys(SlotService::JENIS, 0);
        $sub = $member->latestSubscription;
        $plan = $sub?->plan;
        $hariIni = today();

        if (! $sub || ! $plan) {
            return ['status' => 'tanpa_paket', 'sisa_hari' => 0, 'sampai' => null, 'paket' => null, 'harga' => $nol];
        }

        $sampai = $sub->end_date->copy()->locale('id')->translatedFormat('j M Y');

        if ($sub->status !== 'active' || $sub->end_date->lt($hariIni)) {
            return ['status' => 'habis', 'sisa_hari' => 0, 'sampai' => $sampai, 'paket' => $plan->name, 'harga' => $nol];
        }

        $belumMulai = $sub->start_date->gt($hariIni);
        $dari = $belumMulai ? $sub->start_date : $hariIni;
        $sisaHari = (int) $dari->diffInDays($sub->end_date) + 1; // hari terakhir ikut dihitung

        $harga = [];
        foreach (SlotService::JENIS as $jenis) {
            $tarif = (float) ($jenis === 'motor' ? $plan->price_motor : $plan->price_mobil);
            $harga[$jenis] = (int) (ceil(($tarif / max(1, $plan->duration_days) * $sisaHari) / 1000) * 1000);
        }

        return [
            'status' => $belumMulai ? 'belum_mulai' : 'aktif',
            'sisa_hari' => $sisaHari,
            'sampai' => $sampai,
            'paket' => $plan->name,
            'harga' => $harga,
        ];
    }

    /** "B1234ABC" -> "B 1234 ABC" (format tampil) */
    private function formatPlate(string $plate): string
    {
        if (preg_match('/^([A-Z]{1,2})(\d{1,4})([A-Z]{0,3})$/', $plate, $m)) {
            return trim("{$m[1]} {$m[2]} {$m[3]}");
        }

        return $plate;
    }
}   