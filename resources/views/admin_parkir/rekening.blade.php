@extends('layouts.parkir')

@section('title', 'Rekening & QRIS')
@section('heading', 'Rekening & QRIS')
@section('subheading', 'Tujuan pembayaran yang tampil di aplikasi member saat perpanjangan')

@section('content')
<style>
    .rk-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:18px}
    .rk-card{display:flex;flex-direction:column;gap:12px}
    .rk-top{display:flex;justify-content:space-between;align-items:center;gap:8px}
    .rk-type{font-size:12px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:var(--muted)}
    .rk-card h3{margin:0;font-size:18px}
    .rk-num{font-size:22px;font-weight:800;letter-spacing:.05em;font-variant-numeric:tabular-nums}
    .rk-name{color:var(--muted);font-size:13.5px}
    .rk-qr{width:100%;max-width:220px;aspect-ratio:1;object-fit:contain;border:1px solid var(--line);border-radius:12px;background:#fff;padding:8px;align-self:center}
    .rk-card.is-off{opacity:.55}
    .rk-empty{padding:40px 20px;text-align:center;color:var(--muted)}
    .rk-actions{display:flex;gap:14px;margin-top:auto;padding-top:12px;border-top:1px solid var(--line)}
    .rk-actions form{margin:0}

    dialog.modal{border:0;border-radius:16px;padding:0;width:min(500px,92vw);box-shadow:0 20px 60px rgba(11,31,51,.35)}
    dialog.modal::backdrop{background:rgba(11,31,51,.55)}
    .md-head{display:flex;justify-content:space-between;align-items:center;padding:18px 22px;border-bottom:1px solid var(--line)}
    .md-head h2{margin:0;font-size:17px}
    .md-x{background:none;border:0;font-size:24px;line-height:1;cursor:pointer;color:var(--muted)}
    .md-body{padding:22px}
    .md-foot{display:flex;justify-content:flex-end;gap:8px;padding:0 22px 22px}
    .md-prev{max-width:150px;border:1px solid var(--line);border-radius:10px;margin-top:8px;display:none}
</style>

<div class="toolbar">
    <span style="color:var(--muted);font-size:14px">Hanya metode yang aktif yang tampil di aplikasi member.</span>
    <button type="button" class="btn btn-primary" onclick="openForm()">+ Tambah metode</button>
</div>

@if ($accounts->isEmpty())
    <div class="card rk-empty">Belum ada rekening atau QRIS. Tambahkan minimal satu supaya member bisa memperpanjang.</div>
@else
    <div class="rk-grid">
        @foreach ($accounts as $a)
            <div class="card rk-card {{ $a->is_active ? '' : 'is-off' }}">
                <div class="rk-top">
                    <span class="rk-type">{{ $a->type === 'qris' ? 'QRIS' : 'Transfer bank' }}</span>
                    <span class="badge {{ $a->is_active ? 'b-on' : 'b-off' }}">{{ $a->is_active ? 'Aktif' : 'Nonaktif' }}</span>
                </div>

                <h3>{{ $a->label }}</h3>

                @if ($a->type === 'bank')
                    <div class="rk-num">{{ $a->account_number }}</div>
                    <div class="rk-name">a.n. {{ $a->account_name }}</div>
                @else
                    <img class="rk-qr" src="{{ $a->qris_url }}" alt="QRIS {{ $a->label }}">
                    @if ($a->account_name) <div class="rk-name" style="text-align:center">{{ $a->account_name }}</div> @endif
                @endif

                <div class="rk-actions">
                    <button type="button" class="link l-edit"
                            data-id="{{ $a->id }}" data-type="{{ $a->type }}" data-label="{{ $a->label }}"
                            data-number="{{ $a->account_number }}" data-name="{{ $a->account_name }}"
                            data-qris="{{ $a->qris_url }}" onclick="openEdit(this)">Edit</button>

                    <form method="POST" action="{{ route('parkir.rekening.toggle', $a) }}">
                        @csrf @method('PATCH')
                        <button class="link l-warn">{{ $a->is_active ? 'Nonaktifkan' : 'Aktifkan' }}</button>
                    </form>

                    <form method="POST" action="{{ route('parkir.rekening.destroy', $a) }}"
                          onsubmit="return confirm('Hapus metode pembayaran ini?')">
                        @csrf @method('DELETE')
                        <button class="link l-del">Hapus</button>
                    </form>
                </div>
            </div>
        @endforeach
    </div>
@endif

{{-- ===== Dialog tambah / edit ===== --}}
<dialog id="rkModal" class="modal">
    <div class="md-head">
        <h2 id="mdTitle">Tambah metode pembayaran</h2>
        <button type="button" class="md-x" onclick="rkModal.close()" aria-label="Tutup">&times;</button>
    </div>

    <form id="rkForm" method="POST" enctype="multipart/form-data">
        @csrf
        <input type="hidden" name="_method" id="fMethod" value="POST">
        <input type="hidden" name="_edit_id" id="fEditId" value="">

        <div class="md-body">
            <div class="field">
                <label for="fType">Jenis</label>
                <select id="fType" name="type">
                    <option value="bank">Transfer bank</option>
                    <option value="qris">QRIS</option>
                </select>
                @error('type') <div class="err">{{ $message }}</div> @enderror
            </div>

            <div class="field">
                <label for="fLabel" id="lblLabel">Nama bank</label>
                <input id="fLabel" name="label" maxlength="60" placeholder="Contoh: BCA">
                @error('label') <div class="err">{{ $message }}</div> @enderror
            </div>

            <div id="boxBank">
                <div class="field">
                    <label for="fNumber">Nomor rekening</label>
                    <input id="fNumber" name="account_number" inputmode="numeric" placeholder="1234567890">
                    @error('account_number') <div class="err">{{ $message }}</div> @enderror
                </div>
                <div class="field">
                    <label for="fName">Atas nama</label>
                    <input id="fName" name="account_name" maxlength="100">
                    @error('account_name') <div class="err">{{ $message }}</div> @enderror
                </div>
            </div>

            <div id="boxQris">
                <div class="field">
                    <label for="fQris">Gambar QRIS</label>
                    <input id="fQris" type="file" name="qris" accept="image/png,image/jpeg">
                    <img id="qrPrev" class="md-prev" alt="">
                    @error('qris') <div class="err">{{ $message }}</div> @enderror
                </div>
            </div>
        </div>

        <div class="md-foot">
            <button type="button" class="btn btn-outline" onclick="rkModal.close()">Batal</button>
            <button type="submit" class="btn btn-primary">Simpan</button>
        </div>
    </form>
</dialog>

<script>
    const rkModal = document.getElementById('rkModal');
    const form = document.getElementById('rkForm');
    const storeUrl = @json(route('parkir.rekening.store'));
    const updateTpl = @json(route('parkir.rekening.update', '__ID__'));
    const $ = id => document.getElementById(id);

    function applyType() {
        const bank = $('fType').value === 'bank';
        $('boxBank').style.display = bank ? 'block' : 'none';
        $('boxQris').style.display = bank ? 'none' : 'block';
        $('lblLabel').textContent = bank ? 'Nama bank' : 'Label';
        $('fLabel').placeholder = bank ? 'Contoh: BCA' : 'Contoh: QRIS Parkir';
    }
    $('fType').addEventListener('change', applyType);

    $('fQris').addEventListener('change', e => {
        const f = e.target.files[0];
        if (!f) return;
        $('qrPrev').src = URL.createObjectURL(f);
        $('qrPrev').style.display = 'block';
    });

    function setForm(id, v) {
        const edit = !!id;
        $('mdTitle').textContent = edit ? 'Edit metode pembayaran' : 'Tambah metode pembayaran';
        form.action = edit ? updateTpl.replace('__ID__', id) : storeUrl;
        $('fMethod').value = edit ? 'PUT' : 'POST';
        $('fEditId').value = id || '';
        $('fType').value = v.type || 'bank';
        $('fType').disabled = edit;          // jenis tidak bisa diubah saat edit
        $('fLabel').value = v.label || '';
        $('fNumber').value = v.number || '';
        $('fName').value = v.name || '';
        $('fQris').value = '';
        $('qrPrev').style.display = v.qris ? 'block' : 'none';
        $('qrPrev').src = v.qris || '';
        applyType();
    }

    function openForm() { setForm(null, {}); rkModal.showModal(); }
    function openEdit(b) { setForm(b.dataset.id, b.dataset); rkModal.showModal(); }

    rkModal.addEventListener('click', e => { if (e.target === rkModal) rkModal.close(); });

    @if ($errors->any() && old('_method') !== null)
        setForm(@json(old('_edit_id')) || null, {
            type: @json(old('type')), label: @json(old('label')),
            number: @json(old('account_number')), name: @json(old('account_name'))
        });
        rkModal.showModal();
    @endif
</script>
@endsection