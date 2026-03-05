<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/auth.php';

$user = require_admin();

$pdo = db();
$ridesCount = (int)$pdo->query('SELECT COUNT(*) AS c FROM rides')->fetch()['c'];
$typesCount = (int)$pdo->query('SELECT COUNT(*) AS c FROM ticket_types')->fetch()['c'];
$bookingsCount = 0;
$paidCount = 0;
$revenue = 0.0;

try {
    $bookingsCount = (int)$pdo->query('SELECT COUNT(*) AS c FROM bookings')->fetch()['c'];
    $paidCount = (int)$pdo->query("SELECT COUNT(*) AS c FROM bookings WHERE payment_status = 'Paid'")->fetch()['c'];
    $revenueRow = $pdo->query("SELECT COALESCE(SUM(total_amount),0) AS s FROM bookings WHERE payment_status = 'Paid'")->fetch();
    $revenue = (float)($revenueRow['s'] ?? 0);
} catch (Throwable $e) {
    // bookings table might not exist yet in some setups; keep dashboard usable.
}

$flash = flash_get();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" /><meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Admin Dashboard - AmusePark</title>
  <link rel="stylesheet" href="../css/style.css" />
</head>
<body>
<nav class="admin-nav">
  <a class="logo" href="../index.php">Amuse<span>Park</span></a>
  <ul>
    <li><a href="admin-dashboard.php" class="active">Dashboard</a></li>
    <li><a href="rides.php">Rides</a></li>
    <li><a href="bookings.php">Bookings</a></li>
    <li><a href="ticket-types.php">Ticket Types</a></li>
    <li><a href="../logout.php" style="color:#dc2626;font-weight:600;">Logout</a></li>
  </ul>
</nav>

<div class="page-header">
  <h1 id="admin-welcome">Admin Dashboard — <?= e($user['full_name'] ?? 'Admin') ?></h1>
  <p>AmusePark Management</p>
</div>

<div class="container">
  <?php if ($flash && ($flash['message'] ?? '') !== ''): ?>
    <div class="card" style="padding:1rem;margin-bottom:1rem;border-left:4px solid <?= ($flash['type'] ?? '') === 'error' ? '#dc2626' : '#16a34a' ?>;">
      <strong><?= e(($flash['type'] ?? '') === 'error' ? 'Error' : 'Success') ?>:</strong>
      <?= e($flash['message']) ?>
    </div>
  <?php endif; ?>

  <div class="stats-grid" id="stats">
    <div class="stat-card"><div class="stat-label">Total Rides</div><div class="stat-value"><?= $ridesCount ?></div></div>
    <div class="stat-card"><div class="stat-label">Ticket Types</div><div class="stat-value"><?= $typesCount ?></div></div>
    <div class="stat-card"><div class="stat-label">Total Bookings</div><div class="stat-value"><?= $bookingsCount ?></div></div>
    <div class="stat-card"><div class="stat-label">Total Revenue</div><div class="stat-value" style="color:#1d4ed8;">₱<?= number_format($revenue, 0) ?></div></div>
  </div>

  <div class="card" style="padding:1.25rem;margin-top:1.5rem;">
    <h3 style="font-weight:800;margin-bottom:.5rem;">Quick actions</h3>
    <div style="display:flex;gap:.75rem;flex-wrap:wrap;">
      <a class="btn btn-primary" href="rides.php">Manage Rides</a>
      <a class="btn btn-outline" href="ticket-types.php">Manage Ticket Types</a>
    </div>
  </div>
</div>
</body>
</html>

