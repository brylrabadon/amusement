<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/auth.php';

// 5.6 — Conditional include of expiry cron helper
if (file_exists(__DIR__ . '/cron/expire_bookings.php')) {
    require_once __DIR__ . '/cron/expire_bookings.php';
}

// 5.1 — Optional auth (unauthenticated users allowed at step 0)
$user = current_user();
$pdo  = db();

function booking_ref(): string
{
    return 'AP-' . strtoupper(base_convert((string)time(), 10, 36)) . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
}

function today_ymd(): string
{
    return (new DateTimeImmutable('today'))->format('Y-m-d');
}

function reset_booking_flow(): void
{
    unset($_SESSION['booking_flow']);
}

$flow = $_SESSION['booking_flow'] ?? [];
if (!is_array($flow)) $flow = [];

// Handle expired booking redirect
if (isset($_GET['expired']) && (int)$_GET['expired'] === 1) {
    reset_booking_flow();
    flash_set('error', 'Your booking has expired. Please start a new booking.');
    redirect('tickets.php');
}

$step = isset($_GET['step']) ? (int)$_GET['step'] : 0;
if ($step < 0 || $step > 3) $step = 0;

// Load active ticket types
$types = $pdo->query("SELECT * FROM ticket_types WHERE is_active = 1 ORDER BY price ASC")->fetchAll();

// Handle posts
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'reset') {
        reset_booking_flow();
        redirect('tickets.php');
    }

    if ($action === 'select') {
        $ticketId = (int)($_POST['ticket_type_id'] ?? 0);
        $qty = max(1, (int)($_POST['quantity'] ?? 1));
        if ($ticketId <= 0) {
            flash_set('error', 'Please select a ticket type.');
            redirect('tickets.php');
        }
        $flow['ticket_type_id'] = $ticketId;
        $flow['quantity'] = $qty;
        $_SESSION['booking_flow'] = $flow;
        // 5.1 — Redirect to step 1; login check happens there
        redirect('tickets.php?step=1');
    }

    // 5.1 — Step 1 (details) requires login
    if ($action === 'details') {
        if (!$user) {
            redirect('login.php?next=tickets.php%3Fstep%3D1');
        }

        $ticketId = (int)($flow['ticket_type_id'] ?? 0);
        $qty = (int)($flow['quantity'] ?? 1);
        if ($ticketId <= 0) {
            flash_set('error', 'Please select a ticket type first.');
            redirect('tickets.php');
        }

        $name      = trim((string)($_POST['customer_name'] ?? ''));
        $email     = trim((string)($_POST['customer_email'] ?? ''));
        $phone     = trim((string)($_POST['customer_phone'] ?? ''));
        $visitDate = (string)($_POST['visit_date'] ?? '');

        if ($name === '' || $email === '' || $visitDate === '') {
            flash_set('error', 'Please fill in your name, email, and visit date.');
            redirect('tickets.php?step=1');
        }
        if ($visitDate < today_ymd()) {
            flash_set('error', 'You cannot book a date in the past.');
            redirect('tickets.php?step=1');
        }

        $st = $pdo->prepare('SELECT * FROM ticket_types WHERE id = ? AND is_active = 1');
        $st->execute([$ticketId]);
        $type = $st->fetch();
        if (!$type) {
            flash_set('error', 'Selected ticket type is not available.');
            redirect('tickets.php');
        }

        $unitPrice = (float)$type['price'];
        $total     = $unitPrice * max(1, $qty);
        $ref       = booking_ref();
        $qrData    = 'AMUSEPARK|' . $ref . '|' . $name . '|' . $visitDate . '|' . ($type['name'] ?? '') . 'x' . $qty;

        $ins = $pdo->prepare(
            'INSERT INTO bookings (booking_reference, user_id, customer_name, customer_email, customer_phone, visit_date,
                ticket_type_id, ticket_type_name, quantity, unit_price, total_amount, payment_status, payment_method, qr_code_data, status)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
        );
        $ins->execute([
            $ref,
            (int)$user['id'],
            $name,
            $email,
            $phone,
            $visitDate,
            (int)$type['id'],
            (string)$type['name'],
            max(1, $qty),
            $unitPrice,
            $total,
            'Pending',
            'QR Ph',
            $qrData,
            'Active'
        ]);
        $bookingId = (int)$pdo->lastInsertId();

        // 5.4 — Insert one ticket row per quantity unit
        try {
            $insTicket = $pdo->prepare(
                'INSERT INTO tickets (booking_id, ticket_number, status) VALUES (?, ?, ?)'
            );
            for ($i = 1; $i <= max(1, $qty); $i++) {
                $ticketNumber = 'TK-' . $ref . '-' . str_pad((string)$i, 3, '0', STR_PAD_LEFT);
                $insTicket->execute([$bookingId, $ticketNumber, 'ACTIVE']);
            }
        } catch (\Throwable $e) {
            // tickets table may not exist yet; booking still proceeds
        }

        $flow['booking_id'] = $bookingId;
        $_SESSION['booking_flow'] = $flow;
        redirect('tickets.php?step=2');
    }

    if ($action === 'confirm_payment') {
        if (!$user) {
            redirect('login.php?next=tickets.php%3Fstep%3D1');
        }

        $bookingId = (int)($flow['booking_id'] ?? 0);
        if ($bookingId <= 0) {
            flash_set('error', 'No pending booking found.');
            redirect('tickets.php');
        }

        // 5.6 — Re-fetch booking and check for expiry
        $st = $pdo->prepare('SELECT * FROM bookings WHERE id = ? AND user_id = ?');
        $st->execute([$bookingId, (int)$user['id']]);
        $booking = $st->fetch();
        if (!$booking) {
            flash_set('error', 'Booking not found.');
            reset_booking_flow();
            redirect('tickets.php');
        }

        if (($booking['payment_status'] ?? '') === 'Cancelled') {
            flash_set('error', 'Booking expired. Please start a new booking.');
            reset_booking_flow();
            redirect('tickets.php');
        }

        $up = $pdo->prepare('UPDATE bookings SET payment_status = ?, payment_reference = ?, payment_method = ? WHERE id = ?');
        $up->execute(['Paid', 'PAYMONGO-' . time(), 'QR Ph', $bookingId]);

        redirect('tickets.php?step=3');
    }
}

