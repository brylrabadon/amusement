<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/auth.php';

$user = require_admin();
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    $id = (int)($_POST['id'] ?? 0);
    if ($id > 0) {
        if ($action === 'mark_paid') {
            $pdo->prepare("UPDATE bookings SET payment_status = 'Paid' WHERE id = ?")->execute([$id]);
            flash_set('success', 'Booking marked as Paid.');
        }
        if ($action === 'mark_used') {
            $pdo->prepare("UPDATE bookings SET status = 'Used' WHERE id = ?")->execute([$id]);
            // Also mark all individual tickets as USED
            try {
                $pdo->prepare("UPDATE tickets SET status = 'USED' WHERE booking_id = ? AND status = 'ACTIVE'")->execute([$id]);
            } catch (\Throwable $e) { /* tickets table may not exist yet */ }
            flash_set('success', 'Booking marked as Used.');
        }
        if ($action === 'cancel') {
            $pdo->prepare("UPDATE bookings SET payment_status = 'Cancelled', status = 'Cancelled' WHERE id = ?")->execute([$id]);
            try {
                $pdo->prepare("UPDATE tickets SET status = 'CANCELLED' WHERE booking_id = ?")->execute([$id]);
            } catch (\Throwable $e) { /* tickets table may not exist yet */ }
            flash_set('success', 'Booking cancelled.');
        }
    }
    redirect('admin/bookings.php');
}

$q = trim((string)($_GET['q'] ?? ''));
$pay = trim((string)($_GET['pay'] ?? ''));

$where = [];
$params = [];
if ($q !== '') {
    $where[] = '(booking_reference LIKE ? OR customer_name LIKE ? OR customer_email LIKE ?)';
    $params[] = '%' . $q . '%';
    $params[] = '%' . $q . '%';
    $params[] = '%' . $q . '%';
}
if ($pay !== '') {
    $where[] = 'payment_status = ?';
    $params[] = $pay;
}

$sql = 'SELECT * FROM bookings';
if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
$sql .= ' ORDER BY created_at DESC';

$st = $pdo->prepare($sql);
$st->execute($params);
$bookings = $st->fetchAll();

