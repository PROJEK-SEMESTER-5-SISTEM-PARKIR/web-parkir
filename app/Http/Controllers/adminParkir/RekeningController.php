<?php

namespace App\Http\Controllers\adminParkir;

use App\Http\Controllers\Controller;
use App\Models\PaymentAccount;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class RekeningController extends Controller
{
    public function index(): View
    {
        return view('admin_parkir.rekening', [
            'accounts' => PaymentAccount::orderBy('type')->orderBy('label')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request, $request->input('type'));

        if ($request->hasFile('qris')) {
            $data['qris_path'] = $request->file('qris')->store('qris', 'public');
        }

        PaymentAccount::create($data);

        return redirect()->route('parkir.rekening.index')->with('success', 'Metode pembayaran ditambahkan.');
    }

    public function update(Request $request, PaymentAccount $account): RedirectResponse
    {
        $data = $this->validated($request, $account->type, $account);

        if ($request->hasFile('qris')) {
            if ($account->qris_path) {
                Storage::disk('public')->delete($account->qris_path);
            }
            $data['qris_path'] = $request->file('qris')->store('qris', 'public');
        }

        $account->update($data);

        return redirect()->route('parkir.rekening.index')->with('success', 'Metode pembayaran diperbarui.');
    }

    public function toggle(PaymentAccount $account): RedirectResponse
    {
        $account->update(['is_active' => ! $account->is_active]);

        return back()->with('success', $account->is_active ? 'Metode diaktifkan.' : 'Metode dinonaktifkan.');
    }

    public function destroy(PaymentAccount $account): RedirectResponse
    {
        if ($account->qris_path) {
            Storage::disk('public')->delete($account->qris_path);
        }
        $account->delete();

        return back()->with('success', 'Metode pembayaran dihapus.');
    }

    private function validated(Request $request, ?string $type, ?PaymentAccount $account = null): array
    {
        $isBank = $type === 'bank';

        $data = $request->validate([
            'type'           => ['required', 'in:bank,qris'],
            'label'          => ['required', 'string', 'max:60'],
            'account_name'   => [$isBank ? 'required' : 'nullable', 'string', 'max:100'],
            'account_number' => [$isBank ? 'required' : 'nullable', 'regex:/^[0-9 \-]{5,30}$/'],
            'qris'           => [
                ($type === 'qris' && ! $account?->qris_path) ? 'required' : 'nullable',
                'image', 'mimes:png,jpg,jpeg', 'max:2048',
            ],
        ], [
            'label.required'          => 'Nama bank / label wajib diisi.',
            'account_name.required'   => 'Nama pemilik rekening wajib diisi.',
            'account_number.required' => 'Nomor rekening wajib diisi.',
            'account_number.regex'    => 'Nomor rekening hanya boleh angka (5–30 digit).',
            'qris.required'           => 'Gambar QRIS wajib diunggah.',
            'qris.image'              => 'File harus berupa gambar.',
            'qris.max'                => 'Ukuran gambar maksimal 2 MB.',
        ]);

        unset($data['qris']);

        if (! $isBank) {
            $data['account_number'] = null;
        }

        $data['type'] = $type ?? $data['type'];

        return $data;
    }
}