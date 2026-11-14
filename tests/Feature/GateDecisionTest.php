<?php

use App\Models\AccessRequest;
use App\Models\Member;
use App\Models\MemberSubscription;
use App\Models\ParkingGateState;
use App\Models\ParkingSession;
use App\Models\SecurityAlert;
use App\Models\SubscriptionPlan;
use App\Models\Vehicle;
use App\Services\PlateMatcher;
use Illuminate\Support\Facades\Cache;

// ─────────────────────────────────────────────────────────────────────────────
// Setup: buat data member, kendaraan, langganan, dan state gerbang
// ─────────────────────────────────────────────────────────────────────────────
beforeEach(function () {
    $this->member = Member::create([
        'name'      => 'Pemilik Kendaraan',
        'phone'     => '081234567890',
        'email'     => 'pemilik@example.com',
        'is_active' => true,
    ]);

    $this->vehicle = Vehicle::create([
        'member_id'    => $this->member->id,
        'plate_number' => 'B1001ZZZ',
        'vehicle_type' => 'mobil',
    ]);

    $plan = SubscriptionPlan::create([
        'name'         => 'Paket Bulanan Mobil',
        'slug'         => 'paket-bulanan-' . uniqid(),
        'duration_days' => 30,
        'price_motor'  => 0,
        'price_mobil'  => 150000,
        'is_active'    => true,
    ]);

    MemberSubscription::create([
        'member_id'            => $this->member->id,
        'subscription_plan_id' => $plan->id,
        'start_date'           => now()->subDay()->toDateString(),
        'end_date'             => now()->addDays(29)->toDateString(),
        'status'               => 'active',
    ]);

    // Buat state gerbang dalam kondisi MENUNGGU
    $this->state = ParkingGateState::create([
        'gate'               => 'gate_masuk',
        'trigger'            => true,
        'izin'               => 'MENUNGGU',
        'status'             => 'MENUNGGU VERIFIKASI',
        'face_match'         => false,
        'face_member_id'     => null,
        'plate_match'        => false,
        'plate_member_id'    => null,
        'plate_vehicle_id'   => null,
        'plate_detected'     => null,
        'attempt_count'      => 0,
        'quality_fail_count' => 0,
        'face_votes'         => null,
        'face_frame_count'   => 0,
    ]);
});

// ─────────────────────────────────────────────────────────────────────────────
// UNIT: PlateMatcher
// ─────────────────────────────────────────────────────────────────────────────
describe('PlateMatcher', function () {
    test('cocok persis', function () {
        expect(PlateMatcher::cocok('B1001ZZZ', 'B1001ZZZ'))->toBeTrue();
    });

    test('cocok setelah normalisasi (spasi & huruf kecil)', function () {
        expect(PlateMatcher::cocok('b 1001 zzz', 'B1001ZZZ'))->toBeTrue();
    });

    test('cocok dengan 1 swap: 0 vs O', function () {
        expect(PlateMatcher::cocok('B10O1ZZZ', 'B1001ZZZ'))->toBeTrue();
    });

    test('cocok dengan 1 swap: B vs 8', function () {
        expect(PlateMatcher::cocok('81001ZZZ', 'B1001ZZZ'))->toBeTrue();
    });

    test('cocok dengan 1 swap: S vs 5', function () {
        expect(PlateMatcher::cocok('B100SZZZ', 'B1001ZZZ'))->toBeFalse(); // S bukan pasangan 1
    });

    test('tidak cocok jika 2 posisi berbeda meski pasangan', function () {
        // 0→O di posisi 2 DAN 1→I di posisi 4 → 2 perbedaan → false
        expect(PlateMatcher::cocok('BO0I1ZZZ', 'B1001ZZZ'))->toBeFalse();
    });

    test('tidak cocok jika panjang berbeda', function () {
        expect(PlateMatcher::cocok('B101ZZZ', 'B1001ZZZ'))->toBeFalse();
    });

    test('tidak cocok jika perbedaan bukan pasangan', function () {
        expect(PlateMatcher::cocok('B1001ZZA', 'B1001ZZZ'))->toBeFalse();
    });
});

