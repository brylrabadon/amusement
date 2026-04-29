<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/layout.php';

$user = require_admin();
$pdo  = db();

// ── Chart filter ──────────────────────────────────────────────
$filterPeriod = (string)($_GET['period'] ?? 'last6months');
$validPeriods = ['thismonth','lastmonth','last6months','thisyear','lastyear'];
if (!in_array($filterPeriod, $validPeriods, true)) $filterPeriod = 'last6months';

$chartWhere = '';
$chartLabel = '';
switch ($filterPeriod) {
    case 'thismonth':
        $chartWhere = "WHERE YEAR(created_at)=YEAR(CURDATE()) AND MONTH(created_at)=MONTH(CURDATE())";
        $chartLabel = 'This Month — Daily Revenue';
        break;
    case 'lastmonth':
        $chartWhere = "WHERE YEAR(created_at)=YEAR(DATE_SUB(CURDATE(),INTERVAL 1 MONTH)) AND MONTH(created_at)=MONTH(DATE_SUB(CURDATE(),INTERVAL 1 MONTH))";
        $chartLabel = 'Last Month — Daily Revenue';
        break;
    case 'thisyear':
        $chartWhere = "WHERE YEAR(created_at)=YEAR(CURDATE())";
        $chartLabel = date('Y') . ' — Monthly Revenue';
        break;
    case 'lastyear':
        $chartWhere = "WHERE YEAR(created_at)=YEAR(CURDATE())-1";
        $chartLabel = (string)(date('Y') - 1) . ' — Monthly Revenue';
        break;
    default: // last6months
        $chartWhere = "WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)";
        $chartLabel = 'Last 6 Months — Monthly Revenue';
}

// For daily views (this/last month), group by day; otherwise by month
$isDailyView = in_array($filterPeriod, ['thismonth','lastmonth'], true);
if ($isDailyView) {
    $chartQuery = "SELECT DATE_FORMAT(created_at,'%d') AS mo,
                          DAY(created_at) AS mn,
                          YEAR(created_at) AS yr,
                          COALESCE(SUM(CASE WHEN payment_status='Paid' THEN total_amount ELSE 0 END),0) AS rev,
                          COUNT(*) AS bookings_count
                   FROM bookings {$chartWhere}
                   GROUP BY yr, mn, mo ORDER BY yr ASC, mn ASC";
} else {
    $chartQuery = "SELECT DATE_FORMAT(created_at,'%b') AS mo,
                          MONTH(created_at) AS mn,
                          YEAR(created_at) AS yr,
                          COALESCE(SUM(CASE WHEN payment_status='Paid' THEN total_amount ELSE 0 END),0) AS rev,
                          COUNT(*) AS bookings_count
                   FROM bookings {$chartWhere}
                   GROUP BY yr, mn, mo ORDER BY yr ASC, mn ASC";
}

$ridesCount  = (int)$pdo->query('SELECT COUNT(*) AS c FROM rides')->fetch()['c'];
$typesCount  = (int)$pdo->query('SELECT COUNT(*) AS c FROM ticket_types')->fetch()['c'];
$bookingsCount  = 0;
$paidCount      = 0;
$pendingCount   = 0;
$cancelledCount = 0;
$revenue        = 0.0;
$todayBookings  = 0;
$activeUsers    = 0;
$monthlyRevenue = [];
$topTickets     = [];

