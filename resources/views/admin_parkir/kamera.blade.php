@extends('layouts.parkir')

@section('title', 'Kamera Deteksi')
@section('heading', 'Kamera Deteksi')
@section('subheading', 'Pantau hasil deteksi wajah dan plat nomor di gerbang secara langsung')

@section('content')
<style>
    .km-top{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:16px}
    .km-live{display:inline-flex;align-items:center;gap:8px;font-size:13px;color:var(--muted)}
    .km-dot{width:9px;height:9px;border-radius:50%;background:#9aa7b6}
    .km-dot.on{background:var(--green);box-shadow:0 0 0 4px rgba(31,157,97,.18)}
    .km-dot.off{background:var(--red)}

    .km-grid{display:grid;grid-template-columns:minmax(0,1.7fr) minmax(0,1fr);gap:20px;align-items:start}
    @media (max-width:980px){.km-grid{grid-template-columns:1fr}}

    .km-stage{position:relative;aspect-ratio:16/9;border-radius:14px;overflow:hidden;background:#0b1623}
    .km-stage img{position:absolute;inset:0;width:100%;height:100%;object-fit:contain;display:none}
    .km-stage.has-feed img{display:block}
    .km-off{position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:8px;color:#9fb3c8;text-align:center;padding:20px}
    .km-stage.has-feed .km-off{display:none}
    .km-off svg{width:46px;height:46px;stroke:currentColor;fill:none;stroke-width:1.5;stroke-linecap:round;stroke-linejoin:round;opacity:.8}
    .km-off b{color:#fff;font-size:16px}
    .km-off span{font-size:13px;max-width:340px;line-height:1.5}
    .km-badge{position:absolute;top:12px;left:12px;padding:5px 12px;border-radius:999px;background:rgba(0,0,0,.6);color:#fff;font-size:12.5px;font-weight:700;z-index:2}
    .km-foot{display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;margin-top:14px;font-size:13px;color:var(--muted)}

    .km-controls{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-top:12px;padding-top:12px;border-top:1px solid var(--line, #e2e8f0)}
    .km-btn-in{background:#0d9488;color:#fff;border:none;padding:6px 14px;border-radius:8px;font-size:12.5px;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:6px;transition:opacity .2s}
    .km-btn-in:hover{opacity:.9}
    .km-btn-out{background:#4f46e5;color:#fff;border:none;padding:6px 14px;border-radius:8px;font-size:12.5px;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:6px;transition:opacity .2s}
    .km-btn-out:hover{opacity:.9}
    .km-btn-rst{background:#f1f5f9;color:#334155;border:1px solid #cbd5e1;padding:6px 14px;border-radius:8px;font-size:12.5px;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:6px;transition:background .2s}
    .km-btn-rst:hover{background:#e2e8f0}

    .km-side{display:flex;flex-direction:column;gap:20px}
    .km-card h3{margin:0 0 14px;font-size:15px}
    .km-state{padding:14px 16px;border-radius:12px;background:#f1f4f8;margin-bottom:14px}
    .km-state small{display:block;font-size:12px;color:var(--muted);margin-bottom:4px}
    .km-state b{font-size:17px}
    .km-state.buka{background:#e3f6ec;color:#146c43}
    .km-state.tolak{background:#fbe8e8;color:#9b2c2c}
    .km-state.tunggu{background:#fff6e5;color:#7a5a12}

    .km-row{display:flex;align-items:center;gap:12px;padding:12px 0;border-top:1px solid var(--line)}
    .km-row:first-of-type{border-top:0}
    .km-ic{flex:none;width:36px;height:36px;border-radius:10px;display:grid;place-items:center;background:#eef1f5;color:var(--muted)}
    .km-ic svg{width:18px;height:18px;stroke:currentColor;fill:none;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round}
    .km-ic.ok{background:#e3f6ec;color:var(--green)}
    .km-ic.bad{background:#fbe8e8;color:var(--red)}
    .km-row div small{display:block;font-size:12px;color:var(--muted)}
    .km-row div b{font-size:14.5px}
    .km-plate{display:inline-block;padding:2px 9px;border-radius:6px;border:1.5px solid #1b2a3a;font-weight:800;letter-spacing:.08em;background:#fff}

    .km-log{max-height:260px;overflow:auto}
    .km-log div{display:flex;gap:10px;padding:8px 0;border-top:1px solid var(--line);font-size:13px}
    .km-log div:first-child{border-top:0}
    .km-log time{flex:none;color:var(--muted);font-variant-numeric:tabular-nums}
</style>

<div class="km-top">
    <div style="font-size:13.5px;color:var(--muted)">
        Status AI: <strong>Layanan Deteksi Parkir</strong>
    </div>
    <div class="km-live"><span class="km-dot" id="kmDot"></span><span id="kmLive">Menghubungkan...</span></div>
</div>

<div class="km-grid">
    {{-- Video --}}
    <div class="card">
        <div class="km-stage" id="kmStage">
            <span class="km-badge" id="kmBadge">SIAGA</span>
            <img id="kmFeed" alt="Siaran kamera deteksi">
            <div class="km-off">
                <svg viewBox="0 0 24 24"><path d="M4 8h3l1.5-2h7L17 8h3v11H4z"/><circle cx="12" cy="13" r="3.2"/><path d="M3 3l18 18"/></svg>
                <b>Kamera belum tersambung</b>
                <span>Siaran langsung dari <code>test_akses_parkir.py</code> akan otomatis muncul di sini begitu script dijalankan.</span>
            </div>
        </div>

        <div class="km-foot">
            <span id="kmSrc">Sumber stream: {{ $streamUrl }}</span>
            <button type="button" class="btn btn-outline" id="kmReload" style="padding:5px 12px;font-size:12.5px">Muat ulang kamera</button>
        </div>

        {{-- Kontrol Cepat Gerbang --}}
        <div class="km-controls">
            <button type="button" class="km-btn-in" id="btnTrigMasuk" title="Simulasi kendaraan tiba di Pintu Masuk">
                🚗 Trigger Pintu Masuk
            </button>
            <button type="button" class="km-btn-out" id="btnTrigKeluar" title="Simulasi kendaraan tiba di Pintu Keluar">
                🚙 Trigger Pintu Keluar
            </button>
            <button type="button" class="km-btn-rst" id="btnResetGate" title="Kembalikan status gerbang ke SIAGA">
                🔄 Reset Siaga
            </button>
            <span id="trigNotice" style="font-size:12px;color:var(--muted);margin-left:auto"></span>
        </div>
    </div>

    {{-- Panel hasil --}}
    <div class="km-side">
        <div class="card km-card">
            <h3>Status gerbang</h3>
            <div class="km-state" id="kmState"><small>Status</small><b id="kmStatus">-</b></div>

            <div class="km-row">
                <span class="km-ic" id="kmIcFace"><svg viewBox="0 0 24 24"><circle cx="12" cy="9" r="3.6"/><path d="M4.5 20c.9-3.6 3.9-5.6 7.5-5.6s6.6 2 7.5 5.6"/></svg></span>
                <div><small>Wajah</small><b id="kmFace">-</b></div>
            </div>
            <div class="km-row">
                <span class="km-ic" id="kmIcPlate"><svg viewBox="0 0 24 24"><rect x="3" y="7" width="18" height="10" rx="2"/><path d="M7 12h10"/></svg></span>
                <div><small>Plat nomor</small><b id="kmPlate">-</b></div>
            </div>
            <div class="km-row">
                <span class="km-ic" id="kmIcTrig"><svg viewBox="0 0 24 24"><path d="M13 2 4 14h7l-1 8 9-12h-7z"/></svg></span>
                <div><small>Sensor kendaraan</small><b id="kmTrig">-</b></div>
            </div>
        </div>

        <div class="card km-card">
            <h3>Aktivitas langsung</h3>
            <div class="km-log" id="kmLog"><div style="color:var(--muted)">Menunggu perubahan status...</div></div>
        </div>
    </div>
</div>

<script>
(function () {
    const STATUS_URL = @json(url('/api/parking/verification-status'));
    const TRIGGER_URL = @json(url('/api/parking/trigger'));
    const RESET_URL = @json(url('/api/parking/reset'));
    const STREAM_URL = @json($streamUrl);
    const NAMES = @json($gates);

    const $ = id => document.getElementById(id);
    const stage = $('kmStage'), feed = $('kmFeed');
    let lastKey = '';
    let streamRetryTimer = null;

    // ===== Video Auto-Connect & Auto-Reconnect =====
    function loadFeed() {
        if (streamRetryTimer) {
            clearTimeout(streamRetryTimer);
            streamRetryTimer = null;
        }

        feed.onload = () => {
            stage.classList.add('has-feed');
        };

        feed.onerror = () => {
            stage.classList.remove('has-feed');
            // Coba sambungkan otomatis setiap 2 detik jika kamera belum online
            streamRetryTimer = setTimeout(loadFeed, 2000);
        };

        feed.src = STREAM_URL + '?t=' + Date.now();
    }

    // Interval cek jika frame MJPEG sudah masuk (beberapa browser tidak memicu onload pada MJPEG)
    setInterval(() => {
        if (feed.naturalWidth > 0 && !stage.classList.contains('has-feed')) {
            stage.classList.add('has-feed');
        }
    }, 800);

    // ===== Trigger Gerbang =====
    async function kirimTrigger(gate) {
        const notice = $('trigNotice');
        if (notice) notice.textContent = 'Memicu ' + (NAMES[gate] || gate) + '...';
        try {
            const res = await fetch(TRIGGER_URL, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json'
                },
                body: JSON.stringify({ gate: gate })
            });
            const j = await res.json();
            if (notice) {
                notice.textContent = (NAMES[gate] || gate) + ' dipicu!';
                setTimeout(() => { notice.textContent = ''; }, 3500);
            }
            poll();
        } catch (e) {
            if (notice) notice.textContent = 'Gagal trigger: ' + e.message;
        }
    }

    async function kirimReset() {
        const notice = $('trigNotice');
        if (notice) notice.textContent = 'Mereset gerbang...';
        try {
            await fetch(RESET_URL, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json'
                },
                body: JSON.stringify({ gate: 'gate_masuk' })
            });
            if (notice) {
                notice.textContent = 'Gerbang direset ke SIAGA';
                setTimeout(() => { notice.textContent = ''; }, 3000);
            }
            poll();
        } catch (e) {
            if (notice) notice.textContent = 'Gagal reset';
        }
    }

    $('btnTrigMasuk')?.addEventListener('click', () => kirimTrigger('gate_masuk'));
    $('btnTrigKeluar')?.addEventListener('click', () => kirimTrigger('gate_keluar'));
    $('btnResetGate')?.addEventListener('click', kirimReset);

    // ===== Status gerbang =====
    function setIcon(id, state) { $(id).className = 'km-ic ' + (state || ''); }

    function render(d) {
        const izin = d.izin || 'SIAGA';
        const gateName = (d.gate && NAMES[d.gate]) ? NAMES[d.gate] : 'Gerbang';

        // badge: tampilkan gerbang yang sedang aktif, atau SIAGA kalau idle
        $('kmBadge').textContent = izin === 'SIAGA' ? 'SIAGA' : gateName.toUpperCase();

        $('kmStatus').textContent = d.status || izin;
        $('kmState').className = 'km-state ' + (izin === 'BUKA' ? 'buka' : izin === 'TOLAK' ? 'tolak' : izin === 'MENUNGGU' ? 'tunggu' : '');

        $('kmFace').textContent = d.face_match ? 'Cocok · member #' + d.face_member_id : (izin === 'SIAGA' ? '-' : 'Belum cocok');
        setIcon('kmIcFace', d.face_match ? 'ok' : (izin === 'TOLAK' ? 'bad' : ''));

        if (d.plate_detected) {
            $('kmPlate').innerHTML = '<span class="km-plate">' + d.plate_detected + '</span>' + (d.plate_match ? ' · cocok' : '');
        } else {
            $('kmPlate').textContent = '-';
        }
        setIcon('kmIcPlate', d.plate_match ? 'ok' : (d.plate_detected && izin === 'TOLAK' ? 'bad' : ''));

        $('kmTrig').textContent = d.trigger ? 'Kendaraan terdeteksi' : 'Tidak ada kendaraan';
        setIcon('kmIcTrig', d.trigger ? 'ok' : '');

        const key = [d.gate, d.status, d.izin, d.face_match, d.plate_match, d.plate_detected].join('|');
        if (key !== lastKey) {
            if (lastKey !== '') addLog(gateName + ': ' + (d.status || izin));
            lastKey = key;
        }
    }

    function addLog(text) {
        const log = $('kmLog');
        if (log.firstElementChild && log.firstElementChild.style.color) log.innerHTML = '';
        const row = document.createElement('div');
        row.innerHTML = '<time>' + new Date().toLocaleTimeString('id-ID') + '</time><span></span>';
        row.lastChild.textContent = text;
        log.prepend(row);
        while (log.children.length > 30) log.lastChild.remove();
    }

    async function poll() {
        try {
            const r = await fetch(STATUS_URL, { headers: { Accept: 'application/json' } });
            const j = await r.json();
            if (j && j.data) {
                render(j.data);
                $('kmDot').className = 'km-dot on';
                $('kmLive').textContent = 'Terhubung · ' + new Date().toLocaleTimeString('id-ID');
                return;
            }
            throw new Error('kosong');
        } catch (e) {
            $('kmDot').className = 'km-dot off';
            $('kmLive').textContent = 'Server tidak merespons';
        }
    }

    $('kmReload').addEventListener('click', loadFeed);

    loadFeed();
    poll();
    setInterval(poll, 1000);
})();
</script>
@endsection