// ─────────────────────────────────────────────────────────────────────────────
// ATURAN 1: Member sah — wajah + plat cocok → BUKA
// ─────────────────────────────────────────────────────────────────────────────
test('Aturan 1: member sah, wajah + plat cocok → palang BUKA dan sesi parkir dibuat', function () {
    // Kirim plat dulu
    $this->postJson('/api/parking/plate-result', [
        'gate'           => 'gate_masuk',
        'plate_detected' => 'B1001ZZZ',
    ])->assertOk();

    // Kirim wajah (Mode LBPH) — member_id sama dengan pemilik plat
    $response = $this->postJson('/api/parking/face-result', [
        'gate'      => 'gate_masuk',
        'member_id' => $this->member->id,
        'distance'  => 30.0, // jauh di bawah threshold LBPH 80
    ]);

    // Voting 1 frame belum cukup → masih menunggu frame berikutnya
    // Kirim 4 frame lagi untuk mencapai 5 frame
    for ($i = 0; $i < 4; $i++) {
        $response = $this->postJson('/api/parking/face-result', [
            'gate'      => 'gate_masuk',
            'member_id' => $this->member->id,
            'distance'  => 30.0,
        ]);
    }

    // Setelah 5 frame dengan member yang sama → BUKA
    $state = ParkingGateState::where('gate', 'gate_masuk')->first();
    expect($state->izin)->toBe('BUKA');

    // Sesi parkir harus dibuat
    $sesi = ParkingSession::where('vehicle_id', $this->vehicle->id)->where('status', 'active')->first();
    expect($sesi)->not->toBeNull();
});

// ─────────────────────────────────────────────────────────────────────────────
// ATURAN 2: Voting wajah — perlu ≥3/5 frame mengenali member yang sama
// ─────────────────────────────────────────────────────────────────────────────
test('Aturan 2: hanya 2/5 frame cocok → wajah tidak diterima', function () {
    // Set state: plat sudah cocok
    $this->state->update([
        'plate_match'     => true,
        'plate_member_id' => $this->member->id,
        'plate_vehicle_id' => $this->vehicle->id,
        'plate_detected'  => 'B1001ZZZ',
    ]);

    // Anggota lain untuk "wajah orang asing"
    $asingId = 999;

    // Kirim 2 frame member asli + 3 frame orang asing
    $this->postJson('/api/parking/face-result', ['gate' => 'gate_masuk', 'member_id' => $this->member->id, 'distance' => 20.0]);
    $this->postJson('/api/parking/face-result', ['gate' => 'gate_masuk', 'member_id' => $this->member->id, 'distance' => 20.0]);
    $this->postJson('/api/parking/face-result', ['gate' => 'gate_masuk', 'member_id' => $asingId, 'distance' => 20.0]);
    $this->postJson('/api/parking/face-result', ['gate' => 'gate_masuk', 'member_id' => $asingId, 'distance' => 20.0]);
    $this->postJson('/api/parking/face-result', ['gate' => 'gate_masuk', 'member_id' => $asingId, 'distance' => 20.0]);

    $state = ParkingGateState::where('gate', 'gate_masuk')->first();
    // Wajah tidak cocok (orang asing menang voting) → tidak BUKA
    expect($state->izin)->not->toBe('BUKA');
    expect($state->face_match)->toBeFalse();
});

