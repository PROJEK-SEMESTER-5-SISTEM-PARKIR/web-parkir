@extends('layouts.parkir')

@section('title', 'Data Pelanggan')
@section('heading', 'Data Pelanggan')
@section('subheading', 'Daftar pelanggan yang sudah terdaftar wajah dan plat nomornya')

@section('content')

    <style>
        .btn-sm{padding:5px 12px;font-size:12.5px;white-space:nowrap}
        .dlg{border:0;border-radius:16px;padding:22px;width:min(440px,92vw);box-shadow:0 20px 50px rgba(11,31,51,.3)}
        .dlg::backdrop{background:rgba(11,31,51,.55)}
        .dlg h3{margin:0 0 4px;font-size:18px}
        .dlg .sub{margin:0 0 16px;color:var(--muted);font-size:14px}
        .ringkas{background:var(--bg);border:1px solid var(--line);border-radius:10px;padding:12px 14px;font-size:14px;line-height:1.7}
        .ringkas .catatan{font-size:12.5px;color:var(--muted);margin-top:4px;line-height:1.5}
    </style>

    {{-- HASIL RESET KATA SANDI (tampil sekali setelah admin menekan tombol reset) --}}
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
                        <th>Paket</th>
                        <th>Wajah</th>
                        <th>Status</th>
                        <th>Terdaftar</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($members as $member)
                        <tr>
                            {{-- Nama + foto (foto disajikan lewat route privat, bukan /storage) --}}
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

                            {{-- Kontak --}}
                            <td>
                                <div>{{ $member->phone ?: '-' }}</div>
                                <div style="font-size:12px;color:var(--muted)">{{ $member->email ?: '-' }}</div>
                            </td>

                            {{-- Kendaraan (bisa lebih dari satu) --}}
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

                            {{-- Paket langganan terbaru --}}
                            <td>
                                @php $sub = $member->latestSubscription; @endphp
                                @if ($sub)
                                    <div style="font-weight:600;margin-bottom:4px">{{ $sub->plan?->name ?? '-' }}</div>
                                    @if ($sub->isActive())
                                        <span class="badge b-on">Aktif</span>
                                        <div style="font-size:12px;color:var(--muted);margin-top:4px">
                                            s.d. {{ $sub->end_date->format('d M Y') }}
                                            · sisa {{ (int) now()->startOfDay()->diffInDays($sub->end_date) }} hari
                                        </div>
                                    @elseif ($sub->end_date->lt(now()->startOfDay()))
                                        <span class="badge b-off">Kedaluwarsa</span>
                                        <div style="font-size:12px;color:var(--muted);margin-top:4px">
                                            berakhir {{ $sub->end_date->format('d M Y') }}
                                        </div>
                                    @else
                                        <span class="badge b-off">{{ ucfirst($sub->status) }}</span>
                                    @endif
                                @else
                                    <span style="color:var(--muted)">Belum berlangganan</span>
                                @endif
                            </td>

                            {{-- Status wajah --}}
                            <td>
                                @if ($member->faceProfile)
                                    <span class="badge b-on">Terdaftar</span>
                                @else
                                    <span class="badge b-off">Belum ada</span>
                                @endif
                            </td>

                            {{-- Status member --}}
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

                            {{-- Aksi --}}
                            <td>
                                <div class="actions">
                                    {{-- Perpanjang / mulai paket: hanya untuk pelanggan aktif, boleh kapan saja --}}
                                    @if ($member->is_active)
                                        @php
                                            $masihBerlaku = $sub && $sub->status === 'active' && $sub->end_date->gte(now()->startOfDay());
                                            // menonjol (biru) kalau belum punya paket, sudah habis, atau tinggal <= 7 hari
                                            $mendesak = ! $sub || $sub->end_date->lte(now()->startOfDay()->addDays(7));
                                        @endphp
                                        <button type="button"
                                                class="btn btn-sm {{ $mendesak ? 'btn-primary' : 'btn-outline' }}"
                                                data-perpanjang
                                                data-url="{{ route('parkir.pelanggan.perpanjang', $member) }}"
                                                data-nama="{{ $member->name }}"
                                                data-paket="{{ $sub?->plan?->name }}"
                                                data-planid="{{ $sub?->subscription_plan_id }}"
                                                data-berakhir="{{ $sub?->end_date?->format('Y-m-d') }}"
                                                data-aktif="{{ $masihBerlaku ? '1' : '0' }}">
                                            {{ $sub ? 'Perpanjang' : 'Mulai paket' }}
                                        </button>
                                    @endif

                                    <form method="POST"
                                          action="{{ route('parkir.pelanggan.reset', $member) }}"
                                          data-confirm="Reset kata sandi {{ $member->name }} menjadi 123456789? Kata sandi lamanya tidak berlaku lagi.">
                                        @csrf
                                        <button type="submit" class="link l-key">Reset sandi</button>
                                    </form>

                                    <form method="POST"
                                          action="{{ route('parkir.pelanggan.status', $member) }}"
                                          data-confirm="{{ $member->is_active ? 'Nonaktifkan' : 'Aktifkan kembali' }} {{ $member->name }}?">
                                        @csrf
                                        @method('PATCH')
                                        <button type="submit" class="link l-warn">
                                            {{ $member->is_active ? 'Nonaktifkan' : 'Aktifkan' }}
                                        </button>
                                    </form>

                                    {{-- Pelanggan yang punya riwayat langganan tidak boleh dihapus, cukup dinonaktifkan --}}
                                    @unless ($member->latestSubscription)
                                        <form method="POST"
                                              action="{{ route('parkir.pelanggan.destroy', $member) }}"
                                              data-confirm="Hapus {{ $member->name }} beserta kendaraan dan foto wajahnya? Tindakan ini tidak bisa dibatalkan.">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="link l-del">Hapus</button>
                                        </form>
                                    @endunless
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="empty">
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

        {{-- PAGINATION (dibuat manual karena layout ini tidak pakai Tailwind) --}}
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

    {{-- POPUP PERPANJANG / MULAI PAKET --}}
    <dialog id="dlg-perpanjang" class="dlg">
        <form method="POST" id="form-perpanjang">
            @csrf
            <h3 id="dlg-judul">Perpanjang paket</h3>
            <p class="sub" id="dlg-sub"></p>

            @if ($paket->isEmpty())
                <div class="alert alert-err">
                    Belum ada paket aktif. Buat dulu di menu Paket &amp; Harga.
                </div>
                <div style="display:flex;justify-content:flex-end;margin-top:18px">
                    <button type="button" class="btn btn-outline" data-tutup>Tutup</button>
                </div>
            @else
                <div class="field">
                    <label for="pilih-paket">Paket</label>
                    <select id="pilih-paket" name="subscription_plan_id" required>
                        @foreach ($paket as $p)
                            <option value="{{ $p->id }}" data-hari="{{ $p->duration_days }}">
                                {{ $p->name }} · {{ $p->duration_label }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <div class="ringkas" id="dlg-ringkas"></div>

                <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:18px">
                    <button type="button" class="btn btn-outline" data-tutup>Batal</button>
                    <button type="submit" class="btn btn-primary">Simpan</button>
                </div>
            @endif
        </form>
    </dialog>

    <script>
        // Konfirmasi sebelum form yang punya atribut data-confirm dikirim
        document.addEventListener('submit', function (e) {
            const msg = e.target.dataset.confirm;
            if (msg && !confirm(msg)) e.preventDefault();
        });

        // Popup perpanjang / mulai paket
        (function () {
            const dlg = document.getElementById('dlg-perpanjang');
            if (!dlg) return;

            const form = document.getElementById('form-perpanjang');
            const pilih = document.getElementById('pilih-paket');
            const ringkas = document.getElementById('dlg-ringkas');
            const judul = document.getElementById('dlg-judul');
            const sub = document.getElementById('dlg-sub');
            const bulan = ['Jan','Feb','Mar','Apr','Mei','Jun','Jul','Agu','Sep','Okt','Nov','Des'];
            const fmt = d => d.getDate() + ' ' + bulan[d.getMonth()] + ' ' + d.getFullYear();
            let konteks = null;

            // Pratinjau tanggal. Aturannya sama dengan yang dipakai server.
            function hitung() {
                if (!konteks || !pilih || pilih.selectedIndex < 0) return;

                const hari = parseInt(pilih.options[pilih.selectedIndex].dataset.hari, 10);
                const hariIni = new Date();
                hariIni.setHours(0, 0, 0, 0);

                if (konteks.aktif && konteks.berakhir) {
                    const lama = new Date(konteks.berakhir + 'T00:00:00');
                    const baru = new Date(lama);
                    baru.setDate(baru.getDate() + hari);
                    ringkas.innerHTML =
                        '<div>Berakhir sekarang: <b>' + fmt(lama) + '</b></div>' +
                        '<div>Berakhir setelah diperpanjang: <b>' + fmt(baru) + '</b></div>' +
                        '<div class="catatan">Disambung dari tanggal berakhir saat ini, sisa hari tidak hangus.</div>';
                } else {
                    const baru = new Date(hariIni);
                    baru.setDate(baru.getDate() + hari);
                    ringkas.innerHTML =
                        '<div>Mulai: <b>' + fmt(hariIni) + '</b></div>' +
                        '<div>Berlaku sampai: <b>' + fmt(baru) + '</b></div>' +
                        '<div class="catatan">' + (konteks.berakhir
                            ? 'Paket sebelumnya sudah habis, masa berlaku baru dihitung mulai hari ini.'
                            : 'Pelanggan belum punya paket, masa berlaku dihitung mulai hari ini.') + '</div>';
                }
            }

            document.querySelectorAll('[data-perpanjang]').forEach(function (tombol) {
                tombol.addEventListener('click', function () {
                    konteks = {
                        aktif: tombol.dataset.aktif === '1',
                        berakhir: tombol.dataset.berakhir || '',
                    };

                    form.action = tombol.dataset.url;
                    judul.textContent = tombol.dataset.paket ? 'Perpanjang paket' : 'Mulai paket';
                    sub.textContent = tombol.dataset.nama +
                        (tombol.dataset.paket ? ' · paket saat ini: ' + tombol.dataset.paket : ' · belum berlangganan');

                    if (pilih) {
                        // Pilih paket yang sama dengan paket sekarang sebagai bawaan
                        if (tombol.dataset.planid) pilih.value = tombol.dataset.planid;
                        if (pilih.selectedIndex < 0) pilih.selectedIndex = 0;
                    }

                    hitung();
                    dlg.showModal();
                });
            });

            if (pilih) pilih.addEventListener('change', hitung);
            dlg.querySelectorAll('[data-tutup]').forEach(b => b.addEventListener('click', () => dlg.close()));
        })();
    </script>

@endsection