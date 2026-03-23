<?php
declare(strict_types=1);


require_once __DIR__ . '/lib/auth.php';

$user = current_user();

$maintenanceNames = [];
try {
    $pdo = db();
    $rows = $pdo->query("SELECT name FROM rides WHERE status = 'Maintenance' ORDER BY created_at DESC")->fetchAll();
    foreach ($rows as $r) {
        if (!empty($r['name'])) $maintenanceNames[] = (string)$r['name'];
    }
} catch (Throwable $e) {
    // Keep landing page usable even if DB/tables aren't ready yet.
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>AmusePark - Ride the Fun</title>
  <link rel="stylesheet" href="css/style.css" />
  <style>
    /* New Styles for Background and Readability */
    .hero {
      position: relative;
      background: linear-gradient(rgba(0, 0, 0, 0.6), rgba(0, 0, 0, 0.6)), 
                  url('AmusementPark_1.jpg') no-repeat center center/cover;
      min-height: 80vh;
      display: flex;
      align-items: center;
      justify-content: center;
      color: #ffffff; 
      text-align: center;
    }

    .hero h1 {
      font-size: 3.5rem;
      text-shadow: 2px 2px 10px rgba(0,0,0,0.5);
      margin-bottom: 1rem;
    }

    .hero p {
      font-size: 1.2rem;
      max-width: 700px;
      margin: 0 auto 2rem;
      color: #f1f5f9; 
    }

    /* Centering the Hero Buttons */
    .hero-btns {
      display: flex;
      justify-content: center;
      align-items: center;
      gap: 15px;
    }

    nav {
      background: rgba(255, 255, 255, 0.95);
      backdrop-filter: blur(5px);
    }
    
    .about-grid {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 4rem;
      max-width: 1100px;
      margin: 5rem auto;
      padding: 0 2rem;
      align-items: center;
    }

    .about-img img {
      width: 100%;
      border-radius: 20px;
      box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1);
    }
  </style>
</head>
<body>

<nav>
  <a class="logo" href="index.php">Amuse<span>Park</span></a>
  <ul>
    <li><a href="index.php" class="active">Home</a></li>
    <li><a href="tickets.php">Tickets</a></li>
    <li><a href="rides.php">Rides</a></li>
    <li><a href="contact.php">Contact</a></li>
    <?php if ($user): ?>
      <li><a href="my-bookings.php">My Bookings</a></li>
      <li><a href="logout.php" style="color:#dc2626;font-weight:600;">Logout</a></li>
    <?php else: ?>
      <li><a href="login.php" class="btn btn-yellow">Login</a></li>
    <?php endif; ?>
  </ul>
</nav>

<section class="hero">
  <div class="hero-content">
    <div class="hero-tag" style="background:#facc15; color:#000; display:inline-block; padding:0.5rem 1rem; border-radius:50px; font-weight:bold; margin-bottom:1.5rem;">⭐ Philippines' #1 Ticketing Rides Website</div>
    <h1>Ride the Fun.<br /><span style="color:#facc15;">Book in Seconds.</span></h1>
    <p>Experience thrilling rides, family adventures, and unforgettable memories. <br>Book online and skip the queue!</p>
    <div class="hero-btns">
      <a href="tickets.php" class="btn btn-yellow" style="font-size:1.1rem;padding:.85rem 2rem; text-decoration:none;">🎟 View Tickets</a>
      <a href="rides.php" class="btn btn-outline" style="font-size:1.1rem;padding:.85rem 2rem;border:2px solid #fff;color:#fff; text-decoration:none;">Explore Rides</a>
    </div>
  </div>
</section>

<section style="background:#facc15;padding:4rem 1.5rem; color:#1e293b;">
  <div style="max-width:1000px;margin:0 auto;display:grid;grid-template-columns:repeat(auto-fit, minmax(150px, 1fr));gap:2rem;text-align:center;">
    <div><div style="font-size:2.5rem;font-weight:900;">50+</div><div style="font-weight:600; opacity:0.8;">Exciting Rides</div></div>
    <div><div style="font-size:2.5rem;font-weight:900;">500K+</div><div style="font-weight:600; opacity:0.8;">Happy Visitors</div></div>
    <div><div style="font-size:2.5rem;font-weight:900;">4.9★</div><div style="font-weight:600; opacity:0.8;">Average Rating</div></div>
    <div><div style="font-size:2.5rem;font-weight:900;">20+</div><div style="font-weight:600; opacity:0.8;">Years of Fun</div></div>
  </div>
</section>

