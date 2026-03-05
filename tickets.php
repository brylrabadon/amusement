<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/auth.php';

$sessionUser = current_user();
if ($sessionUser && (($sessionUser['role'] ?? '') === 'admin')) {
    redirect('admin/admin-dashboard.php');
}
$pdo = db();

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

$step = isset($_GET['step']) ? (int)$_GET['step'] : 0;
if ($step < 0 || $step > 3) $step = 0;

// Load active ticket types
$types = $pdo->query("SELECT * FROM ticket_types WHERE is_active = 1 ORDER BY price ASC")->fetchAll();

// Public (not logged in): allow viewing ticket types, but require login to book.
if (!$sessionUser) {
    $flash = flash_get();
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
      <meta charset="UTF-8" /><meta name="viewport" content="width=device-width, initial-scale=1.0" />
      <title>Tickets - AmusePark</title>
      <link rel="stylesheet" href="css/style.css" />
    </head>
    <body>
    <nav>
      <a class="logo" href="index.php">Amuse<span>Park</span></a>
      <ul>
        <li><a href="index.php">Home</a></li>
        <li><a href="contact.php">Contact</a></li>
        <li><a href="login.php" class="btn btn-yellow">Login</a></li>
      </ul>
    </nav>

    <div class="page-header">
      <h1>Ticket Types</h1>
      <p>Browse tickets. Log in to book.</p>
    </div>

    <div class="container">
      <?php if ($flash && ($flash['message'] ?? '') !== ''): ?>
        <div class="card" style="padding:1rem;margin-bottom:1rem;border-left:4px solid <?= ($flash['type'] ?? '') === 'error' ? '#dc2626' : '#16a34a' ?>;">
          <strong><?= e(($flash['type'] ?? '') === 'error' ? 'Error' : 'Success') ?>:</strong>
          <?= e($flash['message']) ?>
        </div>
      <?php endif; ?>

      <div class="card" style="padding:1.25rem;margin-bottom:1.25rem;background:#eff6ff;">
        <strong>Want to book?</strong>
        <div style="color:#64748b;margin-top:.25rem;">Please log in first to continue with booking.</div>
        <div style="margin-top:.75rem;">
          <a class="btn btn-primary" href="login.php">Login to Book</a>
          <a class="btn btn-outline" href="register.php" style="margin-left:.5rem;">Create Account</a>
        </div>
      </div>

      <div class="grid grid-2">
        <?php if (!count($types)): ?>
          <div class="empty" style="grid-column:1/-1;"><div class="empty-icon">🎟</div><p>No tickets available.</p></div>
        <?php endif; ?>
        <?php foreach ($types as $t): ?>
          <div class="card" style="padding:1.25rem;">
            <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:1rem;">
              <div>
                <strong style="font-size:1.1rem;"><?= e($t['name'] ?? '') ?></strong>
                <div style="margin-top:.35rem;"><span class="badge badge-blue"><?= e($t['category'] ?? '') ?></span></div>
                <p style="color:#64748b;font-size:.9rem;margin-top:.65rem;"><?= e($t['description'] ?? '') ?></p>
                <?php if (!empty($t['max_rides'])): ?>
                  <div style="color:#7c3aed;font-size:.85rem;margin-top:.35rem;">Includes <?= (int)$t['max_rides'] ?> rides</div>
                <?php endif; ?>
              </div>
              <div style="text-align:right;flex-shrink:0;">
                <div style="font-size:1.75rem;font-weight:900;color:#1d4ed8;">₱<?= number_format((float)($t['price'] ?? 0), 0) ?></div>
                <div style="font-size:.8rem;color:#94a3b8;">per person</div>
              </div>
            </div>
            <div style="margin-top:1rem;">
              <a class="btn btn-primary btn-full" href="login.php">Login to Book</a>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
    </body>
    </html>
    <?php
    exit;
}

