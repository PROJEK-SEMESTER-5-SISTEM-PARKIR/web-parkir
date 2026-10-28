<?php

use App\Models\AccessRequest;
use App\Models\Member;
use App\Models\MemberSubscription;
use App\Models\ParkingGateState;
use App\Models\ParkingSession;
use App\Models\SubscriptionPlan;
use App\Models\Vehicle;

beforeEach(function () {
    $this->member = Member::create([
        'name' => 'Dimas Test',
        'phone' => '081234567890',
        'email' => 'dimas.test@example.com',
        'is_active' => true,
    ]);

    $this->vehicle = Vehicle::create([
        'member_id' => $this->member->id,
        'plate_number' => 'B 1001 ZZZ',
        'vehicle_type' => 'mobil',
    ]);

    $this->plan = SubscriptionPlan::create([
        'name' => 'Paket Bulanan Mobil',
        'slug' => 'paket-bulanan-mobil',
        'duration_days' => 30,
        'price_motor' => 0,
        'price_mobil' => 150000,
        'is_active' => true,
    ]);

    $this->subscription = MemberSubscription::create([
        'member_id' => $this->member->id,
        'subscription_plan_id' => $this->plan->id,
        'start_date' => now()->subDay()->toDateString(),
        'end_date' => now()->addDays(29)->toDateString(),
        'status' => 'active',
    ]);
});

test('mobil belum masuk tidak bisa keluar di gate_keluar', function () {
    $response = $this->postJson('/api/verifikasi-akses', [
        'gate' => 'gate_keluar',
        'member_id' => $this->member->id,
        'plate_text' => 'B1001ZZZ',
        'distance' => 50.0,
        'update_gate' => true,
    ]);

    $response->assertOk()
        ->assertJson([
            'success' => true,
            'status' => 'tolak',
            'cocok' => false,
            'izin_gate' => 'TOLAK',
        ]);

    expect(ParkingSession::where('vehicle_id', $this->vehicle->id)->count())->toBe(0);

    $accessReq = AccessRequest::latest('id')->first();
    expect($accessReq)->not->toBeNull();
    expect($accessReq->status)->toBe('denied');
    expect($accessReq->reason)->toBe('not_inside');
    expect($accessReq->direction)->toBe('out');

    $gateState = ParkingGateState::where('gate', 'gate_keluar')->first();
    expect($gateState->izin)->toBe('TOLAK');
    expect($gateState->status)->toContain('MOBIL SUDAH KELUAR');
});

test('mobil belum masuk dapat masuk di gate_masuk dan membuat sesi aktif', function () {
    $response = $this->postJson('/api/verifikasi-akses', [
        'gate' => 'gate_masuk',
        'member_id' => $this->member->id,
        'plate_text' => 'B1001ZZZ',
        'distance' => 50.0,
        'update_gate' => true,
    ]);

    $response->assertOk()
        ->assertJson([
            'success' => true,
            'status' => 'izin',
            'cocok' => true,
            'izin_gate' => 'BUKA',
        ]);

    $session = ParkingSession::where('vehicle_id', $this->vehicle->id)
        ->where('status', 'active')
        ->first();

    expect($session)->not->toBeNull();
    expect($session->exit_time)->toBeNull();

    $accessReq = AccessRequest::latest('id')->first();
    expect($accessReq->status)->toBe('auto_approved');
    expect($accessReq->direction)->toBe('in');

    $gateState = ParkingGateState::where('gate', 'gate_masuk')->first();
    expect($gateState->izin)->toBe('BUKA');
    expect($gateState->status)->toBe('PALANG TERBUKA - KENDARAAN MASUK');
});

test('mobil sudah masuk tidak bisa masuk lagi di gate_masuk', function () {
    // 1. Masuk pertama kali
    $this->postJson('/api/verifikasi-akses', [
        'gate' => 'gate_masuk',
        'member_id' => $this->member->id,
        'plate_text' => 'B1001ZZZ',
        'distance' => 50.0,
        'update_gate' => true,
    ]);

    expect(ParkingSession::where('vehicle_id', $this->vehicle->id)->where('status', 'active')->count())->toBe(1);

    // 2. Coba masuk lagi padahal masih di dalam
    $response = $this->postJson('/api/verifikasi-akses', [
        'gate' => 'gate_masuk',
        'member_id' => $this->member->id,
        'plate_text' => 'B1001ZZZ',
        'distance' => 50.0,
        'update_gate' => true,
    ]);

    $response->assertOk()
        ->assertJson([
            'success' => true,
            'status' => 'tolak',
            'cocok' => false,
            'izin_gate' => 'TOLAK',
        ]);

    // Sesi aktif tidak bertambah
    expect(ParkingSession::where('vehicle_id', $this->vehicle->id)->where('status', 'active')->count())->toBe(1);

    $accessReq = AccessRequest::latest('id')->first();
    expect($accessReq->status)->toBe('denied');
    expect($accessReq->reason)->toBe('already_inside');
    expect($accessReq->direction)->toBe('in');

    $gateState = ParkingGateState::where('gate', 'gate_masuk')->first();
    expect($gateState->izin)->toBe('TOLAK');
    expect($gateState->status)->toContain('MOBIL SUDAH DI DALAM');
});

