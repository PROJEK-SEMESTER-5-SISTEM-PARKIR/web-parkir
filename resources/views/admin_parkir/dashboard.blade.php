@extends('layouts.parkir')

@section('title', 'Dashboard Parkir')
@section('heading', 'Dashboard')
@section('subheading', 'Halo, ' . auth()->user()->name . ' · ringkasan parkir hari ini')

@section('content')

<style>
    .d-bar{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:20px}
    .d-pill{display:inline-flex;align-items:center;gap:8px;padding:7px 14px;border-radius:999px;font-size:13px;font-weight:600;background:#fff;border:1px solid var(--line)}
    .d-dot{width:8px;height:8px;border-radius:50%}
    .d-actions{display:flex;gap:8px;flex-wrap:wrap}
    .d-actions a{text-decoration:none}

    .d-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:16px;margin-bottom:20px}
    .d-stat{background:#fff;border:1px solid var(--line);border-radius:16px;padding:18px 20px;display:flex;align-items:center;gap:14px}
    .d-ico{width:46px;height:46px;border-radius:12px;display:flex;align-items:center;justify-content:center;flex-shrink:0}
    .d-ico svg{width:22px;height:22px}
    .d-num{font-size:28px;font-weight:700;line-height:1.1}
    .d-lbl{font-size:13px;color:var(--muted);margin-top:3px}

    .d-grid{display:grid;grid-template-columns:minmax(0,2fr) minmax(0,1fr);gap:20px;align-items:start}
    @media (max-width:900px){.d-grid{grid-template-columns:1fr}}
    .d-panel{background:#fff;border:1px solid var(--line);border-radius:16px;padding:20px 22px}
    .d-panel h3{margin:0;font-size:16px}
    .d-sub{font-size:13px;color:var(--muted);margin:2px 0 8px}

    .d-row{display:flex;align-items:center;gap:12px;padding:13px 0;border-top:1px solid var(--line)}
    .d-list .d-row:first-child{border-top:0}
    .d-av{width:38px;height:38px;border-radius:50%;background:#e8f0fe;color:#1d5fc4;font-weight:700;font-size:14px;display:flex;align-items:center;justify-content:center;flex-shrink:0}
    .d-main{flex:1;min-width:0}
    .d-name{font-size:14px;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
    .d-meta{font-size:12px;color:var(--muted);margin-top:1px}
    .d-tag{font-size:12px;font-weight:600;padding:3px 10px;border-radius:999px;white-space:nowrap}
    .d-ok{background:#e3f6ec;color:var(--green)}
    .d-bad{background:#fbe8e8;color:var(--red)}
    .d-warn{background:#fdf1dc;color:var(--amber)}
    .d-time{font-size:12px;color:var(--muted);min-width:42px;text-align:right}
    .d-empty{text-align:center;color:var(--muted);font-size:14px;padding:30px 0}
    .d-mini{width:34px;height:34px;border-radius:10px;display:flex;align-items:center;justify-content:center;flex-shrink:0}
    .d-mini svg{width:18px;height:18px}

    /* ---------- Slot parkir ---------- */
    .d-slots{margin-bottom:20px}
    .d-slots-head{display:flex;justify-content:space-between;align-items:flex-start;gap:12px;flex-wrap:wrap;margin-bottom:14px}
    .d-slots-head a{text-decoration:none}
    .d-slots-grid{display:grid;grid-template-columns:1fr 1fr;gap:24px}
    @media (max-width:700px){.d-slots-grid{grid-template-columns:1fr;gap:18px}}
    .d-slot-top{display:flex;justify-content:space-between;align-items:baseline;margin-bottom:8px}
    .d-slot-name{font-size:14px;font-weight:600}
    .d-slot-count{font-size:13px;color:var(--muted)}
    .d-slot-count b{font-size:22px;color:var(--text)}
    .d-track{height:10px;border-radius:999px;background:#eef1f5;overflow:hidden}
    .d-track i{display:block;height:100%;border-radius:999px;background:var(--green);transition:width .3s}
    .d-track i.warn{background:var(--amber)}
    .d-track i.full{background:var(--red)}
    .d-slot-meta{display:flex;justify-content:space-between;gap:8px;flex-wrap:wrap;margin-top:8px;font-size:12px;color:var(--muted)}
</style>

{{-- ===== Status AI + aksi cepat ===== --}}
<div class="d-bar">
    <span class="d-pill" title="{{ $aiOnline ? 'Service Python merespons' : 'Service Python tidak merespons, gerbang tidak bisa memverifikasi' }}">
        <span class="d-dot" style="background:{{ $aiOnline ? 'var(--green)' : 'var(--red)' }}"></span>
        Layanan AI {{ $aiOnline ? 'online' : 'offline' }}
    </span>
    <div class="d-actions">
        <a href="{{ route('parkir.pendaftaran') }}" class="btn btn-primary">+ Daftarkan Pelanggan</a>
        <a href="{{ route('parkir.darurat') }}" class="btn btn-outline" style="color:var(--red);border-color:#f1c4c4">Pintu Darurat</a>
    </div>
</div>

{{-- ===== Kartu ringkasan ===== --}}
<div class="d-stats">
    <div class="d-stat">
        <div class="d-ico" style="background:#ede9fe;color:#6d4fd6">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="8" r="3.5"/><path d="M2.5 20c.6-3.6 3.2-5.5 6.5-5.5s5.9 1.9 6.5 5.5"/><path d="M16 4.6a3.5 3.5 0 010 6.8M18.5 14.8c1.8.7 2.8 2.4 3.2 5.2"/></svg>
        </div>
        <div>
            <div class="d-num">{{ $stats['member_terdaftar'] ?? '-' }}</div>
            <div class="d-lbl">
                Member terdaftar
                @isset($stats['member_berlangganan']) &middot; {{ $stats['member_berlangganan'] }} aktif @endisset
            </div>
        </div>
    </div>

    <div class="d-stat">
        <div class="d-ico" style="background:#e8f0fe;color:var(--accent)">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="8" width="18" height="8" rx="2"/><circle cx="7.5" cy="17" r="1.5"/><circle cx="16.5" cy="17" r="1.5"/></svg>
        </div>
        <div><div class="d-num">{{ $stats['sedang_parkir'] }}</div><div class="d-lbl">Sedang parkir</div></div>
    </div>

    <div class="d-stat">
        <div class="d-ico" style="background:#e3f6ec;color:var(--green)">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
        </div>
        <div><div class="d-num">{{ $stats['masuk_hari_ini'] }}</div><div class="d-lbl">Masuk hari ini</div></div>
    </div>

    <div class="d-stat">
        <div class="d-ico" style="background:#f0f2f5;color:var(--muted)">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 12H5M11 6l-6 6 6 6"/></svg>
        </div>
        <div><div class="d-num">{{ $stats['keluar_hari_ini'] }}</div><div class="d-lbl">Keluar hari ini</div></div>
    </div>

    <div class="d-stat">
        <div class="d-ico" style="background:#fbe8e8;color:var(--red)">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 9v4M12 17h.01M10.3 3.9L1.8 18a2 2 0 001.7 3h17a2 2 0 001.7-3L13.7 3.9a2 2 0 00-3.4 0z"/></svg>
        </div>
        <div>
            <div class="d-num" style="color:{{ $stats['gagal_hari_ini'] > 0 ? 'var(--red)' : 'inherit' }}">{{ $stats['gagal_hari_ini'] }}</div>
            <div class="d-lbl">Verifikasi gagal</div>
        </div>
    </div>
</div>

{{-- ===== Slot parkir ===== --}}
@if (!empty($slots))
    <div class="d-panel d-slots">
        <div class="d-slots-head">
            <div>
                <h3>Slot parkir</h3>
                <p class="d-sub" style="margin-bottom:0">Jatah kendaraan member yang sedang berlangganan</p>
            </div>
            <a href="{{ route('parkir.slot') }}" class="btn btn-outline">Atur kapasitas</a>
        </div>

        <div class="d-slots-grid">
           @foreach ($slots as $jenis => $s)
                    @php
                        $label = ucfirst($jenis);
                        $persen = $s['kapasitas'] > 0
                            ? min(100, (int) round($s['terisi'] / $s['kapasitas'] * 100))
                            : ($s['terisi'] > 0 ? 100 : 0);
                        $kelas = $persen >= 100 ? 'full' : ($persen >= 80 ? 'warn' : '');
                    @endphp
                    <div>
                    <div class="d-slot-top">
                        <span class="d-slot-name">{{ $label }}</span>
                        <span class="d-slot-count"><b>{{ $s['terisi'] }}</b> / {{ $s['kapasitas'] }} terisi</span>
                    </div>
                    <div class="d-track" role="progressbar" aria-valuenow="{{ $persen }}" aria-valuemin="0" aria-valuemax="100">
                        <i class="{{ $kelas }}" style="width: {{ $persen }}%"></i>
                    </div>
                    <div class="d-slot-meta">
                        <span>{{ $s['sisa'] === 0 ? 'Penuh, pendaftaran ditutup' : 'Sisa '.$s['sisa'].' slot' }}</span>
                        <span>Di dalam sekarang: {{ $s['di_dalam'] }}</span>
                    </div>
                </div>
            @endforeach
        </div>
    </div>
@endif

{{-- ===== Aktivitas + perlu perhatian ===== --}}
<div class="d-grid">

    {{-- Aktivitas terbaru --}}
    <div class="d-panel">
        <h3>Aktivitas terbaru</h3>
        <p class="d-sub">Hasil verifikasi wajah & plat di gerbang</p>

        <div class="d-list">
            @forelse ($recentRequests as $r)
                @php $ok = $r->plate_match && $r->face_match; @endphp
                <div class="d-row">
                    <div class="d-av">{{ strtoupper(substr($r->member?->name ?? '?', 0, 1)) }}</div>
                    <div class="d-main">
                        <div class="d-name">{{ $r->member?->name ?? 'Tidak dikenali' }}</div>
                        <div class="d-meta">{{ $r->plate_detected ?: 'Plat tidak terbaca' }}</div>
                    </div>
                    <span class="d-tag {{ $ok ? 'd-ok' : 'd-bad' }}">{{ $ok ? 'Berhasil' : 'Gagal' }}</span>
                    <span class="d-time">{{ $r->requested_at?->format('H:i') }}</span>
                </div>
            @empty
                <div class="d-empty">Belum ada aktivitas verifikasi.</div>
            @endforelse
        </div>
    </div>

    {{-- Perlu perhatian --}}
    <div class="d-panel">
        @php
            // Slot yang sudah penuh atau tinggal sedikit (kapasitas 0 dianggap sengaja ditutup)
            $slotPerhatian = collect($slots ?? [])->filter(fn ($s) => $s['kapasitas'] > 0 && $s['sisa'] <= 2);
        @endphp

        <h3>Perlu perhatian</h3>
        <p class="d-sub">Hal yang sebaiknya dicek admin</p>

        <div class="d-list">
            @foreach ($slotPerhatian as $jenis => $s)
                <div class="d-row">
                    <div class="d-mini {{ $s['sisa'] === 0 ? 'd-bad' : 'd-warn' }}">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="16" rx="2"/><path d="M9 16V8h3.2a2.3 2.3 0 010 4.6H9"/></svg>
                    </div>
                    <div class="d-main">
                        <div class="d-name">Slot {{ $jenis }} {{ $s['sisa'] === 0 ? 'penuh' : 'hampir penuh' }}</div>
                        <div class="d-meta">
                            {{ $s['sisa'] === 0 ? 'Pendaftaran '.$jenis.' baru ditutup' : 'Sisa '.$s['sisa'].' dari '.$s['kapasitas'] }}
                        </div>
                    </div>
                </div>
            @endforeach

            @if ($expiredCount > 0)
                <div class="d-row">
                    <div class="d-mini d-warn">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 3"/></svg>
                    </div>
                    <div class="d-main">
                        <div class="d-name">{{ $expiredCount }} langganan kedaluwarsa</div>
                        <div class="d-meta">Status masih aktif, perlu diperbarui</div>
                    </div>
                </div>
            @endif

            @foreach ($recentFailed as $f)
                <div class="d-row">
                    <div class="d-mini d-bad">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M18 6L6 18M6 6l12 12"/></svg>
                    </div>
                    <div class="d-main">
                        <div class="d-name">{{ $f->plate_detected ?: 'Plat tidak terbaca' }}</div>
                        <div class="d-meta">
                            @if (! $f->plate_match && ! $f->face_match) Plat & wajah tidak cocok
                            @elseif (! $f->plate_match) Plat tidak cocok
                            @else Wajah tidak cocok
                            @endif
                        </div>
                    </div>
                    <span class="d-time">{{ $f->requested_at?->isToday() ? $f->requested_at->format('H:i') : $f->requested_at?->format('d M') }}</span>
                </div>
            @endforeach

            @foreach ($expiringSoon as $sub)
                @php $left = (int) today()->diffInDays($sub->end_date); @endphp
                <div class="d-row">
                    <div class="d-mini d-warn">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 3"/></svg>
                    </div>
                    <div class="d-main">
                        <div class="d-name">{{ $sub->member?->name ?? '-' }}</div>
                        <div class="d-meta">Paket {{ $sub->plan?->name ?? '-' }}</div>
                    </div>
                    <span class="d-tag d-warn">{{ $left === 0 ? 'Hari ini' : $left . ' hari' }}</span>
                </div>
            @endforeach

            @if ($slotPerhatian->isEmpty() && $expiredCount === 0 && $recentFailed->isEmpty() && $expiringSoon->isEmpty())
                <div class="d-empty">Semua aman, tidak ada yang perlu dicek.</div>
            @endif
        </div>
    </div>

</div>

@endsection