// ─────────────────────────────────────────────────────────────────────────────
// ATURAN 3: Maks 5 percobaan — plat terdaftar, wajah tidak cocok
// ─────────────────────────────────────────────────────────────────────────────
test('Aturan 3: setelah 5x gagal (plat terdaftar, wajah tidak cocok) → notifikasi dikirim', function () {
    // Set state: plat sudah cocok
    $this->state->update([
        'plate_match'     => true,
        'plate_member_id' => $this->member->id,
        'plate_vehicle_id' => $this->vehicle->id,
        'plate_detected'  => 'B1001ZZZ',
        'attempt_count'   => 4, // sudah 4 percobaan, akan gagal di ke-5
    ]);

    // Percobaan ke-5: 5 frame wajah orang yang tidak dikenali
    for ($i = 0; $i < 5; $i++) {
        $this->postJson('/api/parking/face-result', [
            'gate'      => 'gate_masuk',
            'member_id' => 99999, // ID tidak ada di DB
            'distance'  => 20.0,
        ]);
    }

    // Harus ada AccessRequest dengan status pending
    $accessReq = AccessRequest::where('member_id', $this->member->id)
        ->where('status', 'pending')
        ->where('reason', 'face_mismatch')
        ->first();

    expect($accessReq)->not->toBeNull();
    expect($accessReq->expires_at)->not->toBeNull();
});

// ─────────────────────────────────────────────────────────────────────────────
// ATURAN 4: Kegagalan kualitas (gambar gelap/buram)
// ─────────────────────────────────────────────────────────────────────────────
test('Aturan 4: 2x gagal kualitas → belum dihitung percobaan, pesan LCD muncul', function () {
    $response = $this->postJson('/api/parking/face-result', [
        'gate'         => 'gate_masuk',
        'quality_fail' => true,
    ]);

    $response->assertOk()
        ->assertJson(['quality_fail' => true, 'lcd_message' => 'Kurang cahaya, dekatkan wajah']);

    $state = ParkingGateState::where('gate', 'gate_masuk')->first();
    expect($state->attempt_count)->toBe(0); // belum jadi percobaan
    expect($state->quality_fail_count)->toBe(1);
});

test('Aturan 4: setelah 3x gagal kualitas → dihitung sebagai 1 percobaan', function () {
    // 3 kali gagal kualitas berturut-turut
    for ($i = 0; $i < 3; $i++) {
        $this->postJson('/api/parking/face-result', [
            'gate'         => 'gate_masuk',
            'quality_fail' => true,
        ]);
    }

    $state = ParkingGateState::where('gate', 'gate_masuk')->first();
    // Kegagalan kualitas ke-3 → reset quality_fail_count, naik attempt_count
    expect($state->quality_fail_count)->toBe(0);
    expect($state->attempt_count)->toBe(1);
});

// ─────────────────────────────────────────────────────────────────────────────
// ATURAN 5: Plat terbaca tapi tidak terdaftar → langsung TOLAK tanpa notifikasi
// ─────────────────────────────────────────────────────────────────────────────
test('Aturan 5: plat tidak terdaftar → TOLAK langsung tanpa SecurityAlert', function () {
    $response = $this->postJson('/api/parking/plate-result', [
        'gate'           => 'gate_masuk',
        'plate_detected' => 'XX9999YY', // plat tidak ada di DB
    ]);

    $response->assertOk()->assertJson(['plate_match' => false]);

    $state = ParkingGateState::where('gate', 'gate_masuk')->first();
    expect($state->izin)->toBe('TOLAK');

    // Tidak boleh ada SecurityAlert untuk kasus ini
    $secAlert = SecurityAlert::first();
    expect($secAlert)->toBeNull();

    // AccessRequest dibuat dengan reason plat_tidak_terdaftar
    $req = AccessRequest::where('reason', 'plat_tidak_terdaftar')->first();
    expect($req)->not->toBeNull();
    expect($req->status)->toBe('denied');
});

// ─────────────────────────────────────────────────────────────────────────────
// ATURAN 5b: Plat dengan char-swap (B vs 8) → masih cocok
// ─────────────────────────────────────────────────────────────────────────────
test('Aturan 1 + PlateMatcher: plat dengan swap 8→B tetap cocok', function () {
    $response = $this->postJson('/api/parking/plate-result', [
        'gate'           => 'gate_masuk',
        'plate_detected' => '81001ZZZ', // B → 8
    ]);

    $response->assertOk()->assertJson(['plate_match' => true]);
});