$flash = flash_get();

// 5.1 — Enforce login for step 1 on GET
if ($step === 1 && !$user) {
    redirect('login.php?next=tickets.php%3Fstep%3D1');
}

// Resolve current selection/booking for display
$selectedType = null;
$selectedQty  = (int)($flow['quantity'] ?? 1);
$selectedId   = (int)($flow['ticket_type_id'] ?? 0);
if ($selectedId > 0) {
    foreach ($types as $t) {
        if ((int)$t['id'] === $selectedId) {
            $selectedType = $t;
            break;
        }
    }
}

$booking = null;
if ($step >= 2) {
    if (!$user) {
        redirect('login.php?next=tickets.php%3Fstep%3D1');
    }
    $bookingId = (int)($flow['booking_id'] ?? 0);
    if ($bookingId > 0) {
        $st = $pdo->prepare('SELECT * FROM bookings WHERE id = ? AND user_id = ?');
        $st->execute([$bookingId, (int)$user['id']]);
        $booking = $st->fetch() ?: null;
    }
    if (!$booking) {
        $step = 0;
        reset_booking_flow();
    }
}

// 5.6 — Run expiry check at start of step 2 render
if ($step === 2) {
    if (function_exists('expire_pending_bookings')) {
        expire_pending_bookings($pdo);
    }
}

// 5.2 — For step 0: fetch rides per ticket type from ticket_ride JOIN rides
$typeRides = []; // [ticket_type_id => [ride_name, ...]]
if ($step === 0) {
    foreach ($types as $t) {
        $tid = (int)$t['id'];
        try {
            $rs = $pdo->prepare(
                'SELECT r.name FROM ticket_ride tr JOIN rides r ON r.id = tr.ride_id WHERE tr.ticket_type_id = ? ORDER BY r.name ASC'
            );
            $rs->execute([$tid]);
            $typeRides[$tid] = $rs->fetchAll(\PDO::FETCH_COLUMN);
        } catch (\Throwable $e) {
            $typeRides[$tid] = []; // ticket_ride table not yet created
        }
    }
}

