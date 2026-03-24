<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/auth.php';

$user = require_login(); // any logged-in role
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

// Fetch individual tickets from tickets table
$individualTickets = [];
try {
    $tst = $pdo->prepare('SELECT ticket_number, status FROM tickets WHERE booking_id = ? ORDER BY ticket_number ASC');
    $tst->execute([$id]);
    $individualTickets = $tst->fetchAll();
} catch (\Throwable $e) {
    // tickets table may not exist yet
}

// Fetch included rides for this ticket type
$includedRides = [];
$ttid = (int)($b['ticket_type_id'] ?? 0);
if ($ttid > 0) {
    try {
        $rs = $pdo->prepare('SELECT r.name FROM ticket_ride tr JOIN rides r ON r.id = tr.ride_id WHERE tr.ticket_type_id = ? ORDER BY r.name ASC');
        $rs->execute([$ttid]);
        $includedRides = $rs->fetchAll(\PDO::FETCH_COLUMN);
    } catch (\Throwable $e) {
        // ticket_ride table may not exist yet
    }
}

$flash = flash_get();
$payColors = ['Paid' => '#16a34a', 'Pending' => '#ca8a04', 'Cancelled' => '#dc2626'];
$payStatus = (string)($b['payment_status'] ?? 'Pending');
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
    <li><a href="profile.php">Profile</a></li>
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

  <!-- Booking Summary -->
  <div class="card" style="padding:2rem;text-align:center;margin-bottom:1.25rem;">
    <div style="color:#64748b;font-size:.9rem;margin-bottom:.25rem;">Booking Reference</div>
    <div style="font-family:monospace;font-weight:800;font-size:1.25rem;color:#1d4ed8;margin-bottom:.5rem;"><?= e($b['booking_reference'] ?? '') ?></div>
    <div style="font-weight:700;font-size:1rem;margin-bottom:1rem;"><?= e($b['customer_name'] ?? '') ?></div>
    <?php if (!empty($b['paymongo_intent_id'])): ?>
      <div style="margin-bottom:1rem;">
        <span style="background:#dcfce7;color:#16a34a;border-radius:999px;padding:.25rem .85rem;font-size:.78rem;font-weight:700;">✓ Paid via PayMongo QR Ph</span>
      </div>
    <?php endif; ?>

    <?php if (count($individualTickets) === 0): ?>
      <!-- Fallback: show booking-level QR if no individual tickets exist -->
      <?php
        $pmQrImage = (string)($b['paymongo_qr_image'] ?? '');
        $fallbackQrSrc = $pmQrImage !== ''
          ? $pmQrImage
          : 'https://api.qrserver.com/v1/create-qr-code/?size=240x240&data=' . urlencode($qr);
      ?>
      <img
        src="<?= e($fallbackQrSrc) ?>"
        style="width:240px;height:240px;border-radius:.75rem;border:4px solid #dbeafe;margin-bottom:1.25rem;"
        alt="Booking QR"
      />
    <?php endif; ?>

    <div style="background:#f8fafc;border-radius:.75rem;padding:1rem;text-align:left;font-size:.95rem;">
      <div style="display:flex;justify-content:space-between;padding:.35rem 0;border-bottom:1px solid #f1f5f9;">
        <span style="color:#64748b;">Ticket</span>
        <span><?= e($b['ticket_type_name'] ?? '') ?> × <?= (int)($b['quantity'] ?? 1) ?></span>
      </div>
      <div style="display:flex;justify-content:space-between;padding:.35rem 0;border-bottom:1px solid #f1f5f9;">
        <span style="color:#64748b;">Visit Date</span>
        <span><?= e((string)($b['visit_date'] ?? '')) ?></span>
      </div>
      <div style="display:flex;justify-content:space-between;padding:.35rem 0;border-bottom:1px solid #f1f5f9;">
        <span style="color:#64748b;">Payment</span>
        <span style="font-weight:700;color:<?= e($payColors[$payStatus] ?? '#64748b') ?>;"><?= e($payStatus) ?></span>
      </div>
      <div style="display:flex;justify-content:space-between;padding:.35rem 0;">
        <span style="color:#64748b;">Total</span>
        <strong>₱<?= number_format((float)($b['total_amount'] ?? 0), 0) ?></strong>
      </div>
    </div>
  </div>

  <?php if (count($includedRides) > 0): ?>
    <div class="card" style="padding:1.25rem;margin-bottom:1.25rem;">
      <div style="font-weight:700;margin-bottom:.6rem;">Included Rides</div>
      <div style="display:flex;flex-wrap:wrap;gap:.4rem;">
        <?php foreach ($includedRides as $rn): ?>
          <span style="background:#eff6ff;color:#1d4ed8;border-radius:.4rem;padding:.2rem .6rem;font-size:.85rem;"><?= e($rn) ?></span>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endif; ?>

  <?php if (count($individualTickets) > 0): ?>
    <div class="card" style="padding:1.25rem;margin-bottom:1.25rem;">
      <div style="font-weight:700;margin-bottom:.75rem;">Your Individual Tickets</div>
      <div style="display:flex;flex-direction:column;gap:1rem;">
        <?php foreach ($individualTickets as $tk): ?>
          <?php $tkStatus = (string)($tk['status'] ?? 'ACTIVE'); ?>
          <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:.75rem;padding:.75rem;display:flex;align-items:center;gap:1rem;<?= $tkStatus === 'USED' ? 'opacity:.6;' : '' ?>">
            <img
              src="https://api.qrserver.com/v1/create-qr-code/?size=120x120&data=<?= e(urlencode($tk['ticket_number'])) ?>"
              alt="QR <?= e($tk['ticket_number']) ?>"
              style="width:80px;height:80px;border-radius:.4rem;flex-shrink:0;"
            />
            <div>
              <div style="font-size:.75rem;color:#64748b;">Ticket Number</div>
              <div style="font-family:monospace;font-weight:700;color:#0f172a;font-size:.9rem;"><?= e($tk['ticket_number']) ?></div>
              <div style="margin-top:.3rem;">
                <?php if ($tkStatus === 'USED'): ?>
                  <span style="background:#fee2e2;color:#991b1b;border-radius:.3rem;padding:.15rem .5rem;font-size:.75rem;font-weight:700;">USED</span>
                <?php elseif ($tkStatus === 'CANCELLED'): ?>
                  <span style="background:#fee2e2;color:#991b1b;border-radius:.3rem;padding:.15rem .5rem;font-size:.75rem;font-weight:700;">CANCELLED</span>
                <?php else: ?>
                  <span style="background:#dcfce7;color:#166534;border-radius:.3rem;padding:.15rem .5rem;font-size:.75rem;font-weight:700;">ACTIVE</span>
                <?php endif; ?>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endif; ?>

  <a class="btn btn-outline btn-full" href="my-bookings.php" style="text-align:center;display:block;">← Back to My Bookings</a>
</div>
</body>
</html>
