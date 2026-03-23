<?php
declare(strict_types=1);
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/layout.php';

$user = current_user();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    flash_set('success', 'Thanks! Your message has been received. We\'ll get back to you soon.');
    redirect('contact.php');
}
$flash = flash_get();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" /><meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Contact Us - AmusePark</title>
  <link rel="stylesheet" href="css/style.css" />
  <style>
    body { background: #f9fafb; }
    .contact-grid {
      max-width: 1000px; margin: 3rem auto; padding: 0 1.5rem;
      display: grid; grid-template-columns: 2fr 1.1fr; gap: 2rem;
    }
    .contact-form-card { background: #fff; border-radius: 1.25rem; padding: 2.5rem; box-shadow: 0 2px 16px rgba(0,0,0,.07); }
    .contact-form-card h2 { font-size: 1.6rem; font-weight: 900; color: #111827; margin-bottom: .4rem; }
    .contact-form-card .sub { color: #6b7280; font-size: .95rem; margin-bottom: 2rem; }
    .contact-form-card label { color: #374151; font-weight: 700; font-size: .88rem; }
    .contact-form-card input,
    .contact-form-card textarea {
      background: #f9fafb; border: 1.5px solid #e5e7eb;
      border-radius: .6rem; padding: .7rem 1rem; font-size: .95rem; width: 100%;
    }
    .contact-form-card input:focus,
    .contact-form-card textarea:focus { border-color: #7c3aed; outline: none; background: #fff; }
    .contact-form-card .two-col { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
    .contact-submit {
      width: 100%; padding: .9rem; border-radius: 999px;
      background: #7c3aed; color: #fff; font-weight: 800; font-size: 1rem;
      border: none; cursor: pointer; transition: background .2s;
      margin-top: .5rem;
    }
    .contact-submit:hover { background: #6d28d9; }

    .contact-sidebar {
      background: #111827; color: #fff;
      border-radius: 1.25rem; padding: 2.5rem 2rem;
      height: fit-content;
    }
    .contact-sidebar h3 { color: #facc15; font-size: 1.3rem; font-weight: 800; margin-bottom: 1.75rem; }
    .info-item { margin-bottom: 1.75rem; }
    .info-item strong { display: block; color: #facc15; font-size: .75rem; text-transform: uppercase; letter-spacing: .1em; margin-bottom: .4rem; }
    .info-item p { color: #d1d5db; font-size: .9rem; line-height: 1.65; margin: 0; }

    @media (max-width: 768px) {
      .contact-grid { grid-template-columns: 1fr; }
    }
  </style>
</head>
<body>
<?php render_nav($user, 'contact'); ?>
<?php render_page_header('Contact Us', 'Questions, feedback, or group bookings? We\'d love to hear from you.'); ?>

<<<<<<< HEAD
<div class="contact-grid">
=======
<nav>
  <a class="logo" href="index.php">Amuse<span>Park</span></a>
  <ul>
    <li><a href="index.php">Home</a></li>
    <li><a href="rides.php">Rides</a></li>
    <li><a href="tickets.php">Tickets</a></li>
    <li><a href="contact.php" class="active">Contact</a></li>
    <?php if ($user): ?>
      <li><a href="my-bookings.php">My Bookings</a></li>
      <li><a href="logout.php" style="color:#dc2626;font-weight:600;">Logout</a></li>
    <?php else: ?>
      <li><a href="login.php" class="btn btn-yellow">Login</a></li>
    <?php endif; ?>
  </ul>
</nav>

<div class="page-header">
  <h1>Contact Us</h1>
  <p>Questions, feedback, or group bookings? We’d love to hear from you.</p>
</div>

<div class="content-wrapper">
>>>>>>> 944246f7d1f7012ed1c7107d999e7fdfb8af41b5
  <?php if ($flash && ($flash['message'] ?? '') !== ''): ?>
    <div style="grid-column:1/-1;padding:1rem 1.25rem;border-radius:.75rem;font-weight:600;
      background:<?= ($flash['type']??'')!=='error'?'#dcfce7':'#fee2e2' ?>;
      border:1px solid <?= ($flash['type']??'')!=='error'?'#86efac':'#fca5a5' ?>;
      color:<?= ($flash['type']??'')!=='error'?'#166534':'#991b1b' ?>;">
      <?= e($flash['message']) ?>
    </div>
  <?php endif; ?>

  <div class="contact-form-card">
    <h2>Send us a message</h2>
    <p class="sub">Fill out the form and our team will get back to you as soon as possible.</p>
    <form method="post">
      <div class="two-col">
        <div class="form-group"><label>Full Name</label><input type="text" name="full_name" placeholder="Juan Dela Cruz" required /></div>
        <div class="form-group"><label>Email Address</label><input type="email" name="email" placeholder="juan@example.com" required /></div>
      </div>
      <div class="form-group"><label>Subject</label><input type="text" name="subject" placeholder="What is this regarding?" required /></div>
      <div class="form-group"><label>Message</label><textarea name="message" rows="5" placeholder="Write your message here..." required></textarea></div>
      <button type="submit" class="contact-submit">Send Message →</button>
    </form>
  </div>

  <div class="contact-sidebar">
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
    <div class="info-item" style="margin-bottom:0;">
      <strong>Social Media</strong>
      <p>Follow us on Facebook and Instagram for updates and promos.</p>
    </div>
  </div>
</div>

<?php render_footer(); ?>
</body>
</html>
