<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/layout.php';
require_once __DIR__ . '/lib/paymongo.php';
require_once __DIR__ . '/lib/mailer.php';

if (file_exists(__DIR__ . '/cron/expire_bookings.php')) {
    require_once __DIR__ . '/cron/expire_bookings.php';
}

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

// Handle expired booking redirect
if (isset($_GET['expired']) && (int)$_GET['expired'] === 1) {
    reset_booking_flow();
    flash_set('error', 'Your booking has expired. Please start a new booking.');
    redirect('tickets.php');
}

// Handle resume token from abandoned payment email
if (isset($_GET['resume']) && $_GET['resume'] !== '') {
    $token = (string)$_GET['resume'];
    $st = $pdo->prepare(
        'SELECT * FROM bookings WHERE resume_token = ? LIMIT 1'
    );
    $st->execute([$token]);
    $resumeBooking = $st->fetch();

    if (!$resumeBooking) {
        // Token not found
        flash_set('error', '❌ This payment link is invalid or has already been used.');
        redirect('tickets.php');
    }

    $resumeExpires = strtotime((string)($resumeBooking['resume_expires'] ?? '0'));
    if (time() > $resumeExpires) {
        // Token expired — clear it and show expired notice
        $pdo->prepare('UPDATE bookings SET resume_token = NULL, resume_expires = NULL WHERE id = ?')
            ->execute([(int)$resumeBooking['id']]);
        // Show expired page inline
        require_once __DIR__ . '/lib/layout.php';
        ?><!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Link Expired – AmusePark</title>
  <link rel="stylesheet" href="css/style.css">
  <style>
    body { background:#f1f5f9; font-family:'Poppins',sans-serif; }
    .exp-wrap { max-width:520px; margin:6rem auto; padding:0 1.5rem; text-align:center; }
    .exp-card { background:#fff; border-radius:1.5rem; padding:3rem 2.5rem; box-shadow:0 10px 30px rgba(0,0,0,.08); border:1px solid #e2e8f0; }
    .exp-icon { font-size:4rem; margin-bottom:1rem; }
    .exp-title { font-size:1.6rem; font-weight:900; color:#dc2626; margin:0 0 .75rem; }
    .exp-sub { color:#64748b; font-size:.95rem; line-height:1.7; margin:0 0 2rem; }
    .exp-ref { background:#fee2e2; border:1px solid #fca5a5; border-radius:.75rem; padding:.75rem 1.5rem; font-family:monospace; font-weight:800; color:#991b1b; font-size:1rem; margin-bottom:2rem; display:inline-block; }
    .exp-btn { display:inline-block; background:#1e3a8a; color:#fff; padding:.9rem 2.5rem; border-radius:999px; font-weight:900; text-decoration:none; font-size:1rem; }
    .exp-btn:hover { background:#172554; }
  </style>
</head>
<body>
<?php render_nav($user, ''); ?>
<div class="exp-wrap">
  <div class="exp-card">
    <div class="exp-icon">⏰</div>
    <div class="exp-title">Payment Link Expired</div>
    <p class="exp-sub">
      This payment link was only valid for <strong>3 minutes</strong> and has now expired.<br>
      Your booking <span class="exp-ref"><?= e((string)($resumeBooking['booking_reference'] ?? '')) ?></span> has been cancelled.
    </p>
    <p class="exp-sub" style="margin-bottom:2rem;">
      Don't worry — you can start a fresh booking right now. Your preferred date may still be available.
    </p>
    <a href="tickets.php" class="exp-btn">🎟 Start New Booking</a>
  </div>
</div>
<?php render_footer(); ?>
</body></html>
        <?php
        exit;
    }

    // Token valid — restore session and go to step 2
    $pdo->prepare('UPDATE bookings SET resume_token = NULL, resume_expires = NULL WHERE id = ?')
        ->execute([(int)$resumeBooking['id']]);

    $_SESSION['booking_flow'] = [
        'booking_id'      => (int)$resumeBooking['id'],
        'ticket_type_id'  => (int)$resumeBooking['ticket_type_id'],
        'quantity'        => (int)$resumeBooking['quantity'],
    ];
    redirect('tickets.php?step=2');
}

// AJAX: poll payment intent status
if (isset($_GET['poll_intent']) && $_GET['poll_intent'] !== '') {
    header('Content-Type: application/json');
    $intentId = (string)$_GET['poll_intent'];
    if (PAYMONGO_SECRET_KEY !== '' && preg_match('/^pi_[a-zA-Z0-9]+$/', $intentId)) {
        $data   = paymongo_get_payment_intent($intentId);
        $status = (string)($data['data']['attributes']['status'] ?? 'unknown');
        // If succeeded, mark booking as paid
        if ($status === 'succeeded') {
            $st = $pdo->prepare('SELECT id FROM bookings WHERE paymongo_intent_id = ? AND payment_status = ? LIMIT 1');
            $st->execute([$intentId, 'Pending']);
            $bk = $st->fetch();
            if ($bk) {
                $pdo->prepare("UPDATE bookings SET payment_status = 'Paid', payment_reference = ? WHERE id = ?")
                    ->execute(['PAYMONGO-' . $intentId, (int)$bk['id']]);
            }
        }
        echo json_encode(['status' => $status]);
    } else {
        echo json_encode(['status' => 'unknown']);
    }
    exit;
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

    // Advance to next cart item after confirming current booking
    if ($action === 'next_cart_item') {
        $cartQueue = $flow['cart_queue'] ?? [];
        $cartTotal = (int)($flow['cart_total'] ?? 0);
        $cartIndex = (int)($flow['cart_index'] ?? 0);
        if (!empty($cartQueue)) {
            $next = array_shift($cartQueue);
            $_SESSION['booking_flow'] = [
                'ticket_type_id' => $next['ticket_type_id'],
                'quantity'       => $next['quantity'],
                'cart_queue'     => $cartQueue,
                'cart_total'     => $cartTotal,
                'cart_index'     => $cartIndex + 1,
            ];
            redirect('tickets.php?step=1');
        }
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

        // Validate ride selection
        $maxRidesPerTicket = (isset($type['max_rides']) && $type['max_rides'] !== null && $type['max_rides'] !== '')
            ? (int)$type['max_rides'] : null;
        // Total rides allowed = per-ticket rides × quantity
        $maxRides = $maxRidesPerTicket !== null ? $maxRidesPerTicket * max(1, $qty) : null;

        // Get all valid ride IDs from the rides table
        $allRideIds = [];
        try {
            $allRideIds = array_map('intval',
                $pdo->query('SELECT id FROM rides')->fetchAll(\PDO::FETCH_COLUMN)
            );
        } catch (\Throwable $e) {}

        $selectedRideIds = [];
        if (isset($_POST['selected_ride_ids']) && is_array($_POST['selected_ride_ids'])) {
            $selectedRideIds = array_map('intval', $_POST['selected_ride_ids']);
            // Only keep valid ride IDs
            $selectedRideIds = array_values(array_filter($selectedRideIds, fn($id) => in_array($id, $allRideIds, true)));
        }

        // If ticket has a max_rides limit, require at least one selection
        if ($maxRides !== null && count($selectedRideIds) === 0) {
            flash_set('error', 'Please select at least one ride for your package.');
            redirect('tickets.php?step=1');
        }
        // Enforce total max_rides limit (per-ticket × quantity)
        if ($maxRides !== null && count($selectedRideIds) > $maxRides) {
            flash_set('error', 'You can only select up to ' . $maxRides . ' rides total (' . $maxRidesPerTicket . ' per ticket × ' . $qty . ' tickets).');
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
        $expiresAt = date('Y-m-d H:i:s', time() + 180); // 3-minute deadline
        $ridesSuffix = count($selectedRideNames) > 0 ? '|RIDES:' . implode(',', $selectedRideNames) : '';
        $qrData    = 'AMUSEPARK|' . $ref . '|' . $name . '|' . $visitDate . '|' . ($type['name'] ?? '') . 'x' . $qty . $ridesSuffix;
        $ins = $pdo->prepare(
            'INSERT INTO bookings (booking_reference, user_id, customer_name, customer_email, customer_phone, visit_date,
                ticket_type_id, ticket_type_name, quantity, unit_price, total_amount, payment_status, payment_method, qr_code_data, status, expires_at, payment_deadline)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
        );
        $ins->execute([$ref, (int)$user['id'], $name, $email, $phone, $visitDate,
            (int)$type['id'], (string)$type['name'], max(1, $qty), $unitPrice, $total,
            'Pending', 'QR Ph', $qrData, 'Active', $expiresAt, $expiresAt]);
        $bookingId = (int)$pdo->lastInsertId();

        // Insert individual tickets
        try {
            $insTicket = $pdo->prepare('INSERT INTO tickets (booking_id, ticket_number, status) VALUES (?, ?, ?)');
            for ($i = 1; $i <= max(1, $qty); $i++) {
                $ticketNumber = 'TK-' . $ref . '-' . str_pad((string)$i, 3, '0', STR_PAD_LEFT);
                $insTicket->execute([$bookingId, $ticketNumber, 'ACTIVE']);
            }
        } catch (\Throwable $e) {}

        // Store selected rides in relational booking_rides table
        if (count($selectedRideIds) > 0) {
            try {
                $insRide = $pdo->prepare('INSERT IGNORE INTO booking_rides (booking_id, ride_id) VALUES (?, ?)');
                foreach ($selectedRideIds as $rideId) {
                    $insRide->execute([$bookingId, $rideId]);
                }
            } catch (\Throwable $e) {}
        }

        $flow['booking_id']          = $bookingId;
        $flow['selected_ride_ids']   = $selectedRideIds;
        $flow['selected_ride_names'] = $selectedRideNames;
        $_SESSION['booking_flow'] = $flow;

        // Generate PayMongo QR Ph code immediately
        if (PAYMONGO_SECRET_KEY !== '') {
            $amountCentavos = (int)round($total * 100);
            $description    = 'AmusePark ' . ($type['name'] ?? 'Ticket') . ' x' . max(1, $qty);
            $qrResult = paymongo_create_qrph(
                $amountCentavos, $description,
                $name, $email, $phone,
                ['booking_reference' => $ref, 'booking_id' => (string)$bookingId]
            );
            if ($qrResult['success']) {
                $pdo->prepare('UPDATE bookings SET paymongo_intent_id = ?, paymongo_qr_image = ?, paymongo_qr_code_id = ? WHERE id = ?')
                    ->execute([$qrResult['payment_intent_id'], $qrResult['qr_image_url'], $qrResult['qr_code_id'], $bookingId]);
                $flow['paymongo_intent_id'] = $qrResult['payment_intent_id'];
                $_SESSION['booking_flow'] = $flow;
            }
        }

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

        // If already marked Paid (e.g. by the polling script), skip re-checking PayMongo
        if (($booking['payment_status'] ?? '') === 'Paid') {
            redirect('tickets.php?step=3');
        }

        // Check PayMongo payment intent status
        $intentId = (string)($booking['paymongo_intent_id'] ?? '');
        $paid = false;
        if ($intentId !== '' && PAYMONGO_SECRET_KEY !== '') {
            $intentData = paymongo_get_payment_intent($intentId);
            $piStatus   = (string)($intentData['data']['attributes']['status'] ?? '');
            if ($piStatus === 'succeeded') {
                $paid = true;
                $payments = $intentData['data']['attributes']['payments'] ?? [];
                $pmRef = !empty($payments) ? (string)($payments[0]['attributes']['external_reference_number'] ?? '') : '';
                $pdo->prepare('UPDATE bookings SET payment_status = ?, payment_reference = ? WHERE id = ?')
                    ->execute(['Paid', $pmRef ?: ('PAYMONGO-' . $intentId), $bookingId]);
            }
        }

        if (!$paid) {
            if (PAYMONGO_SECRET_KEY === '') {
                $pdo->prepare('UPDATE bookings SET payment_status = ?, payment_reference = ? WHERE id = ?')
                    ->execute(['Paid', 'DEMO-' . time(), $bookingId]);
                $paid = true;
            } elseif (defined('PAYMONGO_DEV_BYPASS') && PAYMONGO_DEV_BYPASS === true) {
                $pdo->prepare('UPDATE bookings SET payment_status = ?, payment_reference = ? WHERE id = ?')
                    ->execute(['Paid', 'DEV-BYPASS-' . time(), $bookingId]);
                $paid = true;
            } else {
                flash_set('error', 'Payment not yet confirmed. Please complete the QR Ph payment first.');
                redirect('tickets.php?step=2');
            }
        }

        // Send booking confirmation email
        $freshBooking = $pdo->prepare('SELECT * FROM bookings WHERE id = ?');
        $freshBooking->execute([$bookingId]);
        $confirmedBooking = $freshBooking->fetch();
        if ($confirmedBooking) {
            $ticketRows = [];
            try {
                $tSt = $pdo->prepare('SELECT ticket_number FROM tickets WHERE booking_id = ? ORDER BY ticket_number ASC');
                $tSt->execute([$bookingId]);
                $ticketRows = $tSt->fetchAll(\PDO::FETCH_COLUMN);
            } catch (\Throwable $e) {}
            $rideNames = $flow['selected_ride_names'] ?? [];
            send_booking_confirmation_email($confirmedBooking, $ticketRows, $rideNames);
        }

        redirect('tickets.php?step=3');
    }

    // AJAX: send abandoned payment notification email
    if ($action === 'notify_abandoned') {
        header('Content-Type: application/json');
        $bookingId = (int)($flow['booking_id'] ?? 0);
        if ($bookingId > 0 && $user) {
            $st = $pdo->prepare('SELECT * FROM bookings WHERE id = ? AND user_id = ? AND payment_status = ?');
            $st->execute([$bookingId, (int)$user['id'], 'Pending']);
            $bk = $st->fetch();
            if ($bk) {
                send_abandoned_payment_email($bk);
            }
        }
        echo json_encode(['ok' => true]);
        exit;
    }

    // AJAX: regenerate QR Ph code (called when QR expires)
    if ($action === 'generate_qr') {
        header('Content-Type: application/json');
        if (!$user) { echo json_encode(['success' => false, 'error' => 'Not logged in.']); exit; }
        $bookingId = (int)($flow['booking_id'] ?? 0);
        if ($bookingId <= 0) { echo json_encode(['success' => false, 'error' => 'No booking.']); exit; }
        $st = $pdo->prepare('SELECT * FROM bookings WHERE id = ? AND user_id = ?');
        $st->execute([$bookingId, (int)$user['id']]);
        $bk = $st->fetch();
        if (!$bk) { echo json_encode(['success' => false, 'error' => 'Booking not found.']); exit; }
        if (($bk['payment_status'] ?? '') !== 'Pending') {
            echo json_encode(['success' => false, 'error' => 'Booking is no longer pending.']); exit;
        }

        $amountCentavos = (int)round((float)$bk['total_amount'] * 100);
        $description    = 'AmusePark ' . ($bk['ticket_type_name'] ?? 'Ticket') . ' x' . ($bk['quantity'] ?? 1);
        $qrResult = paymongo_create_qrph(
            $amountCentavos, $description,
            (string)($bk['customer_name'] ?? ''),
            (string)($bk['customer_email'] ?? ''),
            (string)($bk['customer_phone'] ?? '')
        );
        if (!$qrResult['success']) {
            echo json_encode(['success' => false, 'error' => $qrResult['error'] ?? 'QR generation failed.']); exit;
        }        $pdo->prepare('UPDATE bookings SET paymongo_intent_id = ?, paymongo_qr_image = ?, paymongo_qr_code_id = ? WHERE id = ?')
            ->execute([$qrResult['payment_intent_id'], $qrResult['qr_image_url'], $qrResult['qr_code_id'], $bookingId]);
        $flow['paymongo_intent_id'] = $qrResult['payment_intent_id'];
        $_SESSION['booking_flow'] = $flow;
        echo json_encode(['success' => true, 'qr_image' => $qrResult['qr_image_url']]);
        exit;
    }
}

$flash = flash_get();

// Enforce login for step 1 on GET
if ($step === 1 && !$user) {
    redirect('login.php?next=tickets.php%3Fstep%3D1');
}

$selectedType = null;
$selectedQty  = (int)($flow['quantity'] ?? 1);
$selectedId   = (int)($flow['ticket_type_id'] ?? 0);
foreach ($types as $t) {
    if ((int)$t['id'] === $selectedId) { $selectedType = $t; break; }
}

$booking = null;
if ($step >= 2) {
    if (!$user) redirect('login.php?next=tickets.php%3Fstep%3D1');
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

// Step 1 — load ALL rides for customer to pick from
$step1Rides = [];
if ($step === 1 && $selectedType) {
    try {
        $rs = $pdo->prepare(
            'SELECT id, name, category, status, duration_minutes, min_height_cm
             FROM rides ORDER BY name ASC'
        );
        $rs->execute();
        $step1Rides = $rs->fetchAll();
    } catch (\Throwable $e) { $step1Rides = []; }
}

// Step 3 — fetch tickets and selected rides for confirmation popup
$popupTickets = [];
$popupRides   = [];
if ($step === 3 && $booking) {
    try {
        $st = $pdo->prepare('SELECT ticket_number FROM tickets WHERE booking_id = ? ORDER BY ticket_number ASC');
        $st->execute([(int)$booking['id']]);
        $popupTickets = $st->fetchAll(\PDO::FETCH_COLUMN);
    } catch (\Throwable $e) { $popupTickets = []; }
    // Use the ride names the customer actually selected (stored in session flow)
    $popupRides = $flow['selected_ride_names'] ?? [];
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" /><meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Buy Tickets - AmusePark</title>
  <link rel="stylesheet" href="css/style.css" />
  <style>
    /* ── Tickets page theme (Dark Blue + Yellow) ── */
    :root {
      --primary: #1e3a8a;
      --primary-dark: #172554;
      --secondary: #fbbf24;
      --secondary-dark: #f59e0b;
      --dark: #0f172a;
      --light: #f8fafc;
    }
    body.tickets-page { background: var(--light); color: var(--dark); font-family: 'Poppins', sans-serif; margin: 0; padding: 0; }
    html { background: var(--primary-dark); margin: 0; padding: 0; }

    .tk-hero {
      background: linear-gradient(135deg, var(--dark) 0%, var(--primary-dark) 100%);
      padding: 6rem 2rem 5rem;
      text-align: center;
      position: relative;
      overflow: hidden;
    }
    .tk-hero::before {
      content: ''; position: absolute; inset: 0;
      background: url('https://www.transparenttextures.com/patterns/cubes.png'); opacity: 0.1;
    }
    .tk-hero h1 {
      font-size: clamp(2.5rem, 6vw, 4.5rem);
      font-weight: 800;
      color: #fff;
      letter-spacing: -0.02em;
      line-height: 1.1;
      margin-bottom: 1.5rem;
      position: relative;
    }
    .tk-hero h1 span { color: var(--secondary); }
    .tk-hero p { color: rgba(255,255,255,0.7); font-size: 1.2rem; max-width: 700px; margin: 0 auto; position: relative; line-height: 1.6; }

    .tk-wrap { max-width: 1000px; margin: 0 auto; padding: 4rem 1.5rem 2rem; }

    /* Stepper */
    .tk-stepper { display: flex; align-items: center; margin-bottom: 4rem; }
    .tk-step { display: flex; align-items: center; gap: .75rem; flex: 1; }
    .tk-step-num {
      width: 40px; height: 40px; border-radius: 50%;
      border: 2px solid #e2e8f0;
      display: flex; align-items: center; justify-content: center;
      font-weight: 700; font-size: .95rem; color: var(--text-muted); flex-shrink: 0;
      background: #fff;
    }
    .tk-step.active .tk-step-num { border-color: var(--primary); color: var(--primary); }
    .tk-step.done .tk-step-num { background: var(--primary); border-color: var(--primary); color: #fff; }
    .tk-step-label { font-size: .9rem; font-weight: 600; color: var(--text-muted); }
    .tk-step.active .tk-step-label { color: var(--primary); }
    .tk-step.done .tk-step-label { color: var(--text-dark); }
    .tk-step-line { flex: 1; height: 2px; background: #e2e8f0; margin: 0 1rem; }
    .tk-step-line.done { background: var(--primary); }

    /* Flash */
    .tk-flash {
      padding: 1.25rem 1.5rem; border-radius: 1rem; margin-bottom: 2rem;
      font-weight: 600; font-size: 1rem; border: 1px solid transparent;
    }
    .tk-flash.error { background: #fee2e2; border-color: #fecaca; color: #991b1b; }
    .tk-flash.success { background: #dcfce7; border-color: #bbf7d0; color: #166534; }

    /* Ticket card row */
    .tk-card {
      background: #fff;
      border: 2px solid #e2e8f0;
      border-radius: 2.5rem;
      padding: 2.25rem 3rem;
      display: flex;
      justify-content: space-between;
      align-items: center;
      gap: 2rem;
      cursor: pointer;
      transition: all .4s cubic-bezier(0.4, 0, 0.2, 1);
      margin-bottom: 1.5rem;
    }
    .tk-card:hover { border-color: var(--primary); box-shadow: 0 20px 40px rgba(30, 58, 138, 0.08); transform: translateY(-4px); }
    .tk-card.selected { border-color: var(--primary); background: #eff6ff; box-shadow: 0 0 0 4px rgba(30, 58, 138, 0.1); }
    .tk-card-left { flex: 1; }
    .tk-card-name { font-size: 1.6rem; font-weight: 800; color: var(--dark); margin-bottom: .5rem; }
    .tk-card-desc { color: #64748b; font-size: .95rem; margin-bottom: 1rem; line-height: 1.6; }
    
    .tk-card-right { text-align: right; flex-shrink: 0; }
    .tk-price { font-size: 3rem; font-weight: 800; color: var(--primary); line-height: 1; }
    .tk-price-label { font-size: .9rem; color: #64748b; margin-top: .5rem; font-weight: 600; }
    
    .tk-buy-btn {
      display: block; margin-top: 1.25rem; width: 100%;
      background: var(--secondary); color: #000;
      font-weight: 800; font-size: 1rem;
      padding: .9rem 2.5rem; border-radius: 12px;
      border: none; cursor: pointer;
      transition: all .3s;
      white-space: nowrap;
      box-shadow: 0 8px 15px rgba(251, 191, 36, 0.2);
    }
    .tk-buy-btn:hover { background: var(--secondary-dark); transform: scale(1.02); }
    
    .tk-cart-add-btn {
      display: block; margin-top: .75rem; width: 100%;
      background: transparent; color: var(--primary);
      font-weight: 700; font-size: .9rem;
      padding: .75rem 1.5rem; border-radius: 12px;
      border: 2.5px solid var(--primary); cursor: pointer;
      transition: all .3s;
      white-space: nowrap;
    }
    .tk-cart-add-btn:hover { background: var(--primary); color: #fff; transform: scale(1.02); }
    .tk-cart-add-btn.added { background: #dcfce7; color: #166534; border-color: #bbf7d0; }

    /* Qty + total */
    .tk-qty-box {
      background: #fff; border: 1px solid #e2e8f0;
      border-radius: 999px; padding: 1.5rem 2.5rem;
      display: flex; align-items: center; gap: 1.5rem;
      margin-bottom: 1.5rem;
    }
    .tk-qty-box label { color: var(--text-muted); font-size: .9rem; font-weight: 600; }
    .tk-qty-box input[type=number] {
      background: var(--bg-light); border: 1.5px solid #e2e8f0; color: var(--text-dark);
      border-radius: 999px; padding: .6rem 1.25rem; font-size: 1rem; width: 110px;
    }
    .tk-qty-box input[type=number]:focus { border-color: var(--primary); outline: none; }
    .tk-total-wrap { margin-left: auto; text-align: right; }
    .tk-total-label { font-size: .9rem; color: var(--text-muted); font-weight: 600; }
    .tk-total-amount { font-size: 2.25rem; font-weight: 800; color: var(--primary); }

    /* Continue btn */
    .tk-continue-btn {
      width: 100%; padding: 1.25rem; border-radius: 999px;
      background: var(--primary); color: #ffffff; font-weight: 800; font-size: 1.15rem;
      border: none; cursor: pointer; transition: all .3s;
      letter-spacing: .02em; box-shadow: 0 10px 25px rgba(30, 58, 138, 0.25);
    }
    .tk-continue-btn:hover { background: var(--primary-dark); transform: translateY(-3px); box-shadow: 0 20px 45px rgba(30, 58, 138, 0.3); }

    /* Dark form card */
    .tk-form-card {
      background: #fff; border: 1px solid #e2e8f0;
      border-radius: 1.5rem; padding: 2rem 2.5rem; margin-bottom: 2rem;
      box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05);
    }
    .tk-form-card label { color: var(--text-dark); font-size: .9rem; font-weight: 700; display: block; margin-bottom: .5rem; }
    .tk-form-card input, .tk-form-card select {
      background: var(--bg-light); border: 1.5px solid #e2e8f0; color: var(--text-dark);
      border-radius: 999px; padding: .75rem 1.5rem; font-size: 1rem; width: 100%; font-family: inherit; transition: all .3s;
    }
    .tk-form-card input:focus, .tk-form-card select:focus { border-color: var(--primary); outline: none; box-shadow: 0 0 0 4px rgba(30, 58, 138, 0.1); }
    .tk-form-card .form-group { margin-bottom: 1.5rem; }

    /* Summary mini card */
    .tk-summary {
      background: #eff6ff; border: 1px solid #dbeafe;
      border-radius: 999px; padding: 1.5rem 3rem;
      display: flex; justify-content: space-between; align-items: center;
      margin-bottom: 2rem;
    }
    .tk-summary-name { font-weight: 800; color: var(--text-dark); font-size: 1.1rem; }
    .tk-summary-sub { color: var(--text-muted); font-size: .9rem; font-weight: 500; }
    .tk-summary-price { font-size: 2rem; font-weight: 800; color: var(--primary); }

    /* Action row */
    .tk-action-row { display: flex; gap: 1rem; }
    .tk-back-btn {
      flex: 1; padding: 1rem; border-radius: 999px;
      background: transparent; border: 2.5px solid #e2e8f0; color: #475569;
      font-weight: 700; font-size: 1rem; cursor: pointer; transition: all .3s;
      text-align: center; text-decoration: none; display: flex; align-items: center; justify-content: center;
    }
    .tk-back-btn:hover { border-color: var(--primary); color: var(--primary); background: #f8fafc; }
    .tk-primary-btn {
      flex: 1; padding: 1rem; border-radius: 999px;
      background: var(--primary); color: #fff; font-weight: 800; font-size: 1rem;
      border: none; cursor: pointer; transition: all .3s;
      box-shadow: 0 10px 20px rgba(30, 58, 138, 0.2);
    }
    .tk-primary-btn:hover { background: var(--primary-dark); transform: translateY(-2px); }

    /* Payment step */
    .tk-payment-card {
      background: #fff; border: 1px solid #e2e8f0;
      border-radius: 1.5rem; padding: 3rem; text-align: center; margin-bottom: 2rem;
      box-shadow: 0 10px 25px rgba(0,0,0,0.05);
    }
    .tk-qr-frame {
      border: 4px solid var(--primary); border-radius: 2rem;
      padding: 1.5rem; display: inline-block; margin-bottom: 1.5rem;
      background: #fff; box-shadow: 0 15px 35px rgba(30, 58, 138, 0.1);
    }
    .tk-ref-num { font-size: 1.75rem; font-weight: 800; color: var(--primary); margin-bottom: 1.5rem; letter-spacing: 0.05em; }
    .tk-amount-box {
      background: #eff6ff; border: 1px solid #dbeafe;
      border-radius: 999px; padding: 1.5rem 3rem;
    }
    .tk-amount-box .lbl { font-size: .9rem; color: var(--text-muted); font-weight: 600; margin-bottom: .25rem; }
    .tk-amount-box .val { font-size: 2.5rem; font-weight: 800; color: var(--primary); }

    /* Countdown */
    .tk-countdown {
      background: #fffbeb; border: 1px solid #fde68a;
      border-radius: 999px; padding: 1rem 2rem;
      display: flex; align-items: center; gap: 1rem; margin-bottom: 2rem;
    }
    .tk-countdown .timer-text { font-weight: 800; color: #92400e; font-size: 1rem; }
    .tk-countdown .timer-sub { font-size: .9rem; color: #b45309; font-weight: 500; }
    .tk-countdown.urgent { background: #fee2e2; border-color: #fecaca; }
    .tk-countdown.urgent .timer-text { color: #991b1b; }

    /* How to pay */
    .tk-how-to {
      background: #f0f9ff; border: 1px solid #bae6fd;
      border-radius: 999px; padding: 1.25rem 2.5rem; margin-bottom: 2rem;
      font-size: .95rem; color: #0369a1; line-height: 1.6;
      text-align: center;
    }

    /* Confirm step */
    .tk-confirm-card {
      background: #fff; border: 1px solid #e2e8f0;
      border-radius: 1.5rem; padding: 2rem 2.5rem; margin-bottom: 2rem;
    }
    .tk-confirm-row {
      display: flex; justify-content: space-between; align-items: center;
      padding: 1rem 0; border-bottom: 1px solid #f1f5f9;
    }
    .tk-confirm-row:last-child { border-bottom: none; }
    .tk-confirm-row .lbl { color: var(--text-muted); font-size: .95rem; font-weight: 600; }
    .tk-confirm-row .val { color: var(--text-dark); font-weight: 700; font-size: .95rem; }

    /* Popup overlay */
    .tk-popup-overlay {
      position: fixed; inset: 0; background: rgba(15,23,42,0.7);
      z-index: 9999; display: flex; align-items: center; justify-content: center; padding: 1rem;
      backdrop-filter: blur(8px);
    }
    .tk-popup {
      background: #fff; border-radius: 2rem;
      max-width: 600px; width: 100%;
      max-height: 90vh; overflow-y: auto; padding: 3rem;
      box-shadow: 0 25px 50px -12px rgba(0,0,0,0.25);
    }
    .tk-popup-header { text-align: center; margin-bottom: 2rem; }
    .tk-popup-header h2 { font-size: 2rem; font-weight: 800; color: var(--primary); margin: .75rem 0 .5rem; }
    .tk-popup-header .ref { font-size: 1.1rem; font-weight: 700; color: var(--text-muted); letter-spacing: .05em; }
    .tk-popup-grid { display: grid; grid-template-columns: 1fr 1fr; gap: .75rem 1.25rem; font-size: .95rem; margin-bottom: 1.5rem; }
    .tk-popup-grid .lbl { color: var(--text-muted); font-weight: 600; }
    .tk-popup-grid .val { color: var(--text-dark); font-weight: 700; }
    .tk-ticket-item {
      background: var(--bg-light); border: 1px solid #e2e8f0;
      border-radius: 999px; padding: 1rem 2rem; display: flex; align-items: center; gap: 1.5rem;
      margin-bottom: 1rem;
    }
    .tk-ticket-item img { width: 100px; height: 100px; border-radius: .75rem; flex-shrink: 0; object-fit: cover; }
    .tk-ticket-num { font-family: monospace; font-weight: 800; color: var(--primary); font-size: 1rem; }

    @media (max-width: 640px) {
      .tk-card { flex-direction: column; align-items: flex-start; padding: 2rem; }
      .tk-card-right { width: 100%; display: flex; align-items: center; justify-content: space-between; margin-top: 1.5rem; }
      .tk-buy-btn { margin-top: 0; }
      .tk-popup-grid { grid-template-columns: 1fr; }
      .tk-stepper { flex-direction: column; align-items: flex-start; gap: 1rem; }
      .tk-step-line { display: none; }
      .tk-qty-box { flex-direction: column; align-items: flex-start; }
      .tk-total-wrap { margin-left: 0; text-align: left; margin-top: 1rem; }
    }

    /* Ensure footer always spans full viewport width */
    .site-footer {
      width: 100vw;
      margin-left: calc(-50vw + 50%);
      border-radius: 0 !important;
    }
  </style>
</head>
<body class="tickets-page">
<?php render_nav($user, 'tickets'); ?>

<!-- HERO -->
<div class="tk-hero">
  <div style="display:inline-flex;align-items:center;gap:.6rem;background:rgba(251,191,36,0.15);border:1px solid rgba(251,191,36,0.3);border-radius:999px;padding:.5rem 1.5rem;margin-bottom:2rem;color:var(--secondary);font-size:.85rem;font-weight:700;text-transform:uppercase;letter-spacing:0.02em;backdrop-filter:blur(4px);">
    🎟 Online Booking — Skip the Queue
  </div>
  <h1>BUY TICKETS <span>NOW!</span> 🎢🎡</h1>
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

        <?php foreach ($types as $t):
          $price = (float)$t['price'];
          $tid   = (int)$t['id'];
          $isSelected = $tid === $selectedId;
        ?>
          <div class="tk-card <?= $isSelected ? 'selected' : '' ?>"
               onclick="selectTicket(<?= $tid ?>, <?= $price ?>)">
            <div class="tk-card-left">
              <div class="tk-card-name"><?= e($t['name']) ?></div>
              <div class="tk-card-desc"><?= e($t['description'] ?? 'Full day access to all included rides') ?></div>
              <?php if (isset($t['max_rides']) && $t['max_rides'] !== null && $t['max_rides'] !== ''): ?>
                <div style="color:var(--primary);font-size:.85rem;font-weight:700;margin-bottom:.5rem;">🎢 Pick up to <?= (int)$t['max_rides'] ?> ride<?= (int)$t['max_rides'] === 1 ? '' : 's' ?> — you choose in the next step</div>
              <?php else: ?>
                <div style="color:var(--primary);font-size:.85rem;font-weight:700;margin-bottom:.5rem;">🎢 Unlimited rides — pick any you want</div>
              <?php endif; ?>
              <input type="radio" id="radio-<?= $tid ?>" name="ticket_type_id" value="<?= $tid ?>"
                     data-price="<?= $price ?>" <?= $isSelected ? 'checked' : '' ?>
                     style="display:none;" />
            </div>
            <div class="tk-card-right">
              <div class="tk-price">₱<?= number_format($price, 0) ?></div>
              <div class="tk-price-label">per person</div>
              <button type="button" class="tk-buy-btn" onclick="event.stopPropagation();bookNow(<?= $tid ?>, <?= $price ?>)">
                Book Now
              </button>
              <button type="button" class="tk-cart-add-btn" onclick="event.stopPropagation();addToCart(<?= $tid ?>, this)">
                🛒 Add to Cart
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

        <button class="tk-continue-btn" style="display:none;" type="submit">Continue to Details →</button>
      </form>

      <script>
      (function() {
        var form = document.getElementById('ticket-form');
        var totalEl = document.getElementById('ticket-total');
        var qtyInput = document.getElementById('ticket-qty');
        window.selectTicket = function(tid, price) {
          var radio = document.getElementById('radio-' + tid);
          if (radio) radio.checked = true;
          document.querySelectorAll('.tk-card').forEach(function(c) { c.classList.remove('selected'); });
          var card = radio ? radio.closest('.tk-card') : null;
          if (card) card.classList.add('selected');
          updateTotal();
        };

        // Book Now — select ticket and immediately submit the form
        window.bookNow = function(tid, price) {
          selectTicket(tid, price);
          var form = document.getElementById('ticket-form');
          if (form) form.submit();
        };

        function updateTotal() {
          var radio = form.querySelector('input[name="ticket_type_id"]:checked');
          var qty = Math.max(1, parseInt(qtyInput.value, 10) || 1);
          qtyInput.value = qty;
          if (!radio || !totalEl) return;
          var price = parseFloat(radio.getAttribute('data-price')) || 0;
          totalEl.textContent = '₱' + Math.round(price * qty).toLocaleString();
        }

        window.updateTicketTotal = updateTotal;
        if (qtyInput) { qtyInput.addEventListener('input', updateTotal); qtyInput.addEventListener('change', updateTotal); }
        updateTotal();
      })();

      // Add to Cart
      window.addToCart = function(tid, btn) {
        var qty = Math.max(1, parseInt(document.getElementById('ticket-qty').value, 10) || 1);
        btn.disabled = true;
        btn.textContent = 'Adding…';
        fetch('cart.php', {
          method: 'POST',
          headers: {'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest'},
          body: 'action=add&ticket_type_id=' + tid + '&qty=' + qty
        })
        .then(function(r) { return r.json(); })
        .then(function(d) {
          btn.textContent = '✓ Added!';
          btn.classList.add('added');
          // Update nav badge
          var badge = document.getElementById('cart-nav-badge');
          if (badge) {
            badge.textContent = d.count;
            badge.style.display = d.count > 0 ? 'inline-flex' : 'none';
          }
          setTimeout(function() {
            btn.textContent = '🛒 Add to Cart';
            btn.classList.remove('added');
            btn.disabled = false;
          }, 1800);
        })
        .catch(function() {
          btn.textContent = '🛒 Add to Cart';
          btn.disabled = false;
        });
      };
      </script>
    <?php endif; ?>
  <?php endif; ?>

  <!-- ═══════════════════════════════════════════════════════ -->
  <!-- STEP 1 — DETAILS + RIDE SELECTION                       -->
  <!-- ═══════════════════════════════════════════════════════ -->
  <?php if ($step === 1): ?>
    <?php
      $maxRides1 = ($selectedType && isset($selectedType['max_rides']) && $selectedType['max_rides'] !== null && $selectedType['max_rides'] !== '')
          ? (int)$selectedType['max_rides'] * max(1, (int)$selectedQty) : null;
      $hasRideList = count($step1Rides) > 0;
      $prevSelectedRideIds = $flow['selected_ride_ids'] ?? [];
    ?>
    <div style="margin-bottom:1.5rem;">
      <div style="font-size:1.6rem;font-weight:900;color:#111827;margin-bottom:.35rem;">Your Details</div>
      <div style="color:#6b7280;font-size:.95rem;">Fill in your info and choose your rides</div>
      <?php
        $cartTotal1 = (int)($flow['cart_total'] ?? 0);
        $cartIndex1 = (int)($flow['cart_index'] ?? 0);
        if ($cartTotal1 > 1):
      ?>
        <div style="margin-top:.75rem;background:#eff6ff;border:1px solid #dbeafe;border-radius:.75rem;padding:.6rem 1.1rem;display:inline-flex;align-items:center;gap:.5rem;font-size:.85rem;font-weight:700;color:#1e3a8a;">
          🛒 Booking ticket <?= $cartIndex1 ?> of <?= $cartTotal1 ?> from your cart
        </div>
      <?php endif; ?>
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
          <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1.25rem;flex-wrap:wrap;gap:.75rem;">
            <div>
              <div style="font-size:1.1rem;font-weight:800;color:#111827;display:flex;align-items:center;gap:.5rem;">
                🎢 Select Your Rides
              </div>
              <?php if ($maxRides1 !== null): ?>
                <div style="font-size:.88rem;color:var(--primary);font-weight:700;margin-top:.3rem;">
                  Pick up to <strong><?= $maxRides1 ?></strong> ride<?= $maxRides1 === 1 ? '' : 's' ?> total
                  <?php if ((int)$selectedQty > 1): ?>
                    <span style="color:#64748b;font-weight:500;">(<?= (int)$selectedType['max_rides'] ?> per ticket × <?= (int)$selectedQty ?> tickets)</span>
                  <?php endif; ?>
                </div>
              <?php else: ?>
                <div style="font-size:.88rem;color:var(--primary);font-weight:700;margin-top:.3rem;">
                  All rides are included — select the ones you want
                </div>
              <?php endif; ?>
            </div>
            <?php if ($maxRides1 !== null): ?>
              <div id="ride-counter"
                   style="background:#eff6ff;border:2px solid #dbeafe;border-radius:12px;
                          padding:.5rem 1.25rem;font-size:.9rem;font-weight:800;color:var(--primary);
                          min-width:130px;text-align:center;">
                0 / <?= $maxRides1 ?> selected
              </div>
            <?php endif; ?>
          </div>

          <!-- Ride grid -->
          <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:.75rem;">
            <?php foreach ($step1Rides as $r):
              $rId     = (int)$r['id'];
              $isOpen  = ($r['status'] ?? 'Open') === 'Open';
              $checked = in_array($rId, $prevSelectedRideIds, true);
              $catColors = ['Thrill'=>['bg'=>'#fee2e2','color'=>'#dc2626'],
                            'Family'=>['bg'=>'#dcfce7','color'=>'#16a34a'],
                            'Kids'  =>['bg'=>'#eff6ff','color'=>'var(--primary)'],
                            'Water' =>['bg'=>'#dbeafe','color'=>'#1d4ed8'],
                            'Classic'=>['bg'=>'#f1f5f9','color'=>'#475569']];
              $cat      = (string)($r['category'] ?? '');
              $catStyle = $catColors[$cat] ?? ['bg'=>'#f1f5f9','color'=>'#475569'];
            ?>
              <label class="ride-checkbox-card <?= !$isOpen ? 'ride-disabled' : '' ?>"
                     style="display:flex;flex-direction:column;gap:.5rem;
                            background:<?= $checked ? '#eff6ff' : '#fff' ?>;
                            border:2px solid <?= $checked ? 'var(--primary)' : '#e2e8f0' ?>;
                            border-radius:1rem;padding:1.25rem;
                            cursor:<?= $isOpen ? 'pointer' : 'not-allowed' ?>;
                            transition:all .3s;
                            position:relative;opacity:<?= $isOpen ? '1' : '.55' ?>;"
                     onclick="<?= $isOpen ? '' : 'return false;' ?>">
                <input type="checkbox"
                       name="selected_ride_ids[]"
                       value="<?= $rId ?>"
                       <?= $checked ? 'checked' : '' ?>
                       <?= !$isOpen ? 'disabled' : '' ?>
                       onchange="onRideChange(this)"
                       style="position:absolute;top:.75rem;right:.75rem;
                              width:20px;height:20px;accent-color:var(--primary);cursor:inherit;" />
                <div style="font-weight:800;font-size:1rem;color:<?= $isOpen ? 'var(--text-dark)' : 'var(--text-muted)' ?>;
                            padding-right:2rem;line-height:1.4;">
                  <?= e($r['name']) ?>
                </div>
                <div style="display:flex;align-items:center;gap:.5rem;flex-wrap:wrap;margin-top:auto;">
                  <span style="font-size:.75rem;font-weight:800;padding:.3rem .7rem;border-radius:6px;text-transform:uppercase;
                               background:<?= $catStyle['bg'] ?>;color:<?= $catStyle['color'] ?>;">
                    <?= e($cat) ?>
                  </span>
                </div>
              </label>
            <?php endforeach; ?>
          </div>

          <script>
          (function() {
            var max = <?= $maxRides1 !== null ? $maxRides1 : 'null' ?>;

            function updateRideState() {
              var allBoxes     = document.querySelectorAll('input[name="selected_ride_ids[]"]');
              var checkedCount = document.querySelectorAll('input[name="selected_ride_ids[]"]:checked').length;
              var counter      = document.getElementById('ride-counter');
              var warn         = document.getElementById('ride-limit-warn');

              if (counter && max !== null) {
                counter.textContent = checkedCount + ' / ' + max + ' selected';
                counter.style.borderColor = checkedCount >= max ? 'var(--primary)' : '#dbeafe';
                counter.style.background  = checkedCount >= max ? '#eff6ff' : '#f0f9ff';
              }

              if (warn) warn.style.display = (max !== null && checkedCount > max) ? 'block' : 'none';

              if (max !== null) {
                allBoxes.forEach(function(box) {
                  if (!box.checked) {
                    var isClosedRide = box.hasAttribute('data-closed');
                    box.disabled = isClosedRide || (checkedCount >= max);
                  }
                  var card = box.closest('label');
                  if (card) {
                    card.style.cursor = (box.disabled && !box.checked) ? 'not-allowed' : 'pointer';
                    card.style.opacity = (box.disabled && !box.checked) ? '0.45' : '1';
                  }
                });
              }
            }

            function onRideChange(cb) {
              var card = cb.closest('label');
              if (card) {
                card.style.borderColor = cb.checked ? 'var(--primary)' : '#e2e8f0';
                card.style.background  = cb.checked ? '#eff6ff' : '#fff';
                card.style.boxShadow   = cb.checked ? '0 4px 15px rgba(30,58,138,0.1)' : 'none';
              }
              updateRideState();
            }

            window.onRideChange = onRideChange;

            document.querySelectorAll('input[name="selected_ride_ids[]"]').forEach(function(cb) {
              if (cb.disabled) cb.setAttribute('data-closed', '1');
              if (cb.checked) {
                var card = cb.closest('label');
                if (card) {
                  card.style.borderColor = 'var(--primary)';
                  card.style.background  = '#eff6ff';
                  card.style.boxShadow   = '0 4px 15px rgba(30,58,138,0.1)';
                }
              }
            });
            updateRideState();
          })();
          </script>
      <?php else: ?>
        <!-- No rides linked to this ticket type yet -->
        <div class="tk-form-card" style="margin-bottom:1.5rem;text-align:center;padding:2rem;">
          <div style="font-size:2rem;margin-bottom:.5rem;">🎢</div>
          <div style="font-weight:700;color:#374151;margin-bottom:.25rem;">All Rides Included</div>
          <div style="font-size:.9rem;color:#6b7280;">This ticket gives you access to all available rides in the park.</div>
        </div>
      <?php endif; ?>

      <!-- Package summary -->
      <?php if ($selectedType): ?>
        <div class="tk-summary">
          <div>
            <div class="tk-summary-name"><?= e((string)$selectedType['name']) ?> × <?= (int)$selectedQty ?></div>
            <div class="tk-summary-sub">
              <?php if ($maxRides1 !== null): ?>
                Up to <?= $maxRides1 ?> rides total
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
      // Use expires_at from DB if available, otherwise fall back to created_at + 3 min
      $expiresAtRaw = (string)($booking['expires_at'] ?? '');
      if ($expiresAtRaw !== '' && $expiresAtRaw !== '0000-00-00 00:00:00') {
          $expiresAt = strtotime($expiresAtRaw);
      } else {
          $createdAt = strtotime((string)($booking['created_at'] ?? 'now'));
          $expiresAt = $createdAt + 180;
      }
      $secondsLeft = max(0, $expiresAt - time());

      // PayMongo QR — 30-min expiry from when QR was generated
      $qrImage     = (string)($booking['paymongo_qr_image'] ?? '');
      $intentId    = (string)($booking['paymongo_intent_id'] ?? '');
      $hasRealQR   = $qrImage !== '';
      $isDemo      = PAYMONGO_SECRET_KEY === '';
    ?>
    <div style="margin-bottom:1.5rem;">
      <div style="font-size:1.6rem;font-weight:900;color:#111827;margin-bottom:.35rem;">Pay via QR Ph</div>
      <div style="color:#6b7280;font-size:.95rem;">Scan the QR code with GCash, Maya, or any QR Ph banking app</div>
    </div>

    <!-- 3-min booking countdown -->
    <div class="tk-countdown" id="expiry-banner">
      <span style="font-size:1.4rem;">⏱</span>
      <div>
        <div class="timer-text">Booking expires in <span id="countdown-display"><?= gmdate('i:s', $secondsLeft) ?></span></div>
        <div class="timer-sub">Booking will be automatically cancelled if unpaid</div>
      </div>
    </div>
    <script>
    (function() {
      var secs = <?= (int)$secondsLeft ?>;
      var el = document.getElementById('countdown-display');
      var banner = document.getElementById('expiry-banner');
      if (!el || secs <= 0) { window.location.href = 'tickets.php?expired=1'; return; }
      var iv = setInterval(function() {
        secs--;
        if (secs <= 0) {
          clearInterval(iv);
          el.textContent = '0:00';
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
      <?php if ($isDemo): ?>
        <!-- Demo mode: no API key configured -->
        <div style="background:#fef9c3;border:1px solid #fcd34d;border-radius:.75rem;padding:1rem;margin-bottom:1.25rem;font-size:.88rem;color:#92400e;">
          <strong>⚠ Demo Mode:</strong> No PayMongo API key configured. Add your keys to <code>config.local.php</code> to enable real QR Ph payments.
        </div>
        <div class="tk-qr-frame">
          <?php
            $demoTicketNum = count($popupTickets) > 0
              ? (string)$popupTickets[0]
              : 'TK-' . ($booking['booking_reference'] ?? '') . '-001';
          ?>
          <img src="https://api.qrserver.com/v1/create-qr-code/?size=220x220&data=<?= e(urlencode($demoTicketNum)) ?>"
               alt="Demo QR" style="width:220px;height:220px;display:block;" />
        </div>
      <?php elseif ($hasRealQR): ?>
        <!-- Real PayMongo QR Ph image (base64) -->
        <div style="margin-bottom:.75rem;">
          <span style="background:#dcfce7;color:#16a34a;border-radius:999px;padding:.25rem .85rem;font-size:.8rem;font-weight:700;">✓ Powered by PayMongo</span>
        </div>
        <div class="tk-qr-frame" id="qr-frame">
          <img id="qr-image" src="<?= e($qrImage) ?>" alt="QR Ph Code" style="width:220px;height:220px;display:block;" />
        </div>
        <!-- 30-min QR expiry countdown -->
        <div style="margin-top:.75rem;font-size:.82rem;color:#6b7280;">
          QR expires in <span id="qr-timer" style="font-weight:700;color:#d97706;">30:00</span>
          &nbsp;·&nbsp;
          <button onclick="regenerateQR()" id="regen-btn" style="background:none;border:none;color:var(--primary);font-weight:700;cursor:pointer;font-size:.82rem;text-decoration:underline;">Regenerate QR</button>
        </div>
        <script>
        (function() {
          var qrSecs = 1800; // 30 minutes
          var el = document.getElementById('qr-timer');
          var iv = setInterval(function() {
            qrSecs--;
            if (qrSecs <= 0) {
              clearInterval(iv);
              el.textContent = 'EXPIRED';
              el.style.color = '#dc2626';
              document.getElementById('qr-image').style.opacity = '0.3';
              document.getElementById('regen-btn').style.display = 'inline';
              return;
            }
            var m = Math.floor(qrSecs / 60), s = qrSecs % 60;
            el.textContent = m + ':' + (s < 10 ? '0' : '') + s;
            if (qrSecs <= 300) el.style.color = '#dc2626';
          }, 1000);
        })();

        function regenerateQR() {
          var btn = document.getElementById('regen-btn');
          btn.textContent = 'Generating…';
          btn.disabled = true;
          fetch('tickets.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'action=generate_qr'
          })
          .then(r => r.json())
          .then(data => {
            if (data.success) {
              document.getElementById('qr-image').src = data.qr_image;
              document.getElementById('qr-image').style.opacity = '1';
              btn.textContent = 'Regenerate QR';
              btn.disabled = false;
              // Reset timer
              document.getElementById('qr-timer').textContent = '30:00';
              document.getElementById('qr-timer').style.color = '#d97706';
            } else {
              alert('Could not regenerate QR: ' + (data.error || 'Unknown error'));
              btn.textContent = 'Regenerate QR';
              btn.disabled = false;
            }
          });
        }
        </script>
      <?php else: ?>
        <!-- QR generation failed — show fallback -->
        <?php
          // Try to get the actual error from PayMongo for debugging
          $qrDebugError = '';
          if (PAYMONGO_SECRET_KEY !== '') {
              $testRes = paymongo_request('GET', '/v1/payment_methods');
              // Just check connectivity — actual error was during booking creation
          }
        ?>
        <div style="background:#fee2e2;border:1px solid #fca5a5;border-radius:.75rem;padding:1rem;margin-bottom:1rem;font-size:.88rem;color:#991b1b;">
          <strong>QR generation failed.</strong> Please try again or contact support.
        </div>
        <button onclick="regenerateQR()" id="regen-btn" class="tk-primary-btn" style="width:auto;padding:.65rem 1.5rem;">🔄 Generate QR Code</button>
        <script>
        function regenerateQR() {
          var btn = document.getElementById('regen-btn');
          btn.textContent = 'Generating…'; btn.disabled = true;
          fetch('tickets.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'action=generate_qr'
          })
          .then(r => r.json())
          .then(data => {
            if (data.success) { window.location.reload(); }
            else {
              var errBox = document.getElementById('qr-error-detail');
              if (errBox) errBox.textContent = data.error || 'Unknown error';
              btn.textContent = '🔄 Generate QR Code'; btn.disabled = false;
            }
          })
          .catch(function() { btn.textContent = '🔄 Generate QR Code'; btn.disabled = false; });
        }
        </script>
        <div id="qr-error-detail" style="margin-top:.75rem;font-size:.8rem;color:#7f1d1d;font-family:monospace;word-break:break-all;"></div>
      <?php endif; ?>

      <div style="color:#64748b;font-size:.85rem;margin:.75rem 0 .35rem;">Reference Number</div>
      <div class="tk-ref-num"><?= e($booking['booking_reference'] ?? '') ?></div>
      <div class="tk-amount-box">
        <div class="lbl">Amount to Pay</div>
        <div class="val">₱<?= number_format((float)($booking['total_amount'] ?? 0), 0) ?></div>
      </div>
    </div>

    <div class="tk-how-to">
      <strong>How to pay:</strong> Open GCash / Maya or any QR Ph banking app &rarr; Scan QR &rarr; Enter amount &rarr; Confirm &rarr; Click "I've Paid" below
    </div>

    <!-- Poll for payment status every 5 seconds -->
    <div id="payment-status-msg" style="display:none"></div>

    <!-- Combined Payment + Booking Confirmation Popup -->
    <div id="payment-success-popup" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,.8);z-index:9999;align-items:center;justify-content:center;padding:1rem;backdrop-filter:blur(8px);overflow-y:auto;">
      <div style="background:#fff;border-radius:2rem;max-width:560px;width:100%;padding:0;box-shadow:0 25px 60px rgba(0,0,0,.3);animation:popIn .4s cubic-bezier(0.34,1.56,0.64,1);overflow:hidden;margin:auto;">

        <!-- Header -->
        <div style="background:linear-gradient(135deg,#0f172a 0%,#1e3a8a 100%);padding:2rem;text-align:center;position:relative;">
          <div style="font-size:3rem;margin-bottom:.5rem;">🎉</div>
          <h2 style="font-size:1.6rem;font-weight:900;color:#fff;margin:0 0 .3rem;">Payment Confirmed!</h2>
          <p style="color:rgba(255,255,255,.7);font-size:.9rem;margin:0;">Your booking is confirmed — enjoy your rides!</p>
          <div id="popup-ref" style="margin-top:.85rem;display:inline-block;background:rgba(251,191,36,.2);border:1px solid rgba(251,191,36,.4);border-radius:999px;padding:.3rem 1.25rem;font-family:monospace;font-weight:900;color:#fbbf24;font-size:.95rem;letter-spacing:.05em;"></div>
        </div>

        <!-- QR Code -->
        <div style="text-align:center;padding:1.5rem 2rem 0;">
          <div style="font-size:.75rem;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:.06em;margin-bottom:.75rem;">🎟 Your Entry QR Code</div>
          <div id="popup-qr-wrap" style="display:inline-block;border:4px solid #1e3a8a;border-radius:1.25rem;padding:1rem;background:#fff;box-shadow:0 8px 20px rgba(30,58,138,.12);">
            <img id="popup-qr-img" src="" alt="Entry QR" style="width:180px;height:180px;display:block;border-radius:.5rem;" />
          </div>
          <div style="font-size:.78rem;color:#94a3b8;margin-top:.5rem;">Present this at the park entrance</div>
          <div id="popup-ticket-num" style="margin-top:.4rem;font-family:monospace;font-size:.82rem;font-weight:800;color:#1e3a8a;"></div>
        </div>

        <!-- Booking Details -->
        <div style="padding:1.25rem 2rem;">
          <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:1rem;overflow:hidden;">
            <div style="background:#1e3a8a;padding:.6rem 1rem;font-size:.75rem;font-weight:800;color:#fff;text-transform:uppercase;letter-spacing:.06em;">📋 Booking Details</div>
            <div id="popup-details" style="font-size:.88rem;"></div>
          </div>
        </div>

        <!-- Rides -->
        <div id="popup-rides-wrap" style="display:none;padding:0 2rem .75rem;">
          <div style="font-size:.75rem;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:.06em;margin-bottom:.5rem;">🎢 Selected Rides</div>
          <div id="popup-rides" style="display:flex;flex-wrap:wrap;gap:.35rem;"></div>
        </div>

        <!-- Notices -->
        <div style="padding:0 2rem 1.25rem;display:flex;flex-direction:column;gap:.5rem;">
          <div style="display:flex;align-items:center;gap:.6rem;background:#f0fdf4;border-radius:.75rem;padding:.75rem 1rem;">
            <span>📧</span><span style="font-size:.82rem;font-weight:700;color:#166534;">Confirmation email sent to your inbox</span>
          </div>
        </div>

        <!-- Button -->
        <div style="padding:0 2rem 2rem;">
          <button onclick="confirmAndProceed()" id="popup-proceed-btn" style="width:100%;background:#1e3a8a;color:#fff;border:none;border-radius:999px;padding:1rem;font-size:1rem;font-weight:900;cursor:pointer;font-family:inherit;box-shadow:0 8px 20px rgba(30,58,138,.25);">
            ✅ View My Tickets <span id="popup-countdown" style="opacity:.75;font-weight:600;">(4)</span>
          </button>
        </div>
      </div>
    </div>
    <style>
    @keyframes popIn{from{opacity:0;transform:scale(.85)}to{opacity:1;transform:scale(1)}}
    </style>

    <?php if ($intentId !== '' && !$isDemo): ?>
    <?php
      // Fetch the first ticket number for this booking to use as the scannable QR
      $popupTicketNum = '';
      try {
          $ptSt = $pdo->prepare('SELECT ticket_number FROM tickets WHERE booking_id = ? ORDER BY ticket_number ASC LIMIT 1');
          $ptSt->execute([(int)$booking['id']]);
          $popupTicketNum = (string)($ptSt->fetchColumn() ?: '');
      } catch (\Throwable $e) {}
      // Fallback: construct from booking reference
      if ($popupTicketNum === '') {
          $popupTicketNum = 'TK-' . ($booking['booking_reference'] ?? '') . '-001';
      }
    ?>
    <script>
    (function() {
      var intentId   = <?= json_encode($intentId) ?>;
      var bookingRef = <?= json_encode($booking['booking_reference'] ?? '') ?>;
      var ticketNum  = <?= json_encode($popupTicketNum) ?>;
      var rideNames  = <?= json_encode($flow['selected_ride_names'] ?? []) ?>;

      var pollIv = setInterval(function() {
        fetch('tickets.php?poll_intent=' + encodeURIComponent(intentId))
          .then(function(r){ return r.json(); })
          .then(function(data) {
            if (data.status === 'succeeded') {
              clearInterval(pollIv);
              window._paymentDone = true;
              // Show popup first
              showConfirmPopup(bookingRef, ticketNum, rideNames);
              // Auto-proceed to step 3 (Confirm) after 4 seconds
              setTimeout(function() {
                confirmAndProceed();
              }, 4000);
            }
          })
          .catch(function() {});
      }, 4000);
    })();

    function showConfirmPopup(ref, ticketNum, rides) {
      // Reference
      document.getElementById('popup-ref').textContent = ref;

      // QR code encodes the TICKET NUMBER — this is what the scanner reads
      document.getElementById('popup-qr-img').src =
        'https://api.qrserver.com/v1/create-qr-code/?size=180x180&data=' + encodeURIComponent(ticketNum);

      // Show ticket number below QR
      var tnEl = document.getElementById('popup-ticket-num');
      if (tnEl) tnEl.textContent = ticketNum;

      // Booking details rows
      var b = <?= json_encode([
        'customer_name'    => $booking['customer_name']    ?? '',
        'customer_email'   => $booking['customer_email']   ?? '',
        'ticket_type_name' => $booking['ticket_type_name'] ?? '',
        'quantity'         => $booking['quantity']         ?? 1,
        'visit_date'       => $booking['visit_date']       ?? '',
        'total_amount'     => $booking['total_amount']     ?? 0,
      ]) ?>;
      var rows = [
        ['Customer',   b.customer_name],
        ['Email',      b.customer_email],
        ['Ticket',     b.ticket_type_name + ' × ' + b.quantity],
        ['Visit Date', b.visit_date],
        ['Total Paid', '₱' + Number(b.total_amount).toLocaleString()],
      ];
      var html = '';
      rows.forEach(function(r, i) {
        html += '<div style="display:flex;justify-content:space-between;padding:.55rem 1rem;'
              + (i % 2 === 1 ? 'background:#f8fafc;' : '')
              + 'border-bottom:1px solid #f1f5f9;">'
              + '<span style="color:#94a3b8;font-weight:600;">' + r[0] + '</span>'
              + '<span style="font-weight:700;color:#0f172a;text-align:right;max-width:60%;overflow:hidden;text-overflow:ellipsis;">' + r[1] + '</span>'
              + '</div>';
      });
      document.getElementById('popup-details').innerHTML = html;

      // Rides
      if (rides && rides.length > 0) {
        var ridesHtml = rides.map(function(r) {
          return '<span style="background:#eff6ff;border:1px solid #dbeafe;color:#1e3a8a;border-radius:999px;padding:.2rem .75rem;font-size:.78rem;font-weight:700;">' + r + '</span>';
        }).join('');
        document.getElementById('popup-rides').innerHTML = ridesHtml;
        document.getElementById('popup-rides-wrap').style.display = 'block';
      }

      document.getElementById('payment-success-popup').style.display = 'flex';

      // Countdown display on the button
      var secs = 4;
      var cdEl = document.getElementById('popup-countdown');
      var cdIv = setInterval(function() {
        secs--;
        if (cdEl) cdEl.textContent = '(' + secs + ')';
        if (secs <= 0) clearInterval(cdIv);
      }, 1000);
    }

    function confirmAndProceed() {
      document.getElementById('payment-success-popup').style.display = 'none';
      // Submit the hidden confirm form
      var f = document.getElementById('confirm-payment-form');
      if (f) { f.submit(); return; }
      // Fallback: redirect directly
      window.location.href = 'tickets.php?step=3';
    }
    </script>
    <?php endif; ?>

    <!-- Hidden form for auto-confirm after payment detected -->
    <form id="confirm-payment-form" method="post" style="display:none;">
      <input type="hidden" name="action" value="confirm_payment" />
    </form>

    <div style="display:flex;justify-content:center;margin-top:1rem;">
      <form method="post">
        <input type="hidden" name="action" value="reset" />
        <button class="tk-back-btn" type="submit" style="width:auto;padding:.9rem 2.5rem;">← Start Over</button>
      </form>
    </div>

    <script>
    // Fire abandoned payment notification when user leaves step 2 without paying
    var _paymentDone = false;
    window._paymentDone = false;
    document.querySelectorAll('form').forEach(function(f) {
      f.addEventListener('submit', function() { _paymentDone = true; window._paymentDone = true; });
    });
    window.addEventListener('beforeunload', function() {
      if (!_paymentDone && !window._paymentDone) {
        var fd = new FormData();
        fd.append('action', 'notify_abandoned');
        navigator.sendBeacon('tickets.php', fd);
      }
    });
    </script>
  <?php endif; ?>

  <!-- ═══════════════════════════════════════════════════════ -->
  <!-- STEP 3 — CONFIRMATION                                   -->
  <!-- ═══════════════════════════════════════════════════════ -->
  <?php if ($step === 3 && $booking): ?>
    <?php $paymentDatetime = !empty($booking['updated_at']) ? $booking['updated_at'] : date('Y-m-d H:i:s'); ?>

    <!-- Page summary (behind popup) -->
    <div style="text-align:center;margin-bottom:2rem;">
      <div style="width:80px;height:80px;background:#dcfce7;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 1.25rem;font-size:2.5rem;">✅</div>
      <div style="font-size:2rem;font-weight:900;color:#111827;margin-bottom:.5rem;">Booking Confirmed!</div>
      <div style="color:#6b7280;">Your QR ticket is ready. Show it at the park entrance.</div>
    </div>

    <div class="tk-confirm-card">
      <div class="tk-confirm-row"><span class="lbl">Booking Ref</span><span class="val" style="color:var(--primary);font-weight:800;"><?= e($booking['booking_reference'] ?? '') ?></span></div>
      <div class="tk-confirm-row"><span class="lbl">Customer</span><span class="val"><?= e($booking['customer_name'] ?? '') ?></span></div>
      <div class="tk-confirm-row"><span class="lbl">Ticket</span><span class="val"><?= e($booking['ticket_type_name'] ?? '') ?> × <?= (int)($booking['quantity'] ?? 1) ?></span></div>
      <div class="tk-confirm-row"><span class="lbl">Visit Date</span><span class="val"><?= e((string)($booking['visit_date'] ?? '')) ?></span></div>
      <div class="tk-confirm-row"><span class="lbl">Total Paid</span><span class="val" style="color:#16a34a;font-size:1.2rem;font-weight:900;">₱<?= number_format((float)($booking['total_amount'] ?? 0), 0) ?></span></div>
    </div>

    <div class="tk-payment-card" style="margin-bottom:1.5rem;">
      <div style="color:#64748b;font-size:.85rem;margin-bottom:.75rem;">Your Entry QR Code</div>
      <div class="tk-qr-frame">
        <?php
          // Use the ticket number — this is what the staff scanner reads
          $step3TicketNum = '';
          if (count($popupTickets) > 0) {
              $step3TicketNum = (string)$popupTickets[0];
          } else {
              // Fallback: construct from booking reference
              $step3TicketNum = 'TK-' . ($booking['booking_reference'] ?? '') . '-001';
          }
        ?>
        <img src="https://api.qrserver.com/v1/create-qr-code/?size=220x220&data=<?= e(urlencode($step3TicketNum)) ?>"
             style="width:220px;height:220px;display:block;" alt="Entry QR" />
      </div>
      <p style="font-size:.85rem;color:#475569;margin-top:.75rem;">Present this at the park entrance</p>
      <p style="font-family:monospace;font-size:.8rem;color:#1e3a8a;font-weight:700;margin-top:.25rem;"><?= e($step3TicketNum) ?></p>
    </div>

    <?php
      $cartQueue    = $flow['cart_queue']  ?? [];
      $cartTotal    = (int)($flow['cart_total']  ?? 0);
      $cartIndex    = (int)($flow['cart_index']  ?? 0);
      $hasMoreItems = count($cartQueue) > 0;
    ?>

    <?php if ($hasMoreItems): ?>
      <!-- Cart progress indicator -->
      <div style="background:#eff6ff;border:1px solid #dbeafe;border-radius:1rem;padding:1rem 1.5rem;margin-bottom:1.5rem;text-align:center;">
        <div style="font-size:.8rem;font-weight:700;color:#64748b;margin-bottom:.4rem;">CART PROGRESS</div>
        <div style="font-size:1rem;font-weight:800;color:#1e3a8a;">
          ✅ Ticket <?= $cartIndex ?> of <?= $cartTotal ?> booked
        </div>
        <div style="font-size:.85rem;color:#475569;margin-top:.3rem;">
          <?= count($cartQueue) ?> more ticket<?= count($cartQueue) !== 1 ? 's' : '' ?> remaining in your cart
        </div>
      </div>

      <!-- Auto-start next item -->
      <form method="post" id="next-cart-form">
        <input type="hidden" name="action" value="next_cart_item" />
        <button class="tk-continue-btn" type="submit" style="background:#16a34a;">
          → Book Next Ticket (<?= count($cartQueue) ?> remaining)
        </button>
      </form>
      <script>
      // Auto-proceed to next cart item after 3 seconds
      setTimeout(function() {
        var f = document.getElementById('next-cart-form');
        if (f) f.submit();
      }, 3000);
      </script>
    <?php else: ?>
      <form method="post">
        <input type="hidden" name="action" value="reset" />
        <button class="tk-continue-btn" type="submit">
          <?= $cartTotal > 1 ? '🎉 All ' . $cartTotal . ' Tickets Booked! Done' : 'Book Another Ticket' ?>
        </button>
      </form>
      <?php if ($cartTotal > 1): ?>
        <p style="text-align:center;color:#16a34a;font-weight:700;margin-top:.75rem;">
          ✅ All cart items have been booked successfully!
        </p>
      <?php endif; ?>
    <?php endif; ?>
  <?php endif; ?>

</div><!-- /.tk-wrap -->
<?php render_footer(); ?>
</body>
</html>
