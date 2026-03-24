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
  <link rel="stylesheet" href="css/style.css"/>
  <style>
    *, *::before, *::after { box-sizing: border-box; }
    :root {
      --purple: #7c3aed; --purple-dark: #5b21b6;
      --gold: #f59e0b;
      --dark: #0f0a1e;
    }
    html { scroll-behavior: smooth; }
    body { background: #fff; color: #1e1b4b; font-family: 'Segoe UI', system-ui, sans-serif; margin: 0; overflow-x: hidden; }

    /* ── TOPBAR ── */
    .ap-topbar {
      background: var(--dark); color: rgba(255,255,255,.7);
      text-align: center; padding: .45rem 1rem; font-size: .78rem; font-weight: 600; letter-spacing: .04em;
    }
    .ap-topbar a { color: var(--gold); text-decoration: none; font-weight: 700; margin-left: .4rem; }

    /* ── HERO ── */
    .ap-hero {
      position: relative; min-height: 100vh;
      display: flex; align-items: center;
      overflow: hidden; background: var(--dark);
    }
    .ap-hero-bg {
      position: absolute; inset: 0; z-index: 0;
      overflow: hidden;
    }
    .ap-hero-bg video {
      width: 100%; height: 100%; object-fit: cover; opacity: .5;
    }
    .ap-hero-overlay {
      position: absolute; inset: 0; z-index: 1;
      background: linear-gradient(135deg, rgba(15,10,30,.9) 0%, rgba(91,33,182,.4) 60%, transparent 100%);
    }
    .ap-hero-content {
      position: relative; z-index: 2;
      max-width: 700px; padding: 0 2.5rem;
    }
    .ap-hero-badge {
      display: inline-flex; align-items: center; gap: .5rem;
      background: rgba(245,158,11,.15); border: 1px solid rgba(245,158,11,.4);
      color: var(--gold); border-radius: 999px;
      padding: .4rem 1rem; font-size: .8rem; font-weight: 700;
      margin-bottom: 1.5rem; letter-spacing: .04em;
    }
    .ap-hero h1 {
      font-size: clamp(2.8rem, 7vw, 5.5rem);
      font-weight: 900; color: #fff; line-height: 1.05;
      letter-spacing: -.03em; margin: 0 0 1.25rem;
    }
    .ap-hero h1 span { color: var(--gold); }
    .ap-hero p {
      color: rgba(255,255,255,.75); font-size: 1.15rem;
      line-height: 1.65; margin: 0 0 2.25rem; max-width: 520px;
    }
    .ap-hero-btns { display: flex; gap: 1rem; flex-wrap: wrap; }
    .ap-btn-gold {
      background: var(--gold); color: #000; font-weight: 800;
      padding: .9rem 2.25rem; border-radius: 999px; font-size: 1rem;
      text-decoration: none; transition: all .2s; display: inline-flex; align-items: center; gap: .5rem;
    }
    .ap-btn-gold:hover { background: #d97706; transform: translateY(-2px); }
    .ap-btn-outline {
      background: transparent; color: #fff; font-weight: 700;
      padding: .9rem 2.25rem; border-radius: 999px; font-size: 1rem;
      border: 2px solid rgba(255,255,255,.35); text-decoration: none;
      transition: all .2s; display: inline-flex; align-items: center; gap: .5rem;
    }
    .ap-btn-outline:hover { border-color: #fff; background: rgba(255,255,255,.08); }

    /* Hero stats strip */
    .ap-hero-stats {
      position: absolute; bottom: 0; left: 0; right: 0; z-index: 2;
      display: flex; justify-content: center; gap: 0;
      background: rgba(15,10,30,.75); backdrop-filter: blur(12px);
      border-top: 1px solid rgba(255,255,255,.08);
    }
    .ap-hero-stat {
      flex: 1; max-width: 220px; text-align: center;
      padding: 1.25rem 1rem; border-right: 1px solid rgba(255,255,255,.08);
    }
    .ap-hero-stat:last-child { border-right: none; }
    .ap-hero-stat-val { font-size: 1.8rem; font-weight: 900; color: var(--gold); line-height: 1; }
    .ap-hero-stat-lbl { font-size: .75rem; color: rgba(255,255,255,.55); font-weight: 600; margin-top: .3rem; text-transform: uppercase; letter-spacing: .05em; }

    /* ── MAINTENANCE BANNER ── */
    .ap-maintenance {
      background: #fef3c7; border-bottom: 2px solid #fcd34d;
      padding: .75rem 2rem; text-align: center;
      font-size: .88rem; font-weight: 600; color: #92400e;
    }

    /* ── SECTION WRAPPER ── */
    .ap-section { padding: 5rem 2rem; }
    .ap-section-inner { max-width: 1200px; margin: 0 auto; }
    .ap-section-label {
      display: inline-block; background: #ede9fe; color: var(--purple);
      border-radius: 999px; padding: .3rem .9rem; font-size: .78rem;
      font-weight: 700; letter-spacing: .06em; text-transform: uppercase; margin-bottom: .85rem;
    }
    .ap-section-title {
      font-size: clamp(1.8rem, 4vw, 2.75rem); font-weight: 900;
      color: #0f0a1e; line-height: 1.15; margin: 0 0 .75rem;
    }
    .ap-section-sub { color: #6b7280; font-size: 1rem; max-width: 520px; line-height: 1.6; margin: 0 0 3rem; }

    /* ── RIDES GRID ── */
    .ap-rides-grid {
      display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 1.5rem;
    }
    .ap-ride-card {
      border-radius: 1.25rem; overflow: hidden;
      border: 1px solid #e5e7eb; background: #fff;
      transition: transform .25s, box-shadow .25s;
    }
    .ap-ride-card:hover { transform: translateY(-6px); box-shadow: 0 20px 40px rgba(124,58,237,.12); }
    .ap-ride-img {
      width: 100%; height: 200px; object-fit: cover; display: block;
      background: #ede9fe;
    }
    .ap-ride-img-placeholder {
      width: 100%; height: 200px; background: linear-gradient(135deg,#ede9fe,#ddd6fe);
      display: flex; align-items: center; justify-content: center; font-size: 3.5rem;
    }
    .ap-ride-body { padding: 1.25rem 1.5rem; }
    .ap-ride-name { font-size: 1.1rem; font-weight: 800; color: #0f0a1e; margin: 0 0 .35rem; }
    .ap-ride-desc { font-size: .85rem; color: #6b7280; line-height: 1.5; margin: 0 0 .85rem; }
    .ap-ride-meta { display: flex; gap: .5rem; flex-wrap: wrap; }
    .ap-ride-tag {
      font-size: .72rem; font-weight: 700; padding: .2rem .6rem; border-radius: .4rem;
    }
    .tag-thrill  { background: #fee2e2; color: #dc2626; }
    .tag-family  { background: #dcfce7; color: #16a34a; }
    .tag-kids    { background: #ede9fe; color: #7c3aed; }
    .tag-water   { background: #dbeafe; color: #1d4ed8; }
    .tag-classic { background: #f1f5f9; color: #475569; }

    /* ── TICKETS SECTION ── */
    .ap-tickets-section { background: #0f0a1e; }
    .ap-tickets-section .ap-section-label { background: rgba(245,158,11,.15); color: var(--gold); }
    .ap-tickets-section .ap-section-title { color: #fff; }
    .ap-tickets-section .ap-section-sub   { color: rgba(255,255,255,.55); }

    .ap-ticket-grid {
      display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 1.5rem;
    }
    .ap-ticket-card {
      background: rgba(255,255,255,.06); border: 1px solid rgba(255,255,255,.1);
      border-radius: 1.25rem; padding: 2rem 1.75rem;
      transition: border-color .2s, transform .2s;
      display: flex; flex-direction: column;
    }
    .ap-ticket-card:hover { border-color: var(--gold); transform: translateY(-4px); }
    .ap-ticket-name { font-size: 1.2rem; font-weight: 800; color: #fff; margin: 0 0 .4rem; }
    .ap-ticket-desc { font-size: .85rem; color: rgba(255,255,255,.55); line-height: 1.5; margin: 0 0 1.25rem; }
    .ap-ticket-rides { font-size: .82rem; color: var(--gold); font-weight: 700; margin-bottom: 1.5rem; }
    .ap-ticket-price { font-size: 2.5rem; font-weight: 900; color: var(--gold); line-height: 1; margin-bottom: 1.5rem; }
    .ap-ticket-price span { font-size: .9rem; color: rgba(255,255,255,.45); font-weight: 500; }
    .ap-ticket-btn {
      display: block; text-align: center; background: var(--gold); color: #000;
      font-weight: 800; padding: .8rem; border-radius: 999px; text-decoration: none;
      font-size: .9rem; transition: background .2s; margin-top: auto;
    }
    .ap-ticket-btn:hover { background: #d97706; }

    /* ── WHY US ── */
    .ap-why-grid {
      display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 2rem;
    }
    .ap-why-card { text-align: center; }
    .ap-why-icon {
      width: 64px; height: 64px; border-radius: 1rem;
      background: #ede9fe; display: flex; align-items: center; justify-content: center;
      font-size: 1.75rem; margin: 0 auto 1rem;
    }
    .ap-why-title { font-size: 1rem; font-weight: 800; color: #0f0a1e; margin: 0 0 .4rem; }
    .ap-why-desc  { font-size: .88rem; color: #6b7280; line-height: 1.55; margin: 0; }

    /* ── CTA BANNER ── */
    .ap-cta {
      background: linear-gradient(135deg, #7c3aed 0%, #a855f7 50%, #ec4899 100%);
      padding: 5rem 2rem; text-align: center;
    }
    .ap-cta h2 { font-size: clamp(2rem, 5vw, 3.5rem); font-weight: 900; color: #fff; margin: 0 0 1rem; }
    .ap-cta p  { color: rgba(255,255,255,.8); font-size: 1.1rem; margin: 0 0 2.5rem; }

    /* ── FOOTER ── */
    .ap-footer {
      background: #0f0a1e; color: rgba(255,255,255,.5);
      text-align: center; padding: 2.5rem 1rem; font-size: .85rem;
    }
    .ap-footer a { color: rgba(255,255,255,.6); text-decoration: none; margin: 0 .75rem; }
    .ap-footer a:hover { color: #fff; }

    @media (max-width: 640px) {
      .ap-hero-stats { display: none; }
      .ap-hero-content { padding: 0 1.25rem; }
      .ap-section { padding: 3.5rem 1.25rem; }
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
      <a href="tickets.php" class="ap-btn-gold">🎟 Buy Tickets Now</a>
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
            <?php if (!empty($r['image_url'])): ?>
              <img class="ap-ride-img" src="<?= e($r['image_url']) ?>" alt="<?= e($r['name']) ?>" loading="lazy" />
            <?php else: ?>
              <div class="ap-ride-img-placeholder">🎡</div>
            <?php endif; ?>
            <div class="ap-ride-body">
              <div class="ap-ride-name"><?= e($r['name']) ?></div>
              <div class="ap-ride-desc"><?= e($r['description'] ?: 'An exciting ride experience awaits you.') ?></div>
              <div class="ap-ride-meta">
                <?php if ($cat): ?>
                  <span class="ap-ride-tag <?= $catTag[$cat] ?? 'tag-classic' ?>"><?= e($cat) ?></span>
                <?php endif; ?>
                <?php if (!empty($r['duration_minutes'])): ?>
                  <span class="ap-ride-tag tag-classic">⏱ <?= (int)$r['duration_minutes'] ?>min</span>
                <?php endif; ?>
                <?php if (!empty($r['min_height_cm'])): ?>
                  <span class="ap-ride-tag tag-classic">📏 <?= (int)$r['min_height_cm'] ?>cm+</span>
                <?php endif; ?>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
      <div style="text-align:center;margin-top:2.5rem;">
        <a href="rides.php" class="ap-btn-gold" style="display:inline-flex;">View All Rides →</a>
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
  <a href="tickets.php" class="ap-btn-gold" style="font-size:1.1rem;padding:1rem 2.75rem;">🎟 Get Your Tickets</a>
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
