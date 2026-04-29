<?php
declare(strict_types=1);
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/layout.php';

$user = require_login();
$pdo  = db();

// Get recently paid bookings for this user
$bookings = [];
try {
    $st = $pdo->prepare(
        "SELECT b.*, t.ticket_number FROM bookings b
         LEFT JOIN tickets t ON t.booking_id = b.id AND t.ticket_number = (
             SELECT ticket_number FROM tickets WHERE booking_id = b.id ORDER BY ticket_number ASC LIMIT 1
         )
         WHERE b.user_id = ? AND b.payment_status = 'Paid'
         ORDER BY b.updated_at DESC LIMIT 10"
    );
    $st->execute([(int)$user['id']]);
    $bookings = $st->fetchAll();
} catch (\Throwable $e) {}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/><meta name="viewport" content="width=device-width,initial-scale=1"/>
  <title>Booking Confirmed — AmusePark</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="css/style.css"/>
  <style>
    *,*::before,*::after{box-sizing:border-box}
    body{font-family:'Poppins',sans-serif;background:#f1f5f9;color:#0f172a;margin:0}
    .conf-hero{background:linear-gradient(135deg,#0f172a 0%,#16a34a 100%);padding:3rem 2rem;text-align:center}
    .conf-hero h1{font-size:1.75rem;font-weight:900;color:#fff;margin:0 0 .3rem}
    .conf-hero p{color:rgba(255,255,255,.75);font-size:.9rem;margin:0}
    .conf-wrap{max-width:700px;margin:0 auto;padding:2rem 1.5rem 4rem}
    .conf-card{background:#fff;border:1px solid #e2e8f0;border-radius:1.25rem;padding:1.5rem;margin-bottom:1rem;box-shadow:0 2px 8px rgba(0,0,0,.04);display:flex;gap:1.25rem;align-items:flex-start}
    .qr-box{border:3px solid #1e3a8a;border-radius:.85rem;padding:.6rem;background:#fff;flex-shrink:0}
    .qr-box img{width:100px;height:100px;display:block;}
    .btn-mybookings{display:inline-flex;align-items:center;gap:.5rem;background:#1e3a8a;color:#fff;padding:.85rem 2rem;border-radius:999px;font-weight:900;font-size:.95rem;text-decoration:none;margin-top:1.5rem}
    .btn-mybookings:hover{background:#172554}
  </style>
</head>
<body>
<?php render_nav($user, ''); ?>

<div class="conf-hero">
  <div style="font-size:3rem;margin-bottom:.5rem;">🎉</div>
  <h1>All Bookings Confirmed!</h1>
  <p>Your tickets are ready — show the QR codes at the park entrance</p>
</div>

<div class="conf-wrap">
  <div style="background:#f0fdf4;border:1px solid #86efac;border-radius:1rem;padding:1rem 1.5rem;margin-bottom:1.5rem;font-weight:700;color:#166534;font-size:.9rem;">
    ✅ Confirmation emails have been sent to your inbox
  </div>

  <?php foreach ($bookings as $bk):
    $ticketNum = (string)($bk['ticket_number'] ?? ('TK-' . ($bk['booking_reference'] ?? '') . '-001'));
  ?>
    <div class="conf-card">
      <div class="qr-box">
        <img src="https://api.qrserver.com/v1/create-qr-code/?size=100x100&data=<?= e(urlencode($ticketNum)) ?>"
             alt="QR <?= e($ticketNum) ?>"/>
      </div>
      <div style="flex:1;min-width:0;">
        <div style="font-family:monospace;font-weight:900;color:#1e3a8a;font-size:.95rem;"><?= e($bk['booking_reference'] ?? '') ?></div>
        <div style="font-weight:700;color:#0f172a;margin-top:.2rem;"><?= e($bk['ticket_type_name'] ?? '') ?> × <?= (int)$bk['quantity'] ?></div>
        <div style="font-size:.82rem;color:#64748b;margin-top:.3rem;">📅 Visit: <?= e((string)$bk['visit_date']) ?></div>
        <div style="font-size:.82rem;color:#64748b;">💰 ₱<?= number_format((float)$bk['total_amount'], 0) ?></div>
        <div style="font-family:monospace;font-size:.75rem;color:#94a3b8;margin-top:.3rem;"><?= e($ticketNum) ?></div>
      </div>
      <div style="text-align:right;flex-shrink:0;">
        <span style="background:#dcfce7;color:#166534;padding:.25rem .75rem;border-radius:999px;font-size:.72rem;font-weight:700;">✅ Paid</span>
      </div>
    </div>
  <?php endforeach; ?>

  <div style="text-align:center;">
    <a href="my-bookings.php" class="btn-mybookings">📋 View All My Bookings</a>
  </div>
</div>

<?php render_footer(); ?>
</body>
</html>
