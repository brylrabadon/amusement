<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/layout.php';

$user = require_admin();
$pdo  = db();

$ridesCount  = (int)$pdo->query('SELECT COUNT(*) AS c FROM rides')->fetch()['c'];
$typesCount  = (int)$pdo->query('SELECT COUNT(*) AS c FROM ticket_types')->fetch()['c'];
$bookingsCount  = 0;
$paidCount      = 0;
$pendingCount   = 0;
$revenue        = 0.0;
$todayBookings  = 0;
$activeUsers    = 0;
$recentBookings = [];
$monthlyRevenue = [];

try {
    $bookingsCount = (int)$pdo->query('SELECT COUNT(*) AS c FROM bookings')->fetch()['c'];
    $paidCount     = (int)$pdo->query("SELECT COUNT(*) AS c FROM bookings WHERE payment_status='Paid'")->fetch()['c'];
    $pendingCount  = (int)$pdo->query("SELECT COUNT(*) AS c FROM bookings WHERE payment_status='Pending'")->fetch()['c'];
    $todayBookings = (int)$pdo->query("SELECT COUNT(*) AS c FROM bookings WHERE DATE(created_at)=CURDATE()")->fetch()['c'];
    $revenue       = (float)$pdo->query("SELECT COALESCE(SUM(total_amount),0) AS s FROM bookings WHERE payment_status='Paid'")->fetch()['s'];
    $activeUsers   = (int)$pdo->query("SELECT COUNT(*) AS c FROM users WHERE is_active=1 AND role='customer'")->fetch()['c'];

    $recentBookings = $pdo->query(
        "SELECT booking_reference, customer_name, ticket_type_name, total_amount, payment_status, created_at
         FROM bookings ORDER BY created_at DESC LIMIT 6"
    )->fetchAll();

    // Last 6 months revenue
    $monthlyRevenue = $pdo->query(
        "SELECT DATE_FORMAT(created_at,'%b') AS mo,
                MONTH(created_at) AS mn,
                YEAR(created_at) AS yr,
                COALESCE(SUM(CASE WHEN payment_status='Paid' THEN total_amount ELSE 0 END),0) AS rev
         FROM bookings
         WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
         GROUP BY yr, mn, mo
         ORDER BY yr ASC, mn ASC"
    )->fetchAll();
} catch (Throwable $e) {}

$flash = flash_get();

// Greeting based on time
$hour = (int)date('G');
$greeting = $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening');

