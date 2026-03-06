<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/auth.php';

$user = current_user();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    flash_set('success', 'Thanks! Your message has been received.');
    redirect('contact.php');
}

$flash = flash_get();

if (!function_exists('e')) {
    function e($text) { return htmlspecialchars((string)$text, ENT_QUOTES, 'UTF-8'); }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Contact Us - AmusePark</title>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="css/style.css" />
  <style>
    :root {
        --primary: #facc15;
        --dark-text: #0f172a; /* Deep navy for maximum readability */
        --body-text: #334155; /* Solid gray for paragraphs */
        --sidebar-bg: #0f172a;
    }

    body {
        font-family: 'Plus Jakarta Sans', sans-serif;
        margin: 0;
        background-color: #f1f5f9;
    }

    /* Page Header */
    .page-header {
        background: linear-gradient(rgba(0, 0, 0, 0.7), rgba(0, 0, 0, 0.7)), 
                    url('AmusementPark_1.jpg') no-repeat center center/cover;
        padding: 8rem 1.5rem 7rem;
        text-align: center;
        color: #fff;
    }

    .page-header h1 { font-size: 3.5rem; font-weight: 800; margin-bottom: 1rem; text-shadow: 2px 2px 4px rgba(0,0,0,0.3); }
    .page-header p { color: #f1f5f9; font-size: 1.2rem; max-width: 650px; margin: 0 auto; opacity: 0.9; }

    .content-wrapper {
        max-width: 1100px;
        margin: -4rem auto 5rem;
        padding: 0 1.5rem;
        display: grid;
        grid-template-columns: 2fr 1.2fr;
        gap: 2.5rem;
    }

    /* Form Container & Text Readability */
    .form-container {
        background: #ffffff;
        padding: 3.5rem;
        border-radius: 24px;
        box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.15);
    }

    /* THE TEXT YOU ASKED TO FIX */
    .form-container h2 { 
        font-size: 2rem; 
        font-weight: 900; 
        color: var(--dark-text); /* Sharp dark color */
        margin-bottom: 0.75rem; 
        letter-spacing: -0.025em;
    }

    .form-container p.sub-text { 
        color: var(--body-text); 
        font-size: 1.05rem; 
        margin-bottom: 2.5rem; 
        line-height: 1.6;
    }

    /* Form Elements */
    .form-group { margin-bottom: 1.5rem; }
    .form-group label { 
        display: block; 
        font-weight: 700; 
        margin-bottom: 0.6rem; 
        color: var(--dark-text); 
        font-size: 0.95rem; 
    }

    .form-group input, .form-group textarea {
        width: 100%;
        padding: 1rem;
        border: 2px solid #cbd5e1; /* Thicker border for visibility */
        border-radius: 12px;
        font-family: inherit;
        font-size: 1rem;
        color: var(--dark-text);
        box-sizing: border-box;
    }

    .form-group input::placeholder, .form-group textarea::placeholder {
        color: #94a3b8;
    }

    /* Sidebar Info */
    .sidebar-card {
        background: var(--sidebar-bg);
        color: #fff;
        padding: 3rem 2rem;
        border-radius: 24px;
        height: fit-content;
    }

    .sidebar-card h3 { color: var(--primary); font-size: 1.6rem; font-weight: 800; margin-bottom: 2rem; }
    .info-item { margin-bottom: 2.2rem; }
    .info-item strong { display: block; color: var(--primary); font-size: 0.85rem; text-transform: uppercase; letter-spacing: 1.5px; margin-bottom: 0.5rem; }
    .info-item p { color: #cbd5e1; margin: 0; line-height: 1.7; font-size: 1rem; }

    @media (max-width: 900px) {
        .content-wrapper { grid-template-columns: 1fr; margin-top: 2rem; }
        .form-container { padding: 2rem; }
    }
  </style>
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

<div class="content-wrapper">
  <?php if ($flash && ($flash['message'] ?? '') !== ''): ?>
    <div style="grid-column: 1 / -1; padding:1.2rem; margin-bottom:1rem; border-radius:12px; font-weight:bold; background:<?= ($flash['type'] ?? '') === 'error' ? '#fee2e2' : '#dcfce7' ?>; color:<?= ($flash['type'] ?? '') === 'error' ? '#991b1b' : '#166534' ?>; border-left:6px solid <?= ($flash['type'] ?? '') === 'error' ? '#dc2626' : '#16a34a' ?>;">
      <?= e($flash['message']) ?>
    </div>
  <?php endif; ?>

  <div class="form-container">
    <h2>Send us a message</h2>
    <p class="sub-text">Fill out the form below and our team will get back to you as soon as possible.</p>
    
    <form method="post">
      <div style="display:grid; grid-template-columns: 1fr 1fr; gap: 1.5rem;">
        <div class="form-group">
          <label>Full Name</label>
          <input type="text" name="full_name" placeholder="Juan Dela Cruz" required />
        </div>
        <div class="form-group">
          <label>Email Address</label>
          <input type="email" name="email" placeholder="juan@example.com" required />
        </div>
      </div>
      <div class="form-group">
        <label>Subject</label>
        <input type="text" name="subject" placeholder="What is this regarding?" required />
      </div>
      <div class="form-group">
        <label>Message</label>
        <textarea name="message" rows="5" placeholder="Write your message here..." required></textarea>
      </div>
      <button type="submit" class="btn btn-yellow" style="width:100%; border:none; padding:1.2rem; font-size:1.1rem; border-radius:12px; cursor:pointer; font-weight:800; transition:0.3s;">
        Send Message
      </button>
    </form>
  </div>

  <div class="sidebar-card">
    <h3>Park Information</h3>
    <div class="info-item">
        <strong>Address</strong>
        <p>123 Amusement Park Purok 3,<br>Brgy. Cadulawan, Minglanilla, Cebu</p>
    </div>
    <div class="info-item">
        <strong>Operating Hours</strong>
        <p>Open daily: 9:00 AM – 9:00 PM</p>
    </div>
    <div class="info-item">
        <strong>Contact Details</strong>
        <p>Phone: +63 912 345 6789<br>Email: hello@amusepark.com</p>
    </div>
    <div class="info-item" style="margin-bottom: 0;">
        <strong>Social Media</strong>
        <p>Follow us on Facebook and Instagram for updates and promos.</p>
    </div>
  </div>
</div>

<footer style="background:#111827;color:#94a3b8;padding:2rem;text-align:center;">
  <p style="font-size:1.2rem;font-weight:900;color:#fff;margin-bottom:.5rem;">Amuse<span style="color:#facc15;">Park</span></p>
  <p>© 2026 AmusePark. All rights reserved.</p>
</footer>

</body>
</html>