try {
    $bookingsCount  = (int)$pdo->query('SELECT COUNT(*) AS c FROM bookings')->fetch()['c'];
    $paidCount      = (int)$pdo->query("SELECT COUNT(*) AS c FROM bookings WHERE payment_status='Paid'")->fetch()['c'];
    $pendingCount   = (int)$pdo->query("SELECT COUNT(*) AS c FROM bookings WHERE payment_status='Pending'")->fetch()['c'];
    $cancelledCount = (int)$pdo->query("SELECT COUNT(*) AS c FROM bookings WHERE payment_status='Cancelled'")->fetch()['c'];
    $todayBookings  = (int)$pdo->query("SELECT COUNT(*) AS c FROM bookings WHERE DATE(created_at)=CURDATE()")->fetch()['c'];
    $revenue        = (float)$pdo->query("SELECT COALESCE(SUM(total_amount),0) AS s FROM bookings WHERE payment_status='Paid'")->fetch()['s'];
    $activeUsers    = (int)$pdo->query("SELECT COUNT(*) AS c FROM users WHERE is_active=1 AND role='customer'")->fetch()['c'];
    $monthlyRevenue = $pdo->query($chartQuery)->fetchAll();

    // Top ticket types by revenue
    $topTickets = $pdo->query(
        "SELECT ticket_type_name, COUNT(*) AS cnt,
                COALESCE(SUM(total_amount),0) AS rev
         FROM bookings WHERE payment_status='Paid'
         GROUP BY ticket_type_name ORDER BY rev DESC LIMIT 5"
    )->fetchAll();
} catch (Throwable $e) {}

$flash = flash_get();

// ── Pagination for recent bookings ────────────────────────────
const PER_PAGE = 7;
$page = max(1, (int)($_GET['page'] ?? 1));
$totalBookingsForPage = 0;
try {
    $totalBookingsForPage = (int)$pdo->query('SELECT COUNT(*) AS c FROM bookings')->fetch()['c'];
} catch (Throwable $e) {}
$totalPages = max(1, (int)ceil($totalBookingsForPage / PER_PAGE));
$page = min($page, $totalPages);
$offset = ($page - 1) * PER_PAGE;

