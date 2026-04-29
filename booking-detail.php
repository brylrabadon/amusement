<?php
declare(strict_types=1);
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/layout.php';

$user = current_user();
if (!$user) {
    flash_set('error', 'Please log in.');
    redirect('login.php?next=' . urlencode('booking-detail.php?ref=' . urlencode((string)($_GET['ref'] ?? ''))));
}
no_cache_headers();

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
    body { background: #f8fafc; color: #1e293b; }
    :root { --primary: #1e3a8a; --primary-dark: #172554; --dark: #0f172a; }
    .bd-wrap { max-width: 900px; margin: 0 auto; padding: 3rem 1.5rem 5rem; }
    .bd-back { display: inline-flex; align-items: center; gap: .5rem; color: var(--primary); font-weight: 700; text-decoration: none; font-size: .95rem; margin-bottom: 2rem; transition: transform .2s; }
    .bd-back:hover { transform: translateX(-4px); }

    .bd-hero {
      background: linear-gradient(135deg, var(--dark) 0%, var(--primary-dark) 100%);
      padding: 4rem 3rem; color: #fff; text-align: left; position: relative; overflow: hidden;
      border-radius: 2.5rem; margin-bottom: 2rem;
      display: flex; justify-content: space-between; align-items: center; gap: 2rem;
      box-shadow: 0 20px 40px rgba(15, 23, 42, 0.1);
    }
    .bd-hero::before {
      content: ''; position: absolute; inset: 0;
      background: url('https://www.transparenttextures.com/patterns/cubes.png'); opacity: 0.05;
    }
    .bd-ref { font-size: 2.2rem; font-weight: 900; letter-spacing: -.03em; margin-bottom: .25rem; position: relative; z-index: 1; }
    .bd-sub { font-size: 1rem; opacity: .7; font-weight: 500; position: relative; z-index: 1; }
    .bd-badges { display: flex; gap: .75rem; flex-wrap: wrap; margin-top: 1.25rem; position: relative; z-index: 1; }
    .bd-badge { padding: .4rem 1rem; border-radius: 999px; font-size: .8rem; font-weight: 800; text-transform: uppercase; letter-spacing: .05em; }

    .bd-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem; margin-bottom: 1.5rem; }
    .bd-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 1.5rem; padding: 2rem; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); }
    .bd-card-title { font-size: .85rem; font-weight: 800; text-transform: uppercase; letter-spacing: .1em; color: #64748b; margin-bottom: 1.5rem; display: flex; align-items: center; gap: .5rem; }
    .bd-row { display: flex; justify-content: space-between; align-items: center; gap: 1rem; padding: .75rem 0; border-bottom: 1px solid #f1f5f9; font-size: .95rem; }
    .bd-row:last-child { border-bottom: none; }
    .bd-lbl { color: #64748b; font-weight: 500; }
    .bd-val { font-weight: 700; color: #0f172a; }

    .bd-tickets { background: #fff; border: 1px solid #e2e8f0; border-radius: 1.5rem; padding: 2rem; margin-bottom: 1.5rem; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); }
    .bd-ticket-item {
      display: flex; align-items: center; gap: 1.25rem;
      background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 1rem;
      padding: 1.25rem; margin-bottom: .75rem; transition: transform .2s;
    }
    .bd-ticket-item:hover { transform: translateY(-2px); border-color: var(--primary); }
    .bd-ticket-qr { width: 80px; height: 80px; border-radius: .75rem; flex-shrink: 0; background: #fff; padding: .5rem; border: 1px solid #e2e8f0; }
    .bd-ticket-info { flex: 1; }
    .bd-ticket-num { font-family: 'JetBrains Mono', monospace; font-weight: 800; color: var(--primary); font-size: 1rem; margin-bottom: .25rem; }
    .bd-ticket-status { font-size: .75rem; font-weight: 800; padding: .25rem .75rem; border-radius: 999px; text-transform: uppercase; }

    .bd-rides { background: #fff; border: 1px solid #e2e8f0; border-radius: 1.5rem; padding: 2rem; margin-bottom: 1.5rem; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); }
    .bd-ride-chip { display: inline-flex; align-items: center; gap: .5rem; background: #eff6ff; color: var(--primary); border: 1.5px solid #dbeafe; border-radius: .75rem; padding: .5rem 1rem; font-size: .9rem; font-weight: 700; margin: .35rem; transition: all .2s; }
    .bd-ride-chip:hover { background: #dbeafe; transform: scale(1.05); }

    .bd-countdown {
      background: #fffbeb; border: 2px solid #fef3c7; border-radius: 1.25rem;
      padding: 1.5rem 2rem; display: flex; align-items: center; gap: 1.5rem; margin-bottom: 1.5rem;
      box-shadow: 0 10px 15px -3px rgba(251, 191, 36, 0.1);
    }
    .bd-countdown.urgent { background: #fef2f2; border-color: #fee2e2; }
    .bd-countdown .timer { font-size: 2rem; font-weight: 900; color: #92400e; letter-spacing: -0.02em; }
    .bd-countdown.urgent .timer { color: #dc2626; }

    @media (max-width: 640px) {
      .bd-grid { grid-template-columns: 1fr; }
      .bd-hero { flex-direction: column; text-align: center; padding: 2.5rem 1.5rem; }
      .bd-hero > div:last-child { text-align: center !important; }
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
      <div class="bd-sub">Booking Reference</div>
      <div class="bd-ref"><?= e($ref) ?></div>
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
      <div style="font-size:1rem;opacity:.7;font-weight:500;margin-bottom:.25rem;">Total Amount Paid</div>
      <div style="font-size:2.8rem;font-weight:900;color:#facc15;letter-spacing:-.03em;">
        ₱<?= number_format((float)($booking['total_amount'] ?? 0), 0) ?>
      </div>
      <div style="font-size:.95rem;opacity:.8;font-weight:600;"><?= e($booking['ticket_type_name'] ?? '') ?> × <?= (int)($booking['quantity'] ?? 1) ?></div>
    </div>
  </div>

  <!-- 3-min countdown if still pending -->
  <?php if ($paySt === 'Pending' && $secondsLeft > 0): ?>
    <div class="bd-countdown" id="bd-countdown">
      <span style="font-size:2.5rem;">⏱</span>
      <div>
        <div class="timer" id="bd-timer"><?= gmdate('i:s', $secondsLeft) ?></div>
        <div style="font-size:.95rem;color:#b45309;font-weight:600;">Payment deadline — booking will be cancelled automatically</div>
      </div>
      <?php if ($deadline !== ''): ?>
        <div style="margin-left:auto;text-align:right;">
          <div style="font-size:.8rem;color:#92400e;text-transform:uppercase;letter-spacing:.05em;font-weight:800;margin-bottom:.2rem;">Expires At</div>
          <div style="font-size:.95rem;color:#1e293b;font-weight:700;"><?= date('h:i A', strtotime($deadline)) ?></div>
        </div>
      <?php endif; ?>
    </div>
    <script>
    (function(){
      var left = <?= $secondsLeft ?>;
      var el = document.getElementById('bd-timer');
      var wrap = document.getElementById('bd-countdown');
      var iv = setInterval(function(){
        left--;
        if(left <= 0){
          clearInterval(iv);
          window.location.reload();
          return;
        }
        var m = Math.floor(left/60), s = left%60;
        el.textContent = m + ':' + (s<10?'0':'') + s;
        if(left <= 30) wrap.classList.add('urgent');
      },1000);
    })();
    </script>
  <?php endif; ?>

  <div class="bd-grid">
    <div class="bd-card">
      <div class="bd-card-title">👤 Customer Info</div>
      <div class="bd-row"><span class="bd-lbl">Name</span> <span class="bd-val"><?= e($booking['customer_name'] ?? '—') ?></span></div>
      <div class="bd-row"><span class="bd-lbl">Email</span> <span class="bd-val"><?= e($booking['customer_email'] ?? '—') ?></span></div>
      <div class="bd-row"><span class="bd-lbl">Phone</span> <span class="bd-val"><?= e($booking['customer_phone'] ?? '—') ?></span></div>
    </div>
    <div class="bd-card">
      <div class="bd-card-title">📅 Visit Details</div>
      <div class="bd-row"><span class="bd-lbl">Visit Date</span> <span class="bd-val"><?= e($booking['visit_date'] ?? '—') ?></span></div>
      <div class="bd-row"><span class="bd-lbl">Booked On</span> <span class="bd-val"><?= date('M d, Y h:i A', strtotime((string)$booking['created_at'])) ?></span></div>
      <div class="bd-row"><span class="bd-lbl">Payment</span> <span class="bd-val"><?= e($booking['payment_method'] ?? '—') ?></span></div>
    </div>
  </div>

  <div class="bd-tickets">
    <div class="bd-card-title">🎟 Individual Tickets</div>
    <?php if (empty($tickets)): ?>
      <p style="color:#94a3b8;font-style:italic;">No tickets found.</p>
    <?php else: ?>
      <div style="display:grid;grid-template-columns:repeat(auto-fill, minmax(350px, 1fr));gap:1rem;">
        <?php foreach ($tickets as $t):
          $tst = (string)($t['status'] ?? 'Active');
          $tc  = $statusColors[ucfirst(strtolower($tst))] ?? ['bg'=>'#f1f5f9','color'=>'#475569'];
        ?>
          <div class="bd-ticket-item">
            <div class="bd-ticket-qr">
              <img src="https://api.qrserver.com/v1/create-qr-code/?size=100x100&data=<?= e(urlencode((string)$t['ticket_number'])) ?>" 
                   alt="QR" style="width:100%;height:100%;display:block;">
            </div>
            <div class="bd-ticket-info">
              <div style="font-size:.75rem;color:#64748b;font-weight:700;text-transform:uppercase;margin-bottom:.2rem;">Ticket Number</div>
              <div class="bd-ticket-num"><?= e($t['ticket_number']) ?></div>
              <span class="bd-ticket-status" style="background:<?= $tc['bg'] ?>;color:<?= $tc['color'] ?>;">
                <?= e($tst) ?>
              </span>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

  <?php if (!empty($rides)): ?>
    <div class="bd-rides">
      <div class="bd-card-title">🎢 Included Rides</div>
      <div style="display:flex;flex-wrap:wrap;margin:-.35rem;">
        <?php foreach ($rides as $r): ?>
          <div class="bd-ride-chip">
            <span>🎡</span> <?= e($r['name']) ?>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endif; ?>
</div>
</body>
</html>