// Logged in: only customers can book.
$user = require_login('customer');

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
        redirect('tickets.php?step=1');
    }

    if ($action === 'details') {
        $ticketId = (int)($flow['ticket_type_id'] ?? 0);
        $qty = (int)($flow['quantity'] ?? 1);
        if ($ticketId <= 0) {
            flash_set('error', 'Please select a ticket type first.');
            redirect('tickets.php');
        }

        $name = trim((string)($_POST['customer_name'] ?? ''));
        $email = trim((string)($_POST['customer_email'] ?? ''));
        $phone = trim((string)($_POST['customer_phone'] ?? ''));
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
        $total = $unitPrice * max(1, $qty);
        $ref = booking_ref();
        $qrData = 'AMUSEPARK|' . $ref . '|' . $name . '|' . $visitDate . '|' . ($type['name'] ?? '') . 'x' . $qty;

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

        $flow['booking_id'] = $bookingId;
        $_SESSION['booking_flow'] = $flow;
        redirect('tickets.php?step=2');
    }

    if ($action === 'confirm_payment') {
        $bookingId = (int)($flow['booking_id'] ?? 0);
        if ($bookingId <= 0) {
            flash_set('error', 'No pending booking found.');
            redirect('tickets.php');
        }

        $st = $pdo->prepare('SELECT * FROM bookings WHERE id = ? AND user_id = ?');
        $st->execute([$bookingId, (int)$user['id']]);
        $booking = $st->fetch();
        if (!$booking) {
            flash_set('error', 'Booking not found.');
            reset_booking_flow();
            redirect('tickets.php');
        }

        $up = $pdo->prepare('UPDATE bookings SET payment_status = ?, payment_reference = ?, payment_method = ? WHERE id = ?');
        $up->execute(['Paid', 'PAYMONGO-' . time(), 'QR Ph', $bookingId]);

        redirect('tickets.php?step=3');
    }
}

$flash = flash_get();

// Resolve current selection/booking for display
$selectedType = null;
$selectedQty = (int)($flow['quantity'] ?? 1);
$selectedId = (int)($flow['ticket_type_id'] ?? 0);
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
    <li><a href="my-bookings.php">My Bookings</a></li>
    <li><a href="logout.php" style="color:#dc2626;font-weight:600;">Logout</a></li>
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
      <form method="post">
        <input type="hidden" name="action" value="select" />
        <div style="display:grid;gap:1rem;margin-bottom:1.5rem;">
          <?php foreach ($types as $t): ?>
            <label class="card" style="padding:1.25rem;display:flex;justify-content:space-between;gap:1rem;align-items:center;cursor:pointer;border:2px solid <?= (int)$t['id'] === $selectedId ? '#1d4ed8' : 'transparent' ?>;">
              <div>
                <div style="display:flex;align-items:center;gap:.5rem;margin-bottom:.25rem;">
                  <strong style="font-size:1.1rem;"><?= e($t['name']) ?></strong>
                  <span class="badge badge-blue"><?= e($t['category']) ?></span>
                </div>
                <p style="color:#64748b;font-size:.9rem;"><?= e($t['description'] ?? '') ?></p>
                <?php if (!empty($t['max_rides'])): ?>
                  <p style="color:#7c3aed;font-size:.8rem;margin-top:.25rem;">Includes <?= (int)$t['max_rides'] ?> rides</p>
                <?php endif; ?>
              </div>
              <div style="text-align:right;flex-shrink:0;">
                <div style="font-size:1.5rem;font-weight:900;color:#1d4ed8;">₱<?= number_format((float)$t['price'], 0) ?></div>
                <div style="font-size:.8rem;color:#94a3b8;margin-top:.25rem;">per person</div>
              </div>
              <div style="margin-left:1rem;">
                <input type="radio" name="ticket_type_id" value="<?= (int)$t['id'] ?>" <?= (int)$t['id'] === $selectedId ? 'checked' : '' ?> />
              </div>
            </label>
          <?php endforeach; ?>
        </div>

        <div class="card" style="padding:1.25rem;margin-bottom:1.5rem;">
          <label>Quantity</label>
          <div class="qty-row" style="margin-top:.75rem;display:flex;align-items:center;gap:.75rem;">
            <input type="number" name="quantity" min="1" value="<?= max(1, $selectedQty) ?>" style="width:120px;" />
            <?php if ($selectedType): ?>
              <div style="margin-left:auto;text-align:right;">
                <div style="font-size:.85rem;color:#64748b;">Total</div>
                <div style="font-size:1.75rem;font-weight:900;color:#1d4ed8;">₱<?= number_format(((float)$selectedType['price']) * max(1, $selectedQty), 0) ?></div>
              </div>
            <?php endif; ?>
          </div>
        </div>

        <button class="btn btn-primary btn-full" type="submit" style="font-size:1rem;padding:.85rem;">Continue →</button>
      </form>
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
    <h2 style="font-weight:800;margin-bottom:.5rem;">Pay via QR Ph</h2>
    <p style="color:#64748b;margin-bottom:1.5rem;">Scan the QR code below with your banking app or GCash</p>
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
    <form method="post" style="display:flex;gap:.75rem;">
      <a class="btn btn-outline" href="tickets.php?step=1" style="flex:1;text-align:center;">Back</a>
      <input type="hidden" name="action" value="confirm_payment" />
      <button class="btn btn-success" type="submit" style="flex:1;">✅ I've Paid – Confirm</button>
    </form>
  <?php endif; ?>

  <?php if ($step === 3 && $booking): ?>
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

