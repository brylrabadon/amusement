<?php
declare(strict_types=1);
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/layout.php';

$user = current_user();
if (!$user) {
    flash_set('error', 'Please log in.');
    redirect('login.php?next=' . urlencode('booking-detail.php?ref=' . urlencode((string)($_GET['ref'] ?? ''))));
}

$role = $user['role'] ?? 'customer';
$pdo  = db();
$ref  = trim((string)($_GET['ref'] ?? ''));

if ($ref === '') {
    flash_set('error', 'No booking reference provided.');
    redirect($role === 'admin' ? 'admin/bookings.php' : ($role === 'staff' ? 'staff/bookings.php' : 'my-bookings.php'));
}

// Fetch booking — admins/staff see any booking, customers only their own
$params = [$ref];
$sql    = 'SELECT * FROM bookings WHERE booking_reference = ?';
if ($role === 'customer') {
    $sql    .= ' AND user_id = ?';
    $params[] = (int)$user['id'];
}
$st = $pdo->prepare($sql);
$st->execute($params);
$booking = $st->fetch();

if (!$booking) {
    flash_set('error', 'Booking not found.');
    redirect($role === 'admin' ? 'admin/bookings.php' : ($role === 'staff' ? 'staff/bookings.php' : 'my-bookings.php'));
}

$bookingId = (int)$booking['id'];

// Fetch individual tickets
$tickets = [];
try {
    $ts = $pdo->prepare('SELECT * FROM tickets WHERE booking_id = ? ORDER BY ticket_number');
    $ts->execute([$bookingId]);
    $tickets = $ts->fetchAll();
} catch (Throwable $e) {}

// Fetch selected rides (from relational booking_rides table)
$rides = [];
try {
    $rs = $pdo->prepare(
        'SELECT r.name, r.category FROM booking_rides br
         JOIN rides r ON r.id = br.ride_id
         WHERE br.booking_id = ? ORDER BY r.name'
    );
    $rs->execute([$bookingId]);
    $rides = $rs->fetchAll();
} catch (Throwable $e) {}

// Fallback: parse rides from qr_code_data if booking_rides is empty
if (empty($rides)) {
    $qrData = (string)($booking['qr_code_data'] ?? '');
    if (preg_match('/\|RIDES:(.+)$/', $qrData, $m)) {
        foreach (explode(',', $m[1]) as $rn) {
            $rn = trim($rn);
            if ($rn !== '') $rides[] = ['name' => $rn, 'category' => ''];
        }
    }
}

$payColors = [
    'Paid'      => ['bg' => '#dcfce7', 'color' => '#166534'],
    'Pending'   => ['bg' => '#fef9c3', 'color' => '#854d0e'],
    'Cancelled' => ['bg' => '#fee2e2', 'color' => '#991b1b'],
    'Refunded'  => ['bg' => '#dbeafe', 'color' => '#1e40af'],
];
$statusColors = [
    'Active'    => ['bg' => '#dcfce7', 'color' => '#166534'],
    'Used'      => ['bg' => '#dbeafe', 'color' => '#1e40af'],
    'Expired'   => ['bg' => '#fef9c3', 'color' => '#854d0e'],
    'Cancelled' => ['bg' => '#fee2e2', 'color' => '#991b1b'],
];

$paySt  = (string)($booking['payment_status'] ?? '');
$bkSt   = (string)($booking['status'] ?? '');
$pc     = $payColors[$paySt]  ?? ['bg' => '#f1f5f9', 'color' => '#475569'];
$sc     = $statusColors[$bkSt] ?? ['bg' => '#f1f5f9', 'color' => '#475569'];

$backUrl = $role === 'admin' ? 'admin/bookings.php' : ($role === 'staff' ? 'staff/bookings.php' : 'my-bookings.php');

