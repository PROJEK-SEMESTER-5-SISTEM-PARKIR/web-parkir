<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Operasional Parkir') · ParkVisi</title>
    <style>
        :root{
            --navy:#0b1f33; --navy-2:#12304d; --accent:#2f80ed;
            --bg:#f4f6f9; --card:#fff; --line:#e3e8ef; --muted:#6b7a90; --text:#1b2a3a;
            --green:#1f9d61; --red:#d64545; --amber:#d98a1a;
        }
        *{box-sizing:border-box}
        body{margin:0;font-family:system-ui,-apple-system,"Segoe UI",sans-serif;background:var(--bg);color:var(--text);display:flex;min-height:100vh}

        /* Sidebar */
        .sidebar{width:240px;background:var(--navy);color:#cfd9e6;display:flex;flex-direction:column;padding:20px 14px;position:sticky;top:0;height:100vh}
        .brand{font-size:20px;font-weight:700;color:#fff;padding:4px 10px 20px}
        .brand small{display:block;font-size:11px;font-weight:400;color:#8ea3ba;margin-top:2px}
        .nav a{display:block;padding:10px 12px;border-radius:8px;color:#cfd9e6;text-decoration:none;font-size:14px;margin-bottom:2px}
        .nav a:hover{background:var(--navy-2);color:#fff}
        .nav a.active{background:var(--accent);color:#fff}
        .nav .label{font-size:11px;text-transform:uppercase;letter-spacing:.08em;color:#6f8399;padding:16px 12px 6px}
        .user{margin-top:auto;border-top:1px solid var(--navy-2);padding-top:14px;font-size:13px}
        .user b{display:block;color:#fff}
        .user button{margin-top:10px;width:100%;background:transparent;border:1px solid #3a5573;color:#cfd9e6;padding:8px;border-radius:8px;cursor:pointer}
        .user button:hover{background:var(--navy-2)}

        /* Konten */
        .main{flex:1;min-width:0;padding:28px 32px}
        .page-head{margin-bottom:22px}
        .page-head h1{margin:0;font-size:24px}
        .page-head p{margin:4px 0 0;color:var(--muted);font-size:14px}

        .card{background:var(--card);border:1px solid var(--line);border-radius:14px;padding:20px}
        .toolbar{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:16px}
        .filters{display:flex;gap:8px;flex-wrap:wrap}
        input,select{padding:9px 12px;border:1px solid var(--line);border-radius:8px;font:inherit;background:#fff}
        input:focus,select:focus{outline:2px solid rgba(47,128,237,.35);border-color:var(--accent)}

        .btn{padding:9px 16px;border-radius:8px;border:1px solid transparent;font:inherit;font-size:14px;cursor:pointer}
        .btn-primary{background:var(--accent);color:#fff}
        .btn-dark{background:var(--navy);color:#fff}
        .btn-outline{background:#fff;border-color:var(--line);color:var(--text)}

        table{width:100%;border-collapse:collapse;font-size:14px}
        th{text-align:left;color:var(--muted);font-weight:600;font-size:12px;text-transform:uppercase;letter-spacing:.04em;padding:12px 10px;border-bottom:1px solid var(--line)}
        td{padding:12px 10px;border-bottom:1px solid var(--line);vertical-align:middle}
        tr:last-child td{border-bottom:0}
        .empty{text-align:center;color:var(--muted);padding:32px 10px}

        .badge{display:inline-block;padding:3px 10px;border-radius:999px;font-size:12px;font-weight:600}
        .b-role{background:#e8f0fe;color:#1d5fc4}
        .b-on{background:#e3f6ec;color:var(--green)}
        .b-off{background:#fbe8e8;color:var(--red)}

        .actions{display:flex;gap:14px;justify-content:flex-end;align-items:center;flex-wrap:wrap}
        .actions form{margin:0}
        .link{background:none;border:0;padding:0;font:inherit;font-size:13px;cursor:pointer;font-weight:600}
        .l-edit{color:var(--accent)} .l-warn{color:var(--amber)} .l-key{color:#7a55d4} .l-del{color:var(--red)}
        .link:hover{text-decoration:underline}

        .field{margin-bottom:14px}
        .field label{display:block;font-size:13px;font-weight:600;margin-bottom:6px}
        .field input,.field select{width:100%}
        .err{color:var(--red);font-size:12px;margin-top:4px}

        .alert{padding:12px 16px;border-radius:10px;margin-bottom:16px;font-size:14px}
        .alert-ok{background:#e3f6ec;color:#146c43}
        .alert-err{background:#fbe8e8;color:#9b2c2c}

        @media (max-width:800px){
            body{flex-direction:column}
            .sidebar{width:100%;height:auto;position:static}
            .main{padding:20px 16px}
        }
    </style>
</head>
<body>
    <aside class="sidebar">
        <div class="brand">ParkVisi<small>Operasional Parkir</small></div>

        <nav class="nav">
            <a href="{{ route('parkir.dashboard') }}" class="{{ request()->routeIs('parkir.dashboard') ? 'active' : '' }}">Dashboard</a>

            <div class="label">Data</div>
            <a href="{{ route('parkir.pendaftaran') }}" class="{{ request()->routeIs('parkir.pendaftaran') ? 'active' : '' }}">Pendaftaran</a>
            <a href="{{ route('parkir.pelanggan') }}" class="{{ request()->routeIs('parkir.pelanggan') ? 'active' : '' }}">Data Pelanggan</a>
            <a href="{{ route('parkir.paket.index') }}" class="{{ request()->routeIs('parkir.paket.*') ? 'active' : '' }}">Paket &amp; Harga</a>
            {{-- aktifkan satu per satu setelah route-nya dibuat --}}
            {{-- <a href="{{ route('parkir.vehicles.index') }}">Kendaraan & Plat</a> --}}
            {{-- <a href="{{ route('parkir.faces.index') }}">Wajah</a> --}}

            <div class="label">Monitoring</div>
            <a href="{{ route('parkir.slot') }}" class="{{ request()->routeIs('parkir.slot*') ? 'active' : '' }}">Slot Parkir</a>
            <a href="{{ route('parkir.darurat') }}" class="{{ request()->routeIs('parkir.darurat') ? 'active' : '' }}">Buka Pintu Darurat</a>
            <a href="{{ route('parkir.kamera') }}" class="{{ request()->routeIs('parkir.kamera') ? 'active' : '' }}">Kamera Deteksi</a>
            {{-- <a href="{{ route('parkir.logs.index') }}">Log Akses</a> --}}
            {{-- <a href="{{ route('parkir.alerts.index') }}">Security Alert</a> --}}
            {{-- <a href="{{ route('parkir.gate.index') }}">Kontrol Palang</a> --}}
        </nav>

        <div class="user">
            <b>{{ auth()->user()->name }}</b>
            <span>Admin Parkir</span>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit">Keluar</button>
            </form>
        </div>
    </aside>

    <main class="main">
        <div class="page-head">
            <h1>@yield('heading', 'Operasional Parkir')</h1>
            @hasSection('subheading')
                <p>@yield('subheading')</p>
            @endif
        </div>

        @if (session('status') || session('success'))
            <div class="alert alert-ok">{{ session('status') ?? session('success') }}</div>
        @endif
        @if (session('error'))
            <div class="alert alert-err">{{ session('error') }}</div>
        @endif

        @yield('content')
    </main>
</body>
</html>