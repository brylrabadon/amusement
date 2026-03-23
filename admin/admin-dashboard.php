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
    // Keep dashboard usable if table doesn't exist
}

$flash = flash_get();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Admin Dashboard - AmusePark</title>
  <link rel="stylesheet" href="../css/style.css" />
  <style>
    body { 
        background-color: #f8fafc; 
        color: #1e293b;
    }
    
    .page-header { 
        /* REMOVED BLUE OVERLAY: Now using a subtle dark shadow for text contrast only */
        background-image: linear-gradient(rgba(0,0,0,0.3), rgba(0,0,0,0.3)), url('https://images.unsplash.com/photo-1513889961551-628c1e5e2ee9?q=80&w=2070');
        background-size: cover;
        background-position: center;
        background-repeat: no-repeat;
        
        padding: 5rem 2rem;
        color: white; 
        border-radius: 0 0 2.5rem 2.5rem;
        margin-bottom: -4rem;
        text-align: left;
    }

    .page-header h1 {
        font-size: 2.5rem;
        font-weight: 800;
        margin: 0;
        letter-spacing: -0.025em;
        text-shadow: 2px 2px 8px rgba(0,0,0,0.5); /* Stronger shadow for readability without blue tint */
    }

    .page-header p {
        opacity: 1;
        font-size: 1.2rem;
        margin-top: 0.75rem;
        font-weight: 500;
        text-shadow: 1px 1px 4px rgba(0,0,0,0.5);
    }

    .stats-grid { 
        display: grid; 
        grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); 
        gap: 1.5rem; 
        position: relative; 
        z-index: 10;
    }

    .stat-card {
        background: white;
        padding: 1.75rem;
        border-radius: 1.25rem;
        box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.05);
        border: 1px solid rgba(241, 245, 249, 0.8);
        transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    }

    .stat-card:hover { transform: translateY(-8px); }

    .stat-label { 
        color: #64748b; 
        font-size: 0.85rem; 
        font-weight: 700; 
        text-transform: uppercase; 
        margin-bottom: 0.75rem;
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }

    .stat-value { 
        font-size: 2.25rem; 
        font-weight: 900; 
        color: #0f172a; 
    }

    .quick-action-card {
        background: white;
        padding: 2.5rem;
        border-radius: 1.25rem;
        margin-top: 2.5rem;
        border: 1px solid #e2e8f0;
    }

    .btn { 
        padding: 0.8rem 1.75rem; 
        border-radius: 0.75rem; 
        font-weight: 700; 
        text-decoration: none; 
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        transition: all 0.2s ease; 
    }

    .btn-primary { background: #1d4ed8; color: white; border: none; }
    .btn-outline { background: white; border: 2px solid #e2e8f0; color: #475569; }

    .alert {
        padding: 1.25rem;
        border-radius: 1rem;
        margin-bottom: 2rem;
        display: flex;
        gap: 0.75rem;
    }
    .alert-success { background: #f0fdf4; color: #166534; border: 1px solid #bbf7d0; }
    .alert-error { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }
  </style>
</head>
<body>

<nav class="admin-nav">
  <a class="logo" href="../index.php">Amuse<span>Park</span></a>
  <ul>
    <li><a href="admin-dashboard.php" class="active">Dashboard</a></li>
    <li><a href="rides.php">Rides</a></li>
    <li><a href="bookings.php">Bookings</a></li>
    <li><a href="ticket-types.php">Ticket Types</a></li>
    <li><a href="scanner.php">Scanner</a></li>
    <li><a href="../profile.php">Profile</a></li>
    <li><a href="../logout.php" style="color:#dc2626;font-weight:600;">Logout</a></li>
  </ul>
</nav>

<div class="page-header">
  <div class="container">
    <h1 id="admin-welcome">Welcome back, <?= e($user['full_name'] ?? 'Admin') ?> 👋</h1>
    <p>System overview and management for AmusePark.</p>
  </div>
</div>

<div class="container">
  <?php if ($flash && ($flash['message'] ?? '') !== ''): ?>
    <div class="alert <?= ($flash['type'] ?? '') === 'error' ? 'alert-error' : 'alert-success' ?>">
      <span><?= ($flash['type'] ?? '') === 'error' ? '🚫' : '✅' ?></span>
      <div>
        <strong><?= e(($flash['type'] ?? '') === 'error' ? 'Error' : 'Success') ?>:</strong>
        <?= e($flash['message']) ?>
      </div>
    </div>
  <?php endif; ?>

  <div class="stats-grid" id="stats">
    <div class="stat-card">
        <div class="stat-label"><span>🎢</span> Total Rides</div>
        <div class="stat-value"><?= $ridesCount ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label"><span>🎟️</span> Ticket Types</div>
        <div class="stat-value"><?= $typesCount ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label"><span>📅</span> Total Bookings</div>
        <div class="stat-value"><?= $bookingsCount ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label"><span>💰</span> Total Revenue</div>
        <div class="stat-value" style="color:#1d4ed8;">₱<?= number_format($revenue, 0) ?></div>
    </div>
  </div>

  <div class="quick-action-card">
    <h3 style="font-weight:800; font-size: 1.25rem; margin: 0; color: #0f172a;">Quick Actions</h3>
    <div style="display: flex; gap: 1rem; flex-wrap: wrap; margin-top: 1.5rem;">
      <a class="btn btn-primary" href="rides.php">Manage Rides</a>
      <a class="btn btn-outline" href="ticket-types.php">Manage Ticket Types</a>
      <a class="btn btn-outline" href="bookings.php">View Bookings</a>
      <a class="btn btn-outline" href="scanner.php">🔍 Scanner</a>
    </div>
  </div>
</div>

</body>
</html>