$recentBookings = [];
try {
    $recentBookings = $pdo->prepare(
        "SELECT booking_reference, customer_name, ticket_type_name, total_amount, payment_status, created_at
         FROM bookings ORDER BY created_at DESC LIMIT ? OFFSET ?"
    );
    $recentBookings->execute([PER_PAGE, $offset]);
    $recentBookings = $recentBookings->fetchAll();
} catch (Throwable $e) { $recentBookings = []; }

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
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="../css/style.css"/>
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
  <style>
    *, *::before, *::after { box-sizing: border-box; }
    body { font-family: 'Poppins', sans-serif; }
    body { background: var(--bg-light); color: var(--text-dark); margin: 0; font-family: 'Poppins', sans-serif; }

    /* Push content below the top nav */
    .dash-body { padding: 3rem 2rem 4rem; max-width: 1400px; margin: 0 auto; }

    /* ── Hero banner ── */
    .dash-hero {
      background: linear-gradient(135deg, #0f172a 0%, #1e3a8a 100%);
      border-radius: 2.5rem;
      padding: 3rem;
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
      pointer-events: none;
    }
    .dash-hero h1 { font-size: 2.25rem; font-weight: 800; margin: 0 0 .5rem; letter-spacing: -.02em; }
    .dash-hero p  { font-size: 1rem; color: rgba(255,255,255,0.7); margin: 0 0 2.5rem; }

    /* Mini stat pills inside hero */
    .hero-stats { display: grid; grid-template-columns: repeat(4, 1fr); gap: 1.25rem; }
    .hero-stat {
      background: rgba(255,255,255,.05);
      backdrop-filter: blur(12px);
      border: 1px solid rgba(255,255,255,.1);
      border-radius: 1.5rem;
      padding: 1.5rem;
      transition: transform .3s;
    }
    .hero-stat:hover { transform: translateY(-5px); background: rgba(255,255,255,0.08); }
    .hero-stat-label { font-size: .75rem; font-weight: 700; text-transform: uppercase; letter-spacing: .06em; color: rgba(255,255,255,0.5); margin-bottom: .6rem; display: flex; align-items: center; gap: .35rem; }
    .hero-stat-value { font-size: 1.8rem; font-weight: 900; line-height: 1; color: var(--secondary); }
    .hero-stat-sub   { font-size: .8rem; color: rgba(255,255,255,0.4); margin-top: .5rem; font-weight: 500; }

    /* ── Two-column main area ── */
    .dash-grid { display: grid; grid-template-columns: 1fr 400px; gap: 2rem; }

    /* ── Cards ── */
    .dash-card {
      background: #fff;
      border-radius: 2rem;
      border: 1px solid #e2e8f0;
      padding: 2rem;
      box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05);
    }
    .dash-card-header {
      display: flex; justify-content: space-between; align-items: center;
      margin-bottom: 1.75rem;
    }
    .dash-card-title { font-size: 1.2rem; font-weight: 800; color: var(--text-dark); margin: 0; }
    .dash-card-sub   { font-size: .85rem; color: var(--text-muted); margin-top: .25rem; font-weight: 500; }
    .dash-view-link  {
      font-size: .85rem; font-weight: 700; color: var(--primary);
      text-decoration: none; display: flex; align-items: center; gap: .25rem;
      padding: .5rem 1.25rem; border-radius: 999px; background: #eff6ff;
      transition: all .2s;
    }
    .dash-view-link:hover { background: var(--primary); color: #fff; }

    /* ── Bar chart ── */
    .bar-chart { display: flex; align-items: flex-end; gap: .75rem; height: 150px; margin-top: 1rem; }
    .bar-col   { flex: 1; display: flex; flex-direction: column; align-items: center; gap: .5rem; }
    .bar       {
      width: 100%; border-radius: .5rem .5rem 0 0;
      background: linear-gradient(180deg, var(--primary) 0%, var(--primary-dark) 100%);
      transition: all .3s;
      min-height: 6px;
    }
    .bar:hover { opacity: .8; transform: scaleX(1.05); }
    .bar-label { font-size: .75rem; color: var(--text-muted); font-weight: 600; }
    .bar-val   { font-size: .75rem; color: var(--primary); font-weight: 800; margin-bottom: .2rem; }

    /* ── Recent bookings table ── */
    .bk-table { width: 100%; border-collapse: collapse; font-size: .9rem; }
    .bk-table th {
      text-align: left; padding: .75rem 1rem;
      font-size: .75rem; font-weight: 700; text-transform: uppercase;
      letter-spacing: .05em; color: var(--text-muted);
      border-bottom: 2px solid #f1f5f9;
    }
    .bk-table td { padding: 1rem; border-bottom: 1px solid #f8fafc; vertical-align: middle; }
    .bk-table tr:last-child td { border-bottom: none; }
    .bk-table tr:hover td { background: #f8fafc; }
    .bk-ref { font-family: monospace; font-size: .85rem; color: var(--primary); font-weight: 800; }

    /* ── Right column ── */
    .right-col { display: flex; flex-direction: column; gap: 2rem; }

    /* Quick actions */
    .qa-list { display: flex; flex-direction: column; gap: .75rem; }
    .qa-btn {
      display: flex; align-items: center; gap: 1rem;
      padding: 1rem 1.25rem; border-radius: 1.25rem;
      text-decoration: none; font-weight: 700; font-size: .95rem;
      border: 1.5px solid #e2e8f0; color: var(--text-dark);
      background: #fff; transition: all .2s;
    }
    .qa-btn:hover { border-color: var(--primary); color: var(--primary); background: #f8fafc; transform: translateX(5px); }
    .qa-icon {
      width: 42px; height: 42px; border-radius: 12px;
      display: flex; align-items: center; justify-content: center;
      font-size: 1.2rem; flex-shrink: 0;
    }

    /* Stats row (right col) */
    .mini-stats { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
    .mini-stat {
      background: var(--bg-light); border-radius: 1.25rem;
      padding: 1.25rem; border: 1px solid #e2e8f0;
    }
    .mini-stat-label { font-size: .7rem; font-weight: 700; color: var(--text-muted); text-transform: uppercase; letter-spacing: .04em; margin-bottom: .5rem; }
    .mini-stat-value { font-size: 1.6rem; font-weight: 900; color: var(--text-dark); line-height: 1; }

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

      <!-- Revenue chart with filters -->
      <div class="dash-card">
        <div class="dash-card-header" style="flex-wrap:wrap;gap:.75rem;">
          <div>
            <div class="dash-card-title">📊 Revenue &amp; Bookings</div>
            <div class="dash-card-sub"><?= e($chartLabel) ?></div>
          </div>
          <div style="display:flex;gap:.4rem;flex-wrap:wrap;">
            <?php
              $periods = ['thismonth'=>'This Month','lastmonth'=>'Last Month','last6months'=>'Last 6 Months','thisyear'=>(string)date('Y'),'lastyear'=>(string)(date('Y')-1)];
              foreach ($periods as $pk => $pl):
                $isActive = $filterPeriod === $pk;
            ?>
              <a href="?period=<?= $pk ?>" style="padding:.35rem .85rem;border-radius:999px;font-size:.75rem;font-weight:700;text-decoration:none;background:<?= $isActive?'#1e3a8a':'#f1f5f9' ?>;color:<?= $isActive?'#fff':'#64748b' ?>;border:1.5px solid <?= $isActive?'#1e3a8a':'#e2e8f0' ?>;"><?= e($pl) ?></a>
            <?php endforeach; ?>
          </div>
        </div>

        <?php if (count($monthlyRevenue) > 0): ?>
          <?php
            $chartTotalRev = array_sum(array_column($monthlyRevenue, 'rev'));
            $chartTotalBk  = array_sum(array_column($monthlyRevenue, 'bookings_count'));
          ?>
          <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:.75rem;margin-bottom:1.25rem;">
            <div style="background:#eff6ff;border-radius:.85rem;padding:.85rem 1rem;text-align:center;">
              <div style="font-size:.7rem;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.05em;margin-bottom:.25rem;">Revenue</div>
              <div style="font-size:1.2rem;font-weight:900;color:#1e3a8a;">₱<?= number_format($chartTotalRev, 0) ?></div>
            </div>
            <div style="background:#f0fdf4;border-radius:.85rem;padding:.85rem 1rem;text-align:center;">
              <div style="font-size:.7rem;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.05em;margin-bottom:.25rem;">Bookings</div>
              <div style="font-size:1.2rem;font-weight:900;color:#16a34a;"><?= $chartTotalBk ?></div>
            </div>
            <div style="background:#fefce8;border-radius:.85rem;padding:.85rem 1rem;text-align:center;">
              <div style="font-size:.7rem;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.05em;margin-bottom:.25rem;">Avg/Booking</div>
              <div style="font-size:1.2rem;font-weight:900;color:#d97706;">₱<?= $chartTotalBk > 0 ? number_format($chartTotalRev / $chartTotalBk, 0) : '0' ?></div>
            </div>
          </div>
          <div style="position:relative;height:220px;">
            <canvas id="revenueChart"></canvas>
          </div>
          <script>
          (function() {
            var labels  = <?= json_encode(array_column($monthlyRevenue, 'mo')) ?>;
            var revData = <?= json_encode(array_map(function($m) { return (float)$m['rev']; }, $monthlyRevenue)) ?>;
            var bkData  = <?= json_encode(array_map(function($m) { return (int)($m['bookings_count'] ?? 0); }, $monthlyRevenue)) ?>;
            var ctx = document.getElementById('revenueChart').getContext('2d');
            new Chart(ctx, {
              type: 'bar',
              data: {
                labels: labels,
                datasets: [
                  { label: 'Revenue (₱)', data: revData, backgroundColor: 'rgba(30,58,138,0.85)', borderRadius: 6, borderSkipped: false, yAxisID: 'y' },
                  { label: 'Bookings', data: bkData, type: 'line', borderColor: '#fbbf24', backgroundColor: 'rgba(251,191,36,0.12)', borderWidth: 2.5, pointBackgroundColor: '#fbbf24', pointRadius: 4, tension: 0.4, fill: true, yAxisID: 'y2' }
                ]
              },
              options: {
                responsive: true, maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                  legend: { position: 'top', labels: { font: { family: 'Poppins', size: 11 }, usePointStyle: true } },
                  tooltip: { callbacks: { label: function(c) { return c.datasetIndex===0 ? ' ₱'+c.parsed.y.toLocaleString() : ' '+c.parsed.y+' bookings'; } } }
                },
                scales: {
                  x: { grid: { display: false }, ticks: { font: { family: 'Poppins', size: 11 } } },
                  y: { position: 'left', grid: { color: 'rgba(0,0,0,.05)' }, ticks: { font: { family: 'Poppins', size: 10 }, callback: function(v) { return '₱'+(v>=1000?(v/1000).toFixed(1)+'k':v); } } },
                  y2: { position: 'right', grid: { display: false }, ticks: { font: { family: 'Poppins', size: 10 }, stepSize: 1 } }
                }
              }
            });
          })();
          </script>
          <?php if (count($topTickets) > 0): ?>
          <div style="margin-top:1.5rem;border-top:1px solid #f1f5f9;padding-top:1.25rem;">
            <div style="font-size:.8rem;font-weight:800;color:#64748b;text-transform:uppercase;letter-spacing:.06em;margin-bottom:.85rem;">Top Ticket Types by Revenue</div>
            <?php $topMax = max(array_column($topTickets,'rev')) ?: 1; $colors=['#1e3a8a','#3b82f6','#fbbf24','#10b981','#f43f5e']; ?>
            <?php foreach ($topTickets as $i => $tt): ?>
              <div style="margin-bottom:.6rem;">
                <div style="display:flex;justify-content:space-between;font-size:.82rem;font-weight:600;margin-bottom:.25rem;">
                  <span style="color:#0f172a;"><?= e($tt['ticket_type_name'] ?? 'Unknown') ?></span>
                  <span style="color:#64748b;">₱<?= number_format((float)$tt['rev'],0) ?> · <?= (int)$tt['cnt'] ?> bookings</span>
                </div>
                <div style="background:#f1f5f9;border-radius:999px;height:8px;overflow:hidden;">
                  <div style="height:100%;border-radius:999px;background:<?= $colors[$i%5] ?>;width:<?= round(((float)$tt['rev']/$topMax)*100) ?>%;"></div>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>
        <?php else: ?>
          <div style="height:180px;display:flex;flex-direction:column;align-items:center;justify-content:center;color:#94a3b8;gap:.5rem;">
            <div style="font-size:2.5rem;">📊</div><div style="font-size:.9rem;">No data for this period</div>
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
                    <a href="../booking-detail.php?ref=<?= urlencode((string)($b['booking_reference'] ?? '')) ?>"
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

        <?php if ($totalPages > 1): ?>
        <div class="pagination">
          <div class="pagination-info">
            Showing <?= $offset + 1 ?>–<?= min($offset + PER_PAGE, $totalBookingsForPage) ?> of <?= $totalBookingsForPage ?> bookings
          </div>
          <div class="pagination-pages">
            <a class="pg-btn <?= $page <= 1 ? 'disabled' : '' ?>" href="?page=<?= $page - 1 ?>">&#8592;</a>
            <?php
              $start = max(1, $page - 2);
              $end   = min($totalPages, $page + 2);
              if ($start > 1) { echo '<a class="pg-btn" href="?page=1">1</a>'; if ($start > 2) echo '<span class="pg-btn pg-dots">…</span>'; }
              for ($i = $start; $i <= $end; $i++):
            ?>
              <a class="pg-btn <?= $i === $page ? 'active' : '' ?>" href="?page=<?= $i ?>"><?= $i ?></a>
            <?php endfor;
              if ($end < $totalPages) { if ($end < $totalPages - 1) echo '<span class="pg-btn pg-dots">…</span>'; echo '<a class="pg-btn" href="?page=' . $totalPages . '">' . $totalPages . '</a>'; }
            ?>
            <a class="pg-btn <?= $page >= $totalPages ? 'disabled' : '' ?>" href="?page=<?= $page + 1 ?>">&#8594;</a>
          </div>
        </div>
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
