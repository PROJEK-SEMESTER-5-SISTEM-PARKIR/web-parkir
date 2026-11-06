<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PaymentAccount;
use App\Models\RenewalPayment;
use App\Models\SubscriptionPlan;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class PerpanjangController extends Controller
{
    private const TAGIHAN_JAM = 24;      // batas bayar sebelum unggah bukti
    private const REVIEW_HARI = 3;       // batas tunggu admin setelah unggah bukti

    /** Data untuk layar perpanjang. */
    public function show(Request $request)
    {
        $member = $request->user();
        $this->expireStale($member->id);

        $open = RenewalPayment::with(['plan', 'account'])
            ->where('member_id', $member->id)
            ->whereIn('status', ['pending', 'waiting'])
            ->latest('id')->first();

        // Hasil terakhir (ditolak / disetujui) tampil sebentar kalau tidak ada tagihan aktif
        $last = null;
        if (! $open) {
            $last = RenewalPayment::with(['plan', 'account'])
                ->where('member_id', $member->id)
                ->whereIn('status', ['rejected', 'approved'])
                ->where('updated_at', '>=', now()->subDays(3))
                ->latest('id')->first();
        }

        return response()->json([
            'plans' => SubscriptionPlan::where('is_active', true)->orderBy('sort_order')->get()
                ->map(fn ($p) => [
                    'id'            => $p->id,
                    'name'          => $p->name,
                    'duration_days' => (int) $p->duration_days,
                    'price_mobil'   => (int) $p->price_mobil,
                    'price_motor'   => (int) $p->price_motor,
                    'is_popular'    => (bool) $p->is_popular,
                ])->values(),
            'vehicles' => $member->vehicles()->get(['plate_number', 'vehicle_type'])
                ->map(fn ($v) => ['plate' => $v->plate_number, 'type' => $v->vehicle_type])->values(),
            'accounts' => PaymentAccount::where('is_active', true)->orderBy('type')->orderBy('label')->get()
                ->map(fn ($a) => $this->account($a))->values(),
            'bill' => $open ? $this->bill($open) : null,
            'last' => $last ? $this->bill($last) : null,
        ]);
    }

    /** Dipakai dashboard: apakah ada pembayaran yang sedang menunggu admin. */
    public function status(Request $request)
    {
        $this->expireStale($request->user()->id);

        return response()->json([
            'menunggu' => RenewalPayment::where('member_id', $request->user()->id)
                ->where('status', 'waiting')->exists(),
        ]);
    }

    /** Buat tagihan. */
    public function store(Request $request)
    {
        $data = $request->validate([
            'plan_id'    => ['required', 'integer'],
            'account_id' => ['required', 'integer'],
        ]);

        $member = $request->user();
        $this->expireStale($member->id);

        if (RenewalPayment::where('member_id', $member->id)->whereIn('status', ['pending', 'waiting'])->exists()) {
            return response()->json(['message' => 'Masih ada tagihan yang aktif.'], 422);
        }

        $plan = SubscriptionPlan::where('is_active', true)->find($data['plan_id']);
        if (! $plan) {
            return response()->json(['message' => 'Paket tidak ditemukan atau sudah tidak aktif.'], 422);
        }

        $account = PaymentAccount::where('is_active', true)->find($data['account_id']);
        if (! $account) {
            return response()->json(['message' => 'Metode pembayaran tidak tersedia.'], 422);
        }

        $vehicles = $member->vehicles()->get();
        if ($vehicles->isEmpty()) {
            return response()->json(['message' => 'Belum ada kendaraan terdaftar.'], 422);
        }

        // Harga dihitung server, bukan dari aplikasi
        $base = 0;
        foreach ($vehicles as $v) {
            $base += (int) ($v->vehicle_type === 'motor' ? $plan->price_motor : $plan->price_mobil);
        }
        if ($base <= 0) {
            return response()->json(['message' => 'Harga paket belum diatur. Hubungi petugas.'], 422);
        }

        $code = $this->uniqueCode($base);

        $bill = RenewalPayment::create([
            'member_id'            => $member->id,
            'subscription_plan_id' => $plan->id,
            'payment_account_id'   => $account->id,
            'vehicle_count'        => $vehicles->count(),
            'base_amount'          => $base,
            'unique_code'          => $code,
            'total_amount'         => $base + $code,
            'status'               => 'pending',
            'expires_at'           => now()->addHours(self::TAGIHAN_JAM),
        ]);

        return response()->json(['bill' => $this->bill($bill->load(['plan', 'account']))], 201);
    }

    /** Unggah bukti pembayaran. */
    public function proof(Request $request, RenewalPayment $payment)
    {
        abort_unless($payment->member_id === $request->user()->id, 404);

        $request->validate([
            'proof' => ['required', 'image', 'mimes:jpg,jpeg,png', 'max:4096'],
        ], [
            'proof.required' => 'Pilih foto bukti pembayaran dulu.',
            'proof.image'    => 'File harus berupa gambar.',
            'proof.mimes'    => 'Format gambar harus JPG atau PNG.',
            'proof.max'      => 'Ukuran gambar maksimal 4 MB.',
        ]);

        return DB::transaction(function () use ($request, $payment) {
            $row = RenewalPayment::lockForUpdate()->with(['plan', 'account'])->findOrFail($payment->id);

            if ($row->status === 'pending' && $row->expires_at->isPast()) {
                $row->update(['status' => 'expired']);
                return response()->json(['message' => 'Tagihan sudah kedaluwarsa. Buat tagihan baru.'], 422);
            }
            if ($row->status !== 'pending') {
                return response()->json(['message' => 'Tagihan ini tidak bisa diberi bukti lagi.'], 422);
            }

            $path = $request->file('proof')->store('payment-proofs', 'local');

            if ($row->proof_path) {
                Storage::disk('local')->delete($row->proof_path);
            }

            $row->update([
                'proof_path' => $path,
                'status'     => 'waiting',
                'expires_at' => now()->addDays(self::REVIEW_HARI),
            ]);

            return response()->json(['bill' => $this->bill($row->fresh(['plan', 'account']))]);
        });
    }

    /** Batalkan tagihan yang belum dibayar (misalnya salah pilih paket). */
    public function cancel(Request $request, RenewalPayment $payment)
    {
        abort_unless($payment->member_id === $request->user()->id, 404);

        $done = DB::transaction(function () use ($payment) {
            $row = RenewalPayment::lockForUpdate()->findOrFail($payment->id);
            if ($row->status !== 'pending') return false;
            $row->update(['status' => 'expired']);
            return true;
        });

        return $done
            ? response()->json(['message' => 'Tagihan dibatalkan.'])
            : response()->json(['message' => 'Tagihan ini tidak bisa dibatalkan.'], 422);
    }

    // ───────────── bantu ─────────────

    private function expireStale(int $memberId): void
    {
        RenewalPayment::where('member_id', $memberId)
            ->whereIn('status', ['pending', 'waiting'])
            ->where('expires_at', '<', now())
            ->update(['status' => 'expired']);
    }

    /** Kode 100-999 yang belum dipakai tagihan aktif lain dengan total sama. */
    private function uniqueCode(int $base): int
    {
        for ($i = 0; $i < 30; $i++) {
            $code = random_int(100, 999);
            $used = RenewalPayment::whereIn('status', ['pending', 'waiting'])
                ->where('total_amount', $base + $code)->exists();
            if (! $used) return $code;
        }

        return random_int(100, 999);
    }

    private function account(PaymentAccount $a): array
    {
        return [
            'id'             => $a->id,
            'type'           => $a->type,
            'label'          => $a->label,
            'account_number' => $a->account_number,
            'account_name'   => $a->account_name,
            'qris_path'      => $a->qris_path,
        ];
    }

    private function bill(RenewalPayment $b): array
    {
        return [
            'id'            => $b->id,
            'status'        => $b->status,
            'plan_name'     => $b->plan->name ?? '-',
            'plan_days'     => (int) ($b->plan->duration_days ?? 0),
            'vehicle_count' => $b->vehicle_count,
            'base_amount'   => $b->base_amount,
            'unique_code'   => $b->unique_code,
            'total_amount'  => $b->total_amount,
            'reject_reason' => $b->reject_reason,
            'expires_at'    => $b->expires_at->toIso8601String(),
            'has_proof'     => (bool) $b->proof_path,
            'account'       => $b->account ? $this->account($b->account) : null,
        ];
    }
}