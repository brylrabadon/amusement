<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/layout.php';

$user = require_staff();
$pdo  = db();

$q   = trim((string)($_GET['q']   ?? ''));
$pay = trim((string)($_GET['pay'] ?? ''));
$date = trim((string)($_GET['date'] ?? ''));

$where  = [];
$params = [];

if ($q !== '') {
    $where[]  = '(booking_reference LIKE ? OR customer_name LIKE ? OR customer_email LIKE ?)';
    $params[] = '%' . $q . '%';
    $params[] = '%' . $q . '%';
    $params[] = '%' . $q . '%';
}
if ($pay !== '') {
    $where[]  = 'payment_status = ?';
    $params[] = $pay;
}
if ($date !== '') {
    $where[]  = 'visit_date = ?';
    $params[] = $date;
}

$sql = 'SELECT * FROM bookings';
if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
$sql .= ' ORDER BY created_at DESC';

$st = $pdo->prepare($sql);
$st->execute($params);
$bookings = $st->fetchAll();

$flash = flash_get();
$payColors = [
    'Paid'      => 'badge-green',
    'Pending'   => 'badge-yellow',
    'Cancelled' => 'badge-red',
    'Refunded'  => 'badge-blue',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Bookings - AmusePark Staff</title>
  <link rel="stylesheet" href="../css/style.css" />
  <style>
    body { background: #f8fafc; color: #1e293b; }

    .bk-header {
      background: linear-gradient(135deg, #0f766e 0%, #14b8a6 100%);
      padding: 3rem 2rem 2rem;
      color: #fff;
    }
    .bk-header h1 { font-size: 2rem; font-weight: 900; margin: 0 0 .35rem; }
    .bk-header p  { font-size: .95rem; opacity: .85; margin: 0; }

    .bk-wrap { max-width: 1200px; margin: 0 auto; padding: 2rem 1.5rem; }

    .filter-bar {
      display: flex; flex-wrap: wrap; gap: .75rem;
      background: #fff; border: 1px solid #e2e8f0;
      border-radius: 1rem; padding: 1.25rem 1.5rem;
      margin-bottom: 1.5rem;
    }
    .filter-bar input,
    .filter-bar select {
      background: #f8fafc; border: 1.5px solid #e2e8f0; color: #1e293b;
      border-radius: .6rem; padding: .55rem .9rem; font-size: .9rem;
    }
    .filter-bar input:focus,
    .filter-bar select:focus { border-color: #14b8a6; outline: none; }
    .filter-bar input[type=text] { flex: 1; min-width: 200px; }
    .filter-btn {
      padding: .55rem 1.25rem; border-radius: .6rem; font-weight: 700;
      font-size: .9rem; cursor: pointer; border: none;
    }
    .filter-btn-primary { background: #0f766e; color: #fff; }
    .filter-btn-primary:hover { background: #0d6460; }
    .filter-btn-reset { background: #f1f5f9; color: #475569; border: 1.5px solid #e2e8f0; }
    .filter-btn-reset:hover { border-color: #14b8a6; color: #0f766e; }

    .bk-table-wrap {
      background: #fff; border-radius: 1rem;
      border: 1px solid #e2e8f0;
      overflow-x: auto;
      box-shadow: 0 1px 6px rgba(0,0,0,.04);
    }
    table { width: 100%; border-collapse: collapse; }
    thead th {
      background: #f8fafc; padding: .85rem 1rem;
      font-size: .78rem; font-weight: 700; text-transform: uppercase;
      letter-spacing: .05em; color: #64748b;
      border-bottom: 1px solid #e2e8f0; text-align: left;
    }
    tbody tr { border-bottom: 1px solid #f1f5f9; transition: background .15s; }
    tbody tr:last-child { border-bottom: none; }
    tbody tr:hover { background: #f8fafc; }
    tbody td { padding: .85rem 1rem; font-size: .9rem; vertical-align: middle; }

    .ref-code {
      font-family: monospace; font-weight: 700;
      background: #f0fdfa; color: #0f766e;
      padding: .2rem .5rem; border-radius: .35rem;
      font-size: .85rem;
    }
    .badge { display:inline-block; padding:.25rem .7rem; border-radius:999px; font-size:.75rem; font-weight:700; }
    .badge-green  { background:#dcfce7; color:#166534; }
    .badge-yellow { background:#fef9c3; color:#854d0e; }
    .badge-red    { background:#fee2e2; color:#991b1b; }
    .badge-blue   { background:#dbeafe; color:#1e40af; }
    .badge-gray   { background:#f1f5f9; color:#475569; }

    .tickets-row { display:none; }
    .tickets-row td {
      background: #f0fdfa; padding: 1rem 1.5rem;
      border-top: 1px solid #ccfbf1;
    }
    .ticket-chip {
      display:inline-block; font-family:monospace; font-size:.8rem;
      background:#fff; color:#0f766e; border:1px solid #99f6e4;
      padding:.2rem .55rem; border-radius:.35rem; margin:.2rem;
    }

    .summary-bar {
      display: flex; gap: 1rem; flex-wrap: wrap;
      margin-bottom: 1.5rem;
    }
    .summary-pill {
      background: #fff; border: 1px solid #e2e8f0;
      border-radius: .75rem; padding: .65rem 1.25rem;
      font-size: .85rem; font-weight: 700; color: #475569;
    }
    .summary-pill span { font-size: 1.1rem; font-weight: 900; color: #0f766e; margin-left: .35rem; }

    .alert { padding: 1rem 1.25rem; border-radius: .75rem; margin-bottom: 1.5rem; font-weight: 600; }
    .alert-success { background: #f0fdf4; color: #166534; border: 1px solid #bbf7d0; }
    .alert-error   { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }

    @media (max-width: 640px) {
      .bk-header { padding: 2rem 1.25rem 1.5rem; }
      .bk-wrap { padding: 1.25rem 1rem; }
    }
  </style>
</head>
<body>

<?php render_nav($user, 'bookings'); ?>

<div class="bk-header">
  <div class="bk-wrap" style="padding-top:0;padding-bottom:0;">
    <h1>📋 Bookings</h1>
    <p>View all customer bookings and ticket details</p>
  </div>
</div>

<div class="bk-wrap">

  <?php if ($flash && ($flash['message'] ?? '') !== ''): ?>
    <div class="alert <?= ($flash['type'] ?? '') === 'error' ? 'alert-error' : 'alert-success' ?>">
      <?= ($flash['type'] ?? '') === 'error' ? '⚠ ' : '✅ ' ?><?= e($flash['message']) ?>
    </div>
  <?php endif; ?>

  <!-- Summary pills -->
  <?php
    $totalCount  = count($bookings);
    $paidCount   = count(array_filter($bookings, fn($b) => ($b['payment_status'] ?? '') === 'Paid'));
    $pendCount   = count(array_filter($bookings, fn($b) => ($b['payment_status'] ?? '') === 'Pending'));
    $totalRev    = array_sum(array_map(fn($b) => ($b['payment_status'] ?? '') === 'Paid' ? (float)($b['total_amount'] ?? 0) : 0, $bookings));
  ?>
  <div class="summary-bar">
    <div class="summary-pill">Total <span><?= $totalCount ?></span></div>
    <div class="summary-pill">Paid <span style="color:#16a34a;"><?= $paidCount ?></span></div>
    <div class="summary-pill">Pending <span style="color:#d97706;"><?= $pendCount ?></span></div>
    <div class="summary-pill">Revenue <span>₱<?= number_format($totalRev, 0) ?></span></div>
  </div>

  <!-- Filters -->
  <form class="filter-bar" method="get">
    <input type="text" name="q" value="<?= e($q) ?>" placeholder="🔍 Search reference, name, email…" />
    <select name="pay">
      <option value="">All Payments</option>
      <?php foreach (['Pending','Paid','Cancelled','Refunded'] as $p): ?>
        <option value="<?= e($p) ?>" <?= $pay === $p ? 'selected' : '' ?>><?= e($p) ?></option>
      <?php endforeach; ?>
    </select>
    <input type="date" name="date" value="<?= e($date) ?>" title="Filter by visit date" />
    <button class="filter-btn filter-btn-primary" type="submit">Filter</button>
    <a class="filter-btn filter-btn-reset" href="bookings.php">Reset</a>
  </form>

  <!-- Table -->
  <div class="bk-table-wrap">
    <table>
      <thead>
        <tr>
          <th>Reference</th>
          <th>Customer</th>
          <th>Ticket</th>
          <th>Visit Date</th>
          <th>Amount</th>
          <th>Payment</th>
          <th>Status</th>
          <th>Tickets</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!count($bookings)): ?>
          <tr>
            <td colspan="8" style="text-align:center;color:#94a3b8;padding:3rem;">
              No bookings found
            </td>
          </tr>
        <?php endif; ?>

        <?php foreach ($bookings as $b):
          $bId    = (int)$b['id'];
          $paySt  = (string)($b['payment_status'] ?? '');
          $status = (string)($b['status'] ?? 'Active');

          $ticketNumbers = [];
          try {
              $tst = $pdo->prepare('SELECT ticket_number FROM tickets WHERE booking_id = ? ORDER BY ticket_number');
              $tst->execute([$bId]);
              $ticketNumbers = $tst->fetchAll(PDO::FETCH_COLUMN);
          } catch (\Throwable $e) {}
        ?>
          <tr>
            <td><span class="ref-code"><?= e($b['booking_reference'] ?? '') ?></span></td>
            <td>
              <div style="font-weight:600;"><?= e($b['customer_name'] ?? '') ?></div>
              <div style="color:#94a3b8;font-size:.78rem;"><?= e($b['customer_email'] ?? '') ?></div>
              <?php if (!empty($b['customer_phone'])): ?>
                <div style="color:#94a3b8;font-size:.78rem;"><?= e($b['customer_phone']) ?></div>
              <?php endif; ?>
            </td>
            <td>
              <div style="font-weight:600;"><?= e($b['ticket_type_name'] ?? '') ?></div>
              <div style="color:#64748b;font-size:.8rem;">×<?= (int)($b['quantity'] ?? 1) ?> ticket<?= (int)($b['quantity'] ?? 1) > 1 ? 's' : '' ?></div>
            </td>
            <td><?= e((string)($b['visit_date'] ?? '')) ?></td>
            <td style="font-weight:800;color:#0f766e;">₱<?= number_format((float)($b['total_amount'] ?? 0), 0) ?></td>
            <td><span class="badge <?= e($payColors[$paySt] ?? 'badge-gray') ?>"><?= e($paySt) ?></span></td>
            <td>
              <?php
                $statusColors = ['Active'=>'badge-green','Used'=>'badge-blue','Cancelled'=>'badge-red'];
              ?>
              <span class="badge <?= $statusColors[$status] ?? 'badge-gray' ?>"><?= e($status) ?></span>
            </td>
            <td>
              <button type="button"
                      onclick="toggleTickets(<?= $bId ?>)"
                      style="background:#f0fdfa;border:1.5px solid #99f6e4;color:#0f766e;
                             padding:.35rem .85rem;border-radius:.5rem;font-size:.8rem;
                             font-weight:700;cursor:pointer;">
                🎫 <?= count($ticketNumbers) ?>
              </button>
            </td>
          </tr>
          <tr class="tickets-row" id="tickets-<?= $bId ?>">
            <td colspan="8">
              <div style="font-size:.8rem;font-weight:700;color:#0f766e;margin-bottom:.5rem;text-transform:uppercase;letter-spacing:.05em;">
                Individual Tickets
              </div>
              <?php if (count($ticketNumbers) > 0): ?>
                <?php foreach ($ticketNumbers as $tn): ?>
                  <span class="ticket-chip"><?= e($tn) ?></span>
                <?php endforeach; ?>
              <?php else: ?>
                <span style="color:#94a3b8;font-size:.85rem;">No individual tickets generated.</span>
              <?php endif; ?>
              <?php if (!empty($b['created_at'])): ?>
                <div style="margin-top:.75rem;font-size:.78rem;color:#64748b;">
                  Booked: <?= e((string)$b['created_at']) ?>
                  <?php if (!empty($b['payment_reference'])): ?>
                    &nbsp;·&nbsp; Ref: <code style="font-size:.78rem;"><?= e($b['payment_reference']) ?></code>
                  <?php endif; ?>
                </div>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

</div><!-- /.bk-wrap -->

<script>
function toggleTickets(id) {
  var row = document.getElementById('tickets-' + id);
  if (row) row.style.display = row.style.display === 'none' ? 'table-row' : 'none';
}
</script>

</body>
</html>
