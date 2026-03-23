<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/layout.php';

<<<<<<< HEAD
if (file_exists(__DIR__ . '/cron/expire_bookings.php')) {
    require_once __DIR__ . '/cron/expire_bookings.php';
}
=======
// 5.6 — Conditional include of expiry cron helper
if (file_exists(__DIR__ . '/cron/expire_bookings.php')) {
    require_once __DIR__ . '/cron/expire_bookings.php';
}

// 5.1 — Optional auth (unauthenticated users allowed at step 0)
$user = current_user();
$pdo  = db();
>>>>>>> 944246f7d1f7012ed1c7107d999e7fdfb8af41b5

$user = current_user();
$pdo  = db();

function booking_ref(): string {
    return 'AP-' . strtoupper(base_convert((string)time(), 10, 36)) . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
}
function today_ymd(): string {
    return (new DateTimeImmutable('today'))->format('Y-m-d');
}
function reset_booking_flow(): void {
    unset($_SESSION['booking_flow']);
}

$flow = $_SESSION['booking_flow'] ?? [];
if (!is_array($flow)) $flow = [];

<<<<<<< HEAD
=======
// Handle expired booking redirect
>>>>>>> 944246f7d1f7012ed1c7107d999e7fdfb8af41b5
if (isset($_GET['expired']) && (int)$_GET['expired'] === 1) {
    reset_booking_flow();
    flash_set('error', 'Your booking has expired. Please start a new booking.');
    redirect('tickets.php');
}

$step = isset($_GET['step']) ? (int)$_GET['step'] : 0;
if ($step < 0 || $step > 3) $step = 0;

// Pre-select package when coming from dashboard
if ($step === 0 && isset($_GET['pkg']) && (int)$_GET['pkg'] > 0) {
    $flow['ticket_type_id'] = (int)$_GET['pkg'];
    $_SESSION['booking_flow'] = $flow;
}

$types = $pdo->query("SELECT * FROM ticket_types WHERE is_active = 1 ORDER BY price ASC")->fetchAll();

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
<<<<<<< HEAD
=======

>>>>>>> 944246f7d1f7012ed1c7107d999e7fdfb8af41b5
        $ticketId = (int)($flow['ticket_type_id'] ?? 0);
        $qty = (int)($flow['quantity'] ?? 1);
        if ($ticketId <= 0) {
            flash_set('error', 'Please select a ticket type first.');
            redirect('tickets.php');
        }
<<<<<<< HEAD
=======

>>>>>>> 944246f7d1f7012ed1c7107d999e7fdfb8af41b5
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

<<<<<<< HEAD
        // Validate ride selection
        $maxRides = (isset($type['max_rides']) && $type['max_rides'] !== null && $type['max_rides'] !== '')
            ? (int)$type['max_rides'] : null;
=======
        $unitPrice = (float)$type['price'];
        $total     = $unitPrice * max(1, $qty);
        $ref       = booking_ref();
        $qrData    = 'AMUSEPARK|' . $ref . '|' . $name . '|' . $visitDate . '|' . ($type['name'] ?? '') . 'x' . $qty;
>>>>>>> 944246f7d1f7012ed1c7107d999e7fdfb8af41b5

        // Get allowed ride IDs for this ticket type
        $allowedRideIds = [];
        try {
            $rs = $pdo->prepare('SELECT ride_id FROM ticket_ride WHERE ticket_type_id = ?');
            $rs->execute([$ticketId]);
            $allowedRideIds = array_map('intval', $rs->fetchAll(\PDO::FETCH_COLUMN));
        } catch (\Throwable $e) {}

        $selectedRideIds = [];
        if (isset($_POST['selected_ride_ids']) && is_array($_POST['selected_ride_ids'])) {
            $selectedRideIds = array_map('intval', $_POST['selected_ride_ids']);
            $selectedRideIds = array_values(array_filter($selectedRideIds, fn($id) => in_array($id, $allowedRideIds, true)));
        }

        // If this package has a ride list, require at least one selection
        if (count($allowedRideIds) > 0 && count($selectedRideIds) === 0) {
            flash_set('error', 'Please select at least one ride for your package.');
            redirect('tickets.php?step=1');
        }
        // Enforce max_rides limit
        if ($maxRides !== null && count($selectedRideIds) > $maxRides) {
            flash_set('error', 'You can only select up to ' . $maxRides . ' rides for this package.');
            redirect('tickets.php?step=1');
        }

        // Get selected ride names for QR/display
        $selectedRideNames = [];
        if (count($selectedRideIds) > 0) {
            try {
                $in = implode(',', $selectedRideIds);
                $selectedRideNames = $pdo->query("SELECT name FROM rides WHERE id IN ($in) ORDER BY name ASC")
                    ->fetchAll(\PDO::FETCH_COLUMN);
            } catch (\Throwable $e) {}
        }

        $unitPrice = (float)$type['price'];
        $total     = $unitPrice * max(1, $qty);
        $ref       = booking_ref();
        $ridesSuffix = count($selectedRideNames) > 0 ? '|RIDES:' . implode(',', $selectedRideNames) : '';
        $qrData    = 'AMUSEPARK|' . $ref . '|' . $name . '|' . $visitDate . '|' . ($type['name'] ?? '') . 'x' . $qty . $ridesSuffix;
        $ins = $pdo->prepare(
            'INSERT INTO bookings (booking_reference, user_id, customer_name, customer_email, customer_phone, visit_date,
                ticket_type_id, ticket_type_name, quantity, unit_price, total_amount, payment_status, payment_method, qr_code_data, status)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
        );
        $ins->execute([$ref, (int)$user['id'], $name, $email, $phone, $visitDate,
            (int)$type['id'], (string)$type['name'], max(1, $qty), $unitPrice, $total,
            'Pending', 'QR Ph', $qrData, 'Active']);
        $bookingId = (int)$pdo->lastInsertId();
