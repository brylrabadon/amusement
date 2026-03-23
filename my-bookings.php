<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/auth.php';

$user = require_login(); // any logged-in role
$pdo = db();

$st = $pdo->prepare('SELECT * FROM bookings WHERE user_id = ? ORDER BY created_at DESC');
$st->execute([(int)$user['id']]);
$bookings = $st->fetchAll();

// Fetch ticket counts per booking
$ticketCounts = [];
try {
    $tcst = $pdo->prepare('SELECT booking_id, COUNT(*) as cnt FROM tickets WHERE booking_id IN (SELECT id FROM bookings WHERE user_id = ?) GROUP BY booking_id');
    $tcst->execute([(int)$user['id']]);
    foreach ($tcst->fetchAll() as $row) {
        $ticketCounts[(int)$row['booking_id']] = (int)$row['cnt'];
    }
} catch (\Throwable $e) {
    // tickets table may not exist yet
}
$flash = flash_get();
$payColors = ['Paid' => 'badge-green', 'Pending' => 'badge-yellow', 'Cancelled' => 'badge-red', 'Refunded' => 'badge-blue'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" /><meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>My Bookings - AmusePark</title>
  <link rel="stylesheet" href="css/style.css" />
</head>
<body>
<nav>
  <a class="logo" href="index.php">Amuse<span>Park</span></a>
  <ul>
    <li><a href="rides.php">Rides</a></li>
    <li><a href="tickets.php">Buy Tickets</a></li>
    <li><a href="my-bookings.php" class="active">My Bookings</a></li>
     <li><a href="profile.php">Profile</a></li>
    <li><a href="logout.php" style="color:#dc2626;font-weight:600;">Logout</a></li>
  </ul>
</nav>

<div class="page-header">
  <h1>My Bookings</h1>
  <p>Your ticket bookings and QR codes</p>
</div>

<div class="container" style="max-width:800px;">
  <?php if ($flash && ($flash['message'] ?? '') !== ''): ?>
    <div class="card" style="padding:1rem;margin-bottom:1rem;border-left:4px solid <?= ($flash['type'] ?? '') === 'error' ? '#dc2626' : '#16a34a' ?>;">
      <strong><?= e(($flash['type'] ?? '') === 'error' ? 'Error' : 'Success') ?>:</strong>
      <?= e($flash['message']) ?>
    </div>
  <?php endif; ?>

  <div style="margin-bottom:1.5rem;">
    <a class="btn btn-primary" href="tickets.php">Buy Tickets</a>
  </div>

  <?php if (!count($bookings)): ?>
    <div class="empty">
      <div class="empty-icon">🎟</div>
      <p>You have no bookings yet.</p>
      <a class="btn btn-primary" href="tickets.php" style="margin-top:1rem;">Buy Tickets</a>
    </div>
  <?php else: ?>
    <div class="grid" style="display:grid;gap:1rem;">
      <?php foreach ($bookings as $b): ?>
        <?php $pay = (string)($b['payment_status'] ?? 'Pending'); ?>
        <div class="card" style="padding:1.25rem;">
          <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:1rem;">
            <div>
              <div style="font-weight:800;font-size:1.1rem;color:#1d4ed8;"><?= e($b['booking_reference'] ?? '') ?></div>
              <div style="color:#64748b;font-size:.9rem;margin-top:.25rem;"><?= e($b['ticket_type_name'] ?? '') ?> × <?= (int)($b['quantity'] ?? 1) ?></div>
              <div style="margin-top:.5rem;">Visit: <?= e((string)($b['visit_date'] ?? '')) ?></div>
              <?php $tc = $ticketCounts[(int)$b['id']] ?? 0; ?>
              <?php if ($tc > 0): ?>
                <div style="font-size:.8rem;color:#7c3aed;margin-top:.3rem;">🎫 <?= $tc ?> ticket<?= $tc !== 1 ? 's' : '' ?> generated</div>
              <?php endif; ?>
              <span class="badge <?= e($payColors[$pay] ?? 'badge-gray') ?>" style="margin-top:.5rem;"><?= e($pay) ?></span>
            </div>
            <div style="text-align:right;">
              <div style="font-size:1.25rem;font-weight:900;">₱<?= number_format((float)($b['total_amount'] ?? 0), 0) ?></div>
              <a class="btn btn-outline btn-sm" href="booking-qr.php?id=<?= (int)$b['id'] ?>" style="margin-top:.5rem;display:inline-block;">View QR</a>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>
</body>
</html>