$flash = flash_get();
$payColors = ['Paid' => 'badge-green', 'Pending' => 'badge-yellow', 'Cancelled' => 'badge-red', 'Refunded' => 'badge-blue'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" /><meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Manage Bookings - AmusePark</title>
  <link rel="stylesheet" href="../css/style.css" />
</head>
<body>
<nav class="admin-nav">
  <a class="logo" href="../index.php">Amuse<span>Park</span></a>
  <ul>
    <li><a href="admin-dashboard.php">Dashboard</a></li>
    <li><a href="rides.php">Rides</a></li>
    <li><a href="bookings.php" class="active">Bookings</a></li>
    <li><a href="ticket-types.php">Ticket Types</a></li>
    <li><a href="scanner.php">Scanner</a></li>
    <li><a href="../profile.php">Profile</a></li>
    <li><a href="../logout.php" style="color:#dc2626;font-weight:600;">Logout</a></li>
  </ul>
</nav>


<div class="page-header"><h1>Bookings Management</h1><p>View and manage all ticket bookings</p></div>
<div class="container">
  <?php if ($flash && ($flash['message'] ?? '') !== ''): ?>
    <div class="card" style="padding:1rem;margin-bottom:1rem;border-left:4px solid <?= ($flash['type'] ?? '') === 'error' ? '#dc2626' : '#16a34a' ?>;">
      <strong><?= e(($flash['type'] ?? '') === 'error' ? 'Error' : 'Success') ?>:</strong>
      <?= e($flash['message']) ?>
    </div>
  <?php endif; ?>

  <form class="filter-row" method="get">
    <input type="text" name="q" value="<?= e($q) ?>" placeholder="🔍 Search bookings..." />
    <select name="pay" style="width:180px;">
      <option value="">All Payments</option>
      <?php foreach (['Pending','Paid','Cancelled','Refunded'] as $p): ?>
        <option value="<?= e($p) ?>" <?= $pay === $p ? 'selected' : '' ?>><?= e($p) ?></option>
      <?php endforeach; ?>
    </select>
    <button class="btn btn-outline" type="submit">Filter</button>
    <a class="btn btn-outline" href="bookings.php">Reset</a>
  </form>

  <div style="overflow-x:auto;background:#fff;border-radius:1rem;box-shadow:0 1px 6px rgba(0,0,0,.06);">
    <table>
      <thead>
        <tr>
          <th>Reference</th><th>Customer</th><th>Ticket</th><th>Visit Date</th><th>Amount</th><th>Payment</th><th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!count($bookings)): ?>
          <tr><td colspan="7" style="text-align:center;color:#94a3b8;padding:2rem;">No bookings found</td></tr>
        <?php endif; ?>
        <?php foreach ($bookings as $b): ?>
          <?php
            $paySt = (string)($b['payment_status'] ?? '');
            $bookingId = (int)$b['id'];

            // Fetch ticket numbers for this booking (wrapped in try/catch in case tickets table doesn't exist)
            $ticketNumbers = [];
            try {
                $tst = $pdo->prepare('SELECT ticket_number FROM tickets WHERE booking_id = ? ORDER BY ticket_number');
                $tst->execute([$bookingId]);
                $ticketNumbers = $tst->fetchAll(PDO::FETCH_COLUMN);
            } catch (\Throwable $e) {
                // tickets table may not exist yet — silently ignore
            }
          ?>
          <tr>
            <td><code style="font-family:monospace;color:#1d4ed8;font-weight:700;background:#eff6ff;padding:.15rem .4rem;border-radius:.3rem;"><?= e($b['booking_reference'] ?? '') ?></code></td>
            <td>
              <div style="font-weight:600;"><?= e($b['customer_name'] ?? '') ?></div>
              <div style="color:#94a3b8;font-size:.8rem;"><?= e($b['customer_email'] ?? '') ?></div>
            </td>
            <td><?= e($b['ticket_type_name'] ?? '') ?> ×<?= (int)($b['quantity'] ?? 1) ?></td>
            <td><?= e((string)($b['visit_date'] ?? '')) ?></td>
            <td style="font-weight:700;">₱<?= number_format((float)($b['total_amount'] ?? 0), 0) ?></td>
            <td><span class="badge <?= e($payColors[$paySt] ?? 'badge-gray') ?>"><?= e($paySt) ?></span></td>
            <td style="display:flex;gap:.35rem;flex-wrap:wrap;">
              <?php if ($paySt === 'Pending'): ?>
                <form method="post">
                  <input type="hidden" name="action" value="mark_paid" />
                  <input type="hidden" name="id" value="<?= $bookingId ?>" />
                  <button class="btn btn-success btn-sm" type="submit">✓ Paid</button>
                </form>
                <form method="post" onsubmit="return confirm('Cancel this booking?');">
                  <input type="hidden" name="action" value="cancel" />
                  <input type="hidden" name="id" value="<?= $bookingId ?>" />
                  <button class="btn btn-danger btn-sm" type="submit">✕ Cancel</button>
                </form>
              <?php endif; ?>
              <?php if (($b['status'] ?? '') === 'Active' && $paySt === 'Paid'): ?>
                <form method="post">
                  <input type="hidden" name="action" value="mark_used" />
                  <input type="hidden" name="id" value="<?= $bookingId ?>" />
                  <button class="btn btn-primary btn-sm" type="submit">📱 Used</button>
                </form>
              <?php endif; ?>
              <button type="button" class="btn btn-outline btn-sm" onclick="toggleTickets(<?= $bookingId ?>)">🎫 Tickets</button>
            </td>
          </tr>
          <tr class="ticket-detail-row" id="tickets-<?= $bookingId ?>" style="display:none;">
            <td colspan="7" style="background:#f8fafc;padding:1rem 1.5rem;border-top:1px solid #e2e8f0;">
              <div style="font-size:.85rem;font-weight:600;color:#475569;margin-bottom:.5rem;">Individual Tickets</div>
              <?php if (count($ticketNumbers) > 0): ?>
                <div style="display:flex;flex-wrap:wrap;gap:.4rem;">
                  <?php foreach ($ticketNumbers as $tn): ?>
                    <code style="font-family:monospace;font-size:.8rem;background:#eff6ff;color:#1d4ed8;padding:.2rem .5rem;border-radius:.3rem;border:1px solid #bfdbfe;"><?= e($tn) ?></code>
                  <?php endforeach; ?>
                </div>
              <?php else: ?>
                <span style="color:#94a3b8;font-size:.85rem;">No individual tickets generated for this booking.</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<script>
function toggleTickets(id) {
  var row = document.getElementById('tickets-' + id);
  if (row) {
    row.style.display = row.style.display === 'none' ? 'table-row' : 'none';
  }
}
</script>
</body>
</html>