<<<<<<< HEAD
        try {
            $insTicket = $pdo->prepare('INSERT INTO tickets (booking_id, ticket_number, status) VALUES (?, ?, ?)');
=======

        // 5.4 — Insert one ticket row per quantity unit
        try {
            $insTicket = $pdo->prepare(
                'INSERT INTO tickets (booking_id, ticket_number, status) VALUES (?, ?, ?)'
            );
>>>>>>> 944246f7d1f7012ed1c7107d999e7fdfb8af41b5
            for ($i = 1; $i <= max(1, $qty); $i++) {
                $ticketNumber = 'TK-' . $ref . '-' . str_pad((string)$i, 3, '0', STR_PAD_LEFT);
                $insTicket->execute([$bookingId, $ticketNumber, 'ACTIVE']);
            }
<<<<<<< HEAD
        } catch (\Throwable $e) {}
        $flow['booking_id']         = $bookingId;
        $flow['selected_ride_ids']  = $selectedRideIds;
        $flow['selected_ride_names'] = $selectedRideNames;
=======
        } catch (\Throwable $e) {
            // tickets table may not exist yet; booking still proceeds
        }

        $flow['booking_id'] = $bookingId;
>>>>>>> 944246f7d1f7012ed1c7107d999e7fdfb8af41b5
        $_SESSION['booking_flow'] = $flow;
        redirect('tickets.php?step=2');
    }

    if ($action === 'confirm_payment') {
        if (!$user) {
            redirect('login.php?next=tickets.php%3Fstep%3D1');
        }
<<<<<<< HEAD
=======

>>>>>>> 944246f7d1f7012ed1c7107d999e7fdfb8af41b5
        $bookingId = (int)($flow['booking_id'] ?? 0);
        if ($bookingId <= 0) {
            flash_set('error', 'No pending booking found.');
            redirect('tickets.php');
        }
<<<<<<< HEAD
=======

        // 5.6 — Re-fetch booking and check for expiry
>>>>>>> 944246f7d1f7012ed1c7107d999e7fdfb8af41b5
        $st = $pdo->prepare('SELECT * FROM bookings WHERE id = ? AND user_id = ?');
        $st->execute([$bookingId, (int)$user['id']]);
        $booking = $st->fetch();
        if (!$booking) {
            flash_set('error', 'Booking not found.');
            reset_booking_flow();
            redirect('tickets.php');
        }
<<<<<<< HEAD
=======

>>>>>>> 944246f7d1f7012ed1c7107d999e7fdfb8af41b5
        if (($booking['payment_status'] ?? '') === 'Cancelled') {
            flash_set('error', 'Booking expired. Please start a new booking.');
            reset_booking_flow();
            redirect('tickets.php');
        }
<<<<<<< HEAD
=======

>>>>>>> 944246f7d1f7012ed1c7107d999e7fdfb8af41b5
        $up = $pdo->prepare('UPDATE bookings SET payment_status = ?, payment_reference = ?, payment_method = ? WHERE id = ?');
        $up->execute(['Paid', 'PAYMONGO-' . time(), 'QR Ph', $bookingId]);
        redirect('tickets.php?step=3');
    }
}

$flash = flash_get();

<<<<<<< HEAD
=======
// 5.1 — Enforce login for step 1 on GET
>>>>>>> 944246f7d1f7012ed1c7107d999e7fdfb8af41b5
if ($step === 1 && !$user) {
    redirect('login.php?next=tickets.php%3Fstep%3D1');
}

<<<<<<< HEAD
$selectedType = null;
$selectedQty  = (int)($flow['quantity'] ?? 1);
$selectedId   = (int)($flow['ticket_type_id'] ?? 0);
foreach ($types as $t) {
    if ((int)$t['id'] === $selectedId) { $selectedType = $t; break; }
=======
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
>>>>>>> 944246f7d1f7012ed1c7107d999e7fdfb8af41b5
}

$booking = null;
if ($step >= 2) {
<<<<<<< HEAD
    if (!$user) redirect('login.php?next=tickets.php%3Fstep%3D1');
=======
    if (!$user) {
        redirect('login.php?next=tickets.php%3Fstep%3D1');
    }
>>>>>>> 944246f7d1f7012ed1c7107d999e7fdfb8af41b5
    $bookingId = (int)($flow['booking_id'] ?? 0);
    if ($bookingId > 0) {
        $st = $pdo->prepare('SELECT * FROM bookings WHERE id = ? AND user_id = ?');
        $st->execute([$bookingId, (int)$user['id']]);
        $booking = $st->fetch() ?: null;
    }
    if (!$booking) { $step = 0; reset_booking_flow(); }
}

if ($step === 2 && function_exists('expire_pending_bookings')) {
    expire_pending_bookings($pdo);
}

$typeRides = [];
if ($step === 0) {
    foreach ($types as $t) {
        $tid = (int)$t['id'];
        try {
            $rs = $pdo->prepare('SELECT r.name FROM ticket_ride tr JOIN rides r ON r.id = tr.ride_id WHERE tr.ticket_type_id = ? ORDER BY r.name ASC');
            $rs->execute([$tid]);
            $typeRides[$tid] = $rs->fetchAll(\PDO::FETCH_COLUMN);
        } catch (\Throwable $e) { $typeRides[$tid] = []; }
    }
}

// For step 1 — load rides for the selected ticket type so customer can pick
$step1Rides = [];
if ($step === 1 && $selectedType) {
    $tid = (int)$selectedType['id'];
    try {
        $rs = $pdo->prepare(
            'SELECT r.id, r.name, r.category, r.status
             FROM ticket_ride tr
             JOIN rides r ON r.id = tr.ride_id
             WHERE tr.ticket_type_id = ?
             ORDER BY r.name ASC'
        );
        $rs->execute([$tid]);
        $step1Rides = $rs->fetchAll();
    } catch (\Throwable $e) { $step1Rides = []; }
}