// 5.8 — For step 3: fetch individual tickets and ride list for popup
$popupTickets = [];
$popupRides   = [];
if ($step === 3 && $booking) {
    try {
        $st = $pdo->prepare('SELECT ticket_number FROM tickets WHERE booking_id = ? ORDER BY ticket_number ASC');
        $st->execute([(int)$booking['id']]);
        $popupTickets = $st->fetchAll(\PDO::FETCH_COLUMN);
    } catch (\Throwable $e) {
        $popupTickets = [];
    }

    $ttid = (int)($booking['ticket_type_id'] ?? 0);
    if ($ttid > 0) {
        try {
            $rs = $pdo->prepare(
                'SELECT r.name FROM ticket_ride tr JOIN rides r ON r.id = tr.ride_id WHERE tr.ticket_type_id = ? ORDER BY r.name ASC'
            );
            $rs->execute([$ttid]);
            $popupRides = $rs->fetchAll(\PDO::FETCH_COLUMN);
        } catch (\Throwable $e) {
            $popupRides = [];
        }
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" /><meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Book Tickets - AmusePark</title>
  <link rel="stylesheet" href="css/style.css" />
</head>
<body>
<nav>
  <a class="logo" href="index.php">Amuse<span>Park</span></a>
  <ul>
    <li><a href="rides.php">Rides</a></li>
    <li><a href="tickets.php" class="active">Buy Tickets</a></li>
    <?php if ($user): ?>
      <li><a href="my-bookings.php">My Bookings</a></li>
      <li><a href="profile.php">Profile</a></li>
      <li><a href="logout.php" style="color:#dc2626;font-weight:600;">Logout</a></li>
    <?php else: ?>
      <li><a href="login.php">Login</a></li>
      <li><a href="register.php">Register</a></li>
    <?php endif; ?>
  </ul>
</nav>

<div class="page-header">
  <h1>Book Your Tickets</h1>
  <p>Secure your spot and skip the queue</p>
</div>

<div class="container" style="max-width:680px;">
  <?php if ($flash && ($flash['message'] ?? '') !== ''): ?>
    <div class="card" style="padding:1rem;margin-bottom:1rem;border-left:4px solid <?= ($flash['type'] ?? '') === 'error' ? '#dc2626' : '#16a34a' ?>;">
      <strong><?= e(($flash['type'] ?? '') === 'error' ? 'Error' : 'Success') ?>:</strong>
      <?= e($flash['message']) ?>
    </div>
  <?php endif; ?>

  <div class="stepper">
    <div class="step <?= $step === 0 ? 'active' : ($step > 0 ? 'done' : '') ?>"><div class="step-num"><?= $step > 0 ? '✓' : '1' ?></div><div class="step-label">Select</div></div>
    <div class="step-line" style="background:<?= $step > 0 ? '#1d4ed8' : '#e2e8f0' ?>"></div>
    <div class="step <?= $step === 1 ? 'active' : ($step > 1 ? 'done' : '') ?>"><div class="step-num"><?= $step > 1 ? '✓' : '2' ?></div><div class="step-label">Details</div></div>
    <div class="step-line" style="background:<?= $step > 1 ? '#1d4ed8' : '#e2e8f0' ?>"></div>
    <div class="step <?= $step === 2 ? 'active' : ($step > 2 ? 'done' : '') ?>"><div class="step-num"><?= $step > 2 ? '✓' : '3' ?></div><div class="step-label">Payment</div></div>
    <div class="step-line" style="background:<?= $step > 2 ? '#1d4ed8' : '#e2e8f0' ?>"></div>
    <div class="step <?= $step === 3 ? 'active' : '' ?>"><div class="step-num"><?= $step > 3 ? '✓' : '4' ?></div><div class="step-label">Confirm</div></div>
  </div>

  <?php if ($step === 0): ?>
    <h2 style="font-weight:800;margin-bottom:1.5rem;">Choose Your Ticket</h2>
    <?php if (!count($types)): ?>
      <p style="color:#94a3b8;text-align:center;padding:2rem;">No tickets available.</p>
    <?php else: ?>
      <form method="post" id="ticket-form">
        <input type="hidden" name="action" value="select" />
        <div style="display:grid;gap:1rem;margin-bottom:1.5rem;">
          <?php foreach ($types as $t):
            $price = (float)$t['price'];
            $tid   = (int)$t['id'];
            $rides = $typeRides[$tid] ?? [];
          ?>
            <div class="card ticket-option" style="padding:1.25rem;display:flex;justify-content:space-between;gap:1rem;align-items:flex-start;cursor:pointer;border:2px solid <?= $tid === $selectedId ? '#1d4ed8' : '#e2e8f0' ?>;"
                 onclick="document.getElementById('radio-<?= $tid ?>').checked=true;document.querySelectorAll('.ticket-option').forEach(function(el){el.style.borderColor='#e2e8f0';});this.style.borderColor='#1d4ed8';updateTicketTotal();">
              <div style="flex:1;">
                <div style="display:flex;align-items:center;gap:.5rem;margin-bottom:.25rem;">
                  <strong style="font-size:1.1rem;"><?= e($t['name']) ?></strong>
                  <span class="badge badge-blue"><?= e($t['category']) ?></span>
                </div>
                <p style="color:#64748b;font-size:.9rem;"><?= e($t['description'] ?? '') ?></p>
                <?php if (isset($t['max_rides']) && $t['max_rides'] !== null && $t['max_rides'] !== ''): ?>
                  <p style="color:#7c3aed;font-size:.8rem;margin-top:.25rem;font-weight:600;"><?= (int)$t['max_rides'] ?> rides included</p>
                <?php else: ?>
                  <p style="color:#7c3aed;font-size:.8rem;margin-top:.25rem;font-weight:600;">Unlimited rides</p>
                <?php endif; ?>
                <?php if (count($rides) > 0): ?>
                  <div style="margin-top:.6rem;">
                    <div style="font-size:.8rem;color:#475569;font-weight:600;margin-bottom:.3rem;">Included Rides:</div>
                    <div style="display:flex;flex-wrap:wrap;gap:.3rem;margin-top:.2rem;">
                      <?php foreach ($rides as $rideName): ?>
                        <span style="background:#eff6ff;color:#1d4ed8;border-radius:.4rem;padding:.15rem .5rem;font-size:.78rem;">🎢 <?= e($rideName) ?></span>
                      <?php endforeach; ?>
                    </div>
                  </div>
                <?php endif; ?>
              </div>
              <div style="text-align:right;flex-shrink:0;">
                <div style="font-size:1.5rem;font-weight:900;color:#1d4ed8;">₱<?= number_format($price, 0) ?></div>
                <div style="font-size:.8rem;color:#94a3b8;margin-top:.25rem;">per person</div>
              </div>
              <div style="margin-left:1rem;padding-top:.25rem;" onclick="event.stopPropagation();">
                <input type="radio" id="radio-<?= $tid ?>" name="ticket_type_id" value="<?= $tid ?>" data-price="<?= (float)$price ?>" <?= $tid === $selectedId ? 'checked' : '' ?>
                       onchange="document.querySelectorAll('.ticket-option').forEach(function(el){el.style.borderColor='#e2e8f0';});this.closest('.ticket-option').style.borderColor='#1d4ed8';updateTicketTotal();" />
              </div>
            </div>
          <?php endforeach; ?>
        </div>

        <div class="card" style="padding:1.25rem;margin-bottom:1.5rem;">
          <label>Quantity</label>
          <div class="qty-row ticket-total-row" style="margin-top:.75rem;display:flex;align-items:center;gap:.75rem;">
            <input type="number" name="quantity" id="ticket-qty" min="1" value="<?= max(1, $selectedQty) ?>" style="width:120px;" />
            <div id="ticket-total-wrap" style="margin-left:auto;text-align:right;">
              <div style="font-size:.85rem;color:#64748b;">Total</div>
              <div id="ticket-total-amount" style="font-size:1.75rem;font-weight:900;color:#1d4ed8;">₱<?= $selectedType ? number_format(((float)$selectedType['price']) * max(1, $selectedQty), 0) : '0' ?></div>
            </div>
          </div>
        </div>

        <button class="btn btn-primary btn-full" type="submit" style="font-size:1rem;padding:.85rem;">Continue →</button>
      </form>
      <script>
      (function() {
        var form = document.getElementById('ticket-form');
        if (!form) return;
        var totalEl = document.getElementById('ticket-total-amount');
        var qtyInput = document.getElementById('ticket-qty');
        function formatNum(n) { return '₱' + Math.round(n).toLocaleString(); }
        window.updateTicketTotal = function() {
          var radio = form.querySelector('input[name="ticket_type_id"]:checked');
          var qty = parseInt(qtyInput.value, 10) || 1;
          qty = Math.max(1, qty);
          qtyInput.value = qty;
          if (!radio || !totalEl) return;
          var price = parseFloat(radio.getAttribute('data-price')) || 0;
          totalEl.textContent = formatNum(price * qty);
        };
        form.querySelectorAll('input[name="ticket_type_id"]').forEach(function(r) {
          r.addEventListener('change', updateTicketTotal);
        });
        if (qtyInput) qtyInput.addEventListener('input', updateTicketTotal);
        if (qtyInput) qtyInput.addEventListener('change', updateTicketTotal);
        updateTicketTotal();
      })();
      </script>
    <?php endif; ?>
  <?php endif; ?>

  <?php if ($step === 1): ?>
    <h2 style="font-weight:800;margin-bottom:1.5rem;">Your Details</h2>
    <form method="post">
      <input type="hidden" name="action" value="details" />
      <div class="card" style="padding:1.5rem;margin-bottom:1.5rem;">
        <div class="form-group"><label>Full Name *</label><input name="customer_name" value="<?= e($user['full_name'] ?? '') ?>" placeholder="Juan dela Cruz" /></div>
        <div class="form-group"><label>Email Address *</label><input name="customer_email" type="email" value="<?= e($user['email'] ?? '') ?>" placeholder="juan@email.com" /></div>
        <div class="form-group"><label>Phone Number</label><input name="customer_phone" value="<?= e($user['phone'] ?? '') ?>" placeholder="+63 9XX XXX XXXX" /></div>
        <div class="form-group"><label>Visit Date *</label><input name="visit_date" type="date" min="<?= e(today_ymd()) ?>" /></div>
      </div>

      <?php if ($selectedType): ?>
        <div class="card" style="padding:1.25rem;background:#eff6ff;margin-bottom:1.5rem;display:flex;justify-content:space-between;align-items:center;">
          <div>
            <div style="font-weight:600;"><?= e((string)$selectedType['name']) ?> × <?= (int)$selectedQty ?></div>
            <div style="color:#64748b;font-size:.9rem;">Selected ticket</div>
          </div>
          <div style="font-size:1.5rem;font-weight:900;color:#1d4ed8;">₱<?= number_format(((float)$selectedType['price']) * max(1, $selectedQty), 0) ?></div>
        </div>
      <?php endif; ?>

      <div style="display:flex;gap:.75rem;">
        <a class="btn btn-outline" href="tickets.php" style="flex:1;text-align:center;">Back</a>
        <button class="btn btn-primary" type="submit" style="flex:1;">Continue to Payment</button>
      </div>
    </form>
  <?php endif; ?>

  <?php if ($step === 2 && $booking): ?>
    <?php
      // Calculate seconds remaining for the 3-minute expiry countdown
      $createdAt = strtotime((string)($booking['created_at'] ?? 'now'));
      $expiresAt = $createdAt + 180; // 3 minutes
      $secondsLeft = max(0, $expiresAt - time());
    ?>
    <h2 style="font-weight:800;margin-bottom:.5rem;">Pay via QR Ph</h2>
    <p style="color:#64748b;margin-bottom:1rem;">Scan the QR code below with your banking app or GCash</p>

    <!-- 3-minute countdown timer -->
    <div id="expiry-banner" style="background:#fef3c7;border:1px solid #fcd34d;border-radius:.75rem;padding:.85rem 1.25rem;margin-bottom:1.25rem;display:flex;align-items:center;gap:.75rem;">
      <span style="font-size:1.25rem;">⏱</span>
      <div>
        <div style="font-weight:700;color:#92400e;font-size:.95rem;">Complete payment within <span id="countdown-display"><?= gmdate('i:s', $secondsLeft) ?></span></div>
        <div style="font-size:.8rem;color:#b45309;">Booking will be automatically cancelled if unpaid</div>
      </div>
    </div>
    <script>
    (function() {
      var secs = <?= (int)$secondsLeft ?>;
      var el = document.getElementById('countdown-display');
      var banner = document.getElementById('expiry-banner');
      if (!el || secs <= 0) return;
      var iv = setInterval(function() {
        secs--;
        if (secs <= 0) {
          clearInterval(iv);
          el.textContent = '0:00';
          banner.style.background = '#fee2e2';
          banner.style.borderColor = '#fca5a5';
          banner.querySelector('div').style.color = '#991b1b';
          // Auto-redirect when expired
          window.location.href = 'tickets.php?expired=1';
          return;
        }
        var m = Math.floor(secs / 60);
        var s = secs % 60;
        el.textContent = m + ':' + (s < 10 ? '0' : '') + s;
        if (secs <= 30) {
          banner.style.background = '#fee2e2';
          banner.style.borderColor = '#fca5a5';
        }
      }, 1000);
    })();
    </script>
    <div class="card" style="padding:2rem;text-align:center;margin-bottom:1.5rem;">
      <div class="qr-box">
        <img src="https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=<?= e(urlencode((string)($booking['qr_code_data'] ?? $booking['booking_reference']))) ?>"
             alt="QR Code" style="width:200px;height:200px;display:block;" />
      </div>
      <div style="color:#64748b;font-size:.9rem;margin-bottom:.35rem;">Reference Number</div>
      <div style="font-size:1.5rem;font-weight:900;color:#1d4ed8;margin-bottom:1rem;"><?= e($booking['booking_reference'] ?? '') ?></div>
      <div style="background:#fef9c3;border-radius:.75rem;padding:1rem;">
        <div style="font-size:.8rem;color:#64748b;">Amount to Pay</div>
        <div style="font-size:2rem;font-weight:900;">₱<?= number_format((float)($booking['total_amount'] ?? 0), 0) ?></div>
      </div>
      <div style="margin-top:.75rem;font-size:.8rem;color:#94a3b8;">This QR expires in 15 minutes. Powered by PayMongo.</div>
    </div>
    <div style="background:#eff6ff;border-radius:.75rem;padding:1rem;margin-bottom:1.5rem;font-size:.9rem;color:#1d4ed8;">
      <strong>How to pay:</strong> Open GCash/Maya or any QR Ph bank app → Scan QR → Confirm payment
    </div>
    <div style="display:flex;gap:.75rem;">
      <form method="post" style="flex:1;">
        <input type="hidden" name="action" value="reset" />
        <button class="btn btn-outline btn-full" type="submit">← Start Over</button>
      </form>
      <form method="post" style="flex:1;">
        <input type="hidden" name="action" value="confirm_payment" />
        <button class="btn btn-success btn-full" type="submit">✅ I've Paid – Confirm</button>
      </form>
    </div>
  <?php endif; ?>

  <?php if ($step === 3 && $booking): ?>
    <?php
      // 5.8 — Reference popup (visible by default)
      $paymentDatetime = !empty($booking['updated_at']) ? $booking['updated_at'] : date('Y-m-d H:i:s');
    ?>
    <div id="ref-popup" style="position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,.65);z-index:9999;display:flex;align-items:center;justify-content:center;padding:1rem;">
      <div style="background:#fff;border-radius:1rem;max-width:560px;width:100%;max-height:90vh;overflow-y:auto;padding:2rem;box-shadow:0 20px 60px rgba(0,0,0,.4);">
        <div style="text-align:center;margin-bottom:1.5rem;">
          <div style="font-size:2.5rem;">🎉</div>
          <h2 style="font-size:1.6rem;font-weight:900;color:#1d4ed8;margin:.5rem 0 .25rem;">Booking Confirmed!</h2>
          <div style="font-size:1.1rem;font-weight:700;color:#0f172a;letter-spacing:.05em;"><?= e($booking['booking_reference'] ?? '') ?></div>
        </div>

        <div style="border-top:1px solid #e2e8f0;padding-top:1rem;margin-bottom:1rem;">
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:.5rem .75rem;font-size:.9rem;">
            <div style="color:#64748b;">Customer</div><div style="font-weight:600;"><?= e($booking['customer_name'] ?? '') ?></div>
            <div style="color:#64748b;">Email</div><div><?= e($booking['customer_email'] ?? '') ?></div>
            <div style="color:#64748b;">Phone</div><div><?= e($booking['customer_phone'] ?? '') ?></div>
            <div style="color:#64748b;">Ticket Type</div><div><?= e($booking['ticket_type_name'] ?? '') ?></div>
            <div style="color:#64748b;">Quantity</div><div><?= (int)($booking['quantity'] ?? 1) ?></div>
            <div style="color:#64748b;">Visit Date</div><div><?= e((string)($booking['visit_date'] ?? '')) ?></div>
            <div style="color:#64748b;">Payment Time</div><div><?= e($paymentDatetime) ?></div>
            <div style="color:#64748b;">Total Paid</div><div style="font-weight:700;color:#16a34a;">₱<?= number_format((float)($booking['total_amount'] ?? 0), 0) ?></div>
          </div>
        </div>

        <?php if (count($popupRides) > 0): ?>
          <div style="margin-bottom:1rem;">
            <div style="font-weight:700;font-size:.9rem;color:#374151;margin-bottom:.4rem;">Included Rides</div>
            <div style="display:flex;flex-wrap:wrap;gap:.3rem;">
              <?php foreach ($popupRides as $rn): ?>
                <span style="background:#eff6ff;color:#1d4ed8;border-radius:.4rem;padding:.2rem .6rem;font-size:.8rem;"><?= e($rn) ?></span>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endif; ?>

        <?php if (count($popupTickets) > 0): ?>
          <div style="margin-bottom:1.5rem;">
            <div style="font-weight:700;font-size:.9rem;color:#374151;margin-bottom:.6rem;">Your Tickets</div>
            <div style="display:flex;flex-direction:column;gap:1rem;">
              <?php foreach ($popupTickets as $tn): ?>
                <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:.75rem;padding:.75rem;display:flex;align-items:center;gap:1rem;">
                  <img src="https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=<?= e(urlencode($tn)) ?>"
                       alt="QR <?= e($tn) ?>" style="width:80px;height:80px;border-radius:.4rem;flex-shrink:0;" />
                  <div>
                    <div style="font-size:.75rem;color:#64748b;">Ticket Number</div>
                    <div style="font-family:monospace;font-weight:700;color:#0f172a;font-size:.9rem;"><?= e($tn) ?></div>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endif; ?>

        <button onclick="document.getElementById('ref-popup').style.display='none'" class="btn btn-primary btn-full" style="font-size:1rem;padding:.85rem;">Got it</button>
      </div>
    </div>

    <div style="text-align:center;">
      <div class="success-icon">✅</div>
      <h2 style="font-size:2rem;font-weight:900;margin-bottom:.5rem;">Booking Confirmed!</h2>
      <p style="color:#64748b;margin-bottom:2rem;">Your QR ticket is ready. Show it at the park entrance.</p>
      <div class="card" style="padding:1.5rem;text-align:left;margin-bottom:1.5rem;">
        <div style="display:flex;justify-content:space-between;padding:.5rem 0;border-bottom:1px solid #f1f5f9;"><span style="color:#64748b;">Booking Ref</span><strong style="color:#1d4ed8;"><?= e($booking['booking_reference'] ?? '') ?></strong></div>
        <div style="display:flex;justify-content:space-between;padding:.5rem 0;border-bottom:1px solid #f1f5f9;"><span style="color:#64748b;">Customer</span><span><?= e($booking['customer_name'] ?? '') ?></span></div>
        <div style="display:flex;justify-content:space-between;padding:.5rem 0;border-bottom:1px solid #f1f5f9;"><span style="color:#64748b;">Ticket</span><span><?= e($booking['ticket_type_name'] ?? '') ?> × <?= (int)($booking['quantity'] ?? 1) ?></span></div>
        <div style="display:flex;justify-content:space-between;padding:.5rem 0;border-bottom:1px solid #f1f5f9;"><span style="color:#64748b;">Visit Date</span><span><?= e((string)($booking['visit_date'] ?? '')) ?></span></div>
        <div style="display:flex;justify-content:space-between;padding:.75rem 0;"><span style="color:#64748b;">Total Paid</span><strong style="font-size:1.25rem;color:#16a34a;">₱<?= number_format((float)($booking['total_amount'] ?? 0), 0) ?></strong></div>
      </div>

      <div class="card" style="padding:1.5rem;text-align:center;margin-bottom:1.5rem;">
        <div style="color:#64748b;font-size:.9rem;margin-bottom:.75rem;">Your Entry QR Code</div>
        <img src="https://api.qrserver.com/v1/create-qr-code/?size=220x220&data=<?= e(urlencode((string)($booking['qr_code_data'] ?? $booking['booking_reference']))) ?>"
             style="width:220px;height:220px;border-radius:.75rem;border:3px solid #dbeafe;" />
        <p style="font-size:.8rem;color:#94a3b8;margin-top:.75rem;">Present this at the park entrance</p>
      </div>

      <form method="post">
        <input type="hidden" name="action" value="reset" />
        <button class="btn btn-primary btn-full" type="submit" style="font-size:1rem;">Book Another Ticket</button>
      </form>
    </div>
  <?php endif; ?>
</div>
</body>
</html>
