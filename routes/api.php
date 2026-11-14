<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BerandaController;
use App\Http\Controllers\Api\ParkingGateController;
use App\Http\Controllers\Api\PasswordController;
use App\Http\Controllers\AccessController;
use App\Http\Controllers\Api\RiwayatController;
use App\Http\Controllers\Api\NotifikasiController;
use App\Http\Controllers\Api\ProfilController;
use App\Http\Controllers\Api\VerifikasiAksesController;
use App\Http\Middleware\EnsureMemberCanAccess;
use App\Http\Controllers\Api\PanggilPetugasController;
use App\Http\Controllers\Api\PerpanjangController;
use App\Http\Controllers\Api\GuestAccessController;

// ===== Gerbang parkir (IoT) - punyamu, tidak diubah =====
Route::post('/parking/trigger',[ParkingGateController::class,'trigger']);
Route::get('/parking/command',[ParkingGateController::class,'command']);
Route::post('/parking/reset',[ParkingGateController::class,'reset']);
Route::get('/parking/verification-status',[ParkingGateController::class,'verificationStatus']);
Route::post('/parking/detect-access',[AccessController::class,'detect']);
Route::post('/parking/face-result',[ParkingGateController::class,'faceResult']);
Route::post('/parking/plate-result',[ParkingGateController::class,'plateResult']);

// ===== Verifikasi akses (untuk Python test_akses_parkir.py) =====
Route::post('/verifikasi-akses', [VerifikasiAksesController::class, 'verifikasi']);

// ===== Aplikasi mobile member =====
// Maksimal 6 percobaan login per menit per IP, mencegah tebak sandi.
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:6,1');

Route::middleware(['auth:sanctum', EnsureMemberCanAccess::class])->group(function () {
    // Tiga rute ini tetap boleh dipakai walau must_change_password masih aktif
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::post('/password/change', [PasswordController::class, 'update']);

    // Rute di bawah ini otomatis terkunci sampai sandi diganti
    Route::get('/beranda', [BerandaController::class, 'index']);
    Route::get('/riwayat', [RiwayatController::class, 'index']);
    Route::get('/notifikasi', [NotifikasiController::class, 'index']);
    Route::post('/notifikasi/{id}/respon', [NotifikasiController::class, 'respond'])->whereNumber('id');
    Route::post('/notifikasi/{id}/buzzer', [NotifikasiController::class, 'buzzer'])->whereNumber('id');
    Route::get('/profil', [ProfilController::class, 'show']);
    Route::get('/profil/foto', [ProfilController::class, 'foto']);
    Route::get('/perpanjang', [PerpanjangController::class, 'show']);
    Route::get('/perpanjang/status', [PerpanjangController::class, 'status']);
    Route::post('/perpanjang', [PerpanjangController::class, 'store']);
    Route::post('/perpanjang/{payment}/bukti', [PerpanjangController::class, 'proof']);
    Route::delete('/perpanjang/{payment}', [PerpanjangController::class, 'cancel']);

    // Panggil petugas: WAJIB di dalam grup auth supaya $request->user() terisi
    Route::post('/panggil-petugas', [PanggilPetugasController::class, 'store'])->middleware('throttle:5,1');
    Route::delete('/panggil-petugas', [PanggilPetugasController::class, 'destroy']);

    // Izin akses tamu
    Route::get('/izin-tamu', [GuestAccessController::class, 'index']);
    Route::post('/izin-tamu', [GuestAccessController::class, 'store']);
    Route::delete('/izin-tamu/{id}', [GuestAccessController::class, 'destroy'])->whereNumber('id');
});