<section style="background:#ffffff;padding:5rem 1.5rem;">
  <div style="max-width:900px;margin:0 auto;text-align:center;">
    <h2 style="font-size:2.5rem;font-weight:900;margin-bottom:.5rem; color:#0f172a;">Why Book Online?</h2>
    <p style="color:#64748b;margin-bottom:3rem; font-size:1.1rem;">Skip the line, save time, enjoy more.</p>
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:1.5rem;">
      <div class="card" style="padding:2.5rem 1.5rem; background:#f8fafc; border-radius:15px; transition: 0.3s;">
        <div style="font-size:2.5rem;margin-bottom:1rem;">🎟</div>
        <h3 style="font-weight:700;margin-bottom:.5rem; color:#1e293b;">Easy Booking</h3>
        <p style="color:#64748b;font-size:.95rem;">Book tickets online in seconds and skip the queue</p>
      </div>
      <div class="card" style="padding:2.5rem 1.5rem; background:#f8fafc; border-radius:15px;">
        <div style="font-size:2.5rem;margin-bottom:1rem;">⚡</div>
        <h3 style="font-weight:700;margin-bottom:.5rem; color:#1e293b;">Instant QR</h3>
        <p style="color:#64748b;font-size:.95rem;">Get your QR code instantly after payment</p>
      </div>
      <div class="card" style="padding:2.5rem 1.5rem; background:#f8fafc; border-radius:15px;">
        <div style="font-size:2.5rem;margin-bottom:1rem;">🔒</div>
        <h3 style="font-weight:700;margin-bottom:.5rem; color:#1e293b;">Secure Payments</h3>
        <p style="color:#64748b;font-size:.95rem;">Pay safely via QR Ph, GCash, or card</p>
      </div>
      <div class="card" style="padding:2.5rem 1.5rem; background:#f8fafc; border-radius:15px;">
        <div style="font-size:2.5rem;margin-bottom:1rem;">🕐</div>
        <h3 style="font-weight:700;margin-bottom:.5rem; color:#1e293b;">Open Year-Round</h3>
        <p style="color:#64748b;font-size:.95rem;">Open 7 days a week, 9AM to 9PM</p>
      </div>
    </div>
  </div>
</section>

<section style="background: #0f172a; padding: 1rem 0;">
  <section class="about-grid">
    <div>
      <h2 style="font-size:2.5rem;font-weight:900;margin-bottom:1.5rem; color:#ffffff;">Looking for Adventure?</h2>
      <p style="color:#cbd5e1;line-height:1.8;margin-bottom:1rem;">AmusePark is your ultimate destination for thrills, laughter, and unforgettable memories. From heart-pounding roller coasters to gentle family rides, we have something for everyone.</p>
      <p style="color:#cbd5e1;line-height:1.8;margin-bottom:1.5rem;">Located in the heart of the Philippines, our park features over 50 world-class rides, multiple dining options, and entertainment zones for all ages.</p>
      <div style="background:rgba(255,255,255,0.05); padding:1.5rem; border-left:4px solid #facc15; border-radius:4px;">
          <p style="color:#ffffff;">📍 123 Amusement Park Purok 3, Brgy. Cadulawan, Minglanilla, Cebu</p>
          <p style="margin-top:.5rem; font-weight:bold; color:#facc15;">🕐 Open daily: 9:00 AM – 9:00 PM</p>
      </div>
    </div>
    <div class="about-img">
      <img src="AmusementPark_1.jpg" alt="Park Panorama" />
    </div>
  </section>
</section>

<section style="background:linear-gradient(135deg,#1e293b,#0f172a);padding:6rem 1.5rem;text-align:center;color:#fff;">
  <h2 style="font-size:2.5rem;font-weight:900;margin-bottom:1rem;">Ready for the Adventure?</h2>
  <p style="color:#cbd5e1;margin-bottom:2.5rem;font-size:1.2rem;">Book your tickets now and get your QR code instantly!</p>
  <a href="tickets.php" class="btn btn-yellow" style="font-size:1.2rem;padding:1rem 3.5rem; text-decoration:none;">🎟 View Ticket Types</a>
</section>

<?php if (count($maintenanceNames)): ?>
  <section id="park-status" style="background:#fee2e2;padding:1.5rem;">
    <div style="max-width:900px;margin:0 auto;font-size:1rem;color:#b91c1c; text-align:center;">
      <strong>⚠️ Service Update:</strong>
      <?= e(implode(', ', $maintenanceNames)) ?> are currently under maintenance.
    </div>
  </section>
<?php endif; ?>

<footer style="background:#0f172a;color:#94a3b8;padding:4rem 2rem;text-align:center;">
  <p style="font-size:1.5rem;font-weight:900;color:#fff;margin-bottom:1rem;">Amuse<span style="color:#facc15;">Park</span></p>
  <p>© 2026 AmusePark Philippines. All rights reserved.</p>
</footer>

</body>
</html>