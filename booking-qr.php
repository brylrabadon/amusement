<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/auth.php';

$user = require_login('customer');
$pdo = db();

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    flash_set('error', 'Invalid booking.');
    redirect('my-bookings.php');
}

$st = $pdo->prepare('SELECT * FROM bookings WHERE id = ? AND (user_id = ? OR customer_email = ?)');
$st->execute([$id, (int)$user['id'], (string)$user['email']]);
$b = $st->fetch();
if (!$b) {
    flash_set('error', 'Booking not found.');
    redirect('my-bookings.php');
}

$qr = (string)($b['qr_code_data'] ?? $b['booking_reference']);
$flash = flash_get();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" /><meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Booking QR - AmusePark</title>
  <link rel="stylesheet" href="css/style.css" />
</head>
<body>
<nav>
  <a class="logo" href="index.php">Amuse<span>Park</span></a>
  <ul>
    <li><a href="rides.php">Rides</a></li>
    <li><a href="tickets.php">Buy Tickets</a></li>
    <li><a href="my-bookings.php" class="active">My Bookings</a></li>
    <li><a href="logout.php" style="color:#dc2626;font-weight:600;">Logout</a></li>
  </ul>
</nav>

<div class="page-header">
  <h1>Entry QR Code</h1>
  <p>Show this QR code at the park entrance</p>
</div>

<div class="container" style="max-width:720px;">
  <?php if ($flash && ($flash['message'] ?? '') !== ''): ?>
    <div class="card" style="padding:1rem;margin-bottom:1rem;border-left:4px solid <?= ($flash['type'] ?? '') === 'error' ? '#dc2626' : '#16a34a' ?>;">
      <strong><?= e(($flash['type'] ?? '') === 'error' ? 'Error' : 'Success') ?>:</strong>
      <?= e($flash['message']) ?>
    </div>
  <?php endif; ?>

  <div class="card" style="padding:2rem;text-align:center;margin-bottom:1.25rem;">
    <div style="color:#64748b;font-size:.9rem;margin-bottom:.25rem;"><?= e($b['booking_reference'] ?? '') ?></div>
    <div style="font-weight:800;font-size:1.25rem;margin-bottom:1rem;"><?= e($b['customer_name'] ?? '') ?></div>
    <img
      src="https://api.qrserver.com/v1/create-qr-code/?size=240x240&data=<?= e(urlencode($qr)) ?>"
      style="width:240px;height:240px;border-radius:.75rem;border:4px solid #dbeafe;margin-bottom:1.25rem;"
      alt="Booking QR"
    />
    <div style="background:#f8fafc;border-radius:.75rem;padding:1rem;text-align:left;font-size:.95rem;">
      <div style="display:flex;justify-content:space-between;padding:.35rem 0;border-bottom:1px solid #f1f5f9;"><span style="color:#64748b;">Ticket</span><span><?= e($b['ticket_type_name'] ?? '') ?> × <?= (int)($b['quantity'] ?? 1) ?></span></div>
      <div style="display:flex;justify-content:space-between;padding:.35rem 0;border-bottom:1px solid #f1f5f9;"><span style="color:#64748b;">Visit Date</span><span><?= e((string)($b['visit_date'] ?? '')) ?></span></div>
      <div style="display:flex;justify-content:space-between;padding:.35rem 0;"><span style="color:#64748b;">Total</span><strong>₱<?= number_format((float)($b['total_amount'] ?? 0), 0) ?></strong></div>
    </div>
  </div>

  <a class="btn btn-outline btn-full" href="my-bookings.php" style="text-align:center;">← Back to My Bookings</a>
</div>
</body>
</html>

