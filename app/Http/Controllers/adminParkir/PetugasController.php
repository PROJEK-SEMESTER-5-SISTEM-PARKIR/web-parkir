<?php

namespace App\Http\Controllers\adminParkir;

use App\Http\Controllers\Controller;
use App\Models\StaffContact;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;


class PetugasController extends Controller
{
    public function index(): View
    {
        return view('admin_parkir.petugas', [
            'contacts' => StaffContact::orderByDesc('is_active')->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        StaffContact::create($this->validated($request));

        return redirect()->route('parkir.petugas.index')->with('success', 'Kontak petugas ditambahkan.');
    }

    public function update(Request $request, StaffContact $contact): RedirectResponse
    {
        $contact->update($this->validated($request, $contact));

        return redirect()->route('parkir.petugas.index')->with('success', 'Kontak petugas diperbarui.');
    }

    public function toggle(StaffContact $contact): RedirectResponse
    {
        $contact->update(['is_active' => ! $contact->is_active]);

        return back()->with('success', $contact->is_active ? 'Kontak diaktifkan.' : 'Kontak dinonaktifkan.');
    }

    public function destroy(StaffContact $contact): RedirectResponse
    {
        $contact->delete();

        return back()->with('success', 'Kontak petugas dihapus.');
    }

    private function validated(Request $request, ?StaffContact $contact = null): array
    {
        // Samakan format dulu supaya cek duplikat akurat
        $request->merge(['phone' => $this->normalizePhone((string) $request->input('phone'))]);

        return $request->validate([
            'name'  => ['required', 'string', 'max:100'],
            'phone' => [
                'required', 'regex:/^0\d{8,14}$/',
                Rule::unique('staff_contacts', 'phone')->ignore($contact?->id),
            ],
        ], [
            'name.required'  => 'Nama petugas wajib diisi.',
            'phone.required' => 'Nomor WhatsApp wajib diisi.',
            'phone.regex'    => 'Nomor WhatsApp tidak valid (9–15 digit).',
            'phone.unique'   => 'Nomor ini sudah terdaftar.',
        ]);
    }

    /** "+62 812-3456-7890" / "6281234567890" / "81234567890" -> "081234567890" */
    private function normalizePhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone);

        if (str_starts_with($digits, '62')) return '0'.substr($digits, 2);
        if (str_starts_with($digits, '8'))  return '0'.$digits;

        return $digits;
    }
}