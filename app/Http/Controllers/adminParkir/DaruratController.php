<?php

namespace App\Http\Controllers\adminParkir;

use App\Http\Controllers\Controller;
use App\Models\EmergencyGateLog;
use App\Models\ParkingGateState;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class DaruratController extends Controller
{
    /** Harus sama dengan GATE_MASUK / GATE_KELUAR di kode ESP32. */
    private const GATES = [
        'gate_masuk'  => 'Gerbang masuk',
        'gate_keluar' => 'Gerbang keluar',
    ];

    /** Nilai khusus di dropdown untuk memilih kedua gerbang sekaligus. */
    private const SEMUA = 'semua';

    public function index(): View
    {
        return view('admin_parkir.darurat', [
            'gates' => self::GATES,
            'states' => ParkingGateState::whereIn('gate', array_keys(self::GATES))
                ->get()->keyBy('gate'),
            'logs' => EmergencyGateLog::with('admin')->latest()->limit(20)->get(),
        ]);
    }

    public function open(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'gate'   => ['required', 'in:'.$this->allowedGates()],
            'reason' => ['required', 'string', 'min:5', 'max:255'],
        ], [
            'reason.required' => 'Alasan wajib diisi.',
            'reason.min' => 'Alasan minimal 5 karakter.',
        ]);

        $gates = $this->resolveGates($data['gate']);

        DB::transaction(function () use ($data, $request, $gates) {
            foreach ($gates as $gate) {
                EmergencyGateLog::create([
                    'gate' => $gate,
                    'action' => 'open',
                    'reason' => $data['reason'],
                    'issued_by' => $request->user()->id,
                ]);

                ParkingGateState::updateOrCreate(
                    ['gate' => $gate],
                    [
                        'trigger' => false,
                        'izin' => 'BUKA',
                        'status' => 'PALANG DIBUKA MANUAL - DARURAT',
                        'face_match' => false,
                        'face_member_id' => null,
                        'plate_match' => false,
                        'plate_member_id' => null,
                        'plate_vehicle_id' => null,
                        'plate_detected' => null,
                    ]
                );
            }
        });

        return back()->with('success', 'Perintah buka palang dikirim ke '.$this->label($data['gate']).'.');
    }

   public function close(Request $request): RedirectResponse
{
    $data = $request->validate([
        'gate' => ['required', 'in:'.$this->allowedGates()],
    ]);

    $resolvedGates = $this->resolveGates($data['gate']);

    DB::transaction(function () use ($resolvedGates, $request) {
        foreach ($resolvedGates as $gate) {
            // Catat selalu ke riwayat darurat bahwa admin memerintahkan tutup
            EmergencyGateLog::create([
                'gate' => $gate,
                'action' => 'close',
                'reason' => 'Ditutup manual oleh admin',
                'issued_by' => $request->user()->id,
            ]);

            // Update state gerbang agar kembali menutup
            ParkingGateState::updateOrCreate(
                ['gate' => $gate],
                [
                    'izin' => 'TUTUP',
                    'status' => 'PERINTAH TUTUP MANUAL',
                ]
            );
        }
    });

    return back()->with('success', 'Perintah tutup palang dikirim ke '.$this->label($data['gate']).'.');
}

    private function allowedGates(): string
    {
        return implode(',', array_merge(array_keys(self::GATES), [self::SEMUA]));
    }

    private function resolveGates(string $gate): array
    {
        return $gate === self::SEMUA ? array_keys(self::GATES) : [$gate];
    }

    private function label(string $gate): string
    {
        return $gate === self::SEMUA ? 'kedua gerbang' : self::GATES[$gate];
    }
}