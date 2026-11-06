@extends('layouts.parkir')

@section('title', 'Data Pelanggan')
@section('heading', 'Data Pelanggan')
@section('subheading', 'Daftar pelanggan yang sudah terdaftar wajah dan plat nomornya')

@section('content')

    <style>
        .btn-sm{padding:5px 12px;font-size:12.5px;white-space:nowrap}
        .aksi-list{position:fixed;z-index:50;min-width:220px;background:#fff;border:1px solid var(--line);border-radius:12px;box-shadow:0 12px 30px rgba(11,31,51,.18);padding:6px}
        .aksi-list[hidden]{display:none}
        .aksi-item{display:flex;flex-direction:column;align-items:flex-start;gap:1px;width:100%;padding:9px 12px;border:0;background:none;font:inherit;font-size:14px;text-align:left;border-radius:8px;cursor:pointer;color:var(--text)}
        .aksi-item:hover{background:var(--bg)}
        .aksi-item small{font-size:12px;color:var(--muted)}
        .aksi-item.bahaya{color:var(--red)}
        .aksi-sep{height:1px;background:var(--line);margin:6px 4px}

        /* Styling Custom Modal Konfirmasi Modern */
        .pl-modal-backdrop {
            position: fixed; top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(15, 23, 42, 0.6); backdrop-filter: blur(4px);
            display: flex; align-items: center; justify-content: center; z-index: 9999;
            opacity: 0; visibility: hidden; transition: all 0.25s ease;
        }
        .pl-modal-backdrop.show { opacity: 1; visibility: visible; }
        .pl-modal-box {
            background: #fff; width: 100%; max-width: 420px; border-radius: 16px;
            padding: 24px; box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1);
            transform: scale(0.95); transition: transform 0.25s ease;
            max-height: 92vh; overflow-y: auto;
        }
        .pl-modal-backdrop.show .pl-modal-box { transform: scale(1); }
        .pl-modal-title { font-size: 17px; font-weight: 700; color: var(--text); margin-bottom: 8px; display: flex; align-items: center; gap: 8px; }
        .pl-modal-desc { font-size: 14px; color: var(--muted); line-height: 1.5; margin-bottom: 20px; }
        .pl-modal-actions { display: flex; gap: 10px; justify-content: flex-end; }
        .pl-modal-actions button { padding: 8px 16px; border-radius: 9px; font-weight: 600; font-size: 13.5px; cursor: pointer; border: 0; }
        .btn-cancel { background: #f1f5f9; color: #475569; }
        .btn-cancel:hover { background: #e2e8f0; }
        .btn-confirm-action { background: var(--accent); color: #fff; }
        .btn-confirm-action.bahaya { background: var(--red); }

        /* Popup Tambah kendaraan */
        .kd-info{margin:0 0 16px;padding:10px 12px;border-radius:10px;background:#eef4ff;border:1px solid #cfdcf7;font-size:13px;color:#1d4a94;line-height:1.5}
        .kd-info.netral{background:#f6f8fb;border-color:var(--line);color:var(--muted)}
        .kd-sum{margin:0 0 14px;padding:12px 14px;border-radius:12px;background:#f6f8fb;border:1px solid var(--line)}
        .kd-sum small{display:block;font-size:12px;color:var(--muted);margin-bottom:2px}
        .kd-sum b{font-size:20px}
        .kd-sum span{display:block;margin-top:4px;font-size:12.5px;color:var(--muted)}
        .kd-row{display:grid;grid-template-columns:1fr 1fr;gap:12px}
        @media (max-width:520px){.kd-row{grid-template-columns:1fr}}
    </style>

    {{-- GALAT VALIDASI --}}
    @if ($errors->any())
        <div class="alert alert-err">
            <ul style="margin:0;padding-left:18px">
                @foreach ($errors->all() as $pesan)
                    <li>{{ $pesan }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    {{-- HASIL RESET KATA SANDI --}}
    @if (session('reset'))
        @php $r = session('reset'); @endphp
        <div class="card" style="margin-bottom:16px;background:#effaf5;border-color:#bfe8d4" role="status">
            <div style="font-weight:700;color:#14532d;margin-bottom:6px">Kata sandi {{ $r['name'] }} berhasil direset</div>
            <div style="font-size:14px">
                Login: <b>{{ $r['phone'] ?: '-' }}</b>
                &middot; Kata sandi: <b style="font-family:ui-monospace,Consolas,monospace;letter-spacing:.04em">{{ $r['password'] }}</b>
            </div>
            <div style="font-size:12.5px;color:#3f6b52;margin-top:6px">
                Sampaikan ke member. Ia akan diminta menggantinya saat masuk ke aplikasi.
            </div>
        </div>
    @endif

    {{-- RINGKASAN --}}
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:14px;margin-bottom:16px">
        <div class="card">
            <div style="font-size:12px;color:var(--muted);text-transform:uppercase;letter-spacing:.04em">Total Pelanggan</div>
            <div style="font-size:26px;font-weight:700;margin-top:4px">{{ $stats['total'] }}</div>
        </div>
        <div class="card">
            <div style="font-size:12px;color:var(--muted);text-transform:uppercase;letter-spacing:.04em">Aktif</div>
            <div style="font-size:26px;font-weight:700;margin-top:4px;color:var(--green)">{{ $stats['aktif'] }}</div>
        </div>
        <div class="card">
            <div style="font-size:12px;color:var(--muted);text-transform:uppercase;letter-spacing:.04em">Nonaktif</div>
            <div style="font-size:26px;font-weight:700;margin-top:4px;color:var(--red)">{{ $stats['nonaktif'] }}</div>
        </div>
    </div>

    <div class="card">
        {{-- TOOLBAR: cari + filter + tombol tambah --}}
        <div class="toolbar">
            <form method="GET" action="{{ route('parkir.pelanggan') }}" class="filters">
                <input type="text" name="q" value="{{ $search }}" placeholder="Cari nama, HP, atau plat nomor..." style="min-width:260px">
                <select name="status">
                    <option value="">Semua status</option>
                    <option value="aktif" @selected($status === 'aktif')>Aktif</option>
                    <option value="nonaktif" @selected($status === 'nonaktif')>Nonaktif</option>
                </select>
                <button type="submit" class="btn btn-dark">Cari</button>
                @if ($search !== '' || $status !== '')
                    <a href="{{ route('parkir.pelanggan') }}" class="btn btn-outline" style="text-decoration:none">Reset</a>
                @endif
            </form>

            <a href="{{ route('parkir.pendaftaran') }}" class="btn btn-primary" style="text-decoration:none">+ Daftarkan Pelanggan</a>
        </div>

        {{-- TABEL --}}
        <div style="overflow-x:auto">
            <table>
                <thead>
                    <tr>
                        <th>Pelanggan</th>
                        <th>Kontak</th>
                        <th>Kendaraan</th>
                        <th>Wajah</th>
                        <th>Status</th>
                        <th>Terdaftar</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($members as $member)
                        <tr>
                            <td>
                                <div style="display:flex;align-items:center;gap:12px">
                                    @if ($member->faceProfile && $member->faceProfile->photo_path)
                                        <img src="{{ route('parkir.pelanggan.foto', $member) }}"
                                             alt="Foto {{ $member->name }}"
                                             loading="lazy"
                                             style="width:40px;height:40px;border-radius:50%;object-fit:cover;border:1px solid var(--line)">
                                    @else
                                        <div style="width:40px;height:40px;border-radius:50%;background:var(--navy-2);color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700">
                                            {{ strtoupper(substr($member->name, 0, 1)) }}
                                        </div>
                                    @endif
                                    <div>
                                        <div style="font-weight:600">{{ $member->name }}</div>
                                        <div style="font-size:12px;color:var(--muted)">ID #{{ $member->id }}</div>
                                    </div>
                                </div>
                            </td>

                            <td>
                                <div>{{ $member->phone ?: '-' }}</div>
                                <div style="font-size:12px;color:var(--muted)">{{ $member->email ?: '-' }}</div>
                            </td>

                            <td>
                                @forelse ($member->vehicles as $vehicle)
                                    <div style="margin-bottom:4px">
                                        <span class="badge b-role">{{ $vehicle->plate_number }}</span>
                                        <span style="font-size:12px;color:var(--muted);margin-left:4px">{{ ucfirst($vehicle->vehicle_type) }}</span>
                                    </div>
                                @empty
                                    <span style="color:var(--muted)">-</span>
                                @endforelse
                            </td>

                            <td>
                                @if ($member->faceProfile)
                                    <span class="badge b-on">Terdaftar</span>
                                @else
                                    <span class="badge b-off">Belum ada</span>
                                @endif
                            </td>

                            <td>
                                @if ($member->is_active)
                                    <span class="badge b-on">Aktif</span>
                                @else
                                    <span class="badge b-off">Nonaktif</span>
                                @endif
                            </td>

                            <td style="color:var(--muted);font-size:13px">
                                {{ $member->created_at?->format('d M Y') }}
                            </td>

                            <td>
                                @php
                                    $aksi = [
                                        'nama'   => $member->name,
                                        'aktif'  => (bool) $member->is_active,
                                        'reset'  => route('parkir.pelanggan.reset', $member),
                                        'status' => route('parkir.pelanggan.status', $member),
                                        // Selalu tampilkan opsi hapus pelanggan di menu Aksi
                                        'hapus'  => route('parkir.pelanggan.destroy', $member),
                                        // Tambah kendaraan: baru muncul kalau rutenya sudah didaftarkan
                                        'kendaraan' => Route::has('parkir.pelanggan.kendaraan')
                                            ? route('parkir.pelanggan.kendaraan', $member)
                                            : null,
                                        'tagihan' => $tagihan[$member->id] ?? null,
                                    ];
                                @endphp
                                <div class="actions">
                                    <button type="button"
                                            class="btn btn-sm btn-outline"
                                            data-aksi="{{ json_encode($aksi, JSON_UNESCAPED_UNICODE) }}"
                                            aria-haspopup="menu" aria-expanded="false">
                                        Aksi &#9662;
                                    </button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="empty">
                                @if ($search !== '' || $status !== '')
                                    Tidak ada pelanggan yang cocok dengan pencarian.
                                @else
                                    Belum ada pelanggan terdaftar.
                                @endif
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{-- PAGINATION --}}
        @if ($members->hasPages())
            <div style="display:flex;justify-content:space-between;align-items:center;margin-top:16px;font-size:13px;color:var(--muted)">
                <span>
                    Menampilkan {{ $members->firstItem() }}–{{ $members->lastItem() }} dari {{ $members->total() }}
                </span>
                <div style="display:flex;gap:8px">
                    @if ($members->onFirstPage())
                        <span class="btn btn-outline" style="opacity:.45;cursor:default">← Sebelumnya</span>
                    @else
                        <a href="{{ $members->previousPageUrl() }}" class="btn btn-outline" style="text-decoration:none">← Sebelumnya</a>
                    @endif

                    @if ($members->hasMorePages())
                        <a href="{{ $members->nextPageUrl() }}" class="btn btn-outline" style="text-decoration:none">Berikutnya →</a>
                    @else
                        <span class="btn btn-outline" style="opacity:.45;cursor:default">Berikutnya →</span>
                    @endif
                </div>
            </div>
        @endif
    </div>

    {{-- MENU AKSI --}}
    <div id="menu-aksi" class="aksi-list" role="menu" hidden></div>

    {{-- Form umum untuk Reset sandi / Aktif-Nonaktif / Hapus --}}
    <form id="form-aksi" method="POST" style="display:none">
        @csrf
        <input type="hidden" name="_method" value="PATCH" disabled>
    </form>

    {{-- ===== CUSTOM MODAL KONFIRMASI MODERN ===== --}}
    <div id="customModal" class="pl-modal-backdrop">
        <div class="pl-modal-box">
            <div class="pl-modal-title">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color:var(--accent)"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                <span id="modalHeading">Konfirmasi Tindakan</span>
            </div>
            <div class="pl-modal-desc" id="modalMessage">
                Apakah Anda yakin ingin melanjutkan tindakan ini?
            </div>
            <div class="pl-modal-actions">
                <button type="button" class="btn-cancel" onclick="closeCustomModal()">Batal</button>
                <button type="button" id="modalConfirmBtn" class="btn-confirm-action" onclick="submitActiveAction()">Ya, Lanjutkan</button>
            </div>
        </div>
    </div>

    {{-- ===== POPUP TAMBAH KENDARAAN ===== --}}
    <div id="kendaraanModal" class="pl-modal-backdrop">
        <div class="pl-modal-box" style="max-width:460px">
            <div class="pl-modal-title">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color:var(--accent)"><rect x="3" y="8" width="18" height="8" rx="2"/><circle cx="7.5" cy="17" r="1.5"/><circle cx="16.5" cy="17" r="1.5"/></svg>
                <span id="kdJudul">Tambah kendaraan</span>
            </div>

            <div class="kd-info" id="kdInfo"></div>

            <form id="kdForm" method="POST">
                @csrf

                <div class="field">
                    <label for="kdPlat">Plat nomor</label>
                    <input id="kdPlat" name="plate" placeholder="B 1234 ABC" autocomplete="off" required style="text-transform:uppercase">
                </div>

                <div class="field">
                    <label for="kdJenis">Jenis kendaraan</label>
                    <select id="kdJenis" name="vehicle_type"></select>
                </div>

                <div id="kdBayar">
                    <div class="kd-sum">
                        <small>Tagihan prorata</small>
                        <b id="kdTagihan">-</b>
                        <span id="kdRincian"></span>
                    </div>

                    <div class="kd-row">
                        <div class="field">
                            <label for="kdMetode">Metode pembayaran</label>
                            <select id="kdMetode" name="payment_method">
                                <option value="tunai">Tunai</option>
                                <option value="transfer">Transfer</option>
                            </select>
                        </div>
                        <div class="field">
                            <label for="kdNominal">Nominal dibayar (Rp)</label>
                            <input id="kdNominal" type="number" name="amount_paid" min="0" step="1" inputmode="numeric">
                        </div>
                    </div>
                </div>

                <div class="pl-modal-actions" style="margin-top:6px">
                    <button type="button" class="btn-cancel" onclick="tutupFormKendaraan()">Batal</button>
                    <button type="submit" class="btn-confirm-action">Simpan kendaraan</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        (function () {
            const menu = document.getElementById('menu-aksi');
            const formAksi = document.getElementById('form-aksi');
            const inputMetode = formAksi.querySelector('[name="_method"]');

            let pemicu = null;
            let pendingAction = null;

            function tutupMenu() {
                if (!pemicu) return;
                menu.hidden = true;
                pemicu.setAttribute('aria-expanded', 'false');
                pemicu = null;
            }

            // Fungsi pemicu modal modern pengganti confirm()
            window.showConfirmModal = function(url, metode, pesan, isBahaya) {
                const modal = document.getElementById('customModal');
                const msgEl = document.getElementById('modalMessage');
                const confirmBtn = document.getElementById('modalConfirmBtn');

                msgEl.textContent = pesan;
                confirmBtn.className = 'btn-confirm-action' + (isBahaya ? ' bahaya' : '');
                confirmBtn.innerText = isBahaya ? 'Hapus Pelanggan' : 'Ya, Lanjutkan';

                pendingAction = { url, metode };
                modal.classList.add('show');
            };

            window.closeCustomModal = function() {
                document.getElementById('customModal').classList.remove('show');
                pendingAction = null;
            };

            window.submitActiveAction = function() {
                if (pendingAction) {
                    formAksi.action = pendingAction.url;
                    inputMetode.value = pendingAction.metode;
                    inputMetode.disabled = (pendingAction.metode === 'POST');
                    formAksi.submit();
                }
            };

            // ===== Popup Tambah kendaraan =====
            const kdModal = document.getElementById('kendaraanModal');
            const kdForm = document.getElementById('kdForm');
            const kdJenis = document.getElementById('kdJenis');
            const kdMetode = document.getElementById('kdMetode');
            const kdNominal = document.getElementById('kdNominal');
            const kdBayar = document.getElementById('kdBayar');
            let kdData = null;

            const rupiah = n => 'Rp ' + new Intl.NumberFormat('id-ID').format(n);
            const huruf = s => s.charAt(0).toUpperCase() + s.slice(1);

            function hitungKendaraan() {
                if (!kdData) return;
                const t = kdData.tagihan;
                const ada = t.status === 'aktif' || t.status === 'belum_mulai';
                const harga = ada ? Number(t.harga[kdJenis.value] || 0) : 0;

                // Tanpa biaya: kolom pembayaran disembunyikan dan tidak ikut terkirim
                kdBayar.hidden = harga <= 0;
                kdMetode.disabled = harga <= 0;
                kdNominal.disabled = harga <= 0;

                document.getElementById('kdTagihan').textContent = rupiah(harga);
                document.getElementById('kdRincian').textContent =
                    t.sisa_hari + ' hari tersisa sampai ' + t.sampai + ' (paket ' + t.paket + ')';
                kdNominal.value = harga > 0 ? harga : '';
            }

            window.bukaFormKendaraan = function (d) {
                kdData = d;
                const t = d.tagihan;

                document.getElementById('kdJjudul');
                document.getElementById('kdJudul').textContent = 'Tambah kendaraan · ' + d.nama;
                kdForm.action = d.kendaraan;
                kdForm.reset();

                // Pilihan jenis diambil dari daftar harga yang dikirim server
                kdJenis.replaceChildren.apply(kdJenis, Object.keys(t.harga).map(function (j) {
                    const o = document.createElement('option');
                    o.value = j;
                    o.textContent = huruf(j);
                    return o;
                }));

                const info = document.getElementById('kdInfo');
                info.classList.remove('netral');

                if (t.status === 'aktif') {
                    info.textContent = 'Langganan ' + t.paket + ' berlaku sampai ' + t.sampai +
                        '. Kendaraan baru ditagih prorata untuk sisa ' + t.sisa_hari + ' hari.';
                } else if (t.status === 'belum_mulai') {
                    info.textContent = 'Langganan ' + t.paket + ' belum mulai (berakhir ' + t.sampai +
                        '). Kendaraan baru ditagih untuk seluruh periodenya.';
                } else if (t.status === 'habis') {
                    info.classList.add('netral');
                    info.textContent = 'Langganan sudah berakhir pada ' + t.sampai +
                        '. Kendaraan dicatat tanpa biaya; biayanya ikut dihitung saat paket diperpanjang.';
                } else {
                    info.classList.add('netral');
                    info.textContent = 'Pelanggan ini belum pernah berlangganan. Kendaraan dicatat tanpa biaya.';
                }

                hitungKendaraan();
                kdModal.classList.add('show');
                setTimeout(function () { document.getElementById('kdPlat').focus(); }, 50);
            };

            window.tutupFormKendaraan = function () {
                kdModal.classList.remove('show');
                kdData = null;
            };

            kdJenis.addEventListener('change', hitungKendaraan);
            kdModal.addEventListener('click', function (e) { if (e.target === kdModal) tutupFormKendaraan(); });

            function pemisah() {
                const el = document.createElement('div');
                el.className = 'aksi-sep';
                return el;
            }

            function buatItem(teks, keterangan, aksi, opsi) {
                opsi = opsi || {};
                const el = document.createElement('button');
                el.type = 'button';
                el.setAttribute('role', 'menuitem');
                el.className = 'aksi-item' + (opsi.bahaya ? ' bahaya' : '');

                const t = document.createElement('span');
                t.textContent = teks;
                el.appendChild(t);

                if (keterangan) {
                    const k = document.createElement('small');
                    k.textContent = keterangan;
                    el.appendChild(k);
                }

                el.addEventListener('click', function () {
                    tutupMenu();
                    aksi();
                });
                return el;
            }

            function isiMenu(d) {
                const isi = [];

                if (d.aktif && d.kendaraan && d.tagihan) {
                    isi.push(buatItem('Tambah kendaraan', 'Cukup plat, tanpa daftar ulang', function () {
                        bukaFormKendaraan(d);
                    }));
                    isi.push(pemisah());
                }

                isi.push(buatItem('Reset sandi', null, function () {
                    showConfirmModal(
                        d.reset, 
                        'POST', 
                        'Reset kata sandi ' + d.nama + ' menjadi 123456789? Kata sandi lamanya tidak berlaku lagi.',
                        false
                    );
                }));

                isi.push(buatItem(d.aktif ? 'Nonaktifkan' : 'Aktifkan', null, function () {
                    showConfirmModal(
                        d.status, 
                        'PATCH', 
                        (d.aktif ? 'Nonaktifkan' : 'Aktifkan kembali') + ' ' + d.nama + '?',
                        false
                    );
                }));

                if (d.hapus) {
                    isi.push(pemisah());
                    isi.push(buatItem('Hapus pelanggan', null, function () {
                        showConfirmModal(
                            d.hapus, 
                            'DELETE', 
                            'Hapus ' + d.nama + ' beserta kendaraan dan foto wajahnya? Tindakan ini tidak bisa dibatalkan.',
                            true
                        );
                    }, { bahaya: true }));
                }

                return isi;
            }

            function bukaMenu(tombol) {
                const d = JSON.parse(tombol.dataset.aksi);
                menu.replaceChildren.apply(menu, isiMenu(d));
                menu.hidden = false;

                const r = tombol.getBoundingClientRect();
                const mw = menu.offsetWidth;
                const mh = menu.offsetHeight;
                const kiri = Math.min(Math.max(8, r.right - mw), window.innerWidth - mw - 8);
                let atas = r.bottom + 6;
                if (atas + mh > window.innerHeight - 8) atas = Math.max(8, r.top - mh - 6);

                menu.style.left = kiri + 'px';
                menu.style.top = atas + 'px';

                pemicu = tombol;
                tombol.setAttribute('aria-expanded', 'true');
            }

            document.querySelectorAll('[data-aksi]').forEach(function (tombol) {
                tombol.addEventListener('click', function (e) {
                    e.stopPropagation();
                    if (pemicu === tombol) { tutupMenu(); return; }
                    tutupMenu();
                    bukaMenu(tombol);
                });
            });

            document.addEventListener('click', function (e) {
                if (pemicu && !menu.contains(e.target)) tutupMenu();
            });
            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape') { tutupMenu(); tutupFormKendaraan(); }
            });
            window.addEventListener('scroll', tutupMenu, true);
            window.addEventListener('resize', tutupMenu);
        })();
    </script>

@endsection