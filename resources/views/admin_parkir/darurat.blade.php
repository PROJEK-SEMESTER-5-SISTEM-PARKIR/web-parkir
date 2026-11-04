@extends('layouts.parkir')

@section('title', 'Buka Pintu Darurat')
@section('heading', 'Kontrol Pintu Darurat')
@section('subheading', 'Buka atau tutup palang secara manual dengan cepat dan terpantau')

@section('content')
<style>
    .dr-grid{display:grid;grid-template-columns:minmax(0,1.1fr) minmax(0,1.3fr);gap:20px;align-items:start}
    @media (max-width:900px){.dr-grid{grid-template-columns:1fr}}
    .dr-card h3{margin:0 0 14px;font-size:16px}
    .dr-state{display:flex;justify-content:space-between;gap:10px;padding:10px 12px;border-radius:10px;background:#f6f8fb;margin-bottom:8px;font-size:13.5px}
    .dr-state span{color:var(--muted)}
    .dr-warn{margin:0 0 16px;padding:10px 12px;border-radius:10px;background:#fff6e5;border:1px solid #f3dca8;font-size:13px;color:#7a5a12;line-height:1.5}
    .btn-danger{background:var(--red);color:#fff}
    .btn-danger:hover{filter:brightness(.94)}
    .btn-dark{background:#334155;color:#fff}
    .btn-dark:hover{filter:brightness(1.12)}
    .dr-field textarea{width:100%;padding:9px 12px;border:1px solid var(--line);border-radius:8px;font:inherit;resize:vertical}
    
    .dr-badge{display:inline-block;padding:2px 8px;border-radius:999px;font-size:12px;font-weight:600}
    .dr-badge.open{background:#fdecea;color:#b42318}
    .dr-badge.close{background:#e8eef5;color:#334155}

    /* Jam & Navigasi Tab Modern */
    .dr-top-bar {display: flex; justify-content: space-between; align-items: center; gap: 12px; flex-wrap: wrap; margin-bottom: 20px;}
    .dr-clock-pill {display: inline-flex; align-items: center; gap: 8px; padding: 6px 14px; border-radius: 999px; font-size: 13px; font-weight: 600; background: #fff; border: 1px solid var(--line); color: var(--text);}
    
    .dr-tabs {display: flex; gap: 6px; background: #eef1f5; padding: 4px; border-radius: 12px; margin-bottom: 16px;}
    .dr-tab-btn {flex: 1; padding: 8px 12px; border: 0; background: transparent; font-weight: 600; font-size: 13px; color: var(--muted); border-radius: 9px; cursor: pointer; transition: all .2s;}
    .dr-tab-btn.active {background: #fff; color: var(--text); box-shadow: 0 1px 3px rgba(0,0,0,0.05);}
    .dr-tab-content {display: none;}
    .dr-tab-content.active {display: block;}

    /* Filter & Pagination untuk Tabel Riwayat */
    .dr-filter-bar { display: flex; gap: 8px; margin-bottom: 12px; flex-wrap: wrap; align-items: center; }
    .dr-filter-bar input, .dr-filter-bar select { padding: 6px 10px; font-size: 12.5px; border: 1px solid var(--line); border-radius: 8px; background: #fff; }
    .dr-pagination { display: flex; justify-content: center; align-items: center; gap: 6px; margin-top: 14px; }
    .dr-page-btn { padding: 5px 10px; font-size: 12px; font-weight: 600; border: 1px solid var(--line); background: #fff; border-radius: 6px; cursor: pointer; }
    .dr-page-btn.active { background: var(--accent); color: #fff; border-color: var(--accent); }
    .dr-page-btn:disabled { opacity: 0.5; cursor: not-allowed; }
    .btn-reset-filter { background: #f1f5f9; border: 1px solid var(--line); padding: 6px 12px; font-size: 12px; border-radius: 8px; cursor: pointer; font-weight: 600; color: var(--muted); }
    .btn-reset-filter:hover { background: #e2e8f0; color: var(--text); }

    /* Custom Modal Konfirmasi Modern */
    .dr-modal-backdrop {
        position: fixed; top: 0; left: 0; width: 100%; height: 100%;
        background: rgba(15, 23, 42, 0.6); backdrop-filter: blur(4px);
        display: flex; align-items: center; justify-content: center; z-index: 9999;
        opacity: 0; visibility: hidden; transition: all 0.25s ease;
    }
    .dr-modal-backdrop.show { opacity: 1; visibility: visible; }
    .dr-modal-box {
        background: #fff; width: 100%; max-width: 420px; border-radius: 16px;
        padding: 24px; box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1);
        transform: scale(0.95); transition: transform 0.25s ease;
    }
    .dr-modal-backdrop.show .dr-modal-box { transform: scale(1); }
    .dr-modal-title { font-size: 17px; font-weight: 700; color: var(--text); margin-bottom: 8px; display: flex; align-items: center; gap: 8px; }
    .dr-modal-desc { font-size: 14px; color: var(--muted); line-height: 1.5; margin-bottom: 20px; }
    .dr-modal-actions { display: flex; gap: 10px; justify-content: flex-end; }
    .dr-modal-actions button { padding: 8px 16px; border-radius: 9px; font-weight: 600; font-size: 13.5px; cursor: pointer; border: 0; }
    .btn-cancel { background: #f1f5f9; color: #475569; }
    .btn-cancel:hover { background: #e2e8f0; }
    .btn-confirm-action { background: var(--red); color: #fff; }
    .btn-confirm-action.dark { background: #334155; }
</style>

{{-- Bar Atas: Jam Real-time --}}
<div class="dr-top-bar">
    <div class="dr-clock-pill" id="realtime-clock">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color:var(--muted)"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
        <span id="clock-text">Memuat waktu...</span>
    </div>
</div>

<div class="dr-grid">
    {{-- Kolom Kiri: Form Kontrol (Terkunci / Sticky) --}}
    <div class="card dr-card" style="position: sticky; top: 20px;">
        <h3>Kontrol Manual Palang</h3>

        <div class="dr-warn">
            <b>Perhatian:</b> Perintah darurat ini melewati verifikasi otomatis wajah & plat nomor. Gunakan hanya saat kondisi mendesak.
        </div>

        {{-- Status Real-time Gerbang --}}
        <div style="margin-bottom: 16px;">
            @foreach ($gates as $key => $label)
                @php $st = $states[$key] ?? null; @endphp
                <div class="dr-state"><b>{{ $label }}</b><span>{{ $st->status ?? 'Belum ada data' }}</span></div>
            @endforeach
        </div>

        {{-- Navigasi Tab --}}
        <div class="dr-tabs">
            <button type="button" class="dr-tab-btn active" onclick="switchTab('buka', this)">Buka Palang</button>
            <button type="button" class="dr-tab-btn" onclick="switchTab('tutup', this)">Tutup Palang</button>
        </div>

        {{-- ===== TAB KONTEN: BUKA PALANG ===== --}}
        <div id="tab-buka" class="dr-tab-content active">
            <form method="POST" action="{{ route('parkir.darurat.open') }}" id="drForm">
                @csrf
                <div class="field">
                    <label for="gate">Gerbang Target</label>
                    <select id="gate" name="gate">
                        @foreach ($gates as $key => $label)
                            <option value="{{ $key }}" @selected(old('gate') === $key)>{{ $label }}</option>
                        @endforeach
                        <option value="semua" @selected(old('gate') === 'semua')>Kedua gerbang (masuk &amp; keluar)</option>
                    </select>
                    @error('gate') <div class="err">{{ $message }}</div> @enderror
                </div>

                <div class="field dr-field">
                    <label for="reason">Alasan Pembukaan <span style="font-weight: normal; color: var(--muted);">(Opsional)</span></label>
                    <textarea id="reason" name="reason" rows="2" maxlength="255"
                              placeholder="Contoh: kendaraan member tertahan">{{ old('reason') }}</textarea>
                    @error('reason') <div class="err">{{ $message }}</div> @enderror
                </div>

                <button type="button" onclick="openCustomModal('open')" class="btn btn-danger" style="width:100%; margin-top: 6px;">Buka palang sekarang</button>
            </form>
        </div>

        {{-- ===== TAB KONTEN: TUTUP PALANG ===== --}}
        <div id="tab-tutup" class="dr-tab-content">
            <form method="POST" action="{{ route('parkir.darurat.close') }}" id="tutupForm">
                @csrf
                <div class="field">
                    <label for="gate_tutup">Gerbang Target</label>
                    <select id="gate_tutup" name="gate">
                        @foreach ($gates as $key => $label)
                            <option value="{{ $key }}">{{ $label }}</option>
                        @endforeach
                        <option value="semua">Kedua gerbang (masuk &amp; keluar)</option>
                    </select>
                </div>

                <button type="button" onclick="openCustomModal('close')" class="btn btn-dark" style="width:100%; margin-top: 6px;">Tutup palang sekarang</button>
                <div style="margin-top:8px; font-size:12px; color:var(--muted); line-height:1.4; background:#f8fafc; padding:8px; border-radius:8px;">
                    ℹ️ Palang menunda penutupan jika jalur masih dilewati kendaraan.
                </div>
            </form>
        </div>
    </div>

    {{-- Kolom Kanan: Riwayat Aktivitas dengan Filter Tanggal, Pencarian & Pagination Per 10 Data --}}
    <div class="card dr-card">
        <h3>Riwayat Tindakan Darurat</h3>

        {{-- Baris Filter Berdasarkan Tanggal & Pencarian Teks --}}
        <div class="dr-filter-bar">
            <input type="text" id="filterSearch" placeholder="Cari alasan / admin..." onkeyup="filterTable()" style="flex: 1; min-width: 140px;">
            <input type="date" id="filterDate" onchange="filterTable()" title="Filter Berdasarkan Tanggal" style="cursor: pointer;">
            <button type="button" class="btn-reset-filter" onclick="resetFilter()">Reset</button>
        </div>

        <div style="overflow-x: auto;">
            <table id="historyTable">
                <thead><tr><th>Waktu</th><th>Admin</th><th>Gerbang</th><th>Aksi</th><th>Alasan</th></tr></thead>
                <tbody id="historyTableBody">
                @forelse ($logs as $l)
                    <tr class="history-row" data-date="{{ $l->created_at->format('Y-m-d') }}">
                        <td style="white-space:nowrap">{{ $l->created_at->format('d/m/Y H:i') }}</td>
                        <td>{{ $l->admin->name ?? '-' }}</td>
                        <td>{{ $gates[$l->gate] ?? $l->gate }}</td>
                        <td>
                            @if (($l->action ?? 'open') === 'close')
                                <span class="dr-badge close">TUTUP</span>
                            @else
                                <span class="dr-badge open">BUKA</span>
                            @endif
                        </td>
                        <td>{{ $l->reason ?: '-' }}</td>
                    </tr>
                @empty
                    <tr id="emptyRow"><td colspan="5" class="empty">Belum ada riwayat tercatat.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>

        {{-- Tombol Navigasi Halaman (Pagination Angka 1, 2, dst) --}}
        <div class="dr-pagination" id="paginationControls"></div>
    </div>
</div>

{{-- ===== CUSTOM MODAL KONFIRMASI MODERN ===== --}}
<div id="customModal" class="dr-modal-backdrop">
    <div class="dr-modal-box">
        <div class="dr-modal-title">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color:var(--red)"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
            <span id="modalHeading">Konfirmasi Tindakan</span>
        </div>
        <div class="dr-modal-desc" id="modalMessage">
            Apakah Anda yakin ingin melanjutkan tindakan ini?
        </div>
        <div class="dr-modal-actions">
            <button type="button" class="btn-cancel" onclick="closeCustomModal()">Batal</button>
            <button type="button" id="modalConfirmBtn" class="btn-confirm-action" onclick="submitActiveForm()">Ya, Lanjutkan</button>
        </div>
    </div>
</div>

<script>
    let activeFormId = null;
    let currentPage = 1;
    const rowsPerPage = 10;

    function switchTab(type, el) {
        document.querySelectorAll('.dr-tab-content').forEach(c => c.classList.remove('active'));
        document.querySelectorAll('.dr-tab-btn').forEach(b => b.classList.remove('active'));
        document.getElementById('tab-' + type).classList.add('active');
        el.classList.add('active');
    }

    function openCustomModal(actionType) {
        const modal = document.getElementById('customModal');
        const msgEl = document.getElementById('modalMessage');
        const confirmBtn = document.getElementById('modalConfirmBtn');
        
        if (actionType === 'open') {
            activeFormId = 'drForm';
            const sel = document.getElementById('gate');
            const target = sel.value === 'semua' ? 'KEDUA palang' : 'palang ' + sel.options[sel.selectedIndex].text;
            msgEl.innerHTML = `Yakin ingin membuka <b>${target}</b> sekarang? Verifikasi otomatis akan dilewati.`;
            confirmBtn.className = 'btn-confirm-action';
            confirmBtn.innerText = 'Buka Palang';
        } else {
            activeFormId = 'tutupForm';
            const sel = document.getElementById('gate_tutup');
            const target = sel.value === 'semua' ? 'KEDUA palang' : 'palang ' + sel.options[sel.selectedIndex].text;
            msgEl.innerHTML = `Yakin ingin menutup <b>${target}</b> sekarang?`;
            confirmBtn.className = 'btn-confirm-action dark';
            confirmBtn.innerText = 'Tutup Palang';
        }
        modal.classList.add('show');
    }

    function closeCustomModal() {
        document.getElementById('customModal').classList.remove('show');
        activeFormId = null;
    }

    function submitActiveForm() {
        if (activeFormId) {
            document.getElementById(activeFormId).submit();
        }
    }

    // Reset Filter Pencarian & Tanggal
    function resetFilter() {
        document.getElementById('filterSearch').value = '';
        document.getElementById('filterDate').value = '';
        filterTable();
    }

    // Sistem Filter Berdasarkan Tanggal & Kata Kunci dengan Pagination 10 Data
    function filterTable() {
        currentPage = 1;
        renderPagination();
    }

    function renderPagination() {
        const searchInput = document.getElementById('filterSearch').value.toLowerCase();
        const dateFilter = document.getElementById('filterDate').value;
        const rows = document.querySelectorAll('.history-row');
        
        let filteredRows = [];

        rows.forEach(row => {
            const text = row.innerText.toLowerCase();
            const rowDate = row.getAttribute('data-date');
            
            const matchSearch = text.includes(searchInput);
            const matchDate = dateFilter === "" || rowDate === dateFilter;

            if (matchSearch && matchDate) {
                filteredRows.push(row);
                row.style.display = 'none';
            } else {
                row.style.display = 'none';
            }
        });

        const totalPages = Math.ceil(filteredRows.length / rowsPerPage) || 1;
        if (currentPage > totalPages) currentPage = totalPages;

        const start = (currentPage - 1) * rowsPerPage;
        const end = start + rowsPerPage;

        for (let i = start; i < end && i < filteredRows.length; i++) {
            filteredRows[i].style.display = '';
        }

        // Buat Tombol Angka Pagination
        const paginationContainer = document.getElementById('paginationControls');
        paginationContainer.innerHTML = '';

        if (totalPages > 1) {
            for (let i = 1; i <= totalPages; i++) {
                const btn = document.createElement('button');
                btn.className = `dr-page-btn ${i === currentPage ? 'active' : ''}`;
                btn.innerText = i;
                btn.onclick = () => {
                    currentPage = i;
                    renderPagination();
                };
                paginationContainer.appendChild(btn);
            }
        }
    }

    // Jam Real-time
    function updateClock() {
        const now = new Date();
        const optionsDate = { weekday: 'short', day: 'numeric', month: 'short', year: 'numeric' };
        const dateString = now.toLocaleDateString('id-ID', optionsDate);
        const hours = String(now.getHours()).padStart(2, '0');
        const minutes = String(now.getMinutes()).padStart(2, '0');
        const seconds = String(now.getSeconds()).padStart(2, '0');
        document.getElementById('clock-text').innerText = `${dateString}, ${hours}:${minutes}:${seconds} WIB`;
    }

    updateClock();
    setInterval(updateClock, 1000);

    // Jalankan pagination saat halaman pertama kali dimuat
    document.addEventListener("DOMContentLoaded", () => {
        renderPagination();
    });
</script>
@endsection