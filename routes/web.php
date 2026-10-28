<?php

use App\Http\Controllers\AccessController;
use App\Http\Controllers\Admin\SubscriptionPlanController;
use App\Http\Controllers\adminParkir\DaruratController;
use App\Http\Controllers\adminParkir\DashboardController as ParkirDashboardController;
use App\Http\Controllers\adminParkir\PelangganController;
use App\Http\Controllers\adminParkir\PendaftaranController;
use App\Http\Controllers\adminParkir\SlotController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\SuperAdmin\AdminAccountController;
use App\Http\Controllers\adminParkir\PaketController;
use App\Http\Controllers\adminParkir\KameraController;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    $plans = SubscriptionPlan::active()->ordered()->get();

    return view('welcome', compact('plans'));
});

// Dashboard Admin Web. Role lain dilempar ke panelnya masing-masing.
Route::get('/dashboard', function () {
    $user = auth()->user();

    return match ($user->role) {
        User::ROLE_SUPERADMIN   => redirect()->route('superadmin.admins.index'),
        User::ROLE_ADMIN_PARKIR => redirect()->route('parkir.dashboard'),
        User::ROLE_ADMIN_WEB    => view('dashboard'),
        default                 => abort(403),
    };
})->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

// Admin Web: paket harga dsb
Route::middleware(['auth', 'role:admin_web,superadmin'])
    ->prefix('admin')
    ->name('admin.')
    ->group(function () {
        Route::resource('paket-harga', SubscriptionPlanController::class)
            ->parameters(['paket-harga' => 'paketHarga']);
    });

// Admin Parkir: operasional
Route::middleware(['auth', 'role:admin_parkir'])
    ->prefix('parkir')
    ->name('parkir.')
    ->group(function () {
        Route::get('/', [ParkirDashboardController::class, 'index'])->name('dashboard');

        Route::get('/pendaftaran', [PendaftaranController::class, 'index'])->name('pendaftaran');
        Route::post('/pendaftaran', [PendaftaranController::class, 'store'])->name('pendaftaran.store');
        Route::post('/pendaftaran/cek-wajah', [PendaftaranController::class, 'cekWajah'])
            ->name('pendaftaran.cek-wajah');
        Route::get('/pelanggan', [PelangganController::class, 'index'])->name('pelanggan');
        Route::post('/pelanggan/{member}/reset-password', [PelangganController::class, 'resetPassword'])->name('pelanggan.reset');
        Route::get('/pelanggan/{member}/foto', [PelangganController::class, 'foto'])->name('pelanggan.foto');
        Route::patch('/pelanggan/{member}/status', [PelangganController::class, 'toggleStatus'])->name('pelanggan.status');
        Route::delete('/pelanggan/{member}', [PelangganController::class, 'destroy'])->name('pelanggan.destroy');
        Route::get('/darurat', [DaruratController::class, 'index'])->name('darurat');
        Route::post('/darurat/buka', [DaruratController::class, 'open'])->name('darurat.open');
        Route::get('/slot', [SlotController::class, 'index'])->name('slot');
        Route::put('/slot', [SlotController::class, 'update'])->name('slot.update');
        Route::get('/paket', [PaketController::class, 'index'])->name('paket.index');
        Route::get('/paket/tambah', [PaketController::class, 'create'])->name('paket.create');
        Route::post('/paket', [PaketController::class, 'store'])->name('paket.store');
        Route::get('/paket/{plan}/ubah', [PaketController::class, 'edit'])->name('paket.edit');
        Route::put('/paket/{plan}', [PaketController::class, 'update'])->name('paket.update');
        Route::get('/kamera', [KameraController::class, 'index'])->name('kamera');
        Route::patch('/paket/{plan}/status', [PaketController::class, 'toggle'])->name('paket.toggle');
        Route::delete('/paket/{plan}', [PaketController::class, 'destroy'])->name('paket.destroy');
         Route::post('/pelanggan/{member}/perpanjang', [PelangganController::class, 'perpanjang'])->name('pelanggan.perpanjang');

        // Akses gerbang / deteksi plat
        Route::get('/test-access', function () {
            return view('admin_parkir.test_access');
        })->name('test-access');

        Route::get('/access', function () {
            return view('admin_parkir.access');
        })->name('access');

        Route::post('/detect-access', [AccessController::class, 'detect'])->name('detect-access');
        Route::post('/verify-access', [AccessController::class, 'verifyAccess'])->name('verify-access');
    });

// Superadmin: kelola akun admin
Route::middleware(['auth', 'role:superadmin'])
    ->prefix('superadmin')
    ->name('superadmin.')
    ->group(function () {
        Route::resource('admins', AdminAccountController::class)->except(['show']);
        Route::patch('admins/{admin}/toggle', [AdminAccountController::class, 'toggle'])->name('admins.toggle');
        Route::patch('admins/{admin}/reset-password', [AdminAccountController::class, 'resetPassword'])->name('admins.reset');
    });

require __DIR__ . '/auth.php';
