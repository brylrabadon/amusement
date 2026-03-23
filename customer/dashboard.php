<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/auth.php';

$user = require_login(); // customer or admin can view
$flash = flash_get();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Dashboard - AmusePark</title>
  <link rel="stylesheet" href="../css/style.css" />
</head>
<body>
<nav>
  <a class="logo" href="../index.php">Amuse<span>Park</span></a>
  <ul>
    <li><a href="../rides.php">Rides</a></li>
    <li><a href="../tickets.php">Buy Tickets</a></li>
    <li><a href="../my-bookings.php">My Bookings</a></li>
     <li><a href="../profile.php">Profile</a></li>
    <li><a href="../logout.php" style="color:#dc2626;font-weight:600;">Logout</a></li>
  </ul>
</nav>

<div class="page-header">
  <h1>Welcome, <?= e($user['full_name'] ?? '') ?></h1>
  <p>Your AmusePark account</p>
</div>

<div class="container" style="max-width:900px;">
  <?php if ($flash && ($flash['message'] ?? '') !== ''): ?>
    <div class="card" style="padding:1rem;margin-bottom:1rem;border-left:4px solid <?= ($flash['type'] ?? '') === 'error' ? '#dc2626' : '#16a34a' ?>;">
      <strong><?= e(($flash['type'] ?? '') === 'error' ? 'Error' : 'Success') ?>:</strong>
      <?= e($flash['message']) ?>
    </div>
  <?php endif; ?>

  <div class="grid grid-2">
    <div class="card" style="padding:1.25rem;">
      <h3 style="font-weight:800;margin-bottom:.25rem;">Book tickets</h3>
      <p style="color:#64748b;margin-bottom:1rem;">Choose a ticket type and reserve your visit date.</p>
      <a class="btn btn-primary" href="../tickets.php">Buy Tickets</a>
    </div>
    <div class="card" style="padding:1.25rem;">
      <h3 style="font-weight:800;margin-bottom:.25rem;">Explore rides</h3>
      <p style="color:#64748b;margin-bottom:1rem;">See the latest rides and attractions.</p>
      <a class="btn btn-outline" href="../rides.php">View Rides</a>
    </div>
  </div>

  <?php if (($user['role'] ?? '') === 'admin'): ?>
    <div class="card" style="padding:1.25rem;margin-top:1.5rem;background:#eff6ff;">
      <strong>Admin:</strong> <a href="../admin/admin-dashboard.php" style="color:#1d4ed8;font-weight:800;text-decoration:none;">Go to Admin Dashboard</a>
    </div>
  <?php endif; ?>
</div>
</body>
</html>
