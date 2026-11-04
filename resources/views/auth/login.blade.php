<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Masuk · Parkir.in</title>
    <style>
        :root{
            --navy:#0b1f33; --navy-2:#12304d; --teal:#14c8a8; --teal-dark:#0e9f86;
            --bg:#f4f6f9; --line:#e3e8ef; --muted:#6b7a90; --text:#0f2236; --red:#d64545;
        }
        *{box-sizing:border-box}
        html,body{height:100%}
        body{margin:0;font-family:system-ui,-apple-system,"Segoe UI",sans-serif;color:var(--text);background:var(--bg)}

        .lg{display:grid;grid-template-columns:1.1fr 1fr;min-height:100vh}
        @media (max-width:900px){.lg{grid-template-columns:1fr}}

        /* ===== Panel kiri ===== */
        .lg-hero{
            position:relative;overflow:hidden;color:#fff;display:flex;flex-direction:column;justify-content:space-between;
            padding:44px 52px;
            background:
                linear-gradient(160deg, rgba(11,31,51,.93) 0%, rgba(18,48,77,.86) 55%, rgba(14,159,134,.55) 100%),
                url("{{ asset('images/hero-parkir.jpg') }}") center/cover no-repeat,
                var(--navy);
        }
        @media (max-width:900px){.lg-hero{display:none}}
        .lg-hero::before,.lg-hero::after{content:"";position:absolute;border-radius:50%;pointer-events:none}
        .lg-hero::before{width:380px;height:380px;top:-140px;right:-120px;background:rgba(20,200,168,.18)}
        .lg-hero::after{width:300px;height:300px;bottom:-130px;left:-100px;background:rgba(255,177,0,.10)}
        .lg-hero > *{position:relative;z-index:1}

        .lg-brand{display:flex;align-items:center;gap:12px;font-size:22px;font-weight:800}
        .lg-logo{width:42px;height:42px;border-radius:12px;background:var(--teal);color:var(--navy);display:grid;place-items:center;font-size:22px;font-weight:800}

        .lg-copy h1{margin:0 0 14px;font-size:38px;line-height:1.15;font-weight:800;max-width:460px}
        .lg-copy p{margin:0;font-size:16px;line-height:1.6;color:#c4d2e1;max-width:430px}

        .lg-points{list-style:none;margin:30px 0 0;padding:0;display:flex;flex-direction:column;gap:14px}
        .lg-points li{display:flex;align-items:center;gap:12px;font-size:14.5px;color:#dbe6f1}
        .lg-points i{flex:none;width:30px;height:30px;border-radius:9px;background:rgba(255,255,255,.12);display:grid;place-items:center}
        .lg-points svg{width:16px;height:16px;stroke:var(--teal);fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}

        .lg-foot{font-size:12.5px;color:#8ea3ba}

        /* ===== Panel kanan ===== */
        .lg-form-wrap{display:flex;align-items:center;justify-content:center;padding:40px 24px;background:#fff}
        .lg-box{width:100%;max-width:400px}

        .lg-mobile-brand{display:none;align-items:center;gap:10px;font-size:20px;font-weight:800;margin-bottom:28px}
        .lg-mobile-brand .lg-logo{width:38px;height:38px;font-size:20px}
        @media (max-width:900px){.lg-mobile-brand{display:flex}}

        .lg-box h2{margin:0 0 6px;font-size:28px;font-weight:800}
        .lg-box > p{margin:0 0 28px;color:var(--muted);font-size:14.5px}

        .lg-status{margin-bottom:18px;padding:11px 14px;border-radius:10px;background:#e3f6ec;color:#146c43;font-size:13.5px}
        .lg-error{margin-bottom:18px;padding:11px 14px;border-radius:10px;background:#fbe8e8;color:#9b2c2c;font-size:13.5px}

        .lg-field{margin-bottom:18px}
        .lg-field label{display:block;margin-bottom:7px;font-size:13.5px;font-weight:600}
        .lg-input{position:relative}
        .lg-input > svg{position:absolute;left:14px;top:50%;transform:translateY(-50%);width:18px;height:18px;stroke:#8a98ab;fill:none;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round;pointer-events:none}
        .lg-input input{
            width:100%;height:50px;padding:0 44px 0 44px;border:1px solid var(--line);border-radius:12px;background:#f8fafc;
            font:inherit;font-size:15px;color:var(--text);transition:border-color .15s, box-shadow .15s, background .15s
        }
        .lg-input input:focus{outline:none;background:#fff;border-color:var(--teal);box-shadow:0 0 0 4px rgba(20,200,168,.16)}
        .lg-input input.is-invalid{border-color:var(--red)}
        .lg-eye{position:absolute;right:6px;top:50%;transform:translateY(-50%);width:38px;height:38px;border:0;background:none;border-radius:8px;cursor:pointer;display:grid;place-items:center}
        .lg-eye:hover{background:#eef2f6}
        .lg-eye svg{width:19px;height:19px;stroke:#8a98ab;fill:none;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round}
        .lg-err{margin-top:6px;font-size:12.5px;color:var(--red)}

        .lg-row{display:flex;justify-content:space-between;align-items:center;gap:10px;margin:2px 0 24px;font-size:13.5px}
        .lg-check{display:inline-flex;align-items:center;gap:8px;cursor:pointer;color:var(--muted)}
        .lg-check input{width:17px;height:17px;accent-color:var(--teal-dark)}
        .lg-row a{color:var(--teal-dark);font-weight:600;text-decoration:none}
        .lg-row a:hover{text-decoration:underline}

        .lg-btn{
            width:100%;height:52px;border:0;border-radius:12px;background:var(--navy);color:#fff;font:inherit;font-size:16px;font-weight:700;
            cursor:pointer;transition:background .15s, transform .05s
        }
        .lg-btn:hover{background:var(--navy-2)}
        .lg-btn:active{transform:translateY(1px)}
        .lg-btn:disabled{opacity:.7;cursor:wait}

        .lg-help{margin-top:26px;text-align:center;font-size:13px;color:var(--muted)}
        .lg-back{display:block;margin-top:10px;text-align:center;font-size:13px;color:var(--muted);text-decoration:none}
        .lg-back:hover{color:var(--text)}
    </style>
</head>
<body>
<div class="lg">

    {{-- ========== Panel kiri ========== --}}
    <aside class="lg-hero">
        <div class="lg-brand"><div class="lg-logo">P</div> Parkir.in</div>

        <div class="lg-copy">
            <h1>Parkir cerdas dengan verifikasi wajah dan plat nomor.</h1>
            <p>Kelola member, langganan, dan akses gerbang dari satu panel.</p>

            <ul class="lg-points">
                <li><i><svg viewBox="0 0 24 24"><path d="M20 6 9 17l-5-5"/></svg></i>Pengenalan wajah dan plat otomatis</li>
                <li><i><svg viewBox="0 0 24 24"><path d="M20 6 9 17l-5-5"/></svg></i>Pantau slot dan aktivitas parkir</li>
                <li><i><svg viewBox="0 0 24 24"><path d="M20 6 9 17l-5-5"/></svg></i>Notifikasi keamanan langsung ke member</li>
            </ul>
        </div>

        <div class="lg-foot">&copy; {{ date('Y') }} Parkir.in</div>
    </aside>

    {{-- ========== Panel kanan ========== --}}
    <main class="lg-form-wrap">
        <div class="lg-box">
            <div class="lg-mobile-brand"><div class="lg-logo">P</div> Parkir.in</div>

            <h2>Selamat datang</h2>
            <p>Masuk untuk membuka panel pengelolaan.</p>

            @if (session('status'))
                <div class="lg-status">{{ session('status') }}</div>
            @endif

            @if ($errors->any() && ! $errors->has('email') && ! $errors->has('password'))
                <div class="lg-error">{{ $errors->first() }}</div>
            @endif

            <form method="POST" action="{{ route('login') }}" id="loginForm">
                @csrf

                <div class="lg-field">
                    <label for="email">Email</label>
                    <div class="lg-input">
                        <svg viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="14" rx="2.5"/><path d="m4 7 8 6 8-6"/></svg>
                        <input id="email" type="email" name="email" value="{{ old('email') }}"
                               class="{{ $errors->has('email') ? 'is-invalid' : '' }}"
                               placeholder="nama@email.com" required autofocus autocomplete="username">
                    </div>
                    @error('email') <div class="lg-err">{{ $message }}</div> @enderror
                </div>

                <div class="lg-field">
                    <label for="password">Password</label>
                    <div class="lg-input">
                        <svg viewBox="0 0 24 24"><rect x="4.5" y="10.5" width="15" height="10" rx="2.5"/><path d="M8 10.5V8a4 4 0 0 1 8 0v2.5"/></svg>
                        <input id="password" type="password" name="password"
                               class="{{ $errors->has('password') ? 'is-invalid' : '' }}"
                               placeholder="Masukkan password" required autocomplete="current-password">
                        <button type="button" class="lg-eye" id="togglePw" aria-label="Tampilkan password">
                            <svg viewBox="0 0 24 24"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/></svg>
                        </button>
                    </div>
                    @error('password') <div class="lg-err">{{ $message }}</div> @enderror
                </div>

                <div class="lg-row">
                    <label class="lg-check">
                        <input id="remember_me" type="checkbox" name="remember"> Ingat saya
                    </label>
                    @if (Route::has('password.request'))
                        <a href="{{ route('password.request') }}">Lupa password?</a>
                    @endif
                </div>

                <button type="submit" class="lg-btn" id="btnLogin">Masuk</button>
            </form>

            <div class="lg-help">Belum punya akun? Hubungi superadmin.</div>
            <a href="{{ url('/') }}" class="lg-back">&larr; Kembali ke beranda</a>
        </div>
    </main>
</div>

<script>
    const pw = document.getElementById('password');
    document.getElementById('togglePw').addEventListener('click', () => {
        pw.type = pw.type === 'password' ? 'text' : 'password';
    });

    document.getElementById('loginForm').addEventListener('submit', () => {
        const b = document.getElementById('btnLogin');
        b.textContent = 'Memproses...';
        setTimeout(() => { b.disabled = true; }, 0);
    });
</script>
</body>
</html>