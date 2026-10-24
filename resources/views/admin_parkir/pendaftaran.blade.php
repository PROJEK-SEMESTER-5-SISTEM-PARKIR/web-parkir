@extends('layouts.parkir')

@section('title', 'Pendaftaran Member')
@section('heading', 'Pendaftaran Member')
@section('subheading', 'Daftarkan member baru beserta plat nomor, wajah, dan paket langganan')

@section('content')

{{-- ================= HASIL: tampil sekali setelah member berhasil disimpan ================= --}}
@if (session('created'))
    @php $c = session('created'); @endphp
    <div class="pd-cred" role="status">
        <div class="pd-cred-head">
            <svg viewBox="0 0 24 24" fill="none" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="m8 12.5 2.8 2.8L16 9.8"/></svg>
            <div>
                <strong>Member berhasil didaftarkan</strong>
                <span>{{ $c['name'] }} &middot; {{ implode(', ', $c['vehicles']) }}</span>
            </div>
        </div>

        @if (!empty($c['plan']))
            <div class="pd-cred-plan">
                Paket <b>{{ $c['plan'] }}</b> berlaku {{ $c['start'] ?? '-' }} sampai {{ $c['end'] ?? '-' }}.
            </div>
        @endif

        <div class="pd-cred-grid">
            <div><small>Login aplikasi (No. HP)</small><b>{{ $c['phone'] }}</b></div>
            <div><small>Kata sandi sementara</small><b class="pd-cred-pass">{{ $c['password'] }}</b></div>
            <button type="button" class="pd-cred-copy" id="pdCredCopy"
                    data-copy="No. HP: {{ $c['phone'] }}&#10;Kata sandi sementara: {{ $c['password'] }}">Salin</button>
        </div>

        <p>Kata sandi ini hanya tampil sekali. Berikan ke member; ia akan diminta menggantinya saat pertama kali masuk ke aplikasi.</p>
    </div>
@endif

