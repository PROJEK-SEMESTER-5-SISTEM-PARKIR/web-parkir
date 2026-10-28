<?php

namespace App\Http\Controllers\adminParkir;

use App\Http\Controllers\Controller;
use Illuminate\View\View;

class KameraController extends Controller
{
    private const GATES = [
        'gate_masuk'  => 'Pintu masuk',
        'gate_keluar' => 'Pintu keluar',
    ];

       public function index(): View
    {
        $base = config('services.cv.url')
            ?: 'http://' . request()->getHost() . ':8090';

        return view('admin_parkir.kamera', [
            'gates'     => self::GATES,
            'streamUrl' => rtrim($base, '/') . '/stream',
        ]);
    }
}