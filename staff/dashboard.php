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

// ── Recent bookings pagination ────────────────────────────────
$staffPage    = max(1, (int)($_GET['page'] ?? 1));
$staffPerPage = 7;
$staffTotal   = 0;
$staffBookings = [];
try {
    $staffTotal = (int)$pdo->query("SELECT COUNT(*) AS c FROM bookings")->fetch()['c'];
    $staffTotalPages = max(1, (int)ceil($staffTotal / $staffPerPage));
    $staffPage   = min($staffPage, $staffTotalPages);
    $staffOffset = ($staffPage - 1) * $staffPerPage;
    $st = $pdo->prepare(
        "SELECT booking_reference, customer_name, ticket_type_name, total_amount, payment_status, visit_date, created_at
         FROM bookings ORDER BY created_at DESC LIMIT ? OFFSET ?"
    );
    $st->execute([$staffPerPage, $staffOffset]);
    $staffBookings = $st->fetchAll();
} catch (Throwable $e) { $staffTotalPages = 1; $staffOffset = 0; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Staff Dashboard - AmusePark</title>
  <link rel="stylesheet" href="../css/style.css" />
  <style>
    body { background: var(--bg-light); color: var(--text-dark); font-family: 'Poppins', sans-serif; }
    
    .dash-body { padding: 3rem 2rem 4rem; max-width: 1200px; margin: 0 auto; }

    .dash-hero {
      background: linear-gradient(135deg, #0f172a 0%, #1e3a8a 100%);
      border-radius: 2.5rem;
      padding: 3.5rem;
      color: #fff;
      margin-bottom: 2.5rem;
      position: relative;
      overflow: hidden;
      box-shadow: 0 20px 50px rgba(15, 23, 42, 0.1);
    }
    .dash-hero::before {
      content: '';
      position: absolute; inset: 0;
      background: url('https://www.transparenttextures.com/patterns/cubes.png'); opacity: 0.1;
    }
    .dash-hero h1 { font-size: 2.25rem; font-weight: 800; margin: 0 0 .5rem; letter-spacing: -.02em; position: relative; z-index: 1; }
    .dash-hero p  { font-size: 1rem; color: rgba(255,255,255,0.7); margin: 0; position: relative; z-index: 1; }

    .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 1.5rem; margin-top: -3.5rem; position: relative; z-index: 10; padding: 0 1rem; }
    
    .stat-card {
      background: #fff;
      padding: 2rem;
      border-radius: 2rem;
      box-shadow: 0 10px 25px -5px rgba(0,0,0,0.05);
      border: 1px solid #e2e8f0;
      transition: all .3s cubic-bezier(0.4, 0, 0.2, 1);
    }
    .stat-card:hover { transform: translateY(-8px); box-shadow: 0 20px 40px -10px rgba(0,0,0,0.1); border-color: var(--primary); }
    
    .stat-label { color: var(--text-muted); font-size: .8rem; font-weight: 700; text-transform: uppercase; letter-spacing: .05em; margin-bottom: .75rem; display: flex; align-items: center; gap: .5rem; }
    .stat-value { font-size: 2.5rem; font-weight: 900; color: var(--text-dark); line-height: 1; }

    .action-card {
      background: #fff; border-radius: 2rem; padding: 2.5rem;
      margin-top: 2.5rem; border: 1px solid #e2e8f0;
      box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05);
    }
    .action-card h3 { font-weight: 800; font-size: 1.3rem; margin: 0 0 1.75rem; color: var(--text-dark); display: flex; align-items: center; gap: .6rem; }
    
    .btn-group { display: flex; gap: 1.25rem; flex-wrap: wrap; }
    
    .btn-action {
      display: inline-flex; align-items: center; gap: .75rem;
      padding: 1rem 2rem; border-radius: 999px;
      font-weight: 800; font-size: 1rem; text-decoration: none;
      transition: all .3s;
    }
    .btn-scanner { background: var(--primary); color: #fff; box-shadow: 0 10px 20px rgba(30, 58, 138, 0.2); }
    .btn-scanner:hover { background: var(--primary-dark); transform: translateY(-3px); box-shadow: 0 15px 30px rgba(30, 58, 138, 0.3); }
    
    .btn-bookings { background: transparent; border: 2.5px solid #e2e8f0; color: var(--text-muted); }
    .btn-bookings:hover { border-color: var(--primary); color: var(--primary); background: #f8fafc; transform: translateY(-3px); }

    .alert {
      padding: 1.25rem 1.75rem; border-radius: 1.25rem; margin-bottom: 2rem;
      display: flex; align-items: center; gap: 1rem; font-weight: 700;
    }
    .alert-success { background: #dcfce7; color: #166534; border: 1px solid #86efac; }
    .alert-error   { background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5; }

    /* ── Pagination pill ── */
    .pagination { display:flex; align-items:center; justify-content:space-between; gap:1rem; margin-top:1.5rem; flex-wrap:wrap; }
    .pagination-info { font-size:.85rem; color:#64748b; font-weight:600; }
    .pagination-pages { display:inline-flex; align-items:center; gap:.2rem; background:#f1f5f9; border-radius:999px; padding:.4rem .65rem; box-shadow:0 2px 10px rgba(0,0,0,.07); }
    .pg-btn { display:inline-flex; align-items:center; justify-content:center; width:36px; height:36px; border-radius:50%; font-size:.88rem; font-weight:600; text-decoration:none; border:none; color:#64748b; background:transparent; transition:all .2s; cursor:pointer; line-height:1; }
    .pg-btn:hover { background:#1e3a8a; color:#fff; }
    .pg-btn.active { background:#1e3a8a; color:#fff; box-shadow:0 4px 10px rgba(30,58,138,.3); font-weight:800; }
    .pg-btn.disabled { opacity:.35; pointer-events:none; }
    .pg-btn.pg-dots { cursor:default; width:24px; font-size:.8rem; }
    .pg-btn.pg-dots:hover { background:transparent; color:#64748b; }
  </style>
</head>
<body>

<?php render_nav($user, 'dashboard'); ?>

<div class="dash-body">
  
  <div class="dash-hero">
    <h1>Welcome, <?= e($user['full_name'] ?? 'Staff') ?> 👋</h1>
    <p>Staff command center — validate entry and manage park guests.</p>
  </div>

  <?php if ($flash && ($flash['message'] ?? '') !== ''): ?>
    <div class="alert <?= ($flash['type'] ?? '') === 'error' ? 'alert-error' : 'alert-success' ?>">
      <span style="font-size:1.5rem;"><?= ($flash['type'] ?? '') === 'error' ? '🚫' : '✅' ?></span>
      <div><?= e($flash['message']) ?></div>
    </div>
  <?php endif; ?>

  <div class="stats-grid">
    <div class="stat-card">
      <div class="stat-label">🔍 Scanned Today</div>
      <div class="stat-value"><?= $todayScans ?></div>
    </div>
    <div class="stat-card">
      <div class="stat-label">⏳ Pending</div>
      <div class="stat-value" style="color:var(--secondary-dark);"><?= $pendingCount ?></div>
    </div>
    <div class="stat-card">
      <div class="stat-label">📅 Today's Bookings</div>
      <div class="stat-value"><?= $todayBookings ?></div>
    </div>
  </div>

  <div class="action-card">
    <h3>⚡ Quick Actions</h3>
    <div class="btn-group">
      <a class="btn-action btn-scanner" href="scanner.php">🔍 Open Ticket Scanner</a>
      <a class="btn-action btn-bookings" href="bookings.php">📋 View All Bookings</a>
    </div>
  </div>

  <!-- Recent Bookings -->
  <div class="action-card" style="margin-top:2rem;">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1.5rem;flex-wrap:wrap;gap:.75rem;">
      <h3 style="margin:0;">📋 Recent Bookings</h3>
      <a href="bookings.php" style="font-size:.85rem;font-weight:700;color:#1e3a8a;text-decoration:none;background:#eff6ff;padding:.45rem 1.1rem;border-radius:999px;">View all →</a>
    </div>

    <?php
      $spColors = ['Paid'=>['#dcfce7','#166534'],'Pending'=>['#fef9c3','#854d0e'],'Cancelled'=>['#fee2e2','#991b1b']];
    ?>
    <?php if (count($staffBookings)): ?>
      <div style="overflow-x:auto;">
        <table style="width:100%;border-collapse:collapse;font-size:.88rem;">
          <thead>
            <tr style="border-bottom:2px solid #f1f5f9;">
              <th style="text-align:left;padding:.65rem 1rem;font-size:.75rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#94a3b8;">Reference</th>
              <th style="text-align:left;padding:.65rem 1rem;font-size:.75rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#94a3b8;">Customer</th>
              <th style="text-align:left;padding:.65rem 1rem;font-size:.75rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#94a3b8;">Ticket</th>
              <th style="text-align:left;padding:.65rem 1rem;font-size:.75rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#94a3b8;">Visit Date</th>
              <th style="text-align:left;padding:.65rem 1rem;font-size:.75rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#94a3b8;">Amount</th>
              <th style="text-align:left;padding:.65rem 1rem;font-size:.75rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#94a3b8;">Status</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($staffBookings as $b):
              $ps = (string)($b['payment_status'] ?? '');
              [$bg, $col] = $spColors[$ps] ?? ['#f1f5f9','#475569'];
            ?>
              <tr style="border-bottom:1px solid #f8fafc;transition:background .15s;" onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background=''">
                <td style="padding:.85rem 1rem;font-family:monospace;font-weight:800;color:#1e3a8a;"><?= e($b['booking_reference'] ?? '') ?></td>
                <td style="padding:.85rem 1rem;font-weight:600;max-width:140px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= e($b['customer_name'] ?? '') ?></td>
                <td style="padding:.85rem 1rem;color:#64748b;font-size:.82rem;"><?= e($b['ticket_type_name'] ?? '') ?></td>
                <td style="padding:.85rem 1rem;color:#64748b;"><?= e((string)($b['visit_date'] ?? '')) ?></td>
                <td style="padding:.85rem 1rem;font-weight:700;">₱<?= number_format((float)($b['total_amount'] ?? 0), 0) ?></td>
                <td style="padding:.85rem 1rem;">
                  <span style="background:<?= $bg ?>;color:<?= $col ?>;padding:.2rem .65rem;border-radius:999px;font-size:.72rem;font-weight:700;"><?= e($ps) ?></span>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <?php if ($staffTotalPages > 1): ?>
      <div class="pagination">
        <div class="pagination-info">
          Showing <?= $staffOffset + 1 ?>–<?= min($staffOffset + $staffPerPage, $staffTotal) ?> of <?= $staffTotal ?> bookings
        </div>
        <div class="pagination-pages">
          <a class="pg-btn <?= $staffPage <= 1 ? 'disabled' : '' ?>" href="?page=<?= $staffPage - 1 ?>">&#8592;</a>
          <?php
            $ss = max(1, $staffPage - 2); $se = min($staffTotalPages, $staffPage + 2);
            if ($ss > 1) { echo '<a class="pg-btn" href="?page=1">1</a>'; if ($ss > 2) echo '<span class="pg-btn pg-dots">…</span>'; }
            for ($i = $ss; $i <= $se; $i++): ?>
              <a class="pg-btn <?= $i === $staffPage ? 'active' : '' ?>" href="?page=<?= $i ?>"><?= $i ?></a>
            <?php endfor;
            if ($se < $staffTotalPages) { if ($se < $staffTotalPages - 1) echo '<span class="pg-btn pg-dots">…</span>'; echo '<a class="pg-btn" href="?page=' . $staffTotalPages . '">' . $staffTotalPages . '</a>'; }
          ?>
          <a class="pg-btn <?= $staffPage >= $staffTotalPages ? 'disabled' : '' ?>" href="?page=<?= $staffPage + 1 ?>">&#8594;</a>
        </div>
      </div>
      <?php endif; ?>
    <?php else: ?>
      <div style="text-align:center;padding:2.5rem;color:#94a3b8;font-size:.9rem;">No bookings yet</div>
    <?php endif; ?>
  </div>

</div>

<?php render_footer(); ?>

</body>
</html>
