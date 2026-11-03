<?php

namespace App\Http\Controllers\adminParkir;

use App\Http\Controllers\Controller;
use App\Models\RenewalPayment;
use App\Services\SlotService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PembayaranController extends Controller
{
    public function index(Request $request): View
    {
        // Tagihan yang lewat batas waktu otomatis kedaluwarsa
        RenewalPayment::whereIn('status', ['pending', 'waiting'])
            ->where('expires_at', '<', now())
            ->update(['status' => 'expired']);

        $status = $request->query('status', 'waiting');

        $payments = RenewalPayment::with(['member', 'plan', 'account', 'reviewer'])
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->latest()
            ->paginate(15)
            ->withQueryString();

        return view('admin_parkir.pembayaran', [
            'payments' => $payments,
            'status'   => $status,
            'counts'   => RenewalPayment::selectRaw('status, count(*) c')->groupBy('status')->pluck('c', 'status'),
        ]);
    }

    public function proof(RenewalPayment $payment): StreamedResponse
    {
        abort_unless($payment->proof_path && Storage::disk('local')->exists($payment->proof_path), 404);

        return Storage::disk('local')->response($payment->proof_path);
    }

    public function approve(Request $request, RenewalPayment $payment, SlotService $slot): RedirectResponse
    {
        try {
            DB::transaction(function () use ($payment, $request, $slot) {
                $row = RenewalPayment::lockForUpdate()->with(['member.vehicles', 'plan'])->findOrFail($payment->id);

                if ($row->status !== 'waiting') {
                    throw new \RuntimeException('Pembayaran ini sudah diproses atau belum ada bukti.');
                }

                $member = $row->member;
                $last = $member->subscriptions()->latest('end_date')->first();
                $today = Carbon::today();

                // Masih aktif: sambung dari hari setelah berakhir. Sudah habis: mulai hari ini.
                $stillActive = $last && Carbon::parse($last->end_date)->gte($today);
                $start = $stillActive ? Carbon::parse($last->end_date)->addDay() : $today->copy();
                $end = $start->copy()->addDays($row->plan->duration_days - 1);

                // Kalau sudah habis, slotnya sudah dilepas: cek lagi
                if (! $stillActive) {
                    $slot->kunci();
                    $slot->pastikanMuat(array_count_values($member->vehicles->pluck('vehicle_type')->all()));
                }

                $sub = $member->subscriptions()->create([
                    'subscription_plan_id' => $row->subscription_plan_id,
                    'start_date' => $start->toDateString(),
                    'end_date' => $end->toDateString(),
                    'status' => 'active',
                ]);

                $sub->payments()->create([
                    'amount_due' => $row->total_amount,
                    'amount_paid' => $row->total_amount,
                    'method' => $row->account?->type === 'qris' ? 'transfer' : 'transfer',
                    'paid_at' => now(),
                    'created_by' => $request->user()->id,
                ]);

                $row->update([
                    'status' => 'approved',
                    'reviewed_by' => $request->user()->id,
                    'reviewed_at' => now(),
                ]);
            });
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Pembayaran disetujui dan langganan member diperpanjang.');
    }

    public function reject(Request $request, RenewalPayment $payment): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:255']], [
            'reason.required' => 'Alasan penolakan wajib diisi.',
            'reason.min' => 'Alasan minimal 5 karakter.',
        ]);

        $done = DB::transaction(function () use ($payment, $data, $request) {
            $row = RenewalPayment::lockForUpdate()->findOrFail($payment->id);
            if ($row->status !== 'waiting') return false;

            $row->update([
                'status' => 'rejected',
                'reject_reason' => $data['reason'],
                'reviewed_by' => $request->user()->id,
                'reviewed_at' => now(),
            ]);
            return true;
        });

        return back()->with($done ? 'success' : 'error', $done ? 'Pembayaran ditolak.' : 'Pembayaran sudah diproses.');
    }
}