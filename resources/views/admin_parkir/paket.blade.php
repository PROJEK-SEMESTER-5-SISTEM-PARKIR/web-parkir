@extends('layouts.parkir')
@section('title', 'Paket & Harga')
@section('heading', 'Paket & Harga')
@section('subheading', 'Atur paket langganan dan harga per mobil')

@php
    $editingPlan = $plans->firstWhere('id', old('edit_id'));
    $hasErrors = $errors->any();
@endphp

@section('content')

<style>
    .pk-toolbar {
        display:flex;
        align-items:center;
        justify-content:space-between;
        gap:12px;
        flex-wrap:wrap;
        margin-bottom:20px;
    }

    .pk-quick {
        display:flex;
        gap:8px;
        flex-wrap:wrap;
        margin-top:8px;
    }

    .pk-quick .btn {
        padding:5px 12px;
        font-size:13px;
    }

    .pk-field-note {
        color:var(--muted);
        font-size:12px;
        margin-top:6px;
        line-height:1.5;
    }

    .pk-check {
        display:flex;
        align-items:flex-start;
        gap:10px;
        margin-bottom:12px;
        font-size:14px;
        cursor:pointer;
    }

    .pk-check input {
        width:auto;
        margin-top:3px;
    }

    .pk-check small {
        display:block;
        color:var(--muted);
        font-size:12px;
        margin-top:2px;
    }

    .pk-textarea {
        width:100%;
        min-height:80px;
        padding:9px 12px;
        border:1px solid var(--line);
        border-radius:8px;
        font:inherit;
        resize:vertical;
        box-sizing:border-box;
    }

    .pk-textarea:focus {
        outline:2px solid rgba(47,128,237,.35);
        border-color:var(--accent);
    }

    .pk-warn {
        margin-bottom:16px;
        padding:12px 14px;
        border-radius:10px;
        background:#fff6e5;
        border:1px solid #f3dca8;
        font-size:13px;
        color:#7a5a12;
        line-height:1.5;
    }

    .pk-actions {
        display:flex;
        align-items:center;
        justify-content:flex-end;
        gap:12px;
        flex-wrap:wrap;
        margin-top:22px;
        padding-top:18px;
        border-top:1px solid var(--line);
    }

    /* Modal */
    .pk-modal {
        display:none;
        position:fixed;
        inset:0;
        z-index:9999;
        padding:20px;
        background:rgba(15,23,42,.58);
        align-items:center;
        justify-content:center;
        overflow-y:auto;
    }

    .pk-modal.show {
        display:flex;
    }

    .pk-modal-box {
        width:100%;
        max-width:650px;
        max-height:calc(100vh - 40px);
        overflow-y:auto;
        background:var(--card, #fff);
        border:1px solid var(--line);
        border-radius:14px;
        box-shadow:0 20px 60px rgba(0,0,0,.2);
        animation:pkModalIn .18s ease-out;
    }

    .pk-modal-head {
        display:flex;
        align-items:flex-start;
        justify-content:space-between;
        gap:12px;
        padding:20px 22px;
        border-bottom:1px solid var(--line);
    }

    .pk-modal-head h3 {
        margin:0;
        font-size:19px;
    }

    .pk-modal-head p {
        margin:5px 0 0;
        color:var(--muted);
        font-size:13px;
    }

    .pk-modal-close {
        width:34px;
        height:34px;
        flex-shrink:0;
        border:1px solid var(--line);
        border-radius:8px;
        background:transparent;
        color:inherit;
        font-size:22px;
        cursor:pointer;
    }

    .pk-modal-body {
        padding:22px;
    }

    .pk-modal-body .field {
        margin-bottom:16px;
    }

    .pk-modal-body label:not(.pk-check) {
        display:inline-block;
        margin-bottom:7px;
    }

    .pk-modal-body input:not([type="checkbox"]),
    .pk-modal-body textarea {
        box-sizing:border-box;
        max-width:100%;
    }

    .pk-modal-body input[type="number"],
    .pk-modal-body input[type="text"] {
        width:100%;
    }

    .pk-modal-body .pk-check input {
        width:auto;
    }

    .pk-table-wrap {
        overflow-x:auto;
    }

    .pk-table-wrap table {
        min-width:680px;
        width:100%;
    }

    .pk-table-wrap td,
    .pk-table-wrap th {
        vertical-align:middle;
    }

    .pk-row-actions {
        display:flex;
        align-items:center;
        justify-content:flex-end;
        gap:12px;
        flex-wrap:wrap;
    }

    .pk-row-actions form {
        margin:0;
    }

    @keyframes pkModalIn {
        from {
            opacity:0;
            transform:translateY(8px) scale(.99);
        }
        to {
            opacity:1;
            transform:translateY(0) scale(1);
        }
    }

    @media(max-width:640px) {
        .pk-modal {
            padding:10px;
            align-items:center;
        }

        .pk-modal-box {
            max-height:calc(100vh - 20px);
        }

        .pk-modal-head,
        .pk-modal-body {
            padding:16px;
        }

        .pk-toolbar {
            align-items:stretch;
        }

        .pk-toolbar > .btn {
            text-align:center;
            justify-content:center;
        }

        .pk-actions {
            justify-content:stretch;
        }

        .pk-actions > * {
            flex:1;
            text-align:center;
        }
    }
</style>

{{-- DAFTAR PAKET --}}
<div class="card">

    <div class="pk-toolbar">
        <div style="color:var(--muted);font-size:14px">
            <strong>{{ $plans->count() }}</strong> paket
            &middot;
            <strong>{{ $plans->where('is_active', true)->count() }}</strong> aktif
        </div>

        <button type="button" class="btn btn-primary" id="btnTambah">
            + Tambah Paket
        </button>
    </div>

    <div class="pk-table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Paket</th>
                    <th>Durasi</th>
                    <th>Harga per mobil</th>
                    <th>Status</th>
                    <th style="text-align:right">Aksi</th>
                </tr>
            </thead>

            <tbody>
                @forelse ($plans as $p)
                    @php
                        $jumlahPakai = (int) ($dipakai[$p->id] ?? 0);
                    @endphp

                    <tr>
                        <td>
                            <b>{{ $p->name }}</b>

                            @if ($p->is_popular)
                                <span class="badge b-role" style="margin-left:6px">
                                    Rekomendasi
                                </span>
                            @endif

                            @if ($p->description)
                                <div style="color:var(--muted);font-size:12.5px;margin-top:4px">
                                    {{ $p->description }}
                                </div>
                            @endif
                        </td>

                        <td>{{ $p->duration_days }} hari</td>

                        <td>
                            Rp {{ number_format($p->price_mobil, 0, ',', '.') }}
                        </td>

                        <td>
                            <span class="badge {{ $p->is_active ? 'b-on' : 'b-off' }}">
                                {{ $p->is_active ? 'Aktif' : 'Nonaktif' }}
                            </span>

                            <div style="color:var(--muted);font-size:12px;margin-top:4px">
                                {{ $jumlahPakai > 0 ? $jumlahPakai.' langganan' : 'Belum dipakai' }}
                            </div>
                        </td>

                        <td>
                            <div class="pk-row-actions">

                                {{-- TOMBOL EDIT MEMBUKA MODAL --}}
                                <button
                                    type="button"
                                    class="link l-edit pk-edit"
                                    data-id="{{ $p->id }}"
                                    data-name="{{ $p->name }}"
                                    data-duration="{{ $p->duration_days }}"
                                    data-mobil="{{ (int) $p->price_mobil }}"
                                    data-description="{{ $p->description }}"
                                    data-sort="{{ $p->sort_order }}"
                                    data-active="{{ $p->is_active ? 1 : 0 }}"
                                    data-popular="{{ $p->is_popular ? 1 : 0 }}"
                                    data-used="{{ $jumlahPakai }}"
                                    data-update-url="{{ route('parkir.paket.update', $p) }}"
                                >
                                    Ubah
                                </button>

                                <form
                                    method="POST"
                                    action="{{ route('parkir.paket.toggle', $p) }}"
                                    onsubmit="return confirm('Ubah status paket ini?')"
                                >
                                    @csrf
                                    @method('PATCH')

                                    <button type="submit" class="link l-warn">
                                        {{ $p->is_active ? 'Nonaktifkan' : 'Aktifkan' }}
                                    </button>
                                </form>

                                @if ($jumlahPakai === 0)
                                    <form
                                        method="POST"
                                        action="{{ route('parkir.paket.destroy', $p) }}"
                                        onsubmit="return confirm('Hapus paket ini? Tindakan ini tidak bisa dibatalkan.')"
                                    >
                                        @csrf
                                        @method('DELETE')

                                        <button type="submit" class="link l-del">
                                            Hapus
                                        </button>
                                    </form>
                                @endif

                            </div>
                        </td>
                    </tr>

                @empty
                    <tr>
                        <td colspan="5" class="empty">
                            Belum ada paket. Klik "Tambah Paket" untuk membuat paket pertama.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <p style="margin:16px 0 0;color:var(--muted);font-size:12.5px;line-height:1.6">
        Perubahan harga dan durasi hanya berlaku untuk pendaftaran baru.
        Langganan yang sudah berjalan tidak ikut berubah.
        Paket yang sudah pernah digunakan tidak dapat dihapus, tetapi dapat dinonaktifkan.
    </p>
</div>


{{-- MODAL TAMBAH / UBAH PAKET --}}
<div
    class="pk-modal {{ $hasErrors ? 'show' : '' }}"
    id="modalPaket"
    role="dialog"
    aria-modal="true"
    aria-labelledby="modalPaketTitle"
    aria-hidden="{{ $hasErrors ? 'false' : 'true' }}"
>
    <div class="pk-modal-box">

        <div class="pk-modal-head">
            <div>
                <h3 id="modalPaketTitle">
                    {{ $editingPlan ? 'Ubah Paket' : 'Tambah Paket' }}
                </h3>

                <p id="modalPaketSubtitle">
                    {{ $editingPlan ? 'Perbarui informasi paket langganan.' : 'Buat paket langganan baru.' }}
                </p>
            </div>

            <button type="button" class="pk-modal-close" id="btnTutup" aria-label="Tutup">
                &times;
            </button>
        </div>

        <div class="pk-modal-body">

            <div class="pk-warn" id="paketWarning"
                 style="{{ $editingPlan && ($dipakai[$editingPlan->id] ?? 0) > 0 ? '' : 'display:none' }}">
                Paket ini sudah dipakai
                <b id="jumlahPakai">
                    {{ $editingPlan ? ($dipakai[$editingPlan->id] ?? 0) : 0 }}
                </b>
                langganan. Perubahan harga dan durasi hanya berlaku untuk pendaftaran berikutnya.
                Langganan yang sudah berjalan tidak ikut berubah.
            </div>

            <form
                method="POST"
                id="formPaket"
                action="{{ $editingPlan ? route('parkir.paket.update', $editingPlan) : route('parkir.paket.store') }}"
            >
                @csrf

                <input
                    type="hidden"
                    name="_method"
                    id="formMethod"
                    value="PUT"
                    {{ $editingPlan ? '' : 'disabled' }}
                >

                <input
                    type="hidden"
                    name="edit_id"
                    id="editId"
                    value="{{ old('edit_id', $editingPlan->id ?? '') }}"
                >

                {{-- Harga motor sudah tidak dipakai (khusus mobil). Tetap dikirim 0 supaya validasi di
                     PaketController yang masih mewajibkan price_motor tidak menolak form. --}}
                <input type="hidden" name="price_motor" value="0">

                <div class="field">
                    <label for="name">Nama paket</label>

                    <input
                        id="name"
                        type="text"
                        name="name"
                        value="{{ old('name', $editingPlan->name ?? '') }}"
                        placeholder="Contoh: Bulanan"
                        autocomplete="off"
                        maxlength="255"
                        required
                    >

                    @error('name')
                        <div class="err">{{ $message }}</div>
                    @enderror
                </div>

                <div class="field">
                    <label for="duration_days">Durasi (hari)</label>

                    <input
                        id="duration_days"
                        type="number"
                        name="duration_days"
                        min="1"
                        max="3650"
                        inputmode="numeric"
                        value="{{ old('duration_days', $editingPlan->duration_days ?? '') }}"
                        required
                    >

                    <div class="pk-quick">
                        @foreach ([7 => '7 hari', 30 => '30 hari', 90 => '90 hari', 180 => '180 hari', 365 => '365 hari'] as $hari => $label)
                            <button
                                type="button"
                                class="btn btn-outline"
                                data-days="{{ $hari }}"
                            >
                                {{ $label }}
                            </button>
                        @endforeach
                    </div>

                    @error('duration_days')
                        <div class="err">{{ $message }}</div>
                    @enderror
                </div>

                <div class="field" style="max-width:280px">
                    <label for="price_mobil">Harga per mobil (Rp)</label>

                    <input
                        id="price_mobil"
                        type="number"
                        name="price_mobil"
                        min="0"
                        inputmode="numeric"
                        value="{{ old('price_mobil', $editingPlan ? (int) $editingPlan->price_mobil : '') }}"
                        required
                    >

                    <div class="pk-field-note">
                        Member dengan beberapa mobil ditagih sesuai jumlah mobilnya.
                    </div>

                    @error('price_mobil')
                        <div class="err">{{ $message }}</div>
                    @enderror
                </div>

                <div class="field">
                    <label for="description">
                        Keterangan
                        <span style="font-weight:400;color:var(--muted)">(opsional)</span>
                    </label>

                    <textarea
                        id="description"
                        name="description"
                        class="pk-textarea"
                        maxlength="500"
                        placeholder="Catatan singkat tentang paket ini"
                    >{{ old('description', $editingPlan->description ?? '') }}</textarea>

                    @error('description')
                        <div class="err">{{ $message }}</div>
                    @enderror
                </div>

                <div class="field" style="max-width:200px">
                    <label for="sort_order">Urutan tampil</label>

                    <input
                        id="sort_order"
                        type="number"
                        name="sort_order"
                        min="0"
                        inputmode="numeric"
                        value="{{ old('sort_order', $editingPlan->sort_order ?? 0) }}"
                    >

                    <div class="pk-field-note">Angka kecil tampil lebih dulu.</div>

                    @error('sort_order')
                        <div class="err">{{ $message }}</div>
                    @enderror
                </div>

                <label class="pk-check">
                    <input
                        type="checkbox"
                        id="is_active"
                        name="is_active"
                        value="1"
                        {{ old('is_active', $editingPlan ? $editingPlan->is_active : true) ? 'checked' : '' }}
                    >

                    <span>
                        Aktif
                        <small>Hanya paket aktif yang muncul di form pendaftaran.</small>
                    </span>
                </label>

                <label class="pk-check">
                    <input
                        type="checkbox"
                        id="is_popular"
                        name="is_popular"
                        value="1"
                        {{ old('is_popular', $editingPlan->is_popular ?? false) ? 'checked' : '' }}
                    >

                    <span>
                        Tandai sebagai rekomendasi
                        <small>Memberi label rekomendasi di pilihan paket.</small>
                    </span>
                </label>

                <div class="pk-actions">
                    <button type="button" class="btn btn-outline" id="btnBatal">
                        Batal
                    </button>

                    <button type="submit" class="btn btn-primary" id="btnSimpan">
                        {{ $editingPlan ? 'Simpan perubahan' : 'Simpan paket' }}
                    </button>
                </div>

            </form>
        </div>
    </div>
</div>


<script>
(function () {
    const modal = document.getElementById('modalPaket');
    const form = document.getElementById('formPaket');
    const method = document.getElementById('formMethod');

    const title = document.getElementById('modalPaketTitle');
    const subtitle = document.getElementById('modalPaketSubtitle');
    const submitButton = document.getElementById('btnSimpan');

    const warning = document.getElementById('paketWarning');
    const jumlahPakai = document.getElementById('jumlahPakai');
    const editId = document.getElementById('editId');

    const storeUrl = @json(route('parkir.paket.store'));

    const fields = {
        name: document.getElementById('name'),
        duration: document.getElementById('duration_days'),
        mobil: document.getElementById('price_mobil'),
        description: document.getElementById('description'),
        sort: document.getElementById('sort_order'),
        active: document.getElementById('is_active'),
        popular: document.getElementById('is_popular')
    };

    function openModal() {
        modal.classList.add('show');
        modal.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';

        setTimeout(function () {
            fields.name.focus();
        }, 100);
    }

    function closeModal() {
        modal.classList.remove('show');
        modal.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = '';
    }

    function resetForm() {
        form.reset();

        form.action = storeUrl;
        method.disabled = true;
        editId.value = '';

        fields.sort.value = '0';
        fields.active.checked = true;
        fields.popular.checked = false;

        warning.style.display = 'none';
        jumlahPakai.textContent = '0';

        title.textContent = 'Tambah Paket';
        subtitle.textContent = 'Buat paket langganan baru.';
        submitButton.textContent = 'Simpan paket';
    }

    function showAddModal() {
        resetForm();
        openModal();
    }

    function showEditModal(button) {
        resetForm();

        form.action = button.dataset.updateUrl;
        method.disabled = false;
        method.value = 'PUT';

        editId.value = button.dataset.id;
        fields.name.value = button.dataset.name;
        fields.duration.value = button.dataset.duration;
        fields.mobil.value = button.dataset.mobil;
        fields.description.value = button.dataset.description;
        fields.sort.value = button.dataset.sort;

        fields.active.checked = button.dataset.active === '1';
        fields.popular.checked = button.dataset.popular === '1';

        const used = parseInt(button.dataset.used || '0', 10);

        jumlahPakai.textContent = used;
        warning.style.display = used > 0 ? '' : 'none';

        title.textContent = 'Ubah Paket';
        subtitle.textContent = 'Perbarui nama, durasi, atau harga paket.';
        submitButton.textContent = 'Simpan perubahan';

        openModal();
    }

    document.getElementById('btnTambah').addEventListener('click', showAddModal);

    document.querySelectorAll('.pk-edit').forEach(function (button) {
        button.addEventListener('click', function () {
            showEditModal(button);
        });
    });

    document.getElementById('btnTutup').addEventListener('click', closeModal);
    document.getElementById('btnBatal').addEventListener('click', closeModal);

    modal.addEventListener('click', function (event) {
        if (event.target === modal) {
            closeModal();
        }
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && modal.classList.contains('show')) {
            closeModal();
        }
    });

    document.querySelectorAll('[data-days]').forEach(function (button) {
        button.addEventListener('click', function () {
            fields.duration.value = button.dataset.days;
        });
    });

    // Buka kembali modal jika validasi Laravel gagal.
    @if ($hasErrors)
        @if ($editingPlan)
            form.action = @json(route('parkir.paket.update', $editingPlan));
            method.disabled = false;
            method.value = 'PUT';
            title.textContent = 'Ubah Paket';
            subtitle.textContent = 'Periksa kembali data yang dimasukkan.';
            submitButton.textContent = 'Simpan perubahan';
        @else
            form.action = storeUrl;
            method.disabled = true;
            title.textContent = 'Tambah Paket';
            subtitle.textContent = 'Periksa kembali data yang dimasukkan.';
            submitButton.textContent = 'Simpan paket';
        @endif

        openModal();
    @endif
})();
</script>

@endsection