// ─────────────────────────────────────────────────────────────────────────────
// ATURAN 6a: "Ini saya" (approve) → palang dibuka
// ─────────────────────────────────────────────────────────────────────────────
test('Aturan 6: pemilik approve (Ini saya) → palang BUKA dan sesi tercatat', function () {
    // Buat AccessRequest pending seolah dikirim setelah 5x gagal
    $req = AccessRequest::create([
        'member_id'     => $this->member->id,
        'vehicle_id'    => $this->vehicle->id,
        'gate'          => 'gate_masuk',
        'direction'     => 'in',
        'reason'        => 'face_mismatch',
        'plate_detected' => 'B1001ZZZ',
        'plate_match'   => true,
        'face_match'    => false,
        'status'        => 'pending',
        'requested_at'  => now(),
        'expires_at'    => now()->addSeconds(60),
    ]);

    // State gerbang masih MENUNGGU dengan plate_vehicle_id terkait
    $this->state->update([
        'izin'            => 'MENUNGGU',
        'plate_vehicle_id' => $this->vehicle->id,
    ]);

    // Pemilik login dan memberi keputusan "Ini saya" (approve)
    $response = $this->actingAs($this->member)
        ->postJson("/api/notifikasi/{$req->id}/respon", ['decision' => 'approve']);

    $response->assertOk()->assertJson(['message' => 'Akses diizinkan. Palang dibuka.']);

    $state = ParkingGateState::where('gate', 'gate_masuk')->first();
    expect($state->izin)->toBe('BUKA');

    // Sesi parkir masuk harus ada
    $sesi = ParkingSession::where('vehicle_id', $this->vehicle->id)->where('status', 'active')->first();
    expect($sesi)->not->toBeNull();
});

// ─────────────────────────────────────────────────────────────────────────────
// ATURAN 6b: "Bukan saya" (reject) → SecurityAlert dibuat
// ─────────────────────────────────────────────────────────────────────────────
test('Aturan 6: pemilik reject (Bukan saya) → SecurityAlert face_mismatch_rejected dibuat', function () {
    $req = AccessRequest::create([
        'member_id'     => $this->member->id,
        'vehicle_id'    => $this->vehicle->id,
        'gate'          => 'gate_masuk',
        'direction'     => 'in',
        'reason'        => 'face_mismatch',
        'plate_detected' => 'B1001ZZZ',
        'plate_match'   => true,
        'face_match'    => false,
        'status'        => 'pending',
        'requested_at'  => now(),
        'expires_at'    => now()->addSeconds(60),
    ]);

    $this->state->update([
        'izin'            => 'MENUNGGU',
        'plate_vehicle_id' => $this->vehicle->id,
    ]);

    $response = $this->actingAs($this->member)
        ->postJson("/api/notifikasi/{$req->id}/respon", ['decision' => 'reject']);

    $response->assertOk()->assertJson(['message' => 'Akses ditolak. Palang tetap tertutup.']);

    // SecurityAlert harus dibuat
    $alert = SecurityAlert::where('access_request_id', $req->id)->first();
    expect($alert)->not->toBeNull();
    expect($alert->type)->toBe('face_mismatch_rejected');
    expect($alert->status)->toBe('open');

    // State gerbang harus TOLAK
    $state = ParkingGateState::where('gate', 'gate_masuk')->first();
    expect($state->izin)->toBe('TOLAK');
});