// Payment deadline
$deadline = (string)($booking['payment_deadline'] ?? $booking['expires_at'] ?? '');
if ($deadline === '' && $paySt === 'Pending') {
    $deadline = date('Y-m-d H:i:s', strtotime((string)$booking['created_at']) + 180);
}
$secondsLeft = 0;
if ($paySt === 'Pending' && $deadline !== '') {
    $secondsLeft = max(0, strtotime($deadline) - time());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Booking <?= e($ref) ?> - AmusePark</title>
  <link rel="stylesheet" href="css/style.css"/>
  <style>
    body { background: #f1f5f9; }
    .bd-wrap { max-width: 860px; margin: 0 auto; padding: 2rem 1.5rem 4rem; }
    .bd-back { display: inline-flex; align-items: center; gap: .4rem; color: var(--primary); font-weight: 700; text-decoration: none; font-size: .9rem; margin-bottom: 1.5rem; }
    .bd-back:hover { text-decoration: underline; }

    .bd-hero {
      background: linear-gradient(135deg, var(--dark) 0%, var(--primary-dark) 100%);
      padding: 3.5rem 2rem; color: #fff; text-align: center; position: relative; overflow: hidden;
    }
    .bd-ref { font-size: 1.6rem; font-weight: 900; letter-spacing: -.02em; margin-bottom: .25rem; }
    .bd-sub { font-size: .9rem; opacity: .8; }
    .bd-badges { display: flex; gap: .5rem; flex-wrap: wrap; margin-top: .75rem; }
    .bd-badge { padding: .3rem .85rem; border-radius: 999px; font-size: .78rem; font-weight: 800; }

    .bd-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 1.25rem; margin-bottom: 1.25rem; }
    .bd-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 1rem; padding: 1.5rem; }
    .bd-card-title { font-size: .78rem; font-weight: 800; text-transform: uppercase; letter-spacing: .06em; color: #9ca3af; margin-bottom: 1rem; }
    .bd-row { display: flex; justify-content: space-between; align-items: flex-start; gap: .5rem; padding: .5rem 0; border-bottom: 1px solid #f3f4f6; font-size: .9rem; }
    .bd-row:last-child { border-bottom: none; }
    .bd-lbl { color: #6b7280; flex-shrink: 0; }
    .bd-val { font-weight: 600; color: #111827; text-align: right; }

    .bd-tickets { background: #fff; border: 1px solid #e5e7eb; border-radius: 1rem; padding: 1.5rem; margin-bottom: 1.25rem; }
    .bd-ticket-item {
      display: flex; align-items: center; gap: 1rem;
      background: #f9fafb; border: 1px solid #e5e7eb; border-radius: .75rem;
      padding: .85rem 1rem; margin-bottom: .6rem;
    }
    .bd-ticket-item:last-child { margin-bottom: 0; }
    .bd-ticket-qr { width: 64px; height: 64px; border-radius: .4rem; flex-shrink: 0; }
    .bd-ticket-num { font-family: monospace; font-weight: 800; color: var(--primary); font-size: .9rem; }
    .bd-ticket-status { font-size: .75rem; font-weight: 700; padding: .15rem .55rem; border-radius: 999px; }

    .bd-rides { background: #fff; border: 1px solid #e5e7eb; border-radius: 1rem; padding: 1.5rem; margin-bottom: 1.25rem; }
    .bd-ride-chip { display: inline-flex; align-items: center; gap: .35rem; background: #eff6ff; color: var(--primary); border: 1px solid #dbeafe; border-radius: .5rem; padding: .3rem .75rem; font-size: .82rem; font-weight: 700; margin: .25rem; }

    .bd-countdown {
      background: #fef3c7; border: 1px solid #fcd34d; border-radius: .85rem;
      padding: 1rem 1.5rem; display: flex; align-items: center; gap: 1rem; margin-bottom: 1.25rem;
    }
    .bd-countdown.urgent { background: #fee2e2; border-color: #fca5a5; }
    .bd-countdown .timer { font-size: 1.5rem; font-weight: 900; color: #92400e; }
    .bd-countdown.urgent .timer { color: #991b1b; }

    @media (max-width: 640px) {
      .bd-grid { grid-template-columns: 1fr; }
      .bd-hero { padding: 1.5rem; }
    }
  </style>
</head>
<body>
<?php render_nav($user, ''); ?>

<div class="bd-wrap">
  <a href="<?= e($backUrl) ?>" class="bd-back">← Back to Bookings</a>

  <!-- Hero -->
  <div class="bd-hero">
    <div>
      <div class="bd-ref"><?= e($ref) ?></div>
      <div class="bd-sub">Booking Reference</div>
      <div class="bd-badges">
        <span class="bd-badge" style="background:<?= $pc['bg'] ?>;color:<?= $pc['color'] ?>;">
          <?= e($paySt) ?>
        </span>
        <span class="bd-badge" style="background:<?= $sc['bg'] ?>;color:<?= $sc['color'] ?>;">
          <?= e($bkSt) ?>
        </span>
      </div>
    </div>
    <div style="text-align:right;">
      <div style="font-size:2rem;font-weight:900;color:#facc15;">
        ₱<?= number_format((float)($booking['total_amount'] ?? 0), 0) ?>
      </div>
      <div style="font-size:.85rem;opacity:.8;"><?= e($booking['ticket_type_name'] ?? '') ?> × <?= (int)($booking['quantity'] ?? 1) ?></div>
    </div>
  </div>

  <!-- 3-min countdown if still pending -->
  <?php if ($paySt === 'Pending' && $secondsLeft > 0): ?>
    <div class="bd-countdown" id="bd-countdown">
      <span style="font-size:1.5rem;">⏱</span>
      <div>
        <div class="timer" id="bd-timer"><?= gmdate('i:s', $secondsLeft) ?></div>
        <div style="font-size:.82rem;color:#b45309;">Payment deadline — booking cancels automatically</div>
      </div>
      <?php if ($deadline !== ''): ?>
        <div style="margin-left:auto;font-size:.82rem;color:#92400e;">
          Deadline: <strong><?= e($deadline) ?></strong>
        </div>
      <?php endif; ?>
    </div>
    <script>
    (function() {
      var s = <?= (int)$secondsLeft ?>;
      var el = document.getElementById('bd-timer');
      var box = document.getElementById('bd-countdown');
      var iv = setInterval(function() {
        s--;
        if (s <= 0) { clearInterval(iv); el.textContent = 'EXPIRED'; box.classList.add('urgent'); return; }
        var m = Math.floor(s/60), sec = s%60;
        el.textContent = m + ':' + (sec < 10 ? '0' : '') + sec;
        if (s <= 30) box.classList.add('urgent');
      }, 1000);
    })();
    </script>
  <?php elseif ($paySt === 'Pending' && $secondsLeft === 0 && $deadline !== ''): ?>
    <div class="bd-countdown urgent">
      <span style="font-size:1.5rem;">⏰</span>
      <div>
        <div class="timer">EXPIRED</div>
        <div style="font-size:.82rem;color:#991b1b;">Payment deadline passed — booking will be cancelled shortly</div>
      </div>
    </div>
  <?php endif; ?>

  <!-- Details grid -->
  <div class="bd-grid">
    <!-- Customer info -->
    <div class="bd-card">
      <div class="bd-card-title">👤 Customer Information</div>
      <div class="bd-row"><span class="bd-lbl">Name</span><span class="bd-val"><?= e($booking['customer_name'] ?? '') ?></span></div>
      <div class="bd-row"><span class="bd-lbl">Email</span><span class="bd-val"><?= e($booking['customer_email'] ?? '') ?></span></div>
      <div class="bd-row"><span class="bd-lbl">Phone</span><span class="bd-val"><?= e($booking['customer_phone'] ?? '—') ?></span></div>
    </div>

    <!-- Ticket info -->
    <div class="bd-card">
      <div class="bd-card-title">🎟 Ticket Information</div>
      <div class="bd-row"><span class="bd-lbl">Type</span><span class="bd-val"><?= e($booking['ticket_type_name'] ?? '') ?></span></div>
      <div class="bd-row"><span class="bd-lbl">Quantity</span><span class="bd-val"><?= (int)($booking['quantity'] ?? 1) ?></span></div>
      <div class="bd-row"><span class="bd-lbl">Unit Price</span><span class="bd-val">₱<?= number_format((float)($booking['unit_price'] ?? 0), 2) ?></span></div>
      <div class="bd-row"><span class="bd-lbl">Total</span><span class="bd-val" style="color:var(--primary);font-size:1.05rem;">₱<?= number_format((float)($booking['total_amount'] ?? 0), 2) ?></span></div>
      <div class="bd-row"><span class="bd-lbl">Visit Date</span><span class="bd-val"><?= e((string)($booking['visit_date'] ?? '')) ?></span></div>
    </div>

    <!-- Payment info -->
    <div class="bd-card">
      <div class="bd-card-title">💳 Payment Details</div>
      <div class="bd-row"><span class="bd-lbl">Method</span><span class="bd-val"><?= e($booking['payment_method'] ?? 'QR Ph') ?></span></div>
      <div class="bd-row"><span class="bd-lbl">Status</span>
        <span class="bd-val">
          <span style="background:<?= $pc['bg'] ?>;color:<?= $pc['color'] ?>;padding:.15rem .6rem;border-radius:999px;font-size:.8rem;font-weight:800;">
            <?= e($paySt) ?>
          </span>
        </span>
      </div>
      <?php if (!empty($booking['payment_reference'])): ?>
        <div class="bd-row"><span class="bd-lbl">Reference</span><span class="bd-val" style="font-family:monospace;font-size:.82rem;"><?= e($booking['payment_reference']) ?></span></div>
      <?php endif; ?>
      <?php if ($deadline !== ''): ?>
        <div class="bd-row"><span class="bd-lbl">Deadline</span><span class="bd-val" style="color:#dc2626;"><?= e($deadline) ?></span></div>
      <?php endif; ?>
    </div>

    <!-- Booking meta -->
    <div class="bd-card">
      <div class="bd-card-title">📅 Booking Meta</div>
      <div class="bd-row"><span class="bd-lbl">Booked At</span><span class="bd-val"><?= e((string)($booking['created_at'] ?? '')) ?></span></div>
      <div class="bd-row"><span class="bd-lbl">Updated At</span><span class="bd-val"><?= e((string)($booking['updated_at'] ?? '')) ?></span></div>
      <div class="bd-row"><span class="bd-lbl">Booking Status</span>
        <span class="bd-val">
          <span style="background:<?= $sc['bg'] ?>;color:<?= $sc['color'] ?>;padding:.15rem .6rem;border-radius:999px;font-size:.8rem;font-weight:800;">
            <?= e($bkSt) ?>
          </span>
        </span>
      </div>
      <?php if (!empty($booking['paymongo_intent_id'])): ?>
        <div class="bd-row"><span class="bd-lbl">PayMongo PI</span><span class="bd-val" style="font-family:monospace;font-size:.75rem;"><?= e($booking['paymongo_intent_id']) ?></span></div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Selected Rides -->
  <?php if (!empty($rides)): ?>
    <div class="bd-rides">
      <div class="bd-card-title" style="font-size:.78rem;font-weight:800;text-transform:uppercase;letter-spacing:.06em;color:#9ca3af;margin-bottom:1rem;">🎢 Selected Rides</div>
      <div>
        <?php foreach ($rides as $r): ?>
          <span class="bd-ride-chip">🎢 <?= e($r['name']) ?><?= !empty($r['category']) ? ' <span style="opacity:.6;font-weight:600;">· ' . e($r['category']) . '</span>' : '' ?></span>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endif; ?>

  <!-- Individual Tickets -->
  <?php if (!empty($tickets)): ?>
    <div class="bd-tickets">
      <div class="bd-card-title" style="font-size:.78rem;font-weight:800;text-transform:uppercase;letter-spacing:.06em;color:#9ca3af;margin-bottom:1rem;">🎫 Individual Tickets (<?= count($tickets) ?>)</div>
      <?php
        $tStatusColors = [
          'ACTIVE'    => ['bg' => '#dcfce7', 'color' => '#166534'],
          'USED'      => ['bg' => '#dbeafe', 'color' => '#1e40af'],
          'CANCELLED' => ['bg' => '#fee2e2', 'color' => '#991b1b'],
          'EXPIRED'   => ['bg' => '#fef9c3', 'color' => '#854d0e'],
        ];
      ?>
      <?php foreach ($tickets as $t):
        $tn  = (string)($t['ticket_number'] ?? '');
        $tst = (string)($t['status'] ?? 'ACTIVE');
        $tc  = $tStatusColors[$tst] ?? ['bg' => '#f1f5f9', 'color' => '#475569'];
      ?>
        <div class="bd-ticket-item">
          <img class="bd-ticket-qr"
               src="https://api.qrserver.com/v1/create-qr-code/?size=128x128&data=<?= e(urlencode($tn)) ?>"
               alt="QR <?= e($tn) ?>" loading="lazy"/>
          <div style="flex:1;">
            <div class="bd-ticket-num"><?= e($tn) ?></div>
            <?php if (!empty($t['scanned_at'])): ?>
              <div style="font-size:.78rem;color:#6b7280;margin-top:.2rem;">Scanned: <?= e((string)$t['scanned_at']) ?></div>
            <?php endif; ?>
          </div>
          <span class="bd-ticket-status" style="background:<?= $tc['bg'] ?>;color:<?= $tc['color'] ?>;">
            <?= e($tst) ?>
          </span>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

</div>
</body>
</html>
