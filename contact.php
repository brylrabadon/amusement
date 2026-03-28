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
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="css/style.css?v=1.1" />
  <style>
    :root {
      --primary: #1e3a8a;
      --primary-dark: #172554;
      --secondary: #fbbf24;
      --secondary-dark: #f59e0b;
      --dark: #0f172a;
      --light: #f8fafc;
    }
    body { background: var(--light); font-family: 'Poppins', sans-serif; }
    .contact-grid {
      max-width: 1100px; margin: 4rem auto; padding: 0 1.5rem;
      display: grid; grid-template-columns: 1.5fr 1fr; gap: 3rem;
    }
    .contact-form-card { background: #fff; border-radius: 1.5rem; padding: 3rem; box-shadow: 0 10px 25px rgba(0,0,0,0.05); border: 1px solid #e2e8f0; }
    .contact-form-card h2 { font-size: 2rem; font-weight: 800; color: var(--text-dark); margin-bottom: .5rem; }
    .contact-form-card .sub { color: var(--text-muted); font-size: 1rem; margin-bottom: 2.5rem; }
    .contact-form-card label { color: var(--text-dark); font-weight: 700; font-size: .9rem; margin-bottom: .5rem; display: block; }
    .contact-form-card input,
    .contact-form-card textarea {
      background: var(--bg-light); border: 1.5px solid #e2e8f0;
      border-radius: 12px; padding: .85rem 1.25rem; font-size: .95rem; width: 100%; transition: all .3s; font-family: inherit;
    }
    .contact-form-card input:focus,
    .contact-form-card textarea:focus { border-color: var(--primary); outline: none; box-shadow: 0 0 0 4px rgba(30, 58, 138, 0.1); background: #fff; }
    .contact-form-card .two-col { display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem; margin-bottom: 1.5rem; }
    .contact-submit {
      width: 100%; padding: 1.1rem; border-radius: 15px;
      background: var(--primary); color: #fff; font-weight: 800; font-size: 1.1rem;
      border: none; cursor: pointer; transition: all .3s;
      margin-top: 1rem; box-shadow: 0 10px 20px rgba(30, 58, 138, 0.2);
    }
    .contact-submit:hover { background: var(--primary-dark); transform: translateY(-3px); }

    .contact-sidebar {
      background: var(--primary-dark); color: #fff;
      border-radius: 1.5rem; padding: 3rem 2.5rem;
      height: fit-content; position: relative; overflow: hidden;
    }
    .contact-sidebar::before {
      content: ''; position: absolute; inset: 0;
      background: url('https://www.transparenttextures.com/patterns/cubes.png'); opacity: 0.1;
    }
    .contact-sidebar h3 { color: var(--secondary); font-size: 1.5rem; font-weight: 800; margin-bottom: 2rem; position: relative; }
    .info-item { margin-bottom: 2rem; position: relative; }
    .info-item strong { display: block; color: var(--secondary); font-size: .8rem; text-transform: uppercase; letter-spacing: .15em; margin-bottom: .5rem; }
    .info-item p { color: rgba(255,255,255,0.8); font-size: 1rem; line-height: 1.7; margin: 0; font-weight: 500; }

    @media (max-width: 992px) {
      .contact-grid { grid-template-columns: 1fr; }
    }
  </style>
</head>
<body>
<?php render_nav($user, 'contact'); ?>
<?php render_page_header('Contact Us', 'Questions, feedback, or group bookings? We\'d love to hear from you.'); ?>

<div class="contact-grid">
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
      <button type="submit" class="contact-submit">Send Message &rarr;</button>
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
      <p>Open daily: 9:00 AM &ndash; 9:00 PM</p>
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