// ─────────────────────────────────────────────────────────────────────────────
// ATURAN 6c: Timeout tidak ada respons → SecurityAlert timeout_no_response
// ─────────────────────────────────────────────────────────────────────────────
test('Aturan 6: timeout tanpa respons → SecurityAlert timeout_no_response dibuat', function () {
    // Buat request yang sudah kedaluwarsa
    $req = AccessRequest::create([
        'member_id'     => $this->member->id,
        'vehicle_id'    => $this->vehicle->id,
        'gate'          => 'gate_masuk',
        'direction'     => 'in',
        'reason'        => 'face_mismatch',
        'plate_detected' => 'B1001ZZZ',
        'plate_match'   => true,
        'face_match'    => false,
        'status'        => 'pending',
        'requested_at'  => now()->subSeconds(70), // 70 detik lalu
        'expires_at'    => now()->subSeconds(10), // sudah kedaluwarsa
    ]);

    // Poll notifikasi → sistem otomatis tandai timeout dan buat SecurityAlert
    $this->actingAs($this->member)
        ->getJson('/api/notifikasi')
        ->assertOk();

    $req->refresh();
    expect($req->status)->toBe('timeout');

    $alert = SecurityAlert::where('access_request_id', $req->id)->first();
    expect($alert)->not->toBeNull();
    expect($alert->type)->toBe('timeout_no_response');
});

// ─────────────────────────────────────────────────────────────────────────────
// ATURAN 7: Jeda 60 detik per kendaraan untuk notifikasi
// ─────────────────────────────────────────────────────────────────────────────
test('Aturan 7: notifikasi kedua untuk kendaraan yang sama dilewati dalam 60 detik', function () {
    // Simulasi: cache jeda sudah diset
    Cache::put('gate_notif_' . $this->vehicle->id, true, 60);

    $this->state->update([
        'plate_match'     => true,
        'plate_member_id' => $this->member->id,
        'plate_vehicle_id' => $this->vehicle->id,
        'plate_detected'  => 'B1001ZZZ',
        'attempt_count'   => 4, // sudah 4x gagal, akan jadi ke-5
    ]);

    // Percobaan ke-5: wajah tidak cocok setelah 5 frame
    for ($i = 0; $i < 5; $i++) {
        $this->postJson('/api/parking/face-result', [
            'gate'      => 'gate_masuk',
            'member_id' => 99999,
            'distance'  => 20.0,
        ]);
    }

    // Tidak boleh ada AccessRequest baru (diblokir oleh jeda cache)
    $count = AccessRequest::where('member_id', $this->member->id)
        ->where('status', 'pending')
        ->count();
    expect($count)->toBe(0);
});

// ─────────────────────────────────────────────────────────────────────────────
// ATURAN TAMBAHAN: Member tidak aktif → TOLAK
// ─────────────────────────────────────────────────────────────────────────────
test('Member tidak aktif → plat cocok tapi akses TOLAK', function () {
    $this->member->update(['is_active' => false]);

    $response = $this->postJson('/api/parking/plate-result', [
        'gate'           => 'gate_masuk',
        'plate_detected' => 'B1001ZZZ',
    ]);

    $response->assertOk()->assertJson(['plate_match' => false, 'message' => 'Member tidak aktif.']);

    $state = ParkingGateState::where('gate', 'gate_masuk')->first();
    expect($state->izin)->toBe('TOLAK');
});

// ─────────────────────────────────────────────────────────────────────────────
// ATURAN TAMBAHAN: Trigger reset counter percobaan
// ─────────────────────────────────────────────────────────────────────────────
test('Trigger baru mereset counter percobaan dan kualitas', function () {
    // Set counter tinggi seolah sudah banyak gagal
    $this->state->update([
        'attempt_count'      => 3,
        'quality_fail_count' => 2,
        'last_failure_reason' => 'wajah_tidak_cocok',
    ]);

    $this->postJson('/api/parking/trigger', ['gate' => 'gate_masuk']);

    $state = ParkingGateState::where('gate', 'gate_masuk')->first();
    expect($state->attempt_count)->toBe(0);
    expect($state->quality_fail_count)->toBe(0);
    expect($state->last_failure_reason)->toBeNull();
});
