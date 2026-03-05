<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/auth.php';

$user = current_user();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    flash_set('success', 'Thanks! Your message has been received.');
    redirect('contact.php');
}

$flash = flash_get();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Contact Us - AmusePark</title>
  <link rel="stylesheet" href="css/style.css" />
</head>
<body>

<nav>
  <a class="logo" href="index.php">Amuse<span>Park</span></a>
  <ul>
    <li><a href="index.php">Home</a></li>
    <li><a href="contact.php" class="active">Contact</a></li>
    <li><a href="login.php" class="btn btn-yellow">Login</a></li>
  </ul>
</nav>

<div class="page-header">
  <h1>Contact Us</h1>
  <p>Questions, feedback, or group bookings? We’d love to hear from you.</p>
</div>

<div class="container">
  <?php if ($flash && ($flash['message'] ?? '') !== ''): ?>
    <div class="card" style="padding:1rem;margin-bottom:1rem;border-left:4px solid <?= ($flash['type'] ?? '') === 'error' ? '#dc2626' : '#16a34a' ?>;">
      <strong><?= e(($flash['type'] ?? '') === 'error' ? 'Error' : 'Success') ?>:</strong>
      <?= e($flash['message']) ?>
    </div>
  <?php endif; ?>

  <div style="display:grid;grid-template-columns:2fr 1.3fr;gap:2.5rem;align-items:flex-start;flex-wrap:wrap;">
    <div>
      <h2 style="font-size:1.5rem;font-weight:800;margin-bottom:1rem;">Send us a message</h2>
      <p style="color:#64748b;margin-bottom:1.5rem;font-size:.95rem;">
        Fill out the form below and our team will get back to you as soon as possible.
      </p>
      <form method="post">
        <div class="form-group">
          <label>Full Name</label>
          <input type="text" name="full_name" placeholder="Your name" required />
        </div>
        <div class="form-group">
          <label>Email Address</label>
          <input type="email" name="email" placeholder="you@example.com" required />
        </div>
        <div class="form-group">
          <label>Phone Number (Optional)</label>
          <input type="text" name="phone" placeholder="+63 9XX XXX XXXX" />
        </div>
        <div class="form-group">
          <label>Subject</label>
          <input type="text" name="subject" placeholder="How can we help you?" required />
        </div>
        <div class="form-group">
          <label>Message</label>
          <textarea name="message" rows="5" placeholder="Type your message here..." required></textarea>
        </div>
        <button type="submit" class="btn btn-primary btn-full" style="margin-top:.5rem;">
          Send Message
        </button>
      </form>
    </div>

    <div class="card" style="padding:1.75rem;">
      <h3 style="font-size:1.2rem;font-weight:800;margin-bottom:1rem;">Park Information</h3>
      <p style="color:#64748b;font-size:.95rem;margin-bottom:1rem;">
        AmusePark is your destination for thrills, laughter, and unforgettable memories.
      </p>
      <div style="margin-bottom:1rem;">
        <strong>Address</strong>
        <p style="color:#64748b;font-size:.9rem;margin-top:.25rem;">
          123 Amusement Park Purok 3,<br />
          Brgy. Cadulawan, Minglanilla, Cebu
        </p>
      </div>
      <div style="margin-bottom:1rem;">
        <strong>Operating Hours</strong>
        <p style="color:#64748b;font-size:.9rem;margin-top:.25rem;">
          Open daily: 9:00 AM – 9:00 PM
        </p>
      </div>
      <div style="margin-bottom:1rem;">
        <strong>Contact Details</strong>
        <p style="color:#64748b;font-size:.9rem;margin-top:.25rem;">
          Phone: +63 912 345 6789<br />
          Email: hello@amusepark.com
        </p>
      </div>
      <div>
        <strong>Social Media</strong>
        <p style="color:#64748b;font-size:.9rem;margin-top:.25rem;">
          Follow us on Facebook and Instagram for updates and promos.
        </p>
      </div>
    </div>
  </div>
</div>

<footer style="background:#111827;color:#94a3b8;padding:2rem;text-align:center;">
  <p style="font-size:1.2rem;font-weight:900;color:#fff;margin-bottom:.5rem;">Amuse<span style="color:#facc15;">Park</span></p>
  <p>© 2026 AmusePark. All rights reserved.</p>
</footer>

</body>
</html>

