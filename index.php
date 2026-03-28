<?php
declare(strict_types=1);
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/layout.php';
$user = current_user();
$pdo  = db();

$maintenanceNames = [];
$featuredRides    = [];
$ticketTypes      = [];

try {
    $rows = $pdo->query("SELECT name FROM rides WHERE status='Maintenance' ORDER BY created_at DESC")->fetchAll();
    foreach ($rows as $r) { if (!empty($r['name'])) $maintenanceNames[] = (string)$r['name']; }
    $featuredRides = $pdo->query("SELECT * FROM rides WHERE status='Open' ORDER BY is_featured DESC, id ASC LIMIT 6")->fetchAll();
    $ticketTypes   = $pdo->query("SELECT * FROM ticket_types WHERE is_active=1 ORDER BY price ASC")->fetchAll();
} catch (Throwable $e) {}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>AmusePark — Where Magic Meets Adventure</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="css/style.css?v=1.1"/>
  <style>
    *, *::before, *::after { box-sizing: border-box; }
    :root {
      --primary: #1e3a8a;
      --primary-dark: #172554;
      --secondary: #fbbf24;
      --secondary-dark: #f59e0b;
      --dark: #0f172a;
      --light: #f8fafc;
    }
    html { scroll-behavior: smooth; }
    body { background: var(--light); color: var(--dark); font-family: 'Poppins', sans-serif; margin: 0; overflow-x: hidden; line-height: 1.6; }

    /* ── TOPBAR ── */
    .ap-topbar {
      background: var(--dark); color: rgba(255,255,255,.8);
      text-align: center; padding: .6rem 1rem; font-size: .85rem; font-weight: 500; letter-spacing: .02em;
    }
    .ap-topbar a { color: var(--secondary); text-decoration: none; font-weight: 700; margin-left: .5rem; transition: color .2s; }
    .ap-topbar a:hover { color: #fff; }

    /* ── HERO ── */
    .ap-hero {
      position: relative; min-height: 90vh;
      display: flex; align-items: center;
      overflow: hidden; background: var(--dark);
    }
    .ap-hero-bg {
      position: absolute; inset: 0; z-index: 0;
      overflow: hidden;
    }
    .ap-hero-bg video {
      width: 100%; height: 100%; object-fit: cover; opacity: .4;
    }
    .ap-hero-overlay {
      position: absolute; inset: 0; z-index: 1;
      background: linear-gradient(to right, rgba(15,23,42,0.95) 0%, rgba(15,23,42,0.6) 50%, transparent 100%);
    }
    .ap-hero-content {
      position: relative; z-index: 2;
      max-width: 850px; padding: 6rem 5rem 12rem;
    }
    .ap-hero-badge {
      display: inline-flex; align-items: center; gap: .6rem;
      background: rgba(251,191,36,0.15); border: 1px solid rgba(251,191,36,0.3);
      color: var(--secondary); border-radius: 999px;
      padding: .5rem 1.5rem; font-size: .85rem; font-weight: 700;
      margin-bottom: 2rem; letter-spacing: .02em; text-transform: uppercase;
      backdrop-filter: blur(4px);
    }
    .ap-hero h1 {
      font-size: clamp(3rem, 8vw, 5.5rem);
      font-weight: 800; color: #fff; line-height: 1.1;
      letter-spacing: -0.03em; margin: 0 0 1.5rem;
    }
    .ap-hero h1 span { color: var(--secondary); }
    .ap-hero p {
      color: rgba(255,255,255,0.8); font-size: 1.25rem;
      line-height: 1.7; margin: 0 0 2.5rem; max-width: 580px;
    }
    .ap-hero-btns { display: flex; gap: 1.25rem; flex-wrap: wrap; }
    .ap-btn-primary {
      background: var(--primary); color: #fff; font-weight: 700;
      padding: 1rem 2.5rem; border-radius: 12px; font-size: 1.1rem;
      text-decoration: none; transition: all .3s; display: inline-flex; align-items: center; gap: .75rem;
      box-shadow: 0 10px 15px -3px rgba(30,58,138,0.3);
    }
    .ap-btn-primary:hover { background: var(--primary-dark); transform: translateY(-3px); box-shadow: 0 20px 25px -5px rgba(30,58,138,0.4); }
    
    .ap-btn-secondary {
      background: var(--secondary); color: #000; font-weight: 700;
      padding: 1rem 2.5rem; border-radius: 12px; font-size: 1.1rem;
      text-decoration: none; transition: all .3s; display: inline-flex; align-items: center; gap: .75rem;
      box-shadow: 0 10px 15px -3px rgba(251,191,36,0.3);
    }
    .ap-btn-secondary:hover { background: var(--secondary-dark); transform: translateY(-3px); box-shadow: 0 20px 25px -5px rgba(251,191,36,0.4); }

    .ap-btn-outline {
      background: transparent; color: #fff; font-weight: 700;
      padding: 1rem 2.5rem; border-radius: 12px; font-size: 1.1rem;
      border: 2px solid rgba(255,255,255,0.3); text-decoration: none;
      transition: all .3s; display: inline-flex; align-items: center; gap: .75rem;
    }
    .ap-btn-outline:hover { border-color: #fff; background: rgba(255,255,255,0.1); transform: translateY(-3px); }

    /* Hero stats strip */
    .ap-hero-stats {
      position: absolute; bottom: 0; left: 0; right: 0; z-index: 2;
      display: flex; justify-content: center; gap: 0;
      background: rgba(15,23,42,0.85); backdrop-filter: blur(12px);
      border-top: 1px solid rgba(255,255,255,0.1);
    }
    .ap-hero-stat {
      flex: 1; max-width: 250px; text-align: center;
      padding: 1.5rem 1rem; border-right: 1px solid rgba(255,255,255,0.1);
    }
    .ap-hero-stat:last-child { border-right: none; }
    .ap-hero-stat-val { font-size: 2rem; font-weight: 800; color: var(--secondary); line-height: 1; margin-bottom: 0.25rem; }
    .ap-hero-stat-lbl { font-size: .8rem; color: rgba(255,255,255,0.6); font-weight: 600; text-transform: uppercase; letter-spacing: .05em; }

    /* ── MAINTENANCE BANNER ── */
    .ap-maintenance {
      background: #fffbeb; border-bottom: 1px solid #fde68a;
      padding: 1rem 2rem; text-align: center;
      font-size: .95rem; font-weight: 600; color: #92400e;
      display: flex; align-items: center; justify-content: center; gap: .75rem;
    }

    /* ── SECTION WRAPPER ── */
    .ap-section { padding: 6rem 2rem; }
    .ap-section-inner { max-width: 1200px; margin: 0 auto; }
    .ap-section-header { margin-bottom: 4rem; text-align: center; }
    .ap-section-label {
      display: inline-block; background: #eff6ff; color: var(--primary);
      border-radius: 8px; padding: .4rem 1rem; font-size: .85rem;
      font-weight: 700; letter-spacing: .05em; text-transform: uppercase; margin-bottom: 1rem;
    }
    .ap-section-title {
      font-size: clamp(2.25rem, 5vw, 3.5rem); font-weight: 800;
      color: var(--dark); line-height: 1.2; margin: 0 0 1rem; letter-spacing: -0.02em;
    }
    .ap-section-sub { color: #64748b; font-size: 1.1rem; max-width: 800px; line-height: 1.6; margin: 0 auto; }

    /* ── RIDES GRID ── */
    .ap-rides-grid {
      display: grid; grid-template-columns: repeat(auto-fill, minmax(350px, 1fr)); gap: 2.5rem;
    }
    .ap-ride-card {
      border-radius: 1.5rem; overflow: hidden;
      border: 1px solid #e2e8f0; background: #fff;
      transition: all .4s cubic-bezier(0.4, 0, 0.2, 1);
      box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05);
    }
    .ap-ride-card:hover { transform: translateY(-10px); box-shadow: 0 25px 50px -12px rgba(0,0,0,0.15); border-color: var(--primary); }
    .ap-ride-img-wrap { position: relative; overflow: hidden; height: 200px; }
    .ap-ride-img {
      width: 100%; height: 100%; object-fit: cover; display: block;
      transition: transform .6s;
    }
    .ap-ride-card:hover .ap-ride-img { transform: scale(1.1); }
    .ap-ride-img-placeholder {
      width: 100%; height: 100%; background: linear-gradient(135deg, #eff6ff, #dbeafe);
      display: flex; align-items: center; justify-content: center; font-size: 4rem;
    }
    .ap-ride-body { padding: 1.5rem; }
    .ap-ride-name { font-size: 1.25rem; font-weight: 800; color: var(--dark); margin: 0 0 .5rem; }
    .ap-ride-desc { font-size: .95rem; color: #64748b; line-height: 1.6; margin: 0 0 1rem; height: 3.2em; overflow: hidden; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; }
    .ap-ride-meta { display: flex; gap: .5rem; flex-wrap: wrap; margin-top: 1rem; }
    .ap-ride-tag {
      font-size: .75rem; font-weight: 700; padding: .25rem .75rem; border-radius: 6px; text-transform: uppercase;
    }
    .tag-thrill  { background: #fee2e2; color: #dc2626; }
    .tag-family  { background: #dcfce7; color: #16a34a; }
    .tag-kids    { background: #eff6ff; color: var(--primary); }
    .tag-water   { background: #dbeafe; color: #1d4ed8; }
    .tag-classic { background: #f1f5f9; color: #475569; }

    /* ── TICKETS SECTION ── */
    .ap-tickets-section { background: var(--dark); position: relative; overflow: hidden; }
    .ap-tickets-section::before {
      content: ''; position: absolute; top: -10%; right: -10%; width: 40%; height: 60%;
      background: radial-gradient(circle, rgba(30,58,138,0.2) 0%, transparent 70%); z-index: 0;
    }
    .ap-tickets-section .ap-section-inner { position: relative; z-index: 1; }
    .ap-tickets-section .ap-section-label { background: rgba(251,191,36,0.1); color: var(--secondary); }
    .ap-tickets-section .ap-section-title { color: #fff; }
    .ap-tickets-section .ap-section-sub   { color: rgba(255,255,255,0.6); }

    .ap-ticket-grid {
      display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 2.5rem;
    }
    .ap-ticket-card {
      background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.08);
      border-radius: 2rem; padding: 3rem 2.5rem;
      transition: all .3s ease;
      display: flex; flex-direction: column;
      backdrop-filter: blur(8px);
    }
    .ap-ticket-card:hover { border-color: var(--secondary); transform: translateY(-8px); background: rgba(255,255,255,0.05); }
    .ap-ticket-name { font-size: 1.5rem; font-weight: 800; color: #fff; margin: 0 0 .75rem; }
    .ap-ticket-desc { font-size: 1rem; color: rgba(255,255,255,0.5); line-height: 1.6; margin: 0 0 1.5rem; }
    .ap-ticket-rides { font-size: .95rem; color: var(--secondary); font-weight: 700; margin-bottom: 2rem; display: flex; align-items: center; gap: .5rem; }
    .ap-ticket-price { font-size: 3rem; font-weight: 800; color: #fff; line-height: 1; margin-bottom: 2rem; }
    .ap-ticket-price span { font-size: 1rem; color: rgba(255,255,255,0.4); font-weight: 500; }
    .ap-ticket-btn {
      display: block; text-align: center; background: var(--secondary); color: #000;
      font-weight: 800; padding: 1.2rem; border-radius: 12px; text-decoration: none;
      font-size: 1.1rem; transition: all .3s; margin-top: auto;
      box-shadow: 0 10px 15px -3px rgba(251,191,36,0.2);
    }
    .ap-ticket-btn:hover { background: var(--secondary-dark); transform: scale(1.02); }

    /* ── WHY US ── */
    .ap-why-grid {
      display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 3rem;
    }
    .ap-why-card { text-align: center; padding: 2rem; border-radius: 2rem; transition: background .3s; }
    .ap-why-card:hover { background: #fff; box-shadow: 0 10px 30px rgba(0,0,0,0.05); }
    .ap-why-icon {
      width: 80px; height: 80px; border-radius: 20px;
      background: #eff6ff; display: flex; align-items: center; justify-content: center;
      font-size: 2.5rem; margin: 0 auto 1.5rem; color: var(--primary);
    }
    .ap-why-title { font-size: 1.25rem; font-weight: 800; color: var(--dark); margin: 0 0 .75rem; }
    .ap-why-desc  { font-size: 1rem; color: #64748b; line-height: 1.6; margin: 0; }

    /* ── CTA BANNER ── */
    .ap-cta {
      background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
      padding: 8rem 2rem; text-align: center; position: relative; overflow: hidden;
    }
    .ap-cta::before {
      content: ''; position: absolute; inset: 0;
      background: url('https://www.transparenttextures.com/patterns/cubes.png'); opacity: 0.1;
    }
    .ap-cta h2 { font-size: clamp(2.5rem, 6vw, 4.5rem); font-weight: 800; color: #fff; margin: 0 0 1.5rem; position: relative; letter-spacing: -0.02em; }
    .ap-cta p  { color: rgba(255,255,255,0.8); font-size: 1.25rem; margin: 0 0 3.5rem; position: relative; max-width: 700px; margin-left: auto; margin-right: auto; }
    .ap-cta .ap-btn-secondary { position: relative; }

    /* ── FOOTER ── */
    .ap-footer {
      background: var(--dark); color: rgba(255,255,255,0.5);
      text-align: center; padding: 4rem 1rem; font-size: .95rem;
      border-top: 1px solid rgba(255,255,255,0.05);
    }
    .ap-footer a { color: rgba(255,255,255,0.7); text-decoration: none; margin: 0 1rem; transition: color .2s; }
    .ap-footer a:hover { color: var(--secondary); }

    @media (max-width: 768px) {
      .ap-hero-stats { display: none; }
      .ap-hero-content { padding: 0 2rem; text-align: center; }
      .ap-hero-btns { justify-content: center; }
      .ap-hero p { margin-left: auto; margin-right: auto; }
      .ap-section { padding: 4rem 1.5rem; }
      .ap-rides-grid { grid-template-columns: 1fr; }
    }
  </style>
</head>
<body>

<!-- Topbar -->
<div class="ap-topbar">
  🎢 Now open daily 9AM–9PM &nbsp;·&nbsp; Book online and skip the queue
  <a href="tickets.php">Buy Tickets →</a>
</div>

<!-- Nav -->
<?php render_nav($user, 'home'); ?>

<?php if (count($maintenanceNames) > 0): ?>
<div class="ap-maintenance">
  🔧 Currently under maintenance: <?= e(implode(', ', $maintenanceNames)) ?>. We apologize for the inconvenience.
</div>
<?php endif; ?>

<!-- ── HERO ── -->
<section class="ap-hero">
  <div class="ap-hero-bg">
    <video autoplay muted loop playsinline>
      <source src="hero.mp4.mp4" type="video/mp4" />
    </video>
  </div>
  <div class="ap-hero-overlay"></div>
  <div class="ap-hero-content">
    <div class="ap-hero-badge">🎟 Online Booking Now Available</div>
    <h1>Where Magic<br>Meets <span>Adventure</span></h1>
    <p>Thrilling rides, unforgettable memories, and non-stop fun for the whole family. Your next great adventure starts here.</p>
    <div class="ap-hero-btns">
      <a href="tickets.php" class="ap-btn-secondary">🎟 Buy Tickets Now</a>
      <a href="rides.php" class="ap-btn-outline">🎢 Explore Rides</a>
    </div>
  </div>
  <div class="ap-hero-stats">
    <div class="ap-hero-stat">
      <div class="ap-hero-stat-val">50+</div>
      <div class="ap-hero-stat-lbl">Attractions</div>
    </div>
    <div class="ap-hero-stat">
      <div class="ap-hero-stat-val">1M+</div>
      <div class="ap-hero-stat-lbl">Happy Visitors</div>
    </div>
    <div class="ap-hero-stat">
      <div class="ap-hero-stat-val">9AM</div>
      <div class="ap-hero-stat-lbl">Opens Daily</div>
    </div>
    <div class="ap-hero-stat">
      <div class="ap-hero-stat-val">⭐ 4.9</div>
      <div class="ap-hero-stat-lbl">Guest Rating</div>
    </div>
  </div>
</section>

<!-- ── FEATURED RIDES ── -->
<section class="ap-section">
  <div class="ap-section-inner">
    <div class="ap-section-label">🎢 Attractions</div>
    <h2 class="ap-section-title">Featured Rides</h2>
    <p class="ap-section-sub">From heart-pounding thrills to family-friendly fun — there's something for everyone.</p>

    <?php if (count($featuredRides) > 0): ?>
      <div class="ap-rides-grid">
        <?php
          $catTag = ['Thrill'=>'tag-thrill','Family'=>'tag-family','Kids'=>'tag-kids','Water'=>'tag-water','Classic'=>'tag-classic'];
          foreach ($featuredRides as $r):
            $cat = (string)($r['category'] ?? '');
        ?>
          <div class="ap-ride-card">
            <div class="ap-ride-img-wrap">
              <?php if (!empty($r['image_url'])): ?>
                <img class="ap-ride-img" src="<?= e($r['image_url']) ?>" alt="<?= e($r['name']) ?>" loading="lazy" />
              <?php else: ?>
                <div class="ap-ride-img-placeholder">🎡</div>
              <?php endif; ?>
              <div style="position:absolute;top:1rem;right:1rem;">
                <span class="ap-ride-tag <?= $catTag[$cat] ?? 'tag-classic' ?>" style="box-shadow:0 4px 10px rgba(0,0,0,0.1);"><?= e($cat) ?></span>
              </div>
            </div>
            <div class="ap-ride-body">
              <div class="ap-ride-name"><?= e($r['name']) ?></div>
              <div class="ap-ride-desc"><?= e($r['description'] ?: 'An exciting ride experience awaits you.') ?></div>
              
              <div style="display:grid;grid-template-columns:1fr 1fr;gap:.75rem;margin-top:1.5rem;padding-top:1.5rem;border-top:1px solid #f1f5f9;">
                <div style="display:flex;align-items:center;gap:.5rem;font-size:.85rem;color:var(--text-muted);">
                  <span style="font-size:1.1rem;">⏱</span> <?= !empty($r['duration_minutes']) ? (int)$r['duration_minutes'] . ' min' : '5 min' ?>
                </div>
                <div style="display:flex;align-items:center;gap:.5rem;font-size:.85rem;color:var(--text-muted);">
                  <span style="font-size:1.1rem;">📏</span> <?= !empty($r['min_height_cm']) ? (int)$r['min_height_cm'] . 'cm+' : 'No min' ?>
                </div>
                <div style="display:flex;align-items:center;gap:.5rem;font-size:.85rem;color:var(--text-muted);">
                  <span style="font-size:1.1rem;">👥</span> <?= !empty($r['max_capacity']) ? (int)$r['max_capacity'] . ' max' : '20 max' ?>
                </div>
                <div style="display:flex;align-items:center;gap:.5rem;font-size:.9rem;color:var(--primary);font-weight:800;">
                  ₱<?= number_format((float)($r['price'] ?? 0), 0) ?>
                </div>
              </div>

              <div style="margin-top:1.5rem;display:flex;gap:.75rem;">
                <a href="rides.php" class="btn btn-primary" style="flex:1;padding:.6rem;font-size:.85rem;border-radius:10px;">Book Now</a>
                <a href="rides.php" class="btn btn-outline" style="flex:1;padding:.6rem;font-size:.85rem;border-radius:10px;">Details</a>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
      <div style="text-align:center;margin-top:2.5rem;">
        <a href="rides.php" class="ap-btn-primary" style="display:inline-flex;">View All Rides →</a>
      </div>
    <?php else: ?>
      <div style="text-align:center;padding:3rem;color:#94a3b8;">
        <div style="font-size:3rem;margin-bottom:1rem;">🎢</div>
        <p>Rides coming soon — check back shortly!</p>
      </div>
    <?php endif; ?>
  </div>
</section>

<!-- ── TICKETS ── -->
<?php if (count($ticketTypes) > 0): ?>
<section class="ap-section ap-tickets-section">
  <div class="ap-section-inner">
    <div class="ap-section-label">🎟 Pricing</div>
    <h2 class="ap-section-title">Choose Your Ticket</h2>
    <p class="ap-section-sub">Flexible packages for every kind of visitor. Book online and skip the queue.</p>

    <div class="ap-ticket-grid">
      <?php foreach ($ticketTypes as $t):
        $maxR = isset($t['max_rides']) && $t['max_rides'] !== null ? (int)$t['max_rides'] : null;
      ?>
        <div class="ap-ticket-card">
          <div class="ap-ticket-name"><?= e($t['name']) ?></div>
          <div class="ap-ticket-desc"><?= e($t['description'] ?: 'Full day access to the park.') ?></div>
          <div class="ap-ticket-rides">
            🎢 <?= $maxR !== null ? 'Up to ' . $maxR . ' ride' . ($maxR === 1 ? '' : 's') : 'Unlimited rides' ?>
          </div>
          <div class="ap-ticket-price">₱<?= number_format((float)$t['price'], 0) ?> <span>/ person</span></div>
          <a href="tickets.php?pkg=<?= (int)$t['id'] ?>" class="ap-ticket-btn">Book Now</a>
          <button onclick="addToCartHome(<?= (int)$t['id'] ?>, this)"
                  style="display:block;width:100%;margin-top:.6rem;padding:.65rem;border-radius:999px;
                         background:transparent;border:2px solid rgba(255,255,255,.3);color:#fff;
                         font-weight:700;font-size:.85rem;cursor:pointer;transition:all .2s;">
            🛒 Add to Cart
          </button>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<!-- ── WHY US ── -->
<section class="ap-section" style="background:#f8fafc;">
  <div class="ap-section-inner">
    <div class="ap-section-label">✨ Why AmusePark</div>
    <h2 class="ap-section-title">The Best Day Out, Guaranteed</h2>
    <p class="ap-section-sub">We go above and beyond to make every visit magical.</p>
    <div class="ap-why-grid">
      <div class="ap-why-card">
        <div class="ap-why-icon">🎢</div>
        <div class="ap-why-title">World-Class Rides</div>
        <div class="ap-why-desc">From gentle carousels to adrenaline-pumping coasters — rides for every age and thrill level.</div>
      </div>
      <div class="ap-why-card">
        <div class="ap-why-icon">🎟</div>
        <div class="ap-why-title">Easy Online Booking</div>
        <div class="ap-why-desc">Skip the queue. Book your tickets online in minutes and go straight to the fun.</div>
      </div>
      <div class="ap-why-card">
        <div class="ap-why-icon">👨‍👩‍👧‍👦</div>
        <div class="ap-why-title">Family Friendly</div>
        <div class="ap-why-desc">Safe, clean, and welcoming for families with kids of all ages. Fun for everyone.</div>
      </div>
      <div class="ap-why-card">
        <div class="ap-why-icon">🔒</div>
        <div class="ap-why-title">Safe & Secure</div>
        <div class="ap-why-desc">All rides are regularly inspected. Your safety is our top priority every single day.</div>
      </div>
    </div>
  </div>
</section>

<!-- ── CTA ── -->
<section class="ap-cta">
  <h2>Ready for the Adventure?</h2>
  <p>Book your tickets now and create memories that last a lifetime.</p>
  <a href="tickets.php" class="ap-btn-secondary" style="font-size:1.1rem;padding:1rem 2.75rem;">🎟 Get Your Tickets</a>
</section>

<!-- ── FOOTER ── -->
<footer class="ap-footer">
  <p style="margin:0 0 .75rem;">
    <a href="index.php">Home</a>
    <a href="rides.php">Rides</a>
    <a href="tickets.php">Tickets</a>
    <a href="contact.php">Contact</a>
    <?php if ($user): ?>
      <a href="my-bookings.php">My Bookings</a>
    <?php endif; ?>
  </p>
  <p style="margin:0;">© <?= date('Y') ?> AmusePark Philippines. All rights reserved.</p>
</footer>

<script>
function addToCartHome(tid, btn) {
  btn.disabled = true;
  btn.textContent = 'Adding…';
  fetch('cart.php', {
    method: 'POST',
    headers: {'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest'},
    body: 'action=add&ticket_type_id=' + tid + '&qty=1'
  })
  .then(function(r) { return r.json(); })
  .then(function(d) {
    btn.textContent = '✓ Added to Cart!';
    btn.style.borderColor = '#86efac';
    btn.style.color = '#86efac';
    var badge = document.getElementById('cart-nav-badge');
    if (badge) {
      badge.textContent = d.count;
      badge.style.display = d.count > 0 ? 'inline-flex' : 'none';
    }
    setTimeout(function() {
      btn.textContent = '🛒 Add to Cart';
      btn.style.borderColor = 'rgba(255,255,255,.3)';
      btn.style.color = '#fff';
      btn.disabled = false;
    }, 2000);
  })
  .catch(function() {
    btn.textContent = '🛒 Add to Cart';
    btn.disabled = false;
  });
}
</script>
</body>
</html>
