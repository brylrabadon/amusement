<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/layout.php';

$user = require_staff();
$pdo  = db();

// Stats
$todayScans   = 0;
$pendingCount = 0;
$todayBookings = 0;

try {
    $todayScans = (int)$pdo->query(
        "SELECT COUNT(*) AS c FROM tickets WHERE DATE(scanned_at) = CURDATE()"
    )->fetch()['c'];

    $pendingCount = (int)$pdo->query(
        "SELECT COUNT(*) AS c FROM bookings WHERE payment_status = 'Pending'"
    )->fetch()['c'];

    $todayBookings = (int)$pdo->query(
        "SELECT COUNT(*) AS c FROM bookings WHERE DATE(created_at) = CURDATE()"
    )->fetch()['c'];
} catch (Throwable $e) {
    // silently continue
}

$flash = flash_get();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Staff Dashboard - AmusePark</title>
  <link rel="stylesheet" href="../css/style.css" />
  <style>
    body { background: #f8fafc; color: #1e293b; }
    .page-header {
      background-image: linear-gradient(rgba(0,0,0,0.35), rgba(0,0,0,0.35)),
                        url('https://images.unsplash.com/photo-1513889961551-628c1e5e2ee9?q=80&w=2070');
      background-size: cover; background-position: center;
      padding: 5rem 2rem; color: white;
      border-radius: 0 0 2.5rem 2.5rem; margin-bottom: -4rem; text-align: left;
    }
    .page-header h1 { font-size: 2.5rem; font-weight: 800; margin: 0; text-shadow: 2px 2px 8px rgba(0,0,0,.5); }
    .page-header p  { font-size: 1.1rem; margin-top: .75rem; font-weight: 500; text-shadow: 1px 1px 4px rgba(0,0,0,.5); }
    .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 1.5rem; position: relative; z-index: 10; }
    .stat-card { background: #fff; padding: 1.75rem; border-radius: 1.25rem; box-shadow: 0 10px 25px -5px rgba(0,0,0,.05); border: 1px solid #f1f5f9; transition: transform .3s; }
    .stat-card:hover { transform: translateY(-6px); }
    .stat-label { color: #64748b; font-size: .85rem; font-weight: 700; text-transform: uppercase; margin-bottom: .75rem; }
    .stat-value { font-size: 2.25rem; font-weight: 900; color: #0f172a; }
    .quick-card { background: #fff; padding: 2.5rem; border-radius: 1.25rem; margin-top: 2.5rem; border: 1px solid #e2e8f0; }
    .quick-card h3 { font-weight: 800; font-size: 1.25rem; margin: 0 0 1.5rem; color: #0f172a; }
    .btn { padding: .8rem 1.75rem; border-radius: .75rem; font-weight: 700; text-decoration: none; display: inline-flex; align-items: center; gap: .5rem; transition: all .2s; }
    .btn-primary { background: #7c3aed; color: #fff; border: none; }
    .btn-primary:hover { background: #6d28d9; }
    .btn-outline { background: #fff; border: 2px solid #e2e8f0; color: #475569; }
    .btn-outline:hover { border-color: #7c3aed; color: #7c3aed; }
    .alert { padding: 1.25rem; border-radius: 1rem; margin-bottom: 2rem; display: flex; gap: .75rem; }
    .alert-success { background: #f0fdf4; color: #166534; border: 1px solid #bbf7d0; }
    .alert-error   { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }
  </style>
</head>
<body>

<?php render_nav($user, 'dashboard'); ?>

<div class="page-header">
  <div class="container">
    <h1>Welcome, <?= e($user['full_name'] ?? 'Staff') ?> 👋</h1>
    <p>Staff dashboard — validate tickets and monitor today's activity.</p>
  </div>
</div>

<div class="container">
  <?php if ($flash && ($flash['message'] ?? '') !== ''): ?>
    <div class="alert <?= ($flash['type'] ?? '') === 'error' ? 'alert-error' : 'alert-success' ?>">
      <span><?= ($flash['type'] ?? '') === 'error' ? '🚫' : '✅' ?></span>
      <div>
        <strong><?= ($flash['type'] ?? '') === 'error' ? 'Error' : 'Success' ?>:</strong>
        <?= e($flash['message']) ?>
      </div>
    </div>
  <?php endif; ?>

  <div class="stats-grid">
    <div class="stat-card">
      <div class="stat-label">🔍 Tickets Scanned Today</div>
      <div class="stat-value"><?= $todayScans ?></div>
    </div>
    <div class="stat-card">
      <div class="stat-label">⏳ Pending Bookings</div>
      <div class="stat-value" style="color:#d97706;"><?= $pendingCount ?></div>
    </div>
    <div class="stat-card">
      <div class="stat-label">📅 Bookings Today</div>
      <div class="stat-value"><?= $todayBookings ?></div>
    </div>
  </div>

  <div class="quick-card">
    <h3>Quick Actions</h3>
    <div style="display:flex;gap:1rem;flex-wrap:wrap;">
      <a class="btn btn-primary" href="scanner.php">🔍 Open Scanner</a>
      <a class="btn btn-outline" href="bookings.php">📋 View Bookings</a>
    </div>
  </div>
</div>

</body>
</html>
