<?php
declare(strict_types=1);
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/layout.php';
$user = current_user();
$maintenanceNames = [];
try {
    $pdo = db();
    $rows = $pdo->query("SELECT name FROM rides WHERE status = 'Maintenance' ORDER BY created_at DESC")->fetchAll();
    foreach ($rows as $r) {
        if (!empty($r['name'])) $maintenanceNames[] = (string)$r['name'];
    }
} catch (Throwable $e) {}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>AmusePark - Ride the Fun</title>
  <link rel="stylesheet" href="css/style.css" />
  <style>
    /* ── Index-page overrides ── */
    body { background: #fff; }

    /* Top info bar */
    .top-bar {
      background: #7c3aed;
      color: #fff;
      text-align: right;
      padding: .45rem 2rem;
      font-size: .82rem;
      font-weight: 600;
      letter-spacing: .02em;
    }
    .top-bar span { background: #facc15; color: #000; border-radius: 999px; padding: .2rem .85rem; margin-left: .75rem; font-weight: 700; }

    /* Nav logo */
    .site-nav .logo img, .admin-nav .logo img { display:inline-block; }
    .site-nav .logo { display:flex; align-items:center; font-size:1.4rem; font-weight:900; text-decoration:none; color:#111827; gap:.35rem; }
    .site-nav .logo span { color:#facc15; }
    .home-hero {
      position: relative;
      min-height: 82vh;
      display: flex; align-items: flex-end;
      overflow: hidden;
      background: #000;
    }
    .home-hero video {
      position: absolute; inset: 0;
      width: 100%; height: 100%;
      object-fit: cover;
      opacity: .75;
      z-index: 0;
    }
    .home-hero::after {
      content: '';
      position: absolute; inset: 0;
      background: linear-gradient(to top, rgba(0,0,0,.78) 0%, rgba(0,0,0,.2) 55%, transparent 100%);
      z-index: 1;
    }
    .home-hero-content {
      position: relative; z-index: 2;
      width: 100%; padding: 4rem 3rem 4.5rem;
      max-width: 780px;
    }
    .home-hero-tag {
      display: inline-flex; align-items: center; gap: .5rem;
      background: #facc15; color: #000;
      border-radius: 999px; padding: .35rem 1.1rem;
      font-size: .82rem; font-weight: 800;
      margin-bottom: 1.25rem; letter-spacing: .03em;
    }
    .home-hero h1 {
      font-size: clamp(2.4rem, 5.5vw, 4rem);
      font-weight: 900; color: #fff;
      line-height: 1.1; margin-bottom: 1rem;
      text-shadow: 0 2px 12px rgba(0,0,0,.4);
    }
    .home-hero h1 em { font-style: normal; color: #facc15; }
    .home-hero p {
      color: #e2e8f0; font-size: 1.1rem;
      margin-bottom: 2rem; max-width: 520px; line-height: 1.65;
    }
    .home-hero-btns { display: flex; gap: 1rem; flex-wrap: wrap; }
    .hero-btn-primary {
      background: #facc15; color: #000;
      font-weight: 800; font-size: 1rem;
      padding: .85rem 2.25rem; border-radius: 999px;
      text-decoration: none; transition: background .2s, transform .15s;
      display: inline-flex; align-items: center; gap: .5rem;
    }
    .hero-btn-primary:hover { background: #fbbf24; transform: translateY(-2px); }
    .hero-btn-outline {
      background: transparent; color: #fff;
      border: 2px solid rgba(255,255,255,.7);
      font-weight: 700; font-size: 1rem;
      padding: .85rem 2.25rem; border-radius: 999px;
      text-decoration: none; transition: all .2s;
      display: inline-flex; align-items: center; gap: .5rem;
    }
    .hero-btn-outline:hover { background: rgba(255,255,255,.15); border-color: #fff; }

    /* Stats bar */
    .stats-bar {
      background: linear-gradient(90deg, #7c3aed 0%, #a855f7 50%, #ec4899 100%);
      padding: 2.25rem 1.5rem;
    }
    .stats-bar-inner {
      max-width: 1000px; margin: 0 auto;
      display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
      gap: 1.5rem; text-align: center;
    }
    .stats-bar-item .num { font-size: 2.4rem; font-weight: 900; color: #fff; line-height: 1; }
    .stats-bar-item .lbl { font-size: .85rem; color: rgba(255,255,255,.8); font-weight: 600; margin-top: .3rem; }

    /* Why book section */
    .why-section { background: #fafafa; padding: 5.5rem 1.5rem; }
    .section-label {
      display: inline-block; background: #f3e8ff; color: #7c3aed;
      border-radius: 999px; padding: .3rem 1rem;
      font-size: .8rem; font-weight: 700; letter-spacing: .06em;
      text-transform: uppercase; margin-bottom: .75rem;
    }
    .section-title { font-size: 2.2rem; font-weight: 900; color: #111827; margin-bottom: .5rem; }
    .section-sub { color: #6b7280; font-size: 1rem; margin-bottom: 3rem; }
    .why-grid {
      max-width: 1000px; margin: 0 auto;
      display: grid; grid-template-columns: repeat(auto-fill, minmax(210px, 1fr));
      gap: 1.5rem;
    }
    .why-card {
      background: #fff; border-radius: 1.25rem;
      padding: 2rem 1.5rem; text-align: center;
      box-shadow: 0 2px 12px rgba(0,0,0,.06);
      border: 1.5px solid #f3f4f6;
      transition: box-shadow .2s, transform .2s;
    }
    .why-card:hover { box-shadow: 0 8px 28px rgba(124,58,237,.12); transform: translateY(-4px); }
    .why-icon {
      width: 64px; height: 64px; border-radius: 1rem;
      display: flex; align-items: center; justify-content: center;
      font-size: 1.75rem; margin: 0 auto 1.1rem;
    }
    .why-card h3 { font-size: 1rem; font-weight: 800; color: #111827; margin-bottom: .4rem; }
    .why-card p { color: #6b7280; font-size: .88rem; line-height: 1.55; }

    /* About section */
    .about-section {
      background: #fff; padding: 5.5rem 1.5rem;
    }
    .about-inner {
      max-width: 1100px; margin: 0 auto;
      display: grid; grid-template-columns: 1fr 1fr;
      gap: 4rem; align-items: center;
    }
    .about-text h2 { font-size: 2.2rem; font-weight: 900; color: #111827; margin-bottom: 1.25rem; line-height: 1.2; }
    .about-text p { color: #6b7280; line-height: 1.75; margin-bottom: 1rem; font-size: .97rem; }
    .about-info-box {
      background: #faf5ff; border-left: 4px solid #7c3aed;
      border-radius: .5rem; padding: 1.25rem 1.5rem; margin-top: 1.5rem;
    }
    .about-info-box p { color: #374151; margin: 0; font-size: .92rem; }
    .about-info-box p + p { margin-top: .5rem; }
    .about-img-wrap { border-radius: 1.5rem; overflow: hidden; box-shadow: 0 16px 48px rgba(0,0,0,.12); }
    .about-img-wrap img { width: 100%; display: block; }

    /* CTA section */
    .cta-section {
      background: linear-gradient(135deg, #7c3aed 0%, #a855f7 50%, #ec4899 100%);
      padding: 6rem 1.5rem; text-align: center;
    }
    .cta-section h2 { font-size: 2.5rem; font-weight: 900; color: #fff; margin-bottom: .75rem; }
    .cta-section p { color: rgba(255,255,255,.85); font-size: 1.1rem; margin-bottom: 2.5rem; }
    .cta-btn {
      background: #facc15; color: #000;
      font-weight: 900; font-size: 1.1rem;
      padding: 1rem 3rem; border-radius: 999px;
      text-decoration: none; display: inline-block;
      transition: background .2s, transform .15s;
    }
    .cta-btn:hover { background: #fbbf24; transform: translateY(-2px); }

    /* Footer */
    .home-footer {
      background: #111827; color: #9ca3af;
      padding: 3.5rem 2rem; text-align: center;
    }
    .home-footer .footer-logo { font-size: 1.6rem; font-weight: 900; color: #fff; margin-bottom: .75rem; }
    .home-footer .footer-logo span { color: #facc15; }
    .home-footer p { font-size: .88rem; }

    /* Maintenance banner */
    .maintenance-bar { background: #fef3c7; border-top: 2px solid #fcd34d; padding: .85rem 1.5rem; text-align: center; color: #92400e; font-size: .9rem; }

    @media (max-width: 768px) {
      .about-inner { grid-template-columns: 1fr; gap: 2rem; }
      .home-hero-content { padding: 2.5rem 1.5rem 3rem; }
      .top-bar { text-align: center; }
    }
  </style>
</head>
<body>

<!-- Top info bar + Nav (shared) -->
<?php
// Homepage always shows the public/customer nav — even for admins
$navUser = $user ? array_merge($user, ['role' => 'customer']) : null;
render_nav($navUser, 'home');
?>

<!-- Hero -->
<section class="home-hero">
  <video autoplay muted loop playsinline preload="auto">
    <source src="hero.mp4.mp4" type="video/mp4">
  </video>
  <div class="home-hero-content">
    <div class="home-hero-tag">⭐ Philippines' #1 Amusement Park</div>
    <h1>Ride the Fun.<br /><em>Book in Seconds.</em></h1>
    <p>Experience thrilling rides, family adventures, and unforgettable memories. Book online and skip the queue!</p>
    <div class="home-hero-btns">
      <a href="tickets.php" class="hero-btn-primary">🎟 Buy Tickets Now</a>
      <a href="rides.php" class="hero-btn-outline">🎢 Explore Rides</a>
    </div>
  </div>
</section>

<!-- Stats bar -->
<div class="stats-bar">
  <div class="stats-bar-inner">
    <div class="stats-bar-item"><div class="num">50+</div><div class="lbl">Exciting Rides</div></div>
    <div class="stats-bar-item"><div class="num">500K+</div><div class="lbl">Happy Visitors</div></div>
    <div class="stats-bar-item"><div class="num">4.9★</div><div class="lbl">Average Rating</div></div>
    <div class="stats-bar-item"><div class="num">20+</div><div class="lbl">Years of Fun</div></div>
  </div>
</div>

<!-- Why Book Online -->
<section class="why-section">
  <div style="max-width:1000px;margin:0 auto;text-align:center;">
    <div class="section-label">WHY BOOK ONLINE</div>
    <h2 class="section-title">Skip the Line, Enjoy More</h2>
    <p class="section-sub">Everything you need for a hassle-free park visit</p>
  </div>
  <div class="why-grid">
    <div class="why-card">
      <div class="why-icon" style="background:#fef3c7;">🎟</div>
      <h3>Easy Booking</h3>
      <p>Book tickets online in seconds and skip the queue at the gate</p>
    </div>
    <div class="why-card">
      <div class="why-icon" style="background:#f3e8ff;">⚡</div>
      <h3>Instant QR Code</h3>
      <p>Get your QR entry code instantly right after payment</p>
    </div>
    <div class="why-card">
      <div class="why-icon" style="background:#dcfce7;">🔒</div>
      <h3>Secure Payments</h3>
      <p>Pay safely via QR Ph, GCash, Maya, or any banking app</p>
    </div>
    <div class="why-card">
      <div class="why-icon" style="background:#fce7f3;">🕐</div>
      <h3>Open Year-Round</h3>
      <p>Open 7 days a week, 9AM to 9PM — rain or shine</p>
    </div>
  </div>
</section>

<!-- About -->
<section class="about-section">
  <div class="about-inner">
    <div class="about-text">
      <div class="section-label">ABOUT THE PARK</div>
      <h2>Looking for <em style="font-style:normal;color:#7c3aed;">Adventure?</em></h2>
      <p>AmusePark is your ultimate destination for thrills, laughter, and unforgettable memories. From heart-pounding roller coasters to gentle family rides, we have something for everyone.</p>
      <p>Located in the heart of the Philippines, our park features over 50 world-class rides, multiple dining options, and entertainment zones for all ages.</p>
      <div class="about-info-box">
        <p>📍 123 Amusement Park Purok 3, Brgy. Cadulawan, Minglanilla, Cebu</p>
        <p style="font-weight:700;color:#7c3aed;">🕐 Open daily: 9:00 AM – 9:00 PM</p>
      </div>
    </div>
    <div class="about-img-wrap">
      <img src="AmusementPark_1.jpg" alt="AmusePark panorama" />
    </div>
  </div>
</section>

<!-- CTA -->
<section class="cta-section">
  <h2>Ready for the Adventure?</h2>
  <p>Book your tickets now and get your QR code instantly!</p>
  <a href="tickets.php" class="cta-btn">🎟 Buy Tickets Now</a>
</section>

<?php if (count($maintenanceNames)): ?>
  <div class="maintenance-bar">
    <strong>⚠️ Service Update:</strong> <?= e(implode(', ', $maintenanceNames)) ?> are currently under maintenance.
  </div>
<?php endif; ?>

<?php render_footer(); ?>

</body>
</html>
