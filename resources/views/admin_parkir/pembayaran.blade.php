@extends('layouts.parkir')

@section('title', 'Verifikasi Pembayaran')
@section('heading', 'Verifikasi Pembayaran')
@section('subheading', 'Cocokkan bukti dan nominal dengan mutasi rekening, lalu setujui untuk memperpanjang langganan')

@section('content')
<style>
    .pv-tabs{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:16px}
    .pv-tabs a{padding:8px 14px;border-radius:999px;background:#fff;border:1px solid var(--line);color:var(--text);text-decoration:none;font-size:13.5px;font-weight:600}
    .pv-tabs a.on{background:var(--navy);border-color:var(--navy);color:#fff}
    .pv-tabs small{opacity:.7;margin-left:4px}
    .pv-amount{font-weight:800;font-variant-numeric:tabular-nums}
    .pv-code{color:var(--accent)}
    .pv-st{display:inline-block;padding:3px 10px;border-radius:999px;font-size:12px;font-weight:700}
    .pv-st.waiting{background:#fff6e5;color:#7a5a12}
    .pv-st.pending{background:#eef1f5;color:var(--muted)}
    .pv-st.approved{background:#e3f6ec;color:var(--green)}
    .pv-st.rejected,.pv-st.expired{background:#fbe8e8;color:var(--red)}
    .pv-thumb{width:46px;height:46px;object-fit:cover;border-radius:8px;border:1px solid var(--line);cursor:pointer}
    dialog.modal{border:0;border-radius:16px;padding:0;width:min(440px,92vw);box-shadow:0 20px 60px rgba(11,31,51,.35)}
    dialog.modal::backdrop{background:rgba(11,31,51,.55)}
    dialog.img{width:min(720px,94vw)}
    dialog.img img{width:100%;display:block;border-radius:16px}
    .md-body{padding:22px}
    .md-body h2{margin:0 0 6px;font-size:17px}
    .md-body textarea{width:100%;padding:9px 12px;border:1px solid var(--line);border-radius:8px;font:inherit;margin-top:10px}
    .md-foot{display:flex;justify-content:flex-end;gap:8px;padding:0 22px 22px}
    .btn-ok{background:var(--green);color:#fff}
    .btn-no{background:var(--red);color:#fff}
</style>

<div class="pv-tabs">
    @foreach (['waiting' => 'Menunggu', 'approved' => 'Disetujui', 'rejected' => 'Ditolak', 'expired' => 'Kedaluwarsa', 'all' => 'Semua'] as $k => $label)
        <a href="{{ route('parkir.pembayaran.index', ['status' => $k]) }}" class="{{ $status === $k ? 'on' : '' }}">
            {{ $label }}@if ($k !== 'all' && ($counts[$k] ?? 0) > 0)<small>{{ $counts[$k] }}</small>@endif
        </a>
    @endforeach
</div>

<div class="card" style="padding:8px 16px;overflow-x:auto">
    <table>
        <thead>
            <tr><th>Waktu</th><th>Member</th><th>Paket</th><th>Tujuan</th><th>Nominal</th><th>Bukti</th><th>Status</th><th style="text-align:right">Aksi</th></tr>
        </thead>
        <tbody>
        @forelse ($payments as $p)
            <tr>
                <td style="white-space:nowrap">{{ $p->created_at->format('d/m H:i') }}</td>
                <td><b>{{ $p->member->name ?? '-' }}</b><br><small style="color:var(--muted)">{{ $p->member->phone ?? '' }}</small></td>
                <td>{{ $p->plan->name ?? '-' }}<br><small style="color:var(--muted)">{{ $p->vehicle_count }} kendaraan</small></td>
                <td>{{ $p->account->label ?? '-' }}</td>
                <td class="pv-amount">
                    Rp {{ number_format($p->total_amount, 0, ',', '.') }}
                    <br><small class="pv-code">termasuk kode unik {{ $p->unique_code }}</small>
                </td>
                <td>
                    @if ($p->proof_path)
                        <img class="pv-thumb" src="{{ route('parkir.pembayaran.bukti', $p) }}" alt="Bukti"
                             onclick="showProof(this.src)">
                    @else - @endif
                </td>
                <td>
                    <span class="pv-st {{ $p->status }}">
                        {{ ['pending'=>'Belum bayar','waiting'=>'Menunggu','approved'=>'Disetujui','rejected'=>'Ditolak','expired'=>'Kedaluwarsa'][$p->status] ?? $p->status }}
                    </span>
                    @if ($p->reject_reason)<br><small style="color:var(--muted)">{{ $p->reject_reason }}</small>@endif
                </td>
                <td>
                    @if ($p->status === 'waiting')
                        <div class="actions">
                            <form method="POST" action="{{ route('parkir.pembayaran.approve', $p) }}"
                                  onsubmit="return confirm('Nominal sudah sesuai dengan mutasi rekening? Langganan akan diperpanjang.')">
                                @csrf <button class="link" style="color:var(--green)">Setujui</button>
                            </form>
                            <button type="button" class="link l-del" onclick="openReject({{ $p->id }})">Tolak</button>
                        </div>
                    @endif
                </td>
            </tr>
        @empty
            <tr><td colspan="8" class="empty">Tidak ada pembayaran.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>

<div style="margin-top:16px">{{ $payments->links() }}</div>

<dialog id="imgModal" class="modal img"><img id="imgFull" alt="Bukti pembayaran"></dialog>

<dialog id="rejModal" class="modal">
    <form id="rejForm" method="POST">
        @csrf
        <div class="md-body">
            <h2>Tolak pembayaran</h2>
            <span style="font-size:13px;color:var(--muted)">Alasan akan terlihat oleh member.</span>
            <textarea name="reason" rows="3" maxlength="255" required placeholder="Contoh: nominal tidak sesuai"></textarea>
        </div>
        <div class="md-foot">
            <button type="button" class="btn btn-outline" onclick="rejModal.close()">Batal</button>
            <button type="submit" class="btn btn-no">Tolak</button>
        </div>
    </form>
</dialog>

<script>
    const imgModal = document.getElementById('imgModal');
    const rejModal = document.getElementById('rejModal');
    const rejTpl = @json(route('parkir.pembayaran.reject', '__ID__'));

    function showProof(src) { document.getElementById('imgFull').src = src; imgModal.showModal(); }
    function openReject(id) { document.getElementById('rejForm').action = rejTpl.replace('__ID__', id); rejModal.showModal(); }
    [imgModal, rejModal].forEach(m => m.addEventListener('click', e => { if (e.target === m) m.close(); }));
</script>
@endsection