<style>
    .pd{
        --pd-line: var(--line, #e3e7ec);
        --pd-muted: var(--muted, #6b7685);
        --pd-accent: var(--accent, #2563eb);
        --pd-red: var(--red, #d64545);
        --pd-soft: #f5f7fa;
        --pd-radius: 14px;
    }
    .pd *{box-sizing:border-box}

    /* ---------- Kartu hasil (kata sandi sementara) ---------- */
    .pd-cred{margin:0 0 20px;padding:20px 24px;border-radius:14px;background:#effaf5;border:1px solid #bfe8d4}
    .pd-cred-head{display:flex;align-items:center;gap:12px;margin-bottom:16px}
    .pd-cred-head svg{width:30px;height:30px;flex:none;stroke:#15803d}
    .pd-cred-head strong{display:block;font-size:15px;color:#14532d}
    .pd-cred-head span{font-size:13px;color:#3f6b52}
    .pd-cred-plan{margin:-4px 0 16px;font-size:13.5px;color:#14532d}
    .pd-cred-grid{display:grid;grid-template-columns:1fr 1fr auto;gap:14px;align-items:end}
    @media (max-width:640px){.pd-cred-grid{grid-template-columns:1fr}}
    .pd-cred-grid small{display:block;font-size:12px;color:#3f6b52;margin-bottom:4px}
    .pd-cred-grid b{display:block;padding:10px 12px;border-radius:10px;background:#fff;border:1px solid #bfe8d4;font-size:16px;letter-spacing:.04em}
    .pd-cred-pass{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace}
    .pd-cred-copy{height:42px;padding:0 18px;border-radius:10px;border:1px solid #15803d;background:#15803d;color:#fff;font:inherit;font-size:14px;font-weight:600;cursor:pointer}
    .pd-cred-copy:hover{filter:brightness(.94)}
    .pd-cred p{margin:14px 0 0;font-size:12.5px;color:#3f6b52}

    /* ---------- Layout ---------- */
    .pd-grid{display:grid;grid-template-columns:minmax(0,1.15fr) minmax(0,1fr);gap:24px;align-items:start}
    @media (max-width:960px){.pd-grid{grid-template-columns:1fr}}
    .pd-side{position:sticky;top:20px}
    @media (max-width:960px){.pd-side{position:static}}

    .pd-panel{background:#fff;border:1px solid var(--pd-line);border-radius:var(--pd-radius);padding:24px}
    .pd-panel + .pd-panel{margin-top:20px}
    .pd-head{margin:0 0 18px}
    .pd-head h3{margin:0;font-size:16px;font-weight:700}
    .pd-head p{margin:4px 0 0;font-size:13px;color:var(--pd-muted)}

    /* ---------- Info akun ---------- */
    .pd-info{list-style:none;margin:0;padding:0;display:flex;flex-direction:column;gap:10px}
    .pd-info li{display:flex;gap:12px;font-size:13.5px;line-height:1.45}
    .pd-info b{flex:none;width:130px;font-weight:600}
    .pd-info span{color:var(--pd-muted)}

    /* ---------- Form fields ---------- */
    .pd-row{display:grid;grid-template-columns:1fr 1fr;gap:14px}
    @media (max-width:560px){.pd-row{grid-template-columns:1fr}}
    .pd .field{margin:0 0 14px}
    .pd .field:last-child{margin-bottom:0}
    .pd .field label{display:block;font-size:13px;font-weight:600;margin-bottom:6px}
    .pd .field .opt{font-weight:400;color:var(--pd-muted)}
    .pd .field .hint{margin-top:6px;font-size:12px;color:var(--pd-muted)}
    .pd .field input[type=text],
    .pd .field input[type=email],
    .pd .field input[type=date],
    .pd .field input[type=number],
    .pd .field input:not([type]),
    .pd .field select,
    .pd-plate input[type=text],
    .pd-plate select{
        width:100%;height:42px;padding:0 12px;border:1px solid var(--pd-line);border-radius:10px;
        background:#fff;font:inherit;font-size:14px;color:inherit;transition:border-color .15s, box-shadow .15s
    }
    .pd .field input:focus, .pd .field select:focus, .pd-plate input:focus, .pd-plate select:focus{
        outline:none;border-color:var(--pd-accent);box-shadow:0 0 0 3px color-mix(in srgb, var(--pd-accent) 18%, transparent)
    }
    .pd .err{color:var(--pd-red);font-size:12.5px;margin-top:6px}

    /* ---------- Plat nomor ---------- */
    .pd-plates{display:flex;flex-direction:column;gap:10px}
    .pd-plate{display:grid;grid-template-columns:minmax(0,1fr) 38px;gap:8px;align-items:center}
    .pd-plate input[type=text]{text-transform:uppercase;letter-spacing:.06em;font-weight:600}
    .pd-plate .pd-del{
        width:38px;height:42px;border:1px solid var(--pd-line);border-radius:10px;background:#fff;
        color:var(--pd-muted);font-size:18px;line-height:1;cursor:pointer;transition:all .15s
    }
    .pd-plate .pd-del:hover{background:#fdeeee;border-color:#f3c4c4;color:var(--pd-red)}
    .pd-plate .pd-del.is-hidden{visibility:hidden}
    .pd-add{
        margin-top:10px;display:inline-flex;align-items:center;gap:6px;background:none;border:0;padding:6px 2px;
        color:var(--pd-accent);font:inherit;font-size:13.5px;font-weight:600;cursor:pointer
    }
    .pd-add:hover{text-decoration:underline}

    /* ---------- Ketersediaan slot ---------- */
    .pd-slots{display:flex;gap:8px;flex-wrap:wrap;margin:-4px 0 14px}
    .pd-slot{padding:5px 12px;border-radius:999px;background:var(--pd-soft);border:1px solid var(--pd-line);font-size:12.5px;color:var(--pd-muted)}
    .pd-slot b{color:#1f2733}
    .pd-slot small{margin-left:4px;font-size:11.5px}
    .pd-slot.is-full{background:#fdeeee;border-color:#f3c4c4;color:var(--pd-red)}
    .pd-slot.is-full b{color:var(--pd-red)}

    /* ---------- Ringkasan paket ---------- */
    .pd-sum{
        display:grid;grid-template-columns:1fr 1fr;gap:14px;margin:4px 0 16px;padding:14px 16px;border-radius:12px;
        background:color-mix(in srgb, var(--pd-accent) 7%, #fff);border:1px solid color-mix(in srgb, var(--pd-accent) 22%, #fff)
    }
    @media (max-width:560px){.pd-sum{grid-template-columns:1fr}}
    .pd-sum small{display:block;font-size:12px;color:var(--pd-muted);margin-bottom:3px}
    .pd-sum b{font-size:15px}
    .pd-sum .full{grid-column:1/-1}
    .pd-sum span{font-size:13px;color:var(--pd-muted);line-height:1.5}
    .pd-empty-plan{margin:0 0 14px;padding:10px 12px;border-radius:10px;background:#fff6e5;border:1px solid #f3dca8;font-size:12.5px;color:#7a5a12}

    /* ---------- Panggung foto ---------- */
    .pd-stage{
        position:relative;aspect-ratio:4/3;width:100%;border-radius:var(--pd-radius);overflow:hidden;
        background:var(--pd-soft);border:2px dashed var(--pd-line);display:flex;align-items:center;justify-content:center;
        transition:border-color .15s, background .15s
    }
    .pd-stage.is-filled,.pd-stage.is-live{border-style:solid;border-color:transparent;background:#0e1116}
    .pd-stage.has-error{border-color:var(--pd-red)}

    .pd-empty{text-align:center;padding:20px;color:var(--pd-muted)}
    .pd-empty svg{width:44px;height:44px;margin-bottom:10px;stroke:currentColor;opacity:.7}
    .pd-empty strong{display:block;color:#1f2733;font-size:14px;margin-bottom:4px}
    .pd-empty span{font-size:12.5px}

    .pd-video,.pd-shot{position:absolute;inset:0;width:100%;height:100%;object-fit:cover;display:none}
    .pd-video{transform:scaleX(-1)} /* cermin hanya untuk tampilan, hasil foto tidak ter-mirror */
    .pd-stage.is-live .pd-video{display:block}
    .pd-stage.is-filled .pd-shot{display:block}
    .pd-shot.is-mirror{transform:scaleX(-1)} /* hanya preview; file yang dikirim tetap tidak ter-mirror */
    .pd-stage.is-live .pd-empty,.pd-stage.is-filled .pd-empty{display:none}

    /* Panduan posisi wajah + petunjuk gerakan */
    .pd-guide{position:absolute;inset:0;display:none;align-items:center;justify-content:center;pointer-events:none}
    .pd-stage.is-live .pd-guide{display:flex}
    .pd-guide i{
        width:46%;aspect-ratio:3/4;border-radius:50%;border:2px solid rgba(255,255,255,.85);
        box-shadow:0 0 0 999px rgba(0,0,0,.35);transition:border-color .15s
    }
    .pd-guide em{
        position:absolute;bottom:14px;left:12px;right:12px;text-align:center;font-style:normal;
        font-size:14px;font-weight:600;color:#fff;text-shadow:0 1px 3px rgba(0,0,0,.7)
    }
    /* wajah tidak terdeteksi: bingkai & teks jadi merah */
    .pd-stage.no-face .pd-guide i{border-color:#ff5c5c;border-style:dashed}
    .pd-stage.no-face .pd-guide em{background:rgba(214,69,69,.92);padding:6px 10px;border-radius:8px;text-shadow:none}

    .pd-count{
        position:absolute;top:12px;right:12px;display:none;padding:4px 10px;border-radius:999px;
        background:rgba(0,0,0,.55);color:#fff;font-size:13px;font-weight:700;letter-spacing:.03em
    }
    .pd-stage.is-capturing .pd-count{display:block}

    /* Bar kemajuan pengambilan foto */
    .pd-progress{display:none;height:6px;margin-top:12px;border-radius:999px;background:var(--pd-line);overflow:hidden}
    .pd-progress.is-on{display:block}
    .pd-progress i{display:block;height:100%;width:0;background:var(--pd-accent);transition:width .25s}

    /* Peringatan (wajah sudah terdaftar, dll.) */
    .pd-alert{
        display:none;margin:0 0 14px;padding:12px 14px;border-radius:10px;
        background:#fdeeee;border:1px solid #f3c4c4;color:#9b2c2c;font-size:13.5px;line-height:1.5
    }
    .pd-alert.is-on{display:block}
    .pd-alert b{font-weight:700}

    /* Tombol di bawah panggung */
    .pd-actions{display:flex;gap:10px;margin-top:14px;flex-wrap:wrap}
    .pd-btn{
        flex:1;min-width:130px;height:42px;display:inline-flex;align-items:center;justify-content:center;gap:8px;
        border-radius:10px;border:1px solid var(--pd-line);background:#fff;color:inherit;font:inherit;font-size:14px;font-weight:600;
        cursor:pointer;transition:all .15s
    }
    .pd-btn:hover{border-color:#c9d0d9;background:var(--pd-soft)}
    .pd-btn.primary{background:var(--pd-accent);border-color:var(--pd-accent);color:#fff}
    .pd-btn.primary:hover{filter:brightness(.94)}
    .pd-btn[hidden]{display:none}
    .pd-btn svg{width:18px;height:18px;stroke:currentColor}

    .pd-msg{margin-top:12px;font-size:12.5px;color:var(--pd-muted)}
    .pd-msg.is-error{color:var(--pd-red)}
    .pd-tips{margin:16px 0 0;padding:12px 14px;border-radius:10px;background:var(--pd-soft);font-size:12.5px;color:var(--pd-muted);line-height:1.55}

    /* input file disembunyikan tapi tetap ada di form (diisi lewat kamera) */
    .pd-file{position:absolute;width:1px;height:1px;opacity:0;pointer-events:none}

    /* ---------- Footer ---------- */
    .pd-foot{display:flex;justify-content:flex-end;gap:10px;margin-top:24px}
    .pd-foot .btn{min-width:130px;justify-content:center}
</style>

<form class="pd" id="pdForm" method="POST" action="{{ route('parkir.pendaftaran.store') }}" enctype="multipart/form-data" novalidate>
    @csrf

    <div class="pd-grid">
        {{-- ================= KIRI: data member, kendaraan, paket ================= --}}
        <div>
            <section class="pd-panel">
                <div class="pd-head">
                    <h3>Data member</h3>
                    <p>Informasi dasar untuk identitas member.</p>
                </div>

                <div class="field">
                    <label for="name">Nama lengkap</label>
                    <input id="name" name="name" value="{{ old('name') }}" autocomplete="off" required>
                    @error('name') <div class="err">{{ $message }}</div> @enderror
                </div>

                <div class="pd-row">
                    <div class="field">
                        <label for="phone">No. HP <span class="opt">(untuk login aplikasi)</span></label>
                        <input id="phone" name="phone" value="{{ old('phone') }}" inputmode="tel" autocomplete="off" required>
                        @error('phone') <div class="err">{{ $message }}</div> @enderror
                    </div>
                    <div class="field">
                        <label for="email">Email <span class="opt">(opsional)</span></label>
                        <input id="email" type="email" name="email" value="{{ old('email') }}" autocomplete="off">
                        @error('email') <div class="err">{{ $message }}</div> @enderror
                    </div>
                </div>
            </section>

            <section class="pd-panel">
                <div class="pd-head">
                    <h3>Kendaraan</h3>
                    <p>Satu member bisa memiliki lebih dari satu kendaraan.</p>
                </div>

                {{-- Ketersediaan slot per jenis kendaraan (dari SlotService) --}}
                @if (!empty($slots))
                    <div class="pd-slots" id="pdSlots">
                        @foreach (['mobil' => 'Mobil'] as $jenis => $label)
                            @php $sl = $slots[$jenis]; @endphp
                            <span class="pd-slot {{ $sl['sisa'] === 0 ? 'is-full' : '' }}" data-jenis="{{ $jenis }}" data-sisa="{{ $sl['sisa'] }}">
                                {{ $label }}: <b>{{ $sl['sisa'] === 0 ? 'penuh' : 'sisa '.$sl['sisa'] }}</b> <small>{{ $sl['terisi'] }}/{{ $sl['kapasitas'] }}</small>
                            </span>
                        @endforeach
                    </div>
                    <div class="err" id="slotWarn" hidden></div>
                @endif

                <div class="pd-plates" id="plateList">
                    <div class="pd-plate">
                        <input type="text" name="plates[]" placeholder="B 1234 ABC" autocomplete="off" required>
                        <input type="hidden" name="vehicle_types[]" value="mobil">
                        <button type="button" class="pd-del is-hidden" aria-label="Hapus plat">&times;</button>
                    </div>
                </div>

                <button type="button" class="pd-add" id="btnAddPlate">+ Tambah kendaraan</button>
                @error('plates.*') <div class="err">{{ $message }}</div> @enderror
                @error('plates') <div class="err">{{ $message }}</div> @enderror
            </section>

            {{-- ================= PAKET LANGGANAN ================= --}}
            <section class="pd-panel">
                <div class="pd-head">
                    <h3>Paket langganan</h3>
                    <p>Member langsung aktif setelah data disimpan. Tagihan dihitung dari jenis tiap kendaraan.</p>
                </div>

                @if (empty($plans) || count($plans) === 0)
                    <div class="pd-empty-plan">Belum ada paket aktif yang bisa dipilih. Tambahkan paket di data master terlebih dahulu.</div>
                @endif

                <div class="pd-row">
                    <div class="field">
                        <label for="plan_id">Paket</label>
                        <select id="plan_id" name="plan_id" required>
                            <option value="">Pilih paket</option>
                            @foreach (($plans ?? []) as $plan)
                                <option value="{{ $plan->id }}"
                                    data-days="{{ $plan->duration_days }}"
                                    data-motor="{{ (int) $plan->price_motor }}"
                                    data-mobil="{{ (int) $plan->price_mobil }}"
                                    {{ (string) old('plan_id') === (string) $plan->id ? 'selected' : '' }}>
                                    {{ $plan->name }} &middot; {{ $plan->duration_days }} hari{{ $plan->is_popular ? ' (rekomendasi)' : '' }}
                                </option>
                            @endforeach
                        </select>
                        @error('plan_id') <div class="err">{{ $message }}</div> @enderror
                    </div>

                    <div class="field">
                        <label for="start_date">Tanggal mulai</label>
                        <input id="start_date" type="date" name="start_date" value="{{ old('start_date', now()->toDateString()) }}" required>
                        @error('start_date') <div class="err">{{ $message }}</div> @enderror
                    </div>
                </div>

                <div class="pd-sum" id="pdSum" aria-live="polite">
                    <div><small>Masa berlaku</small><b id="sumRange">-</b></div>
                    <div><small>Total tagihan</small><b id="sumTotal">-</b></div>
                    <div class="full"><small>Rincian</small><span id="sumDetail">Pilih paket untuk melihat tagihan.</span></div>
                </div>

                <div class="pd-row">
                    <div class="field">
                        <label for="payment_method">Metode pembayaran</label>
                        <select id="payment_method" name="payment_method">
                            <option value="tunai" {{ old('payment_method', 'tunai') === 'tunai' ? 'selected' : '' }}>Tunai</option>
                            <option value="transfer" {{ old('payment_method') === 'transfer' ? 'selected' : '' }}>Transfer</option>
                        </select>
                        @error('payment_method') <div class="err">{{ $message }}</div> @enderror
                    </div>

                    <div class="field">
                        <label for="amount_paid">Nominal dibayar <span class="opt">(Rp)</span></label>
                        <input id="amount_paid" type="number" name="amount_paid" min="0" step="1" inputmode="numeric" value="{{ old('amount_paid') }}">
                        @error('amount_paid') <div class="err">{{ $message }}</div> @enderror
                    </div>
                </div>
                <div class="field"><div class="hint">Nominal terisi otomatis sesuai total tagihan. Ubah kalau yang dibayar berbeda.</div></div>
            </section>

            <section class="pd-panel">
                <div class="pd-head">
                    <h3>Akun aplikasi</h3>
                    <p>Akun dibuat otomatis bersamaan dengan pendaftaran ini.</p>
                </div>

                <ul class="pd-info">
                    <li><b>Login</b><span>Memakai No. HP yang diisi di atas</span></li>
                    <li><b>Kata sandi</b><span>Dibuat otomatis dan tampil sekali setelah disimpan</span></li>
                    <li><b>Masuk pertama kali</b><span>Member diminta mengganti kata sandi</span></li>
                </ul>
            </section>
        </div>

        {{-- ================= KANAN: foto wajah (30 sampel dari kamera) ================= --}}
        <aside class="pd-side">
            <section class="pd-panel">
                <div class="pd-head">
                    <h3>Foto wajah</h3>
                    <p>Kamera mengambil 30 foto yang wajahnya terdeteksi, untuk melatih pengenalan wajah di gerbang.</p>
                </div>

                {{-- Peringatan: wajah sudah terdaftar, layanan error, dll. --}}
                <div class="pd-alert" id="faceAlert" role="alert"></div>
                @error('photos')
                    <div class="pd-alert is-on" role="alert">{{ $message }}</div>
                @enderror
                @error('photos.*')
                    <div class="pd-alert is-on" role="alert">{{ $message }}</div>
                @enderror

                <div class="pd-stage" id="stage">
                    {{-- Kondisi kosong --}}
                    <div class="pd-empty">
                        <svg viewBox="0 0 24 24" fill="none" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <circle cx="12" cy="9" r="3.6"/><path d="M4.5 20c.9-3.6 3.9-5.6 7.5-5.6s6.6 2 7.5 5.6"/>
                        </svg>
                        <strong>Belum ada foto</strong>
                        <span>Buka kamera lalu mulai pengambilan 30 foto</span>
                    </div>

                    {{-- Kondisi kamera menyala --}}
                    <video class="pd-video" id="camVideo" autoplay playsinline muted></video>
                    <div class="pd-guide"><i></i><em id="guideText">Posisikan wajah di dalam bingkai</em></div>
                    <div class="pd-count" id="countBadge">0/30</div>

                    {{-- Kondisi foto sudah ada (pratinjau frame tengah) --}}
                    <img class="pd-shot" id="shot" alt="Pratinjau foto wajah member">
                </div>
                <canvas id="camCanvas" hidden></canvas>

                <div class="pd-progress" id="progress"><i id="progressBar"></i></div>

                <div class="pd-actions">
                    <button type="button" class="pd-btn primary" id="btnOpen">
                        <svg viewBox="0 0 24 24" fill="none" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 8h3l1.5-2h7L17 8h3v11H4z"/><circle cx="12" cy="13" r="3.2"/></svg>
                        Buka kamera
                    </button>
                    <button type="button" class="pd-btn primary" id="btnSnap" hidden>Mulai ambil 30 foto</button>
                    <button type="button" class="pd-btn" id="btnCancel" hidden>Batal</button>
                    <button type="button" class="pd-btn" id="btnRetake" hidden>Ulangi foto</button>
                </div>

                {{-- Diisi otomatis oleh JavaScript dengan 30 frame dari kamera --}}
                <input class="pd-file" type="file" name="photos[]" id="photoInput" accept="image/*" multiple tabindex="-1">

                <div class="pd-msg" id="msg" role="status"></div>

                <div class="pd-tips">
                    Pastikan pencahayaan cukup, hanya ada satu orang di depan kamera, dan ikuti petunjuk di layar:
                    hadap lurus, tengok pelan ke kiri dan kanan, lalu angkat dan tundukkan kepala sedikit.
                    Foto hanya diambil saat wajah terdeteksi. Jika bingkai berwarna merah, perbaiki posisi atau pencahayaan.
                    Proses pengambilan sekitar 15&ndash;20 detik.
                </div>
            </section>
        </aside>
    </div>

    <div class="pd-foot">
        <a href="{{ route('parkir.dashboard') }}" class="btn btn-outline">Batal</a>
        <button type="submit" class="btn btn-primary" id="btnSubmit">Simpan member</button>
    </div>
</form>

<script>
(function () {
    // ================= Tombol salin kata sandi (hanya ada setelah member berhasil disimpan) =================
    const credCopy = document.getElementById('pdCredCopy');
    if (credCopy) {
        credCopy.addEventListener('click', () => {
            navigator.clipboard.writeText(credCopy.dataset.copy).then(() => {
                credCopy.textContent = 'Tersalin';
                setTimeout(() => { credCopy.textContent = 'Salin'; }, 1500);
            });
        });
    }

    // ================= Plat nomor =================
    const plateList = document.getElementById('plateList');

    function syncDeleteButtons() {
        const rows = plateList.querySelectorAll('.pd-plate');
        rows.forEach(r => r.querySelector('.pd-del').classList.toggle('is-hidden', rows.length < 2));
    }

    document.getElementById('btnAddPlate').addEventListener('click', () => {
        const row = document.createElement('div');
        row.className = 'pd-plate';
        row.innerHTML = `
            <input type="text" name="plates[]" placeholder="B 1234 ABC" autocomplete="off">
            <input type="hidden" name="vehicle_types[]" value="mobil">
            <button type="button" class="pd-del" aria-label="Hapus plat">&times;</button>`;
        plateList.appendChild(row);
        syncDeleteButtons();
        row.querySelector('input').focus();
    });

    plateList.addEventListener('click', e => {
        const btn = e.target.closest('.pd-del');
        if (!btn) return;
        btn.closest('.pd-plate').remove();
        syncDeleteButtons();
    });

    // ================= Paket langganan =================
    const planSel   = document.getElementById('plan_id');
    const startIn   = document.getElementById('start_date');
    const amountIn  = document.getElementById('amount_paid');
    const sumRange  = document.getElementById('sumRange');
    const sumTotal  = document.getElementById('sumTotal');
    const sumDetail = document.getElementById('sumDetail');

    // Kalau kolom nominal sudah berisi (misalnya setelah validasi gagal), jangan ditimpa otomatis.
    let amountTouched = amountIn.value !== '';
    amountIn.addEventListener('input', () => { amountTouched = true; });

    const rupiah  = n => 'Rp ' + new Intl.NumberFormat('id-ID').format(n);
    const tanggal = d => d.toLocaleDateString('id-ID', { day: 'numeric', month: 'short', year: 'numeric' });

    function hitungPaket() {
        const opt   = planSel.selectedOptions[0];
        const hari  = Number(opt && opt.dataset.days  || 0);
        const harga = { motor: Number(opt && opt.dataset.motor || 0), mobil: Number(opt && opt.dataset.mobil || 0) };

        // Masa berlaku = mulai + durasi hari - 1 (hari terakhir ikut dihitung)
        const [y, m, d] = (startIn.value || '').split('-').map(Number);
        if (hari && y && m && d) {
            const mulai = new Date(y, m - 1, d);
            const akhir = new Date(y, m - 1, d + hari - 1);
            sumRange.textContent = tanggal(mulai) + ' \u2013 ' + tanggal(akhir);
        } else {
            sumRange.textContent = '-';
        }

        // Tagihan = jumlah harga tiap kendaraan yang plat-nya sudah diisi
        const jumlah = { motor: 0, mobil: 0 };
        plateList.querySelectorAll('.pd-plate').forEach(row => {
            if (!row.querySelector('input[name="plates[]"]').value.trim()) return;
            const jenis = row.querySelector('input[name="vehicle_types[]"]').value;
            jumlah[jenis === 'motor' ? 'motor' : 'mobil']++;
        });

        // Peringatan slot: jumlah kendaraan yang diisi dibanding sisa slot
        const slotWarn = document.getElementById('slotWarn');
        if (slotWarn) {
            const kurang = [];
            document.querySelectorAll('#pdSlots [data-jenis]').forEach(el => {
                const sisa = Number(el.dataset.sisa);
                if (jumlah[el.dataset.jenis] > sisa) {
                    kurang.push(el.dataset.jenis + (sisa === 0 ? ' penuh' : ' tersisa ' + sisa));
                }
            });
            slotWarn.hidden = kurang.length === 0;
            slotWarn.textContent = kurang.length ? 'Slot tidak cukup: ' + kurang.join(', ') + '.' : '';
        }

        const bagian = [];
        if (jumlah.mobil) bagian.push(jumlah.mobil + ' mobil \u00d7 ' + rupiah(harga.mobil));
        if (jumlah.motor) bagian.push(jumlah.motor + ' motor \u00d7 ' + rupiah(harga.motor));

        const total = jumlah.mobil * harga.mobil + jumlah.motor * harga.motor;
        const siap  = planSel.value && bagian.length;

        sumTotal.textContent  = siap ? rupiah(total) : '-';
        sumDetail.textContent = !planSel.value ? 'Pilih paket untuk melihat tagihan.'
                              : (bagian.length ? bagian.join(' + ') : 'Isi plat nomor untuk menghitung tagihan.');

        if (!amountTouched) amountIn.value = siap ? total : '';
    }

    [planSel, startIn].forEach(el => el.addEventListener('change', hitungPaket));
    plateList.addEventListener('input', hitungPaket);
    plateList.addEventListener('change', hitungPaket);
    plateList.addEventListener('click', hitungPaket);                          // hapus baris
    document.getElementById('btnAddPlate').addEventListener('click', hitungPaket); // tambah baris
    hitungPaket();

    // ================= Foto wajah: ambil 30 sampel (hanya frame yang wajahnya terdeteksi) =================
    const TOTAL = 30;          // jumlah foto yang dikumpulkan
    const JEDA  = 300;         // jeda (ms) antar foto yang DITERIMA, supaya variasi posisi ada
    const JEDA_TANPA_WAJAH = 150; // jeda (ms) sebelum mencoba lagi bila wajah tidak terdeteksi
    const MAX_DETIK = 90;      // batas waktu total pengambilan
    const DUP_HITS  = 3;       // berapa frame harus cocok ke member yang sama sebelum dianggap "sudah terdaftar"
    const LEBAR = 640;         // frame dikecilkan agar upload 30 file tetap ringan
    const KUALITAS = 0.85;

    const CEK_URL = "{{ route('parkir.pendaftaran.cek-wajah') }}";
    const CSRF    = document.querySelector('#pdForm input[name="_token"]').value;

    // Petunjuk gerakan kepala per jumlah foto yang sudah terkumpul: [mulai dari foto ke-, teks]
    const PETUNJUK = [
        [0,  'Hadap lurus ke kamera'],
        [6,  'Tengok pelan ke kiri'],
        [12, 'Tengok pelan ke kanan'],
        [18, 'Angkat dagu sedikit'],
        [24, 'Tundukkan kepala sedikit'],
    ];
    const petunjuk = i => PETUNJUK.reduce((t, [mulai, teks]) => (i >= mulai ? teks : t), PETUNJUK[0][1]);
    const tunggu   = ms => new Promise(r => setTimeout(r, ms));

    const stage     = document.getElementById('stage');
    const video     = document.getElementById('camVideo');
    const canvas    = document.getElementById('camCanvas');
    const shot      = document.getElementById('shot');
    const input     = document.getElementById('photoInput');
    const msg       = document.getElementById('msg');
    const faceAlert = document.getElementById('faceAlert');
    const guideText = document.getElementById('guideText');
    const countBadge= document.getElementById('countBadge');
    const progress  = document.getElementById('progress');
    const progressBar = document.getElementById('progressBar');
    const btnOpen   = document.getElementById('btnOpen');
    const btnSnap   = document.getElementById('btnSnap');
    const btnCancel = document.getElementById('btnCancel');
    const btnRetake = document.getElementById('btnRetake');
    const GUIDE_DEFAULT = 'Posisikan wajah di dalam bingkai';

    let stream = null;
    let capturing = false;
    let cancelled = false;

    function setMsg(text, isError) {
        msg.textContent = text || '';
        msg.classList.toggle('is-error', !!isError);
        stage.classList.toggle('has-error', !!isError);
    }

    function showAlert(html) {
        faceAlert.innerHTML = html;
        faceAlert.classList.add('is-on');
    }
    function hideAlert() {
        faceAlert.textContent = '';
        faceAlert.classList.remove('is-on');
    }
    const esc = s => String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));

    // state: 'empty' | 'live' | 'filled'
    function setState(state) {
        stage.classList.toggle('is-live',   state === 'live');
        stage.classList.toggle('is-filled', state === 'filled');
        btnOpen.hidden   = state !== 'empty';
        btnSnap.hidden   = state !== 'live' || capturing;
        btnCancel.hidden = state !== 'live';
        btnRetake.hidden = state !== 'filled';
    }

    function setCapturing(on) {
        capturing = on;
        stage.classList.toggle('is-capturing', on);
        progress.classList.toggle('is-on', on);
        btnSnap.hidden = on || !stage.classList.contains('is-live');
        if (!on) {
            progressBar.style.width = '0';
            guideText.textContent = GUIDE_DEFAULT;
            stage.classList.remove('no-face');
        }
    }

    function stopCam() {
        if (stream) stream.getTracks().forEach(t => t.stop());
        stream = null;
        video.srcObject = null;
    }

    async function openCam() {
        setMsg('');
        hideAlert();
        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            setMsg('Kamera tidak tersedia. Buka halaman ini lewat localhost atau HTTPS.', true);
            return;
        }
        try {
            stream = await navigator.mediaDevices.getUserMedia({
                video: { facingMode: 'user', width: { ideal: 1280 }, height: { ideal: 720 } },
                audio: false
            });
        } catch (e) {
            const pesan = {
                NotAllowedError: 'Izin kamera ditolak. Klik ikon gembok di address bar lalu izinkan kamera.',
                NotFoundError: 'Kamera tidak ditemukan. Pastikan webcam terpasang.',
                NotReadableError: 'Kamera sedang dipakai aplikasi lain. Tutup aplikasi itu lalu coba lagi.'
            };
            setMsg(pesan[e.name] || ('Kamera tidak bisa diakses: ' + e.message), true);
            return;
        }
        video.srcObject = stream;
        setState('live');
        setMsg('Posisikan wajah di dalam bingkai, lalu tekan "Mulai ambil 30 foto".');
    }

    // Tanya server (Laravel -> Python /recognize): wajah terdeteksi? sudah terdaftar sebagai siapa?
    async function cekFrame(blob) {
        const fd = new FormData();
        fd.append('photo', blob, 'frame.jpg');

        const res = await fetch(CEK_URL, {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': CSRF, 'Accept': 'application/json' },
            body: fd,
        });

        if (!res.ok) {
            let pesan = 'Pemeriksaan wajah gagal (kode ' + res.status + ').';
            try { const j = await res.json(); pesan = j.error || j.message || pesan; } catch (_) {}
            throw new Error(pesan);
        }
        return res.json();
    }

    // Kumpulkan TOTAL foto yang wajahnya terdeteksi, lalu isikan ke <input type="file" multiple>
    async function snap() {
        if (capturing) return;
        if (!video.videoWidth) {
            setMsg('Kamera belum siap, tunggu sebentar lalu coba lagi.', true);
            return;
        }

        cancelled = false;
        setMsg('');
        hideAlert();
        setCapturing(true);
        countBadge.textContent = '0/' + TOTAL;

        const w = LEBAR;
        const h = Math.round(video.videoHeight * LEBAR / video.videoWidth);
        canvas.width = w;
        canvas.height = h;
        const ctx = canvas.getContext('2d');

        const files = [];
        const dup = {};              // member_id -> { name, n }
        const mulai = Date.now();
        guideText.textContent = petunjuk(0);

        while (files.length < TOTAL) {
            if (cancelled) break;

            if (Date.now() - mulai > MAX_DETIK * 1000) {
                setCapturing(false);
                reset();
                setMsg('Wajah terlalu sering tidak terdeteksi (terkumpul ' + files.length + ' dari ' + TOTAL +
                       ' foto). Perbaiki pencahayaan, hadap lurus ke kamera, lalu coba lagi.', true);
                return;
            }

            ctx.drawImage(video, 0, 0, w, h);
            const blob = await new Promise(r => canvas.toBlob(r, 'image/jpeg', KUALITAS));
            if (!blob) { await tunggu(JEDA_TANPA_WAJAH); continue; }

            let hasil;
            try {
                hasil = await cekFrame(blob);
            } catch (e) {
                setCapturing(false);
                reset();
                setMsg(e.message, true);
                showAlert('<b>Pemeriksaan wajah gagal.</b> ' + esc(e.message));
                return;
            }
            if (cancelled) break;

            // 1) Wajah tidak terdeteksi: jangan simpan frame, tampilkan peringatan, coba lagi
            if (!hasil.face) {
                stage.classList.add('no-face');
                guideText.textContent = 'Wajah tidak terdeteksi. Hadap kamera & cari tempat yang lebih terang';
                await tunggu(JEDA_TANPA_WAJAH);
                continue;
            }
            stage.classList.remove('no-face');

            // 2) Wajah sudah terdaftar sebagai member lain: hentikan bila terbukti beberapa kali
            if (hasil.duplicate) {
                const d = dup[hasil.member_id] || (dup[hasil.member_id] = { name: hasil.name, n: 0 });
                d.n++;
                if (d.n >= DUP_HITS) {
                    setCapturing(false);
                    reset();
                    showAlert('<b>Wajah sudah terdaftar</b> sebagai <b>' + esc(d.name) + '</b>. ' +
                              'Satu orang hanya boleh punya satu akun member, pendaftaran wajah dihentikan.');
                    setMsg('Wajah sudah terdaftar sebagai ' + d.name + '.', true);
                    return;
                }
                // frame yang dicurigai duplikat tidak dipakai
                await tunggu(JEDA_TANPA_WAJAH);
                continue;
            }

            // 3) Frame valid: simpan, angka & progress bertambah HANYA di sini
            files.push(new File([blob], 'frame_' + String(files.length + 1).padStart(2, '0') + '.jpg', { type: 'image/jpeg' }));
            countBadge.textContent = files.length + '/' + TOTAL;
            progressBar.style.width = Math.round((files.length / TOTAL) * 100) + '%';
            guideText.textContent = files.length < TOTAL ? petunjuk(files.length) : 'Selesai';

            await tunggu(JEDA);
        }

        if (cancelled) { setCapturing(false); return; }

        const dt = new DataTransfer();
        files.forEach(f => dt.items.add(f));
        input.files = dt.files;

        stopCam();
        setCapturing(false);

        // Pratinjau: frame tengah (foto yang sama akan disimpan sebagai profil wajah)
        const tengah = files[Math.floor(files.length / 2)];
        const reader = new FileReader();
        reader.onload = e => {
            shot.classList.add('is-mirror');
            shot.src = e.target.result;
            setState('filled');
            setMsg(files.length + ' foto dengan wajah terdeteksi siap dikirim.');
        };
        reader.readAsDataURL(tengah);
    }

    function reset() {
        cancelled = capturing;
        stopCam();
        input.value = '';
        shot.removeAttribute('src');
        setCapturing(false);
        setState('empty');
        setMsg('');
    }

    btnOpen.addEventListener('click', openCam);
    btnSnap.addEventListener('click', snap);
    btnCancel.addEventListener('click', () => { reset(); hideAlert(); });
    btnRetake.addEventListener('click', () => { reset(); openCam(); });

    window.addEventListener('beforeunload', stopCam);

    // ================= Submit =================
    document.getElementById('pdForm').addEventListener('submit', e => {
        if (capturing) {
            e.preventDefault();
            setMsg('Tunggu sampai pengambilan 30 foto selesai.', true);
            return;
        }

        // Buang baris plat tambahan yang kosong supaya tidak terkirim sebagai data kosong
        const rows = plateList.querySelectorAll('.pd-plate');
        rows.forEach((row, i) => {
            if (i > 0 && !row.querySelector('input[name="plates[]"]').value.trim()) row.remove();
        });

        const firstPlate = plateList.querySelector('input[name="plates[]"]');
        if (!document.getElementById('name').value.trim()) {
            e.preventDefault(); document.getElementById('name').focus(); return;
        }
        const phone = document.getElementById('phone');
        if (!phone.value.trim()) {
            e.preventDefault(); phone.focus(); return;
        }
        if (!firstPlate.value.trim()) {
            e.preventDefault(); firstPlate.focus(); return;
        }
        if (!planSel.value) {
            e.preventDefault(); planSel.focus(); return;
        }
        if (!startIn.value) {
            e.preventDefault(); startIn.focus(); return;
        }
        if (input.files.length < TOTAL / 2) {
            e.preventDefault();
            setMsg('Foto wajah wajib diisi. Buka kamera lalu ambil 30 foto.', true);
            stage.scrollIntoView({ behavior: 'smooth', block: 'center' });
            return;
        }

        // Training LBPH di server butuh beberapa detik: cegah klik ganda
        const btn = document.getElementById('btnSubmit');
        btn.textContent = 'Memproses wajah...';
        setTimeout(() => { btn.disabled = true; }, 0);
    });

    setState('empty');
})();
</script>
@endsection