$popupTickets = [];
$popupRides   = [];
if ($step === 3 && $booking) {
    try {
        $st = $pdo->prepare('SELECT ticket_number FROM tickets WHERE booking_id = ? ORDER BY ticket_number ASC');
        $st->execute([(int)$booking['id']]);
        $popupTickets = $st->fetchAll(\PDO::FETCH_COLUMN);
    } catch (\Throwable $e) { $popupTickets = []; }
    $ttid = (int)($booking['ticket_type_id'] ?? 0);
    if ($ttid > 0) {
        try {
            $rs = $pdo->prepare('SELECT r.name FROM ticket_ride tr JOIN rides r ON r.id = tr.ride_id WHERE tr.ticket_type_id = ? ORDER BY r.name ASC');
            $rs->execute([$ttid]);
            $popupRides = $rs->fetchAll(\PDO::FETCH_COLUMN);
        } catch (\Throwable $e) { $popupRides = []; }
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
  <title>Buy Tickets - AmusePark</title>
  <link rel="stylesheet" href="css/style.css" />
<<<<<<< HEAD
  <style>
    /* ── Tickets page theme (light + purple) ── */
    body.tickets-page { background: #f9fafb; color: #111827; }
=======
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
>>>>>>> 944246f7d1f7012ed1c7107d999e7fdfb8af41b5

    .tk-hero {
      background: linear-gradient(135deg, #7c3aed 0%, #a855f7 55%, #ec4899 100%);
      padding: 5rem 2rem 4rem;
      text-align: center;
    }
    .tk-hero h1 {
      font-size: clamp(2.2rem, 6vw, 4rem);
      font-weight: 900;
      color: #fff;
      letter-spacing: -.02em;
      line-height: 1.1;
      margin-bottom: .75rem;
    }
    .tk-hero h1 span { color: #facc15; }
    .tk-hero p { color: #e9d5ff; font-size: 1.1rem; max-width: 520px; margin: 0 auto; }

    .tk-wrap { max-width: 820px; margin: 0 auto; padding: 3rem 1.5rem; }

    /* Stepper dark */
    .tk-stepper { display: flex; align-items: center; margin-bottom: 2.5rem; }
    .tk-step { display: flex; align-items: center; gap: .5rem; flex: 1; }
    .tk-step-num {
      width: 34px; height: 34px; border-radius: 50%;
      border: 2px solid #e5e7eb;
      display: flex; align-items: center; justify-content: center;
      font-weight: 700; font-size: .85rem; color: #9ca3af; flex-shrink: 0;
    }
    .tk-step.active .tk-step-num { border-color: #7c3aed; color: #7c3aed; }
    .tk-step.done .tk-step-num { background: #7c3aed; border-color: #7c3aed; color: #fff; }
    .tk-step-label { font-size: .8rem; font-weight: 600; color: #9ca3af; }
    .tk-step.active .tk-step-label { color: #7c3aed; }
    .tk-step.done .tk-step-label { color: #6b7280; }
    .tk-step-line { flex: 1; height: 2px; background: #e5e7eb; margin: 0 .5rem; }
    .tk-step-line.done { background: #7c3aed; }

    /* Flash */
    .tk-flash {
      padding: 1rem 1.25rem; border-radius: .75rem; margin-bottom: 1.5rem;
      font-weight: 600; font-size: .95rem;
    }
    .tk-flash.error { background: #fee2e2; border: 1px solid #fca5a5; color: #991b1b; }
    .tk-flash.success { background: #dcfce7; border: 1px solid #86efac; color: #166534; }

    /* Ticket card row */
    .tk-card {
      background: #fff;
      border: 2px solid #f3f4f6;
      border-radius: 1rem;
      padding: 1.75rem 2rem;
      display: flex;
      justify-content: space-between;
      align-items: center;
      gap: 1.5rem;
      cursor: pointer;
      transition: border-color .2s, box-shadow .2s, transform .15s;
      margin-bottom: 1rem;
    }
    .tk-card:hover { border-color: #c4b5fd; box-shadow: 0 8px 32px rgba(124,58,237,.1); transform: translateY(-2px); }
    .tk-card.selected { border-color: #7c3aed; box-shadow: 0 0 0 3px rgba(124,58,237,.12); }
    .tk-card-left { flex: 1; }
    .tk-card-name { font-size: 1.4rem; font-weight: 900; color: #111827; margin-bottom: .35rem; }
    .tk-card-desc { color: #6b7280; font-size: .95rem; margin-bottom: .75rem; }
    .tk-card-rides { display: flex; flex-wrap: wrap; gap: .35rem; margin-top: .5rem; }
    .tk-ride-badge {
      background: #f3e8ff; color: #7c3aed;
      border: 1px solid #e9d5ff;
      border-radius: .4rem; padding: .2rem .6rem; font-size: .78rem; font-weight: 600;
    }
    .tk-card-right { text-align: center; flex-shrink: 0; }
    .tk-price { font-size: 2.5rem; font-weight: 900; color: #7c3aed; line-height: 1; }
    .tk-price-label { font-size: .8rem; color: #9ca3af; margin-top: .25rem; }
    .tk-buy-btn {
      display: block; margin-top: 1rem;
      background: #facc15; color: #000;
      font-weight: 800; font-size: .9rem;
      padding: .65rem 1.75rem; border-radius: 999px;
      border: none; cursor: pointer;
      transition: background .2s, transform .15s;
      white-space: nowrap;
    }
    .tk-buy-btn:hover { background: #fbbf24; transform: scale(1.04); }

    /* Qty + total */
    .tk-qty-box {
      background: #fff; border: 1px solid #e5e7eb;
      border-radius: 1rem; padding: 1.5rem 2rem;
      display: flex; align-items: center; gap: 1.5rem;
      margin-bottom: 1.5rem;
    }
    .tk-qty-box label { color: #6b7280; font-size: .9rem; font-weight: 600; }
    .tk-qty-box input[type=number] {
      background: #f9fafb; border: 1.5px solid #e5e7eb; color: #111827;
      border-radius: .6rem; padding: .6rem 1rem; font-size: 1rem; width: 110px;
    }
    .tk-qty-box input[type=number]:focus { border-color: #7c3aed; outline: none; }
    .tk-total-wrap { margin-left: auto; text-align: right; }
    .tk-total-label { font-size: .8rem; color: #9ca3af; }
    .tk-total-amount { font-size: 2rem; font-weight: 900; color: #7c3aed; }

    /* Continue btn */
    .tk-continue-btn {
      width: 100%; padding: 1rem; border-radius: 999px;
      background: #7c3aed; color: #fff; font-weight: 900; font-size: 1.05rem;
      border: none; cursor: pointer; transition: background .2s, transform .15s;
      letter-spacing: .02em;
    }
    .tk-continue-btn:hover { background: #6d28d9; transform: translateY(-1px); }

    /* Dark form card */
    .tk-form-card {
      background: #fff; border: 1px solid #e5e7eb;
      border-radius: 1rem; padding: 1.75rem 2rem; margin-bottom: 1.5rem;
    }
    .tk-form-card label { color: #374151; font-size: .88rem; font-weight: 600; display: block; margin-bottom: .35rem; }
    .tk-form-card input, .tk-form-card select {
      background: #f9fafb; border: 1.5px solid #e5e7eb; color: #111827;
      border-radius: .6rem; padding: .65rem 1rem; font-size: .95rem; width: 100%;
    }
    .tk-form-card input:focus, .tk-form-card select:focus { border-color: #7c3aed; outline: none; }
    .tk-form-card .form-group { margin-bottom: 1rem; }

    /* Summary mini card */
    .tk-summary {
      background: #faf5ff; border: 1px solid #e9d5ff;
      border-radius: 1rem; padding: 1.25rem 1.75rem;
      display: flex; justify-content: space-between; align-items: center;
      margin-bottom: 1.5rem;
    }
    .tk-summary-name { font-weight: 700; color: #111827; }
    .tk-summary-sub { color: #9ca3af; font-size: .85rem; }
    .tk-summary-price { font-size: 1.6rem; font-weight: 900; color: #7c3aed; }

    /* Action row */
    .tk-action-row { display: flex; gap: .75rem; }
    .tk-back-btn {
      flex: 1; padding: .85rem; border-radius: 999px;
      background: transparent; border: 2px solid #e5e7eb; color: #6b7280;
      font-weight: 700; font-size: .95rem; cursor: pointer; transition: border-color .2s, color .2s;
      text-align: center; text-decoration: none; display: flex; align-items: center; justify-content: center;
    }
    .tk-back-btn:hover { border-color: #7c3aed; color: #7c3aed; }
    .tk-primary-btn {
      flex: 1; padding: .85rem; border-radius: 999px;
      background: #7c3aed; color: #fff; font-weight: 900; font-size: .95rem;
      border: none; cursor: pointer; transition: background .2s;
    }
    .tk-primary-btn:hover { background: #6d28d9; }

    /* Payment step */
    .tk-payment-card {
      background: #fff; border: 1px solid #e5e7eb;
      border-radius: 1rem; padding: 2rem; text-align: center; margin-bottom: 1.5rem;
    }
    .tk-qr-frame {
      border: 3px solid #7c3aed; border-radius: 1rem;
      padding: .75rem; display: inline-block; margin-bottom: 1rem;
    }
    .tk-ref-num { font-size: 1.5rem; font-weight: 900; color: #7c3aed; margin-bottom: 1rem; }
    .tk-amount-box {
      background: #faf5ff; border: 1px solid #e9d5ff;
      border-radius: .75rem; padding: 1rem;
    }
    .tk-amount-box .lbl { font-size: .8rem; color: #9ca3af; }
    .tk-amount-box .val { font-size: 2.2rem; font-weight: 900; color: #7c3aed; }

    /* Countdown */
    .tk-countdown {
      background: #fef3c7; border: 1px solid #fcd34d;
      border-radius: .75rem; padding: .85rem 1.25rem;
      display: flex; align-items: center; gap: .75rem; margin-bottom: 1.25rem;
    }
    .tk-countdown .timer-text { font-weight: 700; color: #92400e; font-size: .95rem; }
    .tk-countdown .timer-sub { font-size: .8rem; color: #b45309; }
    .tk-countdown.urgent { background: #fee2e2; border-color: #fca5a5; }
    .tk-countdown.urgent .timer-text { color: #991b1b; }

    /* How to pay */
    .tk-how-to {
      background: #eff6ff; border: 1px solid #bfdbfe;
      border-radius: .75rem; padding: 1rem 1.25rem; margin-bottom: 1.5rem;
      font-size: .9rem; color: #1d4ed8;
    }

    /* Confirm step */
    .tk-confirm-card {
      background: #fff; border: 1px solid #e5e7eb;
      border-radius: 1rem; padding: 1.5rem 2rem; margin-bottom: 1.5rem;
    }
    .tk-confirm-row {
      display: flex; justify-content: space-between; align-items: center;
      padding: .6rem 0; border-bottom: 1px solid #f3f4f6;
    }
    .tk-confirm-row:last-child { border-bottom: none; }
    .tk-confirm-row .lbl { color: #9ca3af; font-size: .9rem; }
    .tk-confirm-row .val { color: #111827; font-weight: 600; font-size: .9rem; }

    /* Popup overlay */
    .tk-popup-overlay {
      position: fixed; inset: 0; background: rgba(0,0,0,.6);
      z-index: 9999; display: flex; align-items: center; justify-content: center; padding: 1rem;
    }
    .tk-popup {
      background: #fff; border-radius: 1.25rem;
      max-width: 560px; width: 100%;
      max-height: 90vh; overflow-y: auto; padding: 2rem;
      box-shadow: 0 20px 60px rgba(0,0,0,.2);
    }
    .tk-popup-header { text-align: center; margin-bottom: 1.5rem; }
    .tk-popup-header h2 { font-size: 1.6rem; font-weight: 900; color: #7c3aed; margin: .5rem 0 .25rem; }
    .tk-popup-header .ref { font-size: 1rem; font-weight: 700; color: #6b7280; letter-spacing: .05em; }
    .tk-popup-grid { display: grid; grid-template-columns: 1fr 1fr; gap: .4rem .75rem; font-size: .9rem; margin-bottom: 1rem; }
    .tk-popup-grid .lbl { color: #9ca3af; }
    .tk-popup-grid .val { color: #111827; font-weight: 600; }
    .tk-ticket-item {
      background: #f9fafb; border: 1px solid #e5e7eb;
      border-radius: .75rem; padding: .75rem; display: flex; align-items: center; gap: 1rem;
      margin-bottom: .75rem;
    }
    .tk-ticket-item img { width: 80px; height: 80px; border-radius: .4rem; flex-shrink: 0; }
    .tk-ticket-num { font-family: monospace; font-weight: 700; color: #7c3aed; font-size: .9rem; }

    @media (max-width: 600px) {
      .tk-card { flex-direction: column; align-items: flex-start; }
      .tk-card-right { width: 100%; display: flex; align-items: center; justify-content: space-between; }
      .tk-buy-btn { margin-top: 0; }
      .tk-popup-grid { grid-template-columns: 1fr; }
    }
  </style>
</head>
<body class="tickets-page">
<?php render_nav($user, 'tickets'); ?>

<!-- HERO -->
<div class="tk-hero">
  <div style="display:inline-flex;align-items:center;gap:.5rem;background:#f3e8ff;border:1px solid #e9d5ff;border-radius:999px;padding:.4rem 1.1rem;margin-bottom:1.25rem;color:#7c3aed;font-size:.88rem;font-weight:600;">
    🎟 Online Booking — Skip the Queue
  </div>
  <h1>BUY TICKETS <span>NOW!</span> 🎢🎡🎠</h1>
  <p>Get instant access to all rides. Book online and enjoy a seamless park experience.</p>
</div>

<div class="tk-wrap">

  <?php if ($flash && ($flash['message'] ?? '') !== ''): ?>
    <div class="tk-flash <?= ($flash['type'] ?? '') === 'error' ? 'error' : 'success' ?>">
      <?= ($flash['type'] ?? '') === 'error' ? '⚠ ' : '✅ ' ?><?= e($flash['message']) ?>
    </div>
  <?php endif; ?>

  <!-- STEPPER -->
  <div class="tk-stepper">
    <?php
      $steps = ['Select','Details','Payment','Confirm'];
      foreach ($steps as $i => $lbl):
        $cls = $step === $i ? 'active' : ($step > $i ? 'done' : '');
    ?>
      <div class="tk-step <?= $cls ?>">
        <div class="tk-step-num"><?= $step > $i ? '✓' : ($i + 1) ?></div>
        <div class="tk-step-label"><?= $lbl ?></div>
      </div>
      <?php if ($i < 3): ?>
        <div class="tk-step-line <?= $step > $i ? 'done' : '' ?>"></div>
      <?php endif; ?>
    <?php endforeach; ?>
  </div>

  <!-- ═══════════════════════════════════════════════════════ -->
  <!-- STEP 0 — SELECT TICKET                                  -->
  <!-- ═══════════════════════════════════════════════════════ -->
  <?php if ($step === 0): ?>
    <div style="margin-bottom:1.5rem;">
      <div style="font-size:1.6rem;font-weight:900;color:#111827;margin-bottom:.35rem;">Choose Your Ticket</div>
      <div style="color:#6b7280;font-size:.95rem;">Select a ticket type and quantity to get started</div>
    </div>

    <?php if (!count($types)): ?>
      <div style="text-align:center;padding:4rem 2rem;color:#475569;">
        <div style="font-size:3rem;margin-bottom:1rem;">🎟</div>
        <div style="font-size:1.1rem;">No tickets available right now. Check back soon!</div>
      </div>
    <?php else: ?>
      <form method="post" id="ticket-form">
        <input type="hidden" name="action" value="select" />
<<<<<<< HEAD
=======
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
>>>>>>> 944246f7d1f7012ed1c7107d999e7fdfb8af41b5

        <?php foreach ($types as $t):
          $price = (float)$t['price'];
          $tid   = (int)$t['id'];
          $rides = $typeRides[$tid] ?? [];
          $isSelected = $tid === $selectedId;
        ?>
          <div class="tk-card <?= $isSelected ? 'selected' : '' ?>"
               onclick="selectTicket(<?= $tid ?>, <?= $price ?>)">
            <div class="tk-card-left">
              <div class="tk-card-name"><?= e($t['name']) ?></div>
              <div class="tk-card-desc"><?= e($t['description'] ?? 'Full day access to all included rides') ?></div>
              <?php if (isset($t['max_rides']) && $t['max_rides'] !== null && $t['max_rides'] !== ''): ?>
                <div style="color:#7c3aed;font-size:.85rem;font-weight:700;margin-bottom:.5rem;">🎢 <?= (int)$t['max_rides'] ?> rides included</div>
              <?php else: ?>
                <div style="color:#7c3aed;font-size:.85rem;font-weight:700;margin-bottom:.5rem;">🎢 Unlimited rides</div>
              <?php endif; ?>
              <?php if (count($rides) > 0): ?>
                <div style="font-size:.78rem;color:#64748b;font-weight:600;margin-bottom:.4rem;">INCLUDED RIDES:</div>
                <div class="tk-card-rides">
                  <?php foreach ($rides as $rn): ?>
                    <span class="tk-ride-badge"><?= e($rn) ?></span>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>
              <input type="radio" id="radio-<?= $tid ?>" name="ticket_type_id" value="<?= $tid ?>"
                     data-price="<?= $price ?>" <?= $isSelected ? 'checked' : '' ?>
                     style="display:none;" />
            </div>
            <div class="tk-card-right">
              <div class="tk-price">₱<?= number_format($price, 0) ?></div>
              <div class="tk-price-label">per person</div>
              <button type="button" class="tk-buy-btn" onclick="event.stopPropagation();selectTicket(<?= $tid ?>, <?= $price ?>)">
                BUY TICKETS
              </button>
            </div>
          </div>
        <?php endforeach; ?>

        <div class="tk-qty-box">
          <div>
            <label>Quantity</label>
            <input type="number" name="quantity" id="ticket-qty" min="1" value="<?= max(1, $selectedQty) ?>" />
          </div>
          <div class="tk-total-wrap">
            <div class="tk-total-label">Total</div>
            <div class="tk-total-amount" id="ticket-total">
              ₱<?= $selectedType ? number_format(((float)$selectedType['price']) * max(1, $selectedQty), 0) : '0' ?>
            </div>
          </div>
        </div>

        <button class="tk-continue-btn" type="submit">Continue to Details →</button>
      </form>

      <script>
      (function() {
        var form = document.getElementById('ticket-form');
        var totalEl = document.getElementById('ticket-total');
        var qtyInput = document.getElementById('ticket-qty');
<<<<<<< HEAD

        window.selectTicket = function(tid, price) {
          var radio = document.getElementById('radio-' + tid);
          if (radio) radio.checked = true;
          document.querySelectorAll('.tk-card').forEach(function(c) { c.classList.remove('selected'); });
          var card = radio ? radio.closest('.tk-card') : null;
          if (card) card.classList.add('selected');
          updateTotal();
        };

        function updateTotal() {
=======
        function formatNum(n) { return '₱' + Math.round(n).toLocaleString(); }
        window.updateTicketTotal = function() {
>>>>>>> 944246f7d1f7012ed1c7107d999e7fdfb8af41b5
          var radio = form.querySelector('input[name="ticket_type_id"]:checked');
          var qty = Math.max(1, parseInt(qtyInput.value, 10) || 1);
          qtyInput.value = qty;
          if (!radio || !totalEl) return;
          var price = parseFloat(radio.getAttribute('data-price')) || 0;
<<<<<<< HEAD
          totalEl.textContent = '₱' + Math.round(price * qty).toLocaleString();
        }

        window.updateTicketTotal = updateTotal;
        if (qtyInput) { qtyInput.addEventListener('input', updateTotal); qtyInput.addEventListener('change', updateTotal); }
        updateTotal();
=======
          totalEl.textContent = formatNum(price * qty);
        };
        form.querySelectorAll('input[name="ticket_type_id"]').forEach(function(r) {
          r.addEventListener('change', updateTicketTotal);
        });
        if (qtyInput) qtyInput.addEventListener('input', updateTicketTotal);
        if (qtyInput) qtyInput.addEventListener('change', updateTicketTotal);
        updateTicketTotal();
>>>>>>> 944246f7d1f7012ed1c7107d999e7fdfb8af41b5
      })();
      </script>
    <?php endif; ?>
  <?php endif; ?>

  <!-- ═══════════════════════════════════════════════════════ -->
  <!-- STEP 1 — DETAILS + RIDE SELECTION                       -->
  <!-- ═══════════════════════════════════════════════════════ -->
  <?php if ($step === 1): ?>
    <?php
      $maxRides1 = ($selectedType && isset($selectedType['max_rides']) && $selectedType['max_rides'] !== null && $selectedType['max_rides'] !== '')
          ? (int)$selectedType['max_rides'] : null;
      $hasRideList = count($step1Rides) > 0;
      $prevSelectedRideIds = $flow['selected_ride_ids'] ?? [];
    ?>
    <div style="margin-bottom:1.5rem;">
      <div style="font-size:1.6rem;font-weight:900;color:#111827;margin-bottom:.35rem;">Your Details</div>
      <div style="color:#6b7280;font-size:.95rem;">Fill in your info and choose your rides</div>
    </div>

    <form method="post" id="details-form">
      <input type="hidden" name="action" value="details" />

      <!-- Personal info -->
      <div class="tk-form-card">
        <div class="form-group"><label>Full Name *</label>
          <input name="customer_name" value="<?= e($user['full_name'] ?? '') ?>" placeholder="Juan dela Cruz" required /></div>
        <div class="form-group"><label>Email Address *</label>
          <input name="customer_email" type="email" value="<?= e($user['email'] ?? '') ?>" placeholder="juan@email.com" required /></div>
        <div class="form-group"><label>Phone Number</label>
          <input name="customer_phone" value="<?= e($user['phone'] ?? '') ?>" placeholder="+63 9XX XXX XXXX" /></div>
        <div class="form-group"><label>Visit Date *</label>
          <input name="visit_date" type="date" min="<?= e(today_ymd()) ?>" required /></div>
      </div>

      <!-- Ride selection -->
      <?php if ($hasRideList): ?>
        <div class="tk-form-card" style="margin-bottom:1.5rem;">
          <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem;flex-wrap:wrap;gap:.5rem;">
            <div>
              <div style="font-size:1.05rem;font-weight:800;color:#111827;">🎢 Select Your Rides</div>
              <?php if ($maxRides1 !== null): ?>
                <div style="font-size:.85rem;color:#7c3aed;font-weight:600;margin-top:.2rem;">
                  Pick up to <strong><?= $maxRides1 ?></strong> ride<?= $maxRides1 === 1 ? '' : 's' ?> included in your package
                </div>
              <?php else: ?>
                <div style="font-size:.85rem;color:#7c3aed;font-weight:600;margin-top:.2rem;">
                  All rides below are included — select the ones you want
                </div>
              <?php endif; ?>
            </div>
            <?php if ($maxRides1 !== null): ?>
              <div id="ride-counter" style="background:#faf5ff;border:1.5px solid #e9d5ff;border-radius:.6rem;padding:.4rem .9rem;font-size:.88rem;font-weight:700;color:#7c3aed;">
                0 / <?= $maxRides1 ?> selected
              </div>
            <?php endif; ?>
          </div>

          <div style="border:1.5px solid #e9d5ff;border-radius:.75rem;overflow:hidden;">
            <?php foreach ($step1Rides as $idx => $r):
              $rId     = (int)$r['id'];
              $isOpen  = ($r['status'] ?? 'Open') === 'Open';
              $checked = in_array($rId, $prevSelectedRideIds, true);
              $dotColor = $isOpen ? '#16a34a' : '#dc2626';
              $catColors = ['Thrill'=>'#fee2e2','Family'=>'#dcfce7','Kids'=>'#f3e8ff','Water'=>'#dbeafe','Classic'=>'#f1f5f9'];
              $catColor = $catColors[$r['category'] ?? ''] ?? '#f9fafb';
            ?>
              <label class="ride-row <?= !$isOpen ? 'ride-row-disabled' : '' ?>"
                     style="display:flex;align-items:center;gap:.85rem;padding:.85rem 1.1rem;
                            cursor:<?= $isOpen ? 'pointer' : 'not-allowed' ?>;
                            border-bottom:<?= $idx < count($step1Rides)-1 ? '1px solid #f3e8ff' : 'none' ?>;
                            background:#fff;transition:background .15s;"
                     onmouseover="if(<?= $isOpen ? 'true' : 'false' ?>)this.style.background='#faf5ff'"
                     onmouseout="this.style.background='#fff'">
                <input type="checkbox"
                       name="selected_ride_ids[]"
                       value="<?= $rId ?>"
                       <?= $checked ? 'checked' : '' ?>
                       <?= !$isOpen ? 'disabled' : '' ?>
                       onchange="updateRideCounter()"
                       style="width:17px;height:17px;accent-color:#7c3aed;flex-shrink:0;cursor:<?= $isOpen ? 'pointer' : 'not-allowed' ?>;" />
                <span style="width:8px;height:8px;border-radius:50%;background:<?= $dotColor ?>;flex-shrink:0;"></span>
                <span style="flex:1;font-size:.92rem;font-weight:600;color:<?= $isOpen ? '#111827' : '#9ca3af' ?>;
                             <?= !$isOpen ? 'text-decoration:line-through;' : '' ?>">
                  <?= e($r['name']) ?>
                </span>
                <span style="font-size:.72rem;font-weight:700;padding:.2rem .55rem;border-radius:.35rem;background:<?= $catColor ?>;color:#374151;">
                  <?= e($r['category'] ?? '') ?>
                </span>
                <?php if (!$isOpen): ?>
                  <span style="font-size:.72rem;color:#dc2626;font-weight:600;"><?= e($r['status'] ?? 'Closed') ?></span>
                <?php endif; ?>
              </label>
            <?php endforeach; ?>
          </div>

          <?php if ($maxRides1 !== null): ?>
            <div id="ride-limit-warn" style="display:none;margin-top:.75rem;padding:.6rem 1rem;background:#fee2e2;border-radius:.5rem;font-size:.85rem;color:#991b1b;font-weight:600;">
              ⚠ You can only select up to <?= $maxRides1 ?> ride<?= $maxRides1 === 1 ? '' : 's' ?> for this package.
            </div>
          <?php endif; ?>
        </div>

        <script>
        (function() {
          var max = <?= $maxRides1 !== null ? $maxRides1 : 'null' ?>;
          function updateRideCounter() {
            var boxes = document.querySelectorAll('input[name="selected_ride_ids[]"]:not(:disabled)');
            var checked = document.querySelectorAll('input[name="selected_ride_ids[]"]:checked').length;
            var counter = document.getElementById('ride-counter');
            var warn    = document.getElementById('ride-limit-warn');
            if (counter) counter.textContent = checked + ' / ' + max + ' selected';
            if (warn) warn.style.display = (max !== null && checked > max) ? 'block' : 'none';
            if (max !== null) {
              boxes.forEach(function(cb) {
                if (!cb.checked) cb.disabled = checked >= max;
              });
              if (checked < max) {
                boxes.forEach(function(cb) { cb.disabled = false; });
              }
            }
          }
          window.updateRideCounter = updateRideCounter;
          updateRideCounter();
        })();
        </script>
      <?php endif; ?>

      <!-- Package summary -->
      <?php if ($selectedType): ?>
        <div class="tk-summary">
          <div>
            <div class="tk-summary-name"><?= e((string)$selectedType['name']) ?> × <?= (int)$selectedQty ?></div>
            <div class="tk-summary-sub">
              <?php if ($maxRides1 !== null): ?>
                Up to <?= $maxRides1 ?> rides included
              <?php elseif ($hasRideList): ?>
                <?= count($step1Rides) ?> rides available
              <?php else: ?>
                Unlimited rides
              <?php endif; ?>
            </div>
          </div>
          <div class="tk-summary-price">₱<?= number_format(((float)$selectedType['price']) * max(1, $selectedQty), 0) ?></div>
        </div>
      <?php endif; ?>

      <div class="tk-action-row">
        <a class="tk-back-btn" href="tickets.php">← Back</a>
        <button class="tk-primary-btn" type="submit"
                onclick="return validateRides(<?= $maxRides1 !== null ? $maxRides1 : 'null' ?>, <?= $hasRideList ? 'true' : 'false' ?>)">
          Continue to Payment →
        </button>
      </div>
    </form>

    <script>
    function validateRides(max, hasRideList) {
      if (!hasRideList) return true;
      var checked = document.querySelectorAll('input[name="selected_ride_ids[]"]:checked').length;
      if (checked === 0) {
        alert('Please select at least one ride before continuing.');
        return false;
      }
      if (max !== null && checked > max) {
        alert('You can only select up to ' + max + ' rides for this package.');
        return false;
      }
      return true;
    }
    </script>
  <?php endif; ?>

  <!-- ═══════════════════════════════════════════════════════ -->
  <!-- STEP 2 — PAYMENT                                        -->
  <!-- ═══════════════════════════════════════════════════════ -->
  <?php if ($step === 2 && $booking): ?>
    <?php
<<<<<<< HEAD
      $createdAt   = strtotime((string)($booking['created_at'] ?? 'now'));
      $expiresAt   = $createdAt + 180;
      $secondsLeft = max(0, $expiresAt - time());
    ?>
    <div style="margin-bottom:1.5rem;">
      <div style="font-size:1.6rem;font-weight:900;color:#111827;margin-bottom:.35rem;">Pay via QR Ph</div>
      <div style="color:#6b7280;font-size:.95rem;">Scan the QR code with GCash, Maya, or any QR Ph banking app</div>
    </div>

    <div class="tk-countdown" id="expiry-banner">
      <span style="font-size:1.4rem;">⏱</span>
      <div>
        <div class="timer-text">Complete payment within <span id="countdown-display"><?= gmdate('i:s', $secondsLeft) ?></span></div>
        <div class="timer-sub">Booking will be automatically cancelled if unpaid</div>
=======
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
>>>>>>> 944246f7d1f7012ed1c7107d999e7fdfb8af41b5
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
<<<<<<< HEAD
          banner.classList.add('urgent');
          window.location.href = 'tickets.php?expired=1';
          return;
        }
        var m = Math.floor(secs / 60), s = secs % 60;
        el.textContent = m + ':' + (s < 10 ? '0' : '') + s;
        if (secs <= 30) banner.classList.add('urgent');
      }, 1000);
    })();
    </script>

    <div class="tk-payment-card">
      <div class="tk-qr-frame">
=======
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
>>>>>>> 944246f7d1f7012ed1c7107d999e7fdfb8af41b5
        <img src="https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=<?= e(urlencode((string)($booking['qr_code_data'] ?? $booking['booking_reference']))) ?>"
             alt="QR Code" style="width:200px;height:200px;display:block;" />
      </div>
      <div style="color:#64748b;font-size:.85rem;margin-bottom:.35rem;">Reference Number</div>
      <div class="tk-ref-num"><?= e($booking['booking_reference'] ?? '') ?></div>
      <div class="tk-amount-box">
        <div class="lbl">Amount to Pay</div>
        <div class="val">₱<?= number_format((float)($booking['total_amount'] ?? 0), 0) ?></div>
      </div>
      <div style="margin-top:.75rem;font-size:.8rem;color:#475569;">This QR expires in 15 minutes. Powered by PayMongo.</div>
    </div>

    <div class="tk-how-to">
      <strong>How to pay:</strong> Open GCash / Maya or any QR Ph bank app → Scan QR → Confirm payment → Click "I've Paid" below
    </div>
<<<<<<< HEAD

    <div class="tk-action-row">
      <form method="post" style="flex:1;">
=======
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
>>>>>>> 944246f7d1f7012ed1c7107d999e7fdfb8af41b5
        <input type="hidden" name="action" value="reset" />
        <button class="tk-back-btn" type="submit" style="width:100%;">← Start Over</button>
      </form>
      <form method="post" style="flex:1;">
        <input type="hidden" name="action" value="confirm_payment" />
        <button class="tk-primary-btn" type="submit" style="background:#16a34a;color:#fff;">✅ I've Paid – Confirm</button>
      </form>
    </div>
  <?php endif; ?>

  <!-- ═══════════════════════════════════════════════════════ -->
  <!-- STEP 3 — CONFIRMATION                                   -->
  <!-- ═══════════════════════════════════════════════════════ -->
  <?php if ($step === 3 && $booking): ?>
    <?php $paymentDatetime = !empty($booking['updated_at']) ? $booking['updated_at'] : date('Y-m-d H:i:s'); ?>

    <!-- Confirmation Popup -->
    <div class="tk-popup-overlay" id="ref-popup">
      <div class="tk-popup">
        <div class="tk-popup-header">
          <div style="font-size:2.5rem;">🎉</div>
          <h2>Booking Confirmed!</h2>
          <div class="ref"><?= e($booking['booking_reference'] ?? '') ?></div>
        </div>

        <div style="border-top:1px solid #1e293b;padding-top:1rem;margin-bottom:1rem;">
          <div class="tk-popup-grid">
            <div class="lbl">Customer</div><div class="val"><?= e($booking['customer_name'] ?? '') ?></div>
            <div class="lbl">Email</div><div class="val"><?= e($booking['customer_email'] ?? '') ?></div>
            <div class="lbl">Phone</div><div class="val"><?= e($booking['customer_phone'] ?? '') ?></div>
            <div class="lbl">Ticket Type</div><div class="val"><?= e($booking['ticket_type_name'] ?? '') ?></div>
            <div class="lbl">Quantity</div><div class="val"><?= (int)($booking['quantity'] ?? 1) ?></div>
            <div class="lbl">Visit Date</div><div class="val"><?= e((string)($booking['visit_date'] ?? '')) ?></div>
            <div class="lbl">Payment Time</div><div class="val"><?= e($paymentDatetime) ?></div>
            <div class="lbl">Total Paid</div><div class="val" style="color:#16a34a;font-weight:800;">₱<?= number_format((float)($booking['total_amount'] ?? 0), 0) ?></div>
          </div>
        </div>

        <?php
          $displayRides = count($flow['selected_ride_names'] ?? []) > 0
              ? $flow['selected_ride_names']
              : $popupRides;
        ?>
        <?php if (count($displayRides) > 0): ?>
          <div style="margin-bottom:1rem;">
            <div style="font-weight:700;font-size:.85rem;color:#94a3b8;margin-bottom:.5rem;text-transform:uppercase;letter-spacing:.05em;">Selected Rides</div>
            <div style="display:flex;flex-wrap:wrap;gap:.35rem;">
              <?php foreach ($displayRides as $rn): ?>
                <span class="tk-ride-badge"><?= e($rn) ?></span>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endif; ?>

        <?php if (count($popupTickets) > 0): ?>
          <div style="margin-bottom:1.5rem;">
            <div style="font-weight:700;font-size:.85rem;color:#94a3b8;margin-bottom:.75rem;text-transform:uppercase;letter-spacing:.05em;">Your Tickets</div>
            <?php foreach ($popupTickets as $tn): ?>
              <div class="tk-ticket-item">
                <img src="https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=<?= e(urlencode($tn)) ?>"
                     alt="QR <?= e($tn) ?>" />
                <div>
                  <div style="font-size:.75rem;color:#64748b;margin-bottom:.2rem;">Ticket Number</div>
                  <div class="tk-ticket-num"><?= e($tn) ?></div>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>

        <button onclick="document.getElementById('ref-popup').style.display='none'"
                class="tk-continue-btn" style="font-size:1rem;">Got it — View Summary</button>
      </div>
    </div>

    <!-- Page summary (behind popup) -->
    <div style="text-align:center;margin-bottom:2rem;">
      <div style="width:80px;height:80px;background:#f3e8ff;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 1.25rem;font-size:2.5rem;">✅</div>
      <div style="font-size:2rem;font-weight:900;color:#111827;margin-bottom:.5rem;">Booking Confirmed!</div>
      <div style="color:#6b7280;">Your QR ticket is ready. Show it at the park entrance.</div>
    </div>

    <div class="tk-confirm-card">
      <div class="tk-confirm-row"><span class="lbl">Booking Ref</span><span class="val" style="color:#7c3aed;font-weight:800;"><?= e($booking['booking_reference'] ?? '') ?></span></div>
      <div class="tk-confirm-row"><span class="lbl">Customer</span><span class="val"><?= e($booking['customer_name'] ?? '') ?></span></div>
      <div class="tk-confirm-row"><span class="lbl">Ticket</span><span class="val"><?= e($booking['ticket_type_name'] ?? '') ?> × <?= (int)($booking['quantity'] ?? 1) ?></span></div>
      <div class="tk-confirm-row"><span class="lbl">Visit Date</span><span class="val"><?= e((string)($booking['visit_date'] ?? '')) ?></span></div>
      <div class="tk-confirm-row"><span class="lbl">Total Paid</span><span class="val" style="color:#16a34a;font-size:1.2rem;font-weight:900;">₱<?= number_format((float)($booking['total_amount'] ?? 0), 0) ?></span></div>
    </div>

    <div class="tk-payment-card" style="margin-bottom:1.5rem;">
      <div style="color:#64748b;font-size:.85rem;margin-bottom:.75rem;">Your Entry QR Code</div>
      <div class="tk-qr-frame">
        <img src="https://api.qrserver.com/v1/create-qr-code/?size=220x220&data=<?= e(urlencode((string)($booking['qr_code_data'] ?? $booking['booking_reference']))) ?>"
             style="width:220px;height:220px;display:block;" alt="Entry QR" />
      </div>
      <p style="font-size:.85rem;color:#475569;margin-top:.75rem;">Present this at the park entrance</p>
    </div>

    <form method="post">
      <input type="hidden" name="action" value="reset" />
      <button class="tk-continue-btn" type="submit">Book Another Ticket</button>
    </form>
  <?php endif; ?>

</div><!-- /.tk-wrap -->
<?php render_footer(); ?>
</body>
</html>