$payColors = [
    'Paid'      => ['bg'=>'#dcfce7','color'=>'#166534'],
    'Pending'   => ['bg'=>'#fef9c3','color'=>'#854d0e'],
    'Cancelled' => ['bg'=>'#fee2e2','color'=>'#991b1b'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Admin Dashboard - AmusePark</title>
  <link rel="stylesheet" href="../css/style.css"/>
  <style>
    /* ── Layout shell ── */
    *, *::before, *::after { box-sizing: border-box; }
    body { background: #f1f5f9; color: #1e293b; margin: 0; font-family: system-ui, -apple-system, sans-serif; }

    /* Push content below the top nav */
    .dash-body { padding: 2rem 2.5rem 3rem; max-width: 1400px; margin: 0 auto; }

    /* ── Hero banner ── */
    .dash-hero {
      background: linear-gradient(135deg, #6366f1 0%, #8b5cf6 45%, #3b82f6 100%);
      border-radius: 1.5rem;
      padding: 2.25rem 2.5rem;
      color: #fff;
      margin-bottom: 1.75rem;
      position: relative;
      overflow: hidden;
    }
    .dash-hero::before {
      content: '';
      position: absolute; inset: 0;
      background: url("data:image/svg+xml,%3Csvg width='60' height='60' viewBox='0 0 60 60' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='none' fill-rule='evenodd'%3E%3Cg fill='%23ffffff' fill-opacity='0.04'%3E%3Ccircle cx='30' cy='30' r='30'/%3E%3C/g%3E%3C/g%3E%3C/svg%3E") repeat;
      pointer-events: none;
    }
    .dash-hero h1 { font-size: 1.9rem; font-weight: 800; margin: 0 0 .3rem; letter-spacing: -.02em; }
    .dash-hero p  { font-size: .95rem; opacity: .8; margin: 0 0 1.75rem; }

    /* Mini stat pills inside hero */
    .hero-stats { display: grid; grid-template-columns: repeat(4, 1fr); gap: 1rem; }
    .hero-stat {
      background: rgba(255,255,255,.15);
      backdrop-filter: blur(8px);
      border: 1px solid rgba(255,255,255,.2);
      border-radius: 1rem;
      padding: 1rem 1.25rem;
    }
    .hero-stat-label { font-size: .72rem; font-weight: 700; text-transform: uppercase; letter-spacing: .06em; opacity: .75; margin-bottom: .4rem; display: flex; align-items: center; gap: .35rem; }
    .hero-stat-value { font-size: 1.6rem; font-weight: 900; line-height: 1; }
    .hero-stat-sub   { font-size: .75rem; opacity: .7; margin-top: .3rem; }

    /* ── Two-column main area ── */
    .dash-grid { display: grid; grid-template-columns: 1fr 380px; gap: 1.5rem; }

    /* ── Cards ── */
    .dash-card {
      background: #fff;
      border-radius: 1.25rem;
      border: 1px solid #e2e8f0;
      padding: 1.5rem 1.75rem;
    }
    .dash-card-header {
      display: flex; justify-content: space-between; align-items: center;
      margin-bottom: 1.25rem;
    }
    .dash-card-title { font-size: 1rem; font-weight: 800; color: #0f172a; margin: 0; }
    .dash-card-sub   { font-size: .78rem; color: #94a3b8; margin-top: .15rem; }
    .dash-view-link  {
      font-size: .8rem; font-weight: 700; color: #6366f1;
      text-decoration: none; display: flex; align-items: center; gap: .25rem;
    }
    .dash-view-link:hover { color: #4f46e5; }

    /* ── Bar chart ── */
    .bar-chart { display: flex; align-items: flex-end; gap: .6rem; height: 120px; }
    .bar-col   { flex: 1; display: flex; flex-direction: column; align-items: center; gap: .4rem; }
    .bar       {
      width: 100%; border-radius: .4rem .4rem 0 0;
      background: linear-gradient(180deg, #818cf8 0%, #6366f1 100%);
      transition: opacity .2s;
      min-height: 4px;
    }
    .bar:hover { opacity: .75; }
    .bar-label { font-size: .7rem; color: #94a3b8; font-weight: 600; }
    .bar-val   { font-size: .68rem; color: #6366f1; font-weight: 700; }

    /* ── Recent bookings table ── */
    .bk-table { width: 100%; border-collapse: collapse; font-size: .85rem; }
    .bk-table th {
      text-align: left; padding: .5rem .75rem;
      font-size: .72rem; font-weight: 700; text-transform: uppercase;
      letter-spacing: .05em; color: #94a3b8;
      border-bottom: 1px solid #f1f5f9;
    }
    .bk-table td { padding: .7rem .75rem; border-bottom: 1px solid #f8fafc; vertical-align: middle; }
    .bk-table tr:last-child td { border-bottom: none; }
    .bk-table tr:hover td { background: #fafbff; }
    .bk-ref { font-family: monospace; font-size: .78rem; color: #6366f1; font-weight: 700; }

    /* ── Right column ── */
    .right-col { display: flex; flex-direction: column; gap: 1.5rem; }

    /* Quick actions */
    .qa-list { display: flex; flex-direction: column; gap: .6rem; }
    .qa-btn {
      display: flex; align-items: center; gap: .85rem;
      padding: .85rem 1rem; border-radius: .85rem;
      text-decoration: none; font-weight: 700; font-size: .88rem;
      border: 1.5px solid #e2e8f0; color: #1e293b;
      background: #fff; transition: all .18s;
    }
    .qa-btn:hover { border-color: #6366f1; color: #6366f1; background: #fafbff; transform: translateX(3px); }
    .qa-icon {
      width: 36px; height: 36px; border-radius: .6rem;
      display: flex; align-items: center; justify-content: center;
      font-size: 1rem; flex-shrink: 0;
    }

    /* Stats row (right col) */
    .mini-stats { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: .75rem; }
    .mini-stat {
      background: #f8fafc; border-radius: .85rem;
      padding: .9rem 1rem; border: 1px solid #f1f5f9;
    }
    .mini-stat-label { font-size: .72rem; font-weight: 700; color: #94a3b8; text-transform: uppercase; letter-spacing: .04em; margin-bottom: .35rem; }
    .mini-stat-value { font-size: 1.4rem; font-weight: 900; color: #0f172a; }

    /* Badge */
    .pay-badge {
      display: inline-block; padding: .2rem .6rem;
      border-radius: 999px; font-size: .72rem; font-weight: 700;
    }

    /* Alert */
    .dash-alert { padding: 1rem 1.25rem; border-radius: .85rem; margin-bottom: 1.5rem; font-weight: 600; font-size: .9rem; }
    .dash-alert-success { background: #f0fdf4; color: #166534; border: 1px solid #bbf7d0; }
    .dash-alert-error   { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }

    @media (max-width: 1024px) {
      .dash-grid { grid-template-columns: 1fr; }
      .hero-stats { grid-template-columns: repeat(2, 1fr); }
    }
    @media (max-width: 600px) {
      .dash-body { padding: 1.25rem 1rem 2rem; }
      .hero-stats { grid-template-columns: 1fr 1fr; }
      .dash-hero { padding: 1.5rem; }
    }
  </style>
</head>
<body>

<?php render_nav($user, 'dashboard'); ?>

<div class="dash-body">

  <?php if ($flash && ($flash['message'] ?? '') !== ''): ?>
    <div class="dash-alert <?= ($flash['type'] ?? '') === 'error' ? 'dash-alert-error' : 'dash-alert-success' ?>">
      <?= ($flash['type'] ?? '') === 'error' ? '⚠ ' : '✅ ' ?><?= e($flash['message']) ?>
    </div>
  <?php endif; ?>

  <!-- ── Hero Banner ── -->
  <div class="dash-hero">
    <h1><?= $greeting ?>, <?= e($user['full_name'] ?? 'Admin') ?> 👋</h1>
    <p>Here's what's happening at AmusePark today.</p>

    <div class="hero-stats">
      <div class="hero-stat">
        <div class="hero-stat-label">💰 Total Revenue</div>
        <div class="hero-stat-value">₱<?= number_format($revenue, 0) ?></div>
        <div class="hero-stat-sub">from paid bookings</div>
      </div>
      <div class="hero-stat">
        <div class="hero-stat-label">👥 Active Users</div>
        <div class="hero-stat-value"><?= $activeUsers ?></div>
        <div class="hero-stat-sub">registered customers</div>
      </div>
      <div class="hero-stat">
        <div class="hero-stat-label">📅 Total Bookings</div>
        <div class="hero-stat-value"><?= $bookingsCount ?></div>
        <div class="hero-stat-sub"><?= $todayBookings ?> new today</div>
      </div>
      <div class="hero-stat">
        <div class="hero-stat-label">✅ Paid</div>
        <div class="hero-stat-value"><?= $paidCount ?></div>
        <div class="hero-stat-sub"><?= $pendingCount ?> still pending</div>
      </div>
    </div>
  </div>

  <!-- ── Main grid ── -->
  <div class="dash-grid">

    <!-- LEFT: chart + recent bookings -->
    <div style="display:flex;flex-direction:column;gap:1.5rem;">

      <!-- Revenue bar chart -->
      <div class="dash-card">
        <div class="dash-card-header">
          <div>
            <div class="dash-card-title">Revenue Growth</div>
            <div class="dash-card-sub">Monthly revenue from paid bookings</div>
          </div>
          <a class="dash-view-link" href="bookings.php">View all →</a>
        </div>

        <?php
          $maxRev = 1;
          foreach ($monthlyRevenue as $m) { if ((float)$m['rev'] > $maxRev) $maxRev = (float)$m['rev']; }
        ?>
        <?php if (count($monthlyRevenue) > 0): ?>
          <div class="bar-chart">
            <?php foreach ($monthlyRevenue as $m):
              $pct = max(4, round(((float)$m['rev'] / $maxRev) * 100));
            ?>
              <div class="bar-col">
                <div class="bar-val">₱<?= number_format((float)$m['rev'] / 1000, 1) ?>k</div>
                <div class="bar" style="height:<?= $pct ?>%;"></div>
                <div class="bar-label"><?= e($m['mo']) ?></div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php else: ?>
          <div style="height:120px;display:flex;align-items:center;justify-content:center;color:#94a3b8;font-size:.9rem;">
            No revenue data yet
          </div>
        <?php endif; ?>
      </div>

      <!-- Recent bookings -->
      <div class="dash-card">
        <div class="dash-card-header">
          <div>
            <div class="dash-card-title">Recent Bookings</div>
            <div class="dash-card-sub">Latest customer transactions</div>
          </div>
          <a class="dash-view-link" href="bookings.php">View all →</a>
        </div>

        <?php if (count($recentBookings) > 0): ?>
          <table class="bk-table">
            <thead>
              <tr>
                <th>Reference</th>
                <th>Customer</th>
                <th>Ticket</th>
                <th>Amount</th>
                <th>Status</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($recentBookings as $b):
                $ps = (string)($b['payment_status'] ?? '');
                $pc = $payColors[$ps] ?? ['bg'=>'#f1f5f9','color'=>'#475569'];
              ?>
                <tr>
                  <td>
                    <a href="booking-detail.php?ref=<?= urlencode((string)($b['booking_reference'] ?? '')) ?>"
                       class="bk-ref" style="text-decoration:none;border-bottom:1px dashed #a5b4fc;"
                       title="View booking details">
                      <?= e($b['booking_reference'] ?? '') ?>
                    </a>
                  </td>
                  <td style="font-weight:600;max-width:140px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= e($b['customer_name'] ?? '') ?></td>
                  <td style="color:#64748b;font-size:.8rem;"><?= e($b['ticket_type_name'] ?? '') ?></td>
                  <td style="font-weight:700;">₱<?= number_format((float)($b['total_amount'] ?? 0), 0) ?></td>
                  <td>
                    <span class="pay-badge" style="background:<?= $pc['bg'] ?>;color:<?= $pc['color'] ?>;">
                      <?= e($ps) ?>
                    </span>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php else: ?>
          <div style="text-align:center;padding:2rem;color:#94a3b8;font-size:.9rem;">No bookings yet</div>
        <?php endif; ?>
      </div>

    </div><!-- /left col -->

    <!-- RIGHT: quick actions + mini stats -->
    <div class="right-col">

      <!-- Mini stats -->
      <div class="dash-card">
        <div class="dash-card-header">
          <div class="dash-card-title">Overview</div>
        </div>
        <div class="mini-stats">
          <div class="mini-stat">
            <div class="mini-stat-label">🎟 Ticket Types</div>
            <div class="mini-stat-value"><?= $typesCount ?></div>
          </div>
          <div class="mini-stat">
            <div class="mini-stat-label">🎢 Rides</div>
            <div class="mini-stat-value"><?= $ridesCount ?></div>
          </div>
          <div class="mini-stat">
            <div class="mini-stat-label">📅 Today</div>
            <div class="mini-stat-value"><?= $todayBookings ?></div>
          </div>
          <div class="mini-stat">
            <div class="mini-stat-label">✅ Paid</div>
            <div class="mini-stat-value" style="color:#16a34a;"><?= $paidCount ?></div>
          </div>
          <div class="mini-stat">
            <div class="mini-stat-label">⏳ Pending</div>
            <div class="mini-stat-value" style="color:#d97706;"><?= $pendingCount ?></div>
          </div>
          <div class="mini-stat">
            <div class="mini-stat-label">👥 Users</div>
            <div class="mini-stat-value"><?= $activeUsers ?></div>
          </div>
        </div>
      </div>

      <!-- Quick actions -->
      <div class="dash-card">
        <div class="dash-card-header">
          <div class="dash-card-title">Quick Actions</div>
        </div>
        <div class="qa-list">
          <a class="qa-btn" href="rides.php">
            <div class="qa-icon" style="background:#ede9fe;">🎢</div>
            Manage Rides
          </a>
          <a class="qa-btn" href="ticket-types.php">
            <div class="qa-icon" style="background:#fef9c3;">🎟</div>
            Ticket Types
          </a>
          <a class="qa-btn" href="bookings.php">
            <div class="qa-icon" style="background:#dcfce7;">📋</div>
            View Bookings
          </a>
          <a class="qa-btn" href="bookings.php?pay=Pending">
            <div class="qa-icon" style="background:#fee2e2;">⏳</div>
            Pending Bookings
            <?php if ($pendingCount > 0): ?>
              <span style="margin-left:auto;background:#ef4444;color:#fff;border-radius:999px;padding:.1rem .55rem;font-size:.72rem;font-weight:800;"><?= $pendingCount ?></span>
            <?php endif; ?>
          </a>
        </div>
      </div>

    </div><!-- /right col -->
  </div><!-- /dash-grid -->

</div><!-- /dash-body -->

</body>
</html>
