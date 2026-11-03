<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>ParkVisi — Parkir Tanpa Tiket, Cukup Wajah & Plat Nomor</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&family=Sora:wght@600;700;800&display=swap" rel="stylesheet">
<style>
  :root{
    --ink:#07111f;
    --navy:#0b1d33;
    --navy-2:#102a47;
    --teal:#00c2a8;
    --teal-soft:rgba(0,194,168,.12);
    --text:#0b1d33;
    --muted:#5b6b7a;
    --line:rgba(255,255,255,.10);
    --paper:#f6f8fb;
    --radius:20px;
  }
  *{box-sizing:border-box;margin:0;padding:0}
  html{scroll-behavior:smooth}
  body.pv{font-family:'Inter',system-ui,sans-serif;color:var(--text);background:#fff;line-height:1.6;-webkit-font-smoothing:antialiased}
  .pv a{text-decoration:none;color:inherit}
  .pv ul{list-style:none}
  .pv h1,.pv h2,.pv h3{font-family:'Sora',sans-serif;line-height:1.15;letter-spacing:-.02em}
  .pv-wrap{width:min(1160px,92%);margin-inline:auto}

  /* ---------- Tombol ---------- */
  .pv-btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;padding:14px 26px;border-radius:999px;
    font-weight:600;font-size:15px;border:1.5px solid transparent;cursor:pointer;transition:transform .2s,box-shadow .2s,background .2s,color .2s}
  .pv-btn-primary{background:var(--teal);color:var(--ink);box-shadow:0 10px 30px -8px rgba(0,194,168,.65)}
  .pv-btn-primary:hover{transform:translateY(-2px);box-shadow:0 16px 36px -8px rgba(0,194,168,.8)}
  .pv-btn-ghost{border-color:rgba(255,255,255,.28);color:#fff;background:rgba(255,255,255,.04)}
  .pv-btn-ghost:hover{background:rgba(255,255,255,.12)}
  .pv-btn-dark{border-color:var(--navy);color:var(--navy)}
  .pv-btn-dark:hover{background:var(--navy);color:#fff}

  /* ---------- Navbar ---------- */
  .pv-header{position:fixed;inset:0 0 auto 0;z-index:50;padding:14px 0;transition:background .3s,backdrop-filter .3s,border-color .3s;border-bottom:1px solid transparent}
  .pv-header.is-scrolled{background:rgba(7,17,31,.72);backdrop-filter:blur(14px);-webkit-backdrop-filter:blur(14px);border-color:var(--line)}
  .pv-nav{display:flex;align-items:center;justify-content:space-between;gap:24px}
  .pv-brand{display:inline-flex;align-items:center;gap:10px;font-family:'Sora',sans-serif;font-weight:700;font-size:20px;color:#fff}
  .pv-brand svg{width:30px;height:30px}
  .pv-links{display:flex;gap:34px}
  .pv-links a{color:rgba(255,255,255,.75);font-size:14.5px;font-weight:500;transition:color .2s}
  .pv-links a:hover{color:#fff}
  .pv-nav .pv-btn{padding:10px 20px;font-size:14px}
  .pv-burger{display:none;background:none;border:0;color:#fff;font-size:26px;cursor:pointer}

  /* ---------- Hero ---------- */
  .pv-hero{position:relative;min-height:100vh;display:flex;align-items:center;color:#fff;overflow:hidden;
    background:var(--ink) center/cover no-repeat}
  .pv-hero::before{content:"";position:absolute;inset:0;
    background:
      radial-gradient(900px 500px at 15% 20%,rgba(0,194,168,.22),transparent 60%),
      linear-gradient(180deg,rgba(7,17,31,.78) 0%,rgba(7,17,31,.88) 60%,#07111f 100%)}
  .pv-hero::after{content:"";position:absolute;inset:0;opacity:.07;
    background-image:linear-gradient(#fff 1px,transparent 1px),linear-gradient(90deg,#fff 1px,transparent 1px);background-size:56px 56px;
    mask-image:radial-gradient(ellipse at center,#000 30%,transparent 75%);-webkit-mask-image:radial-gradient(ellipse at center,#000 30%,transparent 75%)}
  .pv-hero-in{position:relative;z-index:2;padding:140px 0 100px;max-width:780px}
  .pv-eyebrow{display:inline-flex;align-items:center;gap:10px;padding:8px 16px;border-radius:999px;font-size:13px;font-weight:500;
    background:rgba(255,255,255,.07);border:1px solid var(--line);color:rgba(255,255,255,.85);backdrop-filter:blur(8px)}
  .pv-eyebrow i{width:8px;height:8px;border-radius:50%;background:var(--teal);box-shadow:0 0 0 4px rgba(0,194,168,.25);animation:pulse 2s infinite}
  @keyframes pulse{50%{box-shadow:0 0 0 8px rgba(0,194,168,0)}}
  .pv-hero h1{font-size:clamp(38px,6.2vw,68px);font-weight:800;margin:26px 0 22px}
  .pv-hero h1 span{display:block;background:linear-gradient(90deg,#fff 0%,var(--teal) 100%);-webkit-background-clip:text;background-clip:text;color:transparent}
  .pv-lead{font-size:clamp(16px,1.6vw,19px);color:rgba(255,255,255,.72);max-width:600px}
  .pv-hero-act{display:flex;flex-wrap:wrap;gap:14px;margin-top:38px}

  /* ---------- Section umum ---------- */
  .pv-section{padding:110px 0}
  .pv-head{max-width:720px;margin:0 auto 64px;text-align:center}
  .pv-tag{display:inline-block;font-size:12.5px;font-weight:600;letter-spacing:.14em;text-transform:uppercase;color:var(--teal);margin-bottom:16px}
  .pv-head h2{font-size:clamp(28px,3.6vw,42px);font-weight:700;margin-bottom:16px}
  .pv-head p{color:var(--muted);font-size:17px}

  /* ---------- Cara kerja ---------- */
  .pv-steps{display:grid;grid-template-columns:repeat(3,1fr);gap:24px;position:relative}
  .pv-step{position:relative;padding:36px 30px;border-radius:var(--radius);background:var(--paper);border:1px solid #e8edf3;transition:transform .3s,box-shadow .3s,background .3s}
  .pv-step:hover{transform:translateY(-6px);background:#fff;box-shadow:0 24px 50px -20px rgba(11,29,51,.25)}
  .pv-num{display:grid;place-items:center;width:52px;height:52px;border-radius:16px;margin-bottom:24px;
    font-family:'Sora',sans-serif;font-weight:700;font-size:17px;color:var(--teal);background:var(--navy)}
  .pv-step h3{font-size:19px;margin-bottom:10px}
  .pv-step p{color:var(--muted);font-size:15px}

  /* ---------- Fitur ---------- */
  .pv-dark{background:var(--navy);color:#fff;position:relative;overflow:hidden}
  .pv-dark::before{content:"";position:absolute;width:640px;height:640px;right:-200px;top:-200px;border-radius:50%;
    background:radial-gradient(circle,rgba(0,194,168,.18),transparent 70%)}
  .pv-dark .pv-head p{color:rgba(255,255,255,.65)}
  .pv-split{position:relative;display:grid;grid-template-columns:1fr 1fr;gap:24px}
  .pv-panel{padding:42px 38px;border-radius:24px;background:rgba(255,255,255,.045);border:1px solid var(--line);backdrop-filter:blur(10px);transition:border-color .3s,transform .3s}
  .pv-panel:hover{border-color:rgba(0,194,168,.5);transform:translateY(-4px)}
  .pv-sub{display:inline-block;font-size:12px;font-weight:600;letter-spacing:.1em;color:var(--teal);background:var(--teal-soft);padding:6px 12px;border-radius:999px;margin-bottom:20px}
  .pv-panel h3{font-size:24px;margin-bottom:26px}
  .pv-panel li{display:flex;gap:12px;padding:12px 0;color:rgba(255,255,255,.78);font-size:15.5px;border-top:1px solid var(--line)}
  .pv-panel li svg{flex:none;margin-top:4px;color:var(--teal)}

  /* ---------- Harga ---------- */
  .pv-pricing{background:linear-gradient(180deg,var(--ink),var(--navy));color:#fff;padding:110px 0}
  .pv-pricing .pv-head p{color:rgba(255,255,255,.65)}
  .pv-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(270px,1fr));gap:26px;align-items:stretch}
  .pv-card{position:relative;display:flex;flex-direction:column;border-radius:24px;padding:36px 30px;
    background:rgba(255,255,255,.05);border:1px solid var(--line);backdrop-filter:blur(10px);transition:transform .3s,border-color .3s}
  .pv-card:hover{transform:translateY(-8px);border-color:rgba(255,255,255,.28)}
  .pv-card.is-pop{background:linear-gradient(160deg,rgba(0,194,168,.2),rgba(255,255,255,.05) 55%);border-color:var(--teal);box-shadow:0 30px 60px -25px rgba(0,194,168,.55)}
  .pv-ribbon{position:absolute;top:-14px;left:50%;transform:translateX(-50%);background:var(--teal);color:var(--ink);font-size:12.5px;font-weight:700;padding:6px 16px;border-radius:999px;white-space:nowrap}
  .pv-card h3{font-size:21px;margin-bottom:24px}
  .pv-price{padding:14px 0;border-top:1px solid var(--line)}
  .pv-price span{display:block;font-size:12px;text-transform:uppercase;letter-spacing:.1em;color:rgba(255,255,255,.55);margin-bottom:2px}
  .pv-price b{font-family:'Sora',sans-serif;font-size:28px;font-weight:700}
  .pv-price small{font-size:13px;color:rgba(255,255,255,.55);margin-left:4px}
  .pv-card .pv-btn{margin:24px 0 20px;width:100%}
  .pv-desc{font-size:14px;color:rgba(255,255,255,.62);margin-bottom:16px}
  .pv-feat{margin-top:auto;padding-top:18px;border-top:1px solid var(--line)}
  .pv-feat li{display:flex;gap:10px;font-size:14.5px;padding:6px 0;color:rgba(255,255,255,.85)}
  .pv-feat svg{flex:none;margin-top:4px}
  .pv-empty{grid-column:1/-1;text-align:center;color:rgba(255,255,255,.6)}

  /* ---------- Statistik ---------- */
  .pv-band{padding:70px 0;background:var(--paper);border-bottom:1px solid #e8edf3}
  .pv-band-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:24px;text-align:center}
  .pv-band b{display:block;font-family:'Sora',sans-serif;font-size:clamp(30px,4vw,44px);font-weight:800;color:var(--navy)}
  .pv-band span{color:var(--muted);font-size:14.5px}

  /* ---------- CTA ---------- */
  .pv-cta{display:flex;align-items:center;justify-content:space-between;gap:36px;padding:60px 56px;border-radius:32px;color:#fff;position:relative;overflow:hidden;
    background:radial-gradient(600px 300px at 90% 0%,rgba(0,194,168,.35),transparent 60%),linear-gradient(135deg,var(--navy),var(--ink))}
  .pv-cta h2{font-size:clamp(24px,3vw,34px);margin-bottom:12px;max-width:560px}
  .pv-cta p{color:rgba(255,255,255,.7)}
  .pv-cta-act{display:flex;flex-wrap:wrap;gap:14px;flex:none}

  /* ---------- Footer ---------- */
  .pv-footer{background:var(--ink);color:rgba(255,255,255,.65);padding:80px 0 30px;margin-top:0}
  .pv-foot{display:grid;grid-template-columns:1.6fr 1fr 1fr 1fr;gap:40px;padding-bottom:50px}
  .pv-foot h4{color:#fff;font-size:14px;margin-bottom:18px;letter-spacing:.04em}
  .pv-foot li{margin-bottom:10px;font-size:14.5px}
  .pv-foot a:hover{color:var(--teal)}
  .pv-foot-b{display:flex;justify-content:space-between;gap:16px;flex-wrap:wrap;padding-top:26px;border-top:1px solid var(--line);font-size:13.5px}

  /* ---------- Animasi muncul ---------- */
  .reveal{opacity:0;transform:translateY(28px);transition:opacity .7s ease,transform .7s ease}
  .reveal.is-in{opacity:1;transform:none}
  @media (prefers-reduced-motion:reduce){.reveal{opacity:1;transform:none;transition:none}.pv-eyebrow i{animation:none}}

  /* ---------- Responsif ---------- */
  @media (max-width:900px){
    .pv-steps,.pv-split{grid-template-columns:1fr}
    .pv-band-grid{grid-template-columns:1fr 1fr;row-gap:36px}
    .pv-cta{flex-direction:column;align-items:flex-start;padding:44px 30px}
    .pv-foot{grid-template-columns:1fr 1fr}
    .pv-section,.pv-pricing{padding:80px 0}
  }
  @media (max-width:760px){
    .pv-burger{display:block}
    .pv-nav>.pv-btn{display:none}
    .pv-links{position:absolute;top:100%;left:0;right:0;flex-direction:column;gap:0;background:rgba(7,17,31,.96);backdrop-filter:blur(14px);
      max-height:0;overflow:hidden;transition:max-height .35s}
    .pv-links.is-open{max-height:340px;border-bottom:1px solid var(--line)}
    .pv-links a{display:block;padding:16px 4%}
    .pv-header.menu-open{background:rgba(7,17,31,.96)}
  }
  @media (max-width:520px){.pv-foot{grid-template-columns:1fr}}
</style>
</head>
<body class="pv">

<header class="pv-header" id="pvHeader">
  <div class="pv-wrap pv-nav">
    <a href="#" class="pv-brand">
      <svg viewBox="0 0 28 28" fill="none"><rect width="28" height="28" rx="8" fill="#00C2A8"/><path d="M9 20V8h5.2a4 4 0 010 8H9" stroke="#0B1D33" stroke-width="2.2" stroke-linecap="round"/></svg>
      ParkVisi
    </a>
    <ul class="pv-links" id="pvLinks">
      <li><a href="#cara-kerja">Cara Kerja</a></li>
      <li><a href="#fitur">Fitur</a></li>
      <li><a href="#harga">Harga</a></li>
      <li><a href="#kontak">Kontak</a></li>
    </ul>
    <a href="#unduh" class="pv-btn pv-btn-primary">Unduh Aplikasi</a>
    <button class="pv-burger" id="pvBurger" aria-label="Menu">☰</button>
  </div>
</header>

<section class="pv-hero" style="background-image:url('{{ asset('images/hero-parkir.jpg') }}')">
  <div class="pv-wrap pv-hero-in">
    <span class="pv-eyebrow"><i></i> Sistem parkir berbasis pengenalan wajah &amp; plat nomor</span>
    <h1>Parkir Tanpa Tiket.<span>Cukup Wajah dan Plat Nomor Anda.</span></h1>
    <p class="pv-lead">ParkVisi menggantikan tiket dan kartu member dengan pengenalan wajah dan plat nomor otomatis. Palang terbuka begitu Anda mendekat, tanpa berhenti, tanpa antre.</p>
    <div class="pv-hero-act">
      <a href="#unduh" class="pv-btn pv-btn-primary">Daftarkan Kendaraan Saya →</a>
      <a href="#cara-kerja" class="pv-btn pv-btn-ghost">Lihat Cara Kerja</a>
    </div>
  </div>
</section>

<section class="pv-section" id="cara-kerja">
  <div class="pv-wrap">
    <div class="pv-head reveal">
      <span class="pv-tag">Cara kerja</span>
      <h2>Tiga langkah, sekali daftar, langsung bisa dipakai</h2>
      <p>Pendaftaran dilakukan sekali lewat aplikasi mobile. Setelah itu, sistem yang bekerja setiap kali Anda datang.</p>
    </div>
    <div class="pv-steps">
      <div class="pv-step reveal">
        <div class="pv-num">01</div>
        <h3>Daftarkan wajah &amp; plat nomor</h3>
        <p>Ambil foto wajah dan masukkan nomor plat kendaraan lewat aplikasi ParkVisi. Data tersimpan aman dan terenkripsi.</p>
      </div>
      <div class="pv-step reveal">
        <div class="pv-num">02</div>
        <h3>Kamera mengenali otomatis</h3>
        <p>Saat mendekati palang, kamera di titik masuk membaca wajah pengemudi dan plat nomor secara bersamaan untuk mencocokkan identitas.</p>
      </div>
      <div class="pv-step reveal">
        <div class="pv-num">03</div>
        <h3>Palang terbuka, tarif tercatat</h3>
        <p>Setelah cocok, palang terbuka otomatis. Durasi parkir dan tarif dihitung sistem dan bisa dibayar langsung dari aplikasi.</p>
      </div>
    </div>
  </div>
</section>

<section class="pv-section pv-dark" id="fitur">
  <div class="pv-wrap">
    <div class="pv-head reveal">
      <span class="pv-tag">Satu sistem, dua sisi</span>
      <h2>Dibangun terpisah untuk pengelola dan pengguna</h2>
      <p>Web admin mengatur operasional dari belakang layar, aplikasi mobile menjadi satu-satunya yang dibutuhkan pengguna saat parkir.</p>
    </div>
    <div class="pv-split">
      <div class="pv-panel reveal">
        <span class="pv-sub">WEB ADMIN · untuk pengelola</span>
        <h3>Kendalikan operasional parkir dari satu dashboard</h3>
        <ul>
          @foreach ([
            'Pantau slot terisi dan kosong secara real-time per lantai',
            'Kelola daftar wajah & plat nomor terdaftar, blokir bila perlu',
            'Laporan pemasukan harian, mingguan, dan per titik masuk',
            'Riwayat kejadian saat pengenalan gagal, untuk verifikasi manual',
          ] as $item)
            <li><svg width="18" height="18" viewBox="0 0 18 18" fill="none"><path d="M4 9l3.5 3.5L14 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>{{ $item }}</li>
          @endforeach
        </ul>
      </div>
      <div class="pv-panel reveal">
        <span class="pv-sub">APLIKASI MOBILE · untuk pengguna</span>
        <h3>Cukup daftar sekali, lalu lupakan tiketnya</h3>
        <ul>
          @foreach ([
            'Pendaftaran wajah & plat nomor dalam hitungan menit',
            'Notifikasi saat masuk dan keluar area parkir',
            'Pembayaran langsung dari saldo aplikasi',
            'Lihat sisa slot parkir yang tersedia secara real-time',
          ] as $item)
            <li><svg width="18" height="18" viewBox="0 0 18 18" fill="none"><path d="M4 9l3.5 3.5L14 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>{{ $item }}</li>
          @endforeach
        </ul>
      </div>
    </div>
  </div>
</section>

<section class="pv-pricing" id="harga">
  <div class="pv-wrap">
    <div class="pv-head reveal">
      <span class="pv-tag">Harga</span>
      <h2>Pilih paket langganan sesuai kebutuhan Anda</h2>
      <p>Sekali daftar wajah &amp; plat nomor, nikmati akses parkir tanpa tiket selama masa aktif paket.</p>
    </div>
    <div class="pv-grid">
      @forelse ($plans as $plan)
        <div class="pv-card reveal {{ $plan->is_popular ? 'is-pop' : '' }}">
          @if ($plan->is_popular)
            <span class="pv-ribbon">★ Rekomendasi</span>
          @endif
          <h3>{{ $plan->name }}</h3>
          <div class="pv-price">
            <span>Motor</span>
            <b>Rp {{ number_format($plan->price_motor, 0, ',', '.') }}</b><small>/ {{ $plan->duration_label }}</small>
          </div>
          <div class="pv-price">
            <span>Mobil</span>
            <b>Rp {{ number_format($plan->price_mobil, 0, ',', '.') }}</b><small>/ {{ $plan->duration_label }}</small>
          </div>

          <a href="#unduh" class="pv-btn {{ $plan->is_popular ? 'pv-btn-primary' : 'pv-btn-ghost' }}">Pilih Paket</a>

          @if ($plan->description)
            <p class="pv-desc">{{ $plan->description }}</p>
          @endif

          @if (count($plan->features_list))
            <ul class="pv-feat">
              @foreach ($plan->features_list as $feature)
                <li>
                  <svg width="16" height="16" viewBox="0 0 18 18" fill="none"><path d="M4 9l3.5 3.5L14 6" stroke="#00C2A8" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                  {{ $feature }}
                </li>
              @endforeach
            </ul>
          @endif
        </div>
      @empty
        <p class="pv-empty">Paket harga belum tersedia saat ini.</p>
      @endforelse
    </div>
  </div>
</section>

<div class="pv-band">
  <div class="pv-wrap pv-band-grid">
    <div class="reveal"><b>500+</b><span>slot parkir tersedia</span></div>
    <div class="reveal"><b>99,2%</b><span>akurasi pencocokan wajah &amp; plat</span></div>
    <div class="reveal"><b>850+</b><span>kendaraan terdaftar</span></div>
    <div class="reveal"><b>&lt;2 dtk</b><span>waktu buka palang otomatis</span></div>
  </div>
</div>

<section class="pv-section" id="unduh">
  <div class="pv-wrap">
    <div class="pv-cta reveal">
      <div>
        <h2>Kelola parkir Anda, atau daftarkan kendaraan Anda hari ini.</h2>
        <p>Pengelola bisa mulai dari dashboard admin. Pengguna kendaraan cukup unduh aplikasinya.</p>
      </div>
      <div class="pv-cta-act">
        <a href="{{ route('login') }}" class="pv-btn pv-btn-primary">Coba Dashboard Admin</a>
        <a href="#" class="pv-btn pv-btn-ghost">Unduh Aplikasi ParkVisi</a>
      </div>
    </div>
  </div>
</section>

<footer class="pv-footer" id="kontak">
  <div class="pv-wrap">
    <div class="pv-foot">
      <div>
        <a href="#" class="pv-brand" style="margin-bottom:14px">
          <svg viewBox="0 0 28 28" fill="none"><rect width="28" height="28" rx="8" fill="#00C2A8"/><path d="M9 20V8h5.2a4 4 0 010 8H9" stroke="#0B1D33" stroke-width="2.2" stroke-linecap="round"/></svg>
          ParkVisi
        </a>
        <p style="font-size:14.5px;max-width:280px;margin-top:12px">Sistem manajemen parkir komersial dengan pengenalan wajah dan plat nomor otomatis.</p>
      </div>
      <div>
        <h4>Produk</h4>
        <ul>
          <li><a href="#cara-kerja">Cara Kerja</a></li>
          <li><a href="#fitur">Fitur</a></li>
          <li><a href="{{ route('login') }}">Dashboard Admin</a></li>
          <li><a href="#unduh">Aplikasi Mobile</a></li>
        </ul>
      </div>
      <div>
        <h4>Perusahaan</h4>
        <ul>
          <li><a href="#">Tentang Kami</a></li>
          <li><a href="#">Karier</a></li>
        </ul>
      </div>
      <div>
        <h4>Kontak</h4>
        <ul>
          <li><a href="mailto:halo@parkvisi.id">halo@parkvisi.id</a></li>
          <li><a href="tel:+622100000000">(021) 0000-0000</a></li>
        </ul>
      </div>
    </div>
    <div class="pv-foot-b">
      <span>© {{ date('Y') }} ParkVisi. Seluruh hak cipta dilindungi.</span>
      <span>Dibuat dengan Laravel</span>
    </div>
  </div>
</footer>

<script>
(function () {
  // Navbar berubah jadi kaca saat di-scroll
  const header = document.getElementById('pvHeader');
  const onScroll = () => header.classList.toggle('is-scrolled', window.scrollY > 24);
  onScroll();
  window.addEventListener('scroll', onScroll, { passive: true });

  // Menu mobile
  const burger = document.getElementById('pvBurger');
  const links  = document.getElementById('pvLinks');
  burger.addEventListener('click', () => {
    const open = links.classList.toggle('is-open');
    header.classList.toggle('menu-open', open);
  });
  links.addEventListener('click', e => {
    if (e.target.tagName === 'A') { links.classList.remove('is-open'); header.classList.remove('menu-open'); }
  });

  // Animasi muncul saat di-scroll
  const items = document.querySelectorAll('.reveal');
  if ('IntersectionObserver' in window) {
    const io = new IntersectionObserver(entries => {
      entries.forEach(en => {
        if (en.isIntersecting) { en.target.classList.add('is-in'); io.unobserve(en.target); }
      });
    }, { threshold: 0.12 });
    items.forEach((el, i) => { el.style.transitionDelay = (i % 4) * 80 + 'ms'; io.observe(el); });
  } else {
    items.forEach(el => el.classList.add('is-in'));
  }
})();
</script>
</body>
</html>