test('mobil yang berada di dalam dapat keluar di gate_keluar dan sesi ditutup', function () {
    // 1. Masuk
    $this->postJson('/api/verifikasi-akses', [
        'gate' => 'gate_masuk',
        'member_id' => $this->member->id,
        'plate_text' => 'B1001ZZZ',
        'distance' => 50.0,
        'update_gate' => true,
    ]);

    $session = ParkingSession::where('vehicle_id', $this->vehicle->id)->where('status', 'active')->first();
    expect($session)->not->toBeNull();

    // 2. Keluar
    $response = $this->postJson('/api/verifikasi-akses', [
        'gate' => 'gate_keluar',
        'member_id' => $this->member->id,
        'plate_text' => 'B1001ZZZ',
        'distance' => 50.0,
        'update_gate' => true,
    ]);

    $response->assertOk()
        ->assertJson([
            'success' => true,
            'status' => 'izin',
            'cocok' => true,
            'izin_gate' => 'BUKA',
        ]);

    $session->refresh();
    expect($session->status)->toBe('completed');
    expect($session->exit_time)->not->toBeNull();

    $gateState = ParkingGateState::where('gate', 'gate_keluar')->first();
    expect($gateState->izin)->toBe('BUKA');
    expect($gateState->status)->toBe('PALANG TERBUKA - KENDARAAN KELUAR');

    // 3. Coba keluar lagi setelah keluar -> HARUS DITOLAK
    $responseKeluarLagi = $this->postJson('/api/verifikasi-akses', [
        'gate' => 'gate_keluar',
        'member_id' => $this->member->id,
        'plate_text' => 'B1001ZZZ',
        'distance' => 50.0,
        'update_gate' => true,
    ]);

    $responseKeluarLagi->assertOk()
        ->assertJson([
            'success' => true,
            'status' => 'tolak',
            'cocok' => false,
            'izin_gate' => 'TOLAK',
        ]);

    $accessReq = AccessRequest::latest('id')->first();
    expect($accessReq->status)->toBe('denied');
    expect($accessReq->reason)->toBe('not_inside');
    expect($accessReq->direction)->toBe('out');
});

test('alur parking gate controller dengan face result dan plate result menghormati status keluar masuk', function () {
    // 1. Trigger gate_masuk
    $this->postJson('/api/parking/trigger', ['gate' => 'gate_masuk']);

    // 2. Face result
    $this->postJson('/api/parking/face-result', [
        'gate' => 'gate_masuk',
        'member_id' => $this->member->id,
        'distance' => 45.2,
    ]);

    // 3. Plate result -> mobil masuk
    $this->postJson('/api/parking/plate-result', [
        'gate' => 'gate_masuk',
        'plate_detected' => 'B 1001 ZZZ',
    ]);

    $stateMasuk = ParkingGateState::where('gate', 'gate_masuk')->first();
    expect($stateMasuk->izin)->toBe('BUKA');
    expect($stateMasuk->status)->toBe('PALANG TERBUKA - KENDARAAN MASUK');

    expect(ParkingSession::where('vehicle_id', $this->vehicle->id)->where('status', 'active')->count())->toBe(1);

    // 4. Trigger gate_masuk lagi -> mobil sudah di dalam
    $this->postJson('/api/parking/trigger', ['gate' => 'gate_masuk']);
    $this->postJson('/api/parking/face-result', [
        'gate' => 'gate_masuk',
        'member_id' => $this->member->id,
        'distance' => 45.2,
    ]);
    $this->postJson('/api/parking/plate-result', [
        'gate' => 'gate_masuk',
        'plate_detected' => 'B 1001 ZZZ',
    ]);

    $stateMasukLagi = ParkingGateState::where('gate', 'gate_masuk')->first();
    expect($stateMasukLagi->izin)->toBe('TOLAK');
    expect($stateMasukLagi->status)->toBe('AKSES DITOLAK: MOBIL SUDAH DI DALAM');
    expect(ParkingSession::where('vehicle_id', $this->vehicle->id)->where('status', 'active')->count())->toBe(1);

    // 5. Trigger gate_keluar -> mobil keluar
    $this->postJson('/api/parking/trigger', ['gate' => 'gate_keluar']);
    $this->postJson('/api/parking/face-result', [
        'gate' => 'gate_keluar',
        'member_id' => $this->member->id,
        'distance' => 48.0,
    ]);
    $this->postJson('/api/parking/plate-result', [
        'gate' => 'gate_keluar',
        'plate_detected' => 'B 1001 ZZZ',
    ]);

    $stateKeluar = ParkingGateState::where('gate', 'gate_keluar')->first();
    expect($stateKeluar->izin)->toBe('BUKA');
    expect($stateKeluar->status)->toBe('PALANG TERBUKA - KENDARAAN KELUAR');
    expect(ParkingSession::where('vehicle_id', $this->vehicle->id)->where('status', 'active')->count())->toBe(0);

    // 6. Trigger gate_keluar lagi -> mobil sudah keluar
    $this->postJson('/api/parking/trigger', ['gate' => 'gate_keluar']);
    $this->postJson('/api/parking/face-result', [
        'gate' => 'gate_keluar',
        'member_id' => $this->member->id,
        'distance' => 48.0,
    ]);
    $this->postJson('/api/parking/plate-result', [
        'gate' => 'gate_keluar',
        'plate_detected' => 'B 1001 ZZZ',
    ]);

    $stateKeluarLagi = ParkingGateState::where('gate', 'gate_keluar')->first();
    expect($stateKeluarLagi->izin)->toBe('TOLAK');
    expect($stateKeluarLagi->status)->toBe('AKSES DITOLAK: MOBIL SUDAH KELUAR');
});
