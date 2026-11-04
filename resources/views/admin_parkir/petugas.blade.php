@extends('layouts.parkir')

@section('title', 'Kontak Petugas')
@section('heading', 'Kontak Petugas')
@section('subheading', 'Nomor WhatsApp yang menerima pesan saat member menekan "Panggil petugas"')

@section('content')
<style>
    .pt-note{margin-bottom:16px;padding:12px 14px;border-radius:10px;background:#eef4ff;border:1px solid #cfdcf7;font-size:13px;color:#1d4a94;line-height:1.5}
    .pt-name{font-weight:700}
    .pt-phone{font-variant-numeric:tabular-nums;letter-spacing:.03em}
    .pt-off{opacity:.55}
    dialog.modal{border:0;border-radius:16px;padding:0;width:min(460px,92vw);box-shadow:0 20px 60px rgba(11,31,51,.35)}
    dialog.modal::backdrop{background:rgba(11,31,51,.55)}
    .md-head{display:flex;justify-content:space-between;align-items:center;padding:18px 22px;border-bottom:1px solid var(--line)}
    .md-head h2{margin:0;font-size:17px}
    .md-x{background:none;border:0;font-size:24px;line-height:1;cursor:pointer;color:var(--muted)}
    .md-body{padding:22px}
    .md-foot{display:flex;justify-content:flex-end;gap:8px;padding:0 22px 22px}
    .hint{margin-top:6px;font-size:12px;color:var(--muted)}
</style>

<div class="toolbar">
    <span style="color:var(--muted);font-size:14px">Hanya kontak yang aktif yang menerima pesan.</span>
    <button type="button" class="btn btn-primary" onclick="openForm()">+ Tambah petugas</button>
</div>

@if ($contacts->where('is_active', true)->isEmpty())
    <div class="pt-note" style="background:#fff6e5;border-color:#f3dca8;color:#7a5a12">
        Belum ada kontak aktif. Tombol "Panggil petugas" di aplikasi akan membunyikan bel, tetapi tidak ada WhatsApp yang terkirim.
    </div>
@endif

<div class="card" style="padding:8px 16px;overflow-x:auto">
    <table>
        <thead><tr><th>Nama</th><th>No. WhatsApp</th><th>Status</th><th style="text-align:right">Aksi</th></tr></thead>
        <tbody>
        @forelse ($contacts as $c)
            <tr class="{{ $c->is_active ? '' : 'pt-off' }}">
                <td class="pt-name">{{ $c->name }}</td>
                <td class="pt-phone">{{ $c->phone }}</td>
                <td><span class="badge {{ $c->is_active ? 'b-on' : 'b-off' }}">{{ $c->is_active ? 'Aktif' : 'Nonaktif' }}</span></td>
                <td>
                    <div class="actions">
                        <button type="button" class="link l-edit"
                                data-id="{{ $c->id }}" data-name="{{ $c->name }}" data-phone="{{ $c->phone }}"
                                onclick="openEdit(this)">Edit</button>

                        <form method="POST" action="{{ route('parkir.petugas.toggle', $c) }}">
                            @csrf @method('PATCH')
                            <button class="link l-warn">{{ $c->is_active ? 'Nonaktifkan' : 'Aktifkan' }}</button>
                        </form>

                        <form method="POST" action="{{ route('parkir.petugas.destroy', $c) }}"
                              onsubmit="return confirm('Hapus kontak ini?')">
                            @csrf @method('DELETE')
                            <button class="link l-del">Hapus</button>
                        </form>
                    </div>
                </td>
            </tr>
        @empty
            <tr><td colspan="4" class="empty">Belum ada kontak petugas.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>

<dialog id="ptModal" class="modal">
    <div class="md-head">
        <h2 id="mdTitle">Tambah petugas</h2>
        <button type="button" class="md-x" onclick="ptModal.close()" aria-label="Tutup">&times;</button>
    </div>

    <form id="ptForm" method="POST">
        @csrf
        <input type="hidden" name="_method" id="fMethod" value="POST">
        <input type="hidden" name="_edit_id" id="fEditId" value="">

        <div class="md-body">
            <div class="field">
                <label for="fName">Nama petugas</label>
                <input id="fName" name="name" maxlength="100" placeholder="Contoh: Pak Budi" required>
                @error('name') <div class="err">{{ $message }}</div> @enderror
            </div>

            <div class="field" style="margin:0">
                <label for="fPhone">No. WhatsApp</label>
                <input id="fPhone" name="phone" inputmode="tel" placeholder="0812xxxxxxxx" required>
                <div class="hint">Format 0812..., +62812..., atau 812... semuanya diterima.</div>
                @error('phone') <div class="err">{{ $message }}</div> @enderror
            </div>
        </div>

        <div class="md-foot">
            <button type="button" class="btn btn-outline" onclick="ptModal.close()">Batal</button>
            <button type="submit" class="btn btn-primary">Simpan</button>
        </div>
    </form>
</dialog>

<script>
    const ptModal = document.getElementById('ptModal');
    const form = document.getElementById('ptForm');
    const storeUrl = @json(route('parkir.petugas.store'));
    const updateTpl = @json(route('parkir.petugas.update', '__ID__'));
    const $ = id => document.getElementById(id);

    function setForm(id, name, phone) {
        const edit = !!id;
        $('mdTitle').textContent = edit ? 'Edit petugas' : 'Tambah petugas';
        form.action = edit ? updateTpl.replace('__ID__', id) : storeUrl;
        $('fMethod').value = edit ? 'PUT' : 'POST';
        $('fEditId').value = id || '';
        $('fName').value = name || '';
        $('fPhone').value = phone || '';
    }

    function openForm() { setForm(null); ptModal.showModal(); }
    function openEdit(b) { setForm(b.dataset.id, b.dataset.name, b.dataset.phone); ptModal.showModal(); }

    ptModal.addEventListener('click', e => { if (e.target === ptModal) ptModal.close(); });

    // Validasi gagal: buka lagi dialognya dengan isian tadi
    @if ($errors->any() && old('_method') !== null)
        setForm(@json(old('_edit_id')) || null, @json(old('name')), @json(old('phone')));
        ptModal.showModal();
    @endif
</script>
@endsection