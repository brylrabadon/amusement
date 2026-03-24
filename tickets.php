<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/layout.php';
require_once __DIR__ . '/lib/paymongo.php';

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
        $maxRides = (isset($type['max_rides']) && $type['max_rides'] !== null && $type['max_rides'] !== '')
            ? (int)$type['max_rides'] : null;

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
        try {
            $insTicket = $pdo->prepare('INSERT INTO tickets (booking_id, ticket_number, status) VALUES (?, ?, ?)');
            for ($i = 1; $i <= max(1, $qty); $i++) {
                $ticketNumber = 'TK-' . $ref . '-' . str_pad((string)$i, 3, '0', STR_PAD_LEFT);
                $insTicket->execute([$bookingId, $ticketNumber, 'ACTIVE']);
            }
        } catch (\Throwable $e) {}
        $flow['booking_id']         = $bookingId;
        $flow['selected_ride_ids']  = $selectedRideIds;
        $flow['selected_ride_names'] = $selectedRideNames;
        $_SESSION['booking_flow'] = $flow;

        // Generate PayMongo QR Ph code immediately
        if (PAYMONGO_SECRET_KEY !== '') {
            $amountCentavos = (int)round($total * 100);
            $description    = 'AmusePark ' . ($type['name'] ?? 'Ticket') . ' x' . max(1, $qty);
            $qrResult = paymongo_create_qrph(
                $amountCentavos, $description,
                $name, $email, $phone,
                ['booking_reference' => $ref, 'booking_id' => $bookingId]
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

        // Check PayMongo payment intent status
        $intentId = (string)($booking['paymongo_intent_id'] ?? '');
        $paid = false;
        if ($intentId !== '' && PAYMONGO_SECRET_KEY !== '') {
            $intentData = paymongo_get_payment_intent($intentId);
            $piStatus   = (string)($intentData['data']['attributes']['status'] ?? '');
            if ($piStatus === 'succeeded') {
                $paid = true;
                // Get payment reference from payments array
                $payments = $intentData['data']['attributes']['payments'] ?? [];
                $pmRef = !empty($payments) ? (string)($payments[0]['attributes']['external_reference_number'] ?? '') : '';
                $pdo->prepare('UPDATE bookings SET payment_status = ?, payment_reference = ? WHERE id = ?')
                    ->execute(['Paid', $pmRef ?: ('PAYMONGO-' . $intentId), $bookingId]);
            }
        }

        if (!$paid) {
            // Fallback for test/demo mode when no real API key is set
            if (PAYMONGO_SECRET_KEY === '') {
                $pdo->prepare('UPDATE bookings SET payment_status = ?, payment_reference = ? WHERE id = ?')
                    ->execute(['Paid', 'DEMO-' . time(), $bookingId]);
                $paid = true;
            } else {
                flash_set('error', 'Payment not yet confirmed. Please complete the QR Ph payment first.');
                redirect('tickets.php?step=2');
            }
        }

        redirect('tickets.php?step=3');
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
        }
        $pdo->prepare('UPDATE bookings SET paymongo_intent_id = ?, paymongo_qr_image = ?, paymongo_qr_code_id = ? WHERE id = ?')
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
    /* ── Tickets page theme (light + purple) ── */
    body.tickets-page { background: #f9fafb; color: #111827; }

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
    .tk-cart-add-btn {
      display: block; margin-top: .5rem; width: 100%;
      background: #fff; color: #7c3aed;
      font-weight: 700; font-size: .85rem;
      padding: .55rem 1.25rem; border-radius: 999px;
      border: 2px solid #7c3aed; cursor: pointer;
      transition: background .2s, color .2s, transform .15s;
      white-space: nowrap;
    }
    .tk-cart-add-btn:hover { background: #7c3aed; color: #fff; transform: scale(1.04); }
    .tk-cart-add-btn.added { background: #dcfce7; color: #16a34a; border-color: #86efac; }

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
                <div style="color:#7c3aed;font-size:.85rem;font-weight:700;margin-bottom:.5rem;">🎢 Pick up to <?= (int)$t['max_rides'] ?> ride<?= (int)$t['max_rides'] === 1 ? '' : 's' ?> — you choose in the next step</div>
              <?php else: ?>
                <div style="color:#7c3aed;font-size:.85rem;font-weight:700;margin-bottom:.5rem;">🎢 Unlimited rides — pick any you want</div>
              <?php endif; ?>
              <input type="radio" id="radio-<?= $tid ?>" name="ticket_type_id" value="<?= $tid ?>"
                     data-price="<?= $price ?>" <?= $isSelected ? 'checked' : '' ?>
                     style="display:none;" />
            </div>
            <div class="tk-card-right">
              <div class="tk-price">₱<?= number_format($price, 0) ?></div>
              <div class="tk-price-label">per person</div>
              <button type="button" class="tk-buy-btn" onclick="event.stopPropagation();selectTicket(<?= $tid ?>, <?= $price ?>)">
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

        <button class="tk-continue-btn" type="submit">Continue to Details →</button>
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
          <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1.25rem;flex-wrap:wrap;gap:.75rem;">
            <div>
              <div style="font-size:1.1rem;font-weight:800;color:#111827;display:flex;align-items:center;gap:.5rem;">
                🎢 Select Your Rides
              </div>
              <?php if ($maxRides1 !== null): ?>
                <div style="font-size:.88rem;color:#7c3aed;font-weight:600;margin-top:.3rem;">
                  Pick up to <strong><?= $maxRides1 ?></strong> ride<?= $maxRides1 === 1 ? '' : 's' ?> included in your package
                </div>
              <?php else: ?>
                <div style="font-size:.88rem;color:#7c3aed;font-weight:600;margin-top:.3rem;">
                  All rides are included — select the ones you want
                </div>
              <?php endif; ?>
            </div>
            <?php if ($maxRides1 !== null): ?>
              <div id="ride-counter"
                   style="background:#faf5ff;border:2px solid #e9d5ff;border-radius:.6rem;
                          padding:.45rem 1rem;font-size:.9rem;font-weight:800;color:#7c3aed;
                          min-width:110px;text-align:center;">
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
                            'Kids'  =>['bg'=>'#f3e8ff','color'=>'#7c3aed'],
                            'Water' =>['bg'=>'#dbeafe','color'=>'#1d4ed8'],
                            'Classic'=>['bg'=>'#f1f5f9','color'=>'#475569']];
              $cat      = (string)($r['category'] ?? '');
              $catStyle = $catColors[$cat] ?? ['bg'=>'#f1f5f9','color'=>'#475569'];
            ?>
              <label class="ride-checkbox-card <?= !$isOpen ? 'ride-disabled' : '' ?>"
                     style="display:flex;flex-direction:column;gap:.5rem;
                            background:<?= $checked ? '#faf5ff' : '#fff' ?>;
                            border:2px solid <?= $checked ? '#7c3aed' : '#e5e7eb' ?>;
                            border-radius:.85rem;padding:1rem;
                            cursor:<?= $isOpen ? 'pointer' : 'not-allowed' ?>;
                            transition:border-color .15s,background .15s,box-shadow .15s;
                            position:relative;opacity:<?= $isOpen ? '1' : '.55' ?>;"
                     onclick="<?= $isOpen ? '' : 'return false;' ?>">
                <input type="checkbox"
                       name="selected_ride_ids[]"
                       value="<?= $rId ?>"
                       <?= $checked ? 'checked' : '' ?>
                       <?= !$isOpen ? 'disabled' : '' ?>
                       onchange="onRideChange(this)"
                       style="position:absolute;top:.75rem;right:.75rem;
                              width:18px;height:18px;accent-color:#7c3aed;cursor:inherit;" />
                <div style="font-weight:700;font-size:.95rem;color:<?= $isOpen ? '#111827' : '#9ca3af' ?>;
                            padding-right:1.75rem;line-height:1.3;">
                  <?= e($r['name']) ?>
                </div>
                <div style="display:flex;align-items:center;gap:.5rem;flex-wrap:wrap;">
                  <span style="font-size:.72rem;font-weight:700;padding:.2rem .55rem;border-radius:.35rem;
                               background:<?= $catStyle['bg'] ?>;color:<?= $catStyle['color'] ?>;">
                    <?= e($cat) ?>
                  </span>
                  <?php if (!empty($r['duration_minutes'])): ?>
                    <span style="font-size:.72rem;color:#6b7280;">⏱ <?= (int)$r['duration_minutes'] ?>min</span>
                  <?php endif; ?>
                  <?php if (!empty($r['min_height_cm'])): ?>
                    <span style="font-size:.72rem;color:#6b7280;">📏 <?= (int)$r['min_height_cm'] ?>cm+</span>
                  <?php endif; ?>
                </div>
                <?php if (!$isOpen): ?>
                  <span style="font-size:.72rem;color:#dc2626;font-weight:700;">
                    🚫 <?= e($r['status'] ?? 'Closed') ?>
                  </span>
                <?php endif; ?>
              </label>
            <?php endforeach; ?>
          </div>

          <?php if ($maxRides1 !== null): ?>
            <div id="ride-limit-warn"
                 style="display:none;margin-top:.85rem;padding:.65rem 1rem;
                        background:#fee2e2;border-radius:.6rem;font-size:.85rem;
                        color:#991b1b;font-weight:600;">
              ⚠ You can only select up to <?= $maxRides1 ?> ride<?= $maxRides1 === 1 ? '' : 's' ?> for this package.
            </div>
          <?php endif; ?>
        </div>

        <script>
        (function() {
          var max = <?= $maxRides1 !== null ? $maxRides1 : 'null' ?>;

          function updateRideState() {
            // Always query ALL boxes (including currently disabled ones)
            var allBoxes     = document.querySelectorAll('input[name="selected_ride_ids[]"]');
            var checkedCount = document.querySelectorAll('input[name="selected_ride_ids[]"]:checked').length;
            var counter      = document.getElementById('ride-counter');
            var warn         = document.getElementById('ride-limit-warn');

            // Update counter badge
            if (counter && max !== null) {
              counter.textContent = checkedCount + ' / ' + max + ' selected';
              counter.style.borderColor = checkedCount >= max ? '#7c3aed' : '#e9d5ff';
              counter.style.background  = checkedCount >= max ? '#ede9fe' : '#faf5ff';
            }

            // Show/hide over-limit warning
            if (warn) warn.style.display = (max !== null && checkedCount > max) ? 'block' : 'none';

            // Enable/disable unchecked boxes based on whether limit is reached
            if (max !== null) {
              allBoxes.forEach(function(box) {
                if (box.checked) {
                  // Always keep checked boxes enabled so user can uncheck them
                  box.disabled = false;
                } else {
                  // Disable unchecked boxes only when at the limit
                  // But respect the original "ride closed" state
                  var isClosedRide = box.hasAttribute('data-closed');
                  box.disabled = isClosedRide || (checkedCount >= max);
                }
                // Update card cursor
                var card = box.closest('label');
                if (card) {
                  card.style.cursor = (box.disabled && !box.checked) ? 'not-allowed' : 'pointer';
                  card.style.opacity = (box.disabled && !box.checked) ? '0.45' : '1';
                }
              });
            }
          }

          function onRideChange(cb) {
            // Update card visual immediately
            var card = cb.closest('label');
            if (card) {
              card.style.borderColor = cb.checked ? '#7c3aed' : '#e5e7eb';
              card.style.background  = cb.checked ? '#faf5ff' : '#fff';
              card.style.boxShadow   = cb.checked ? '0 0 0 3px rgba(124,58,237,.1)' : 'none';
            }
            updateRideState();
          }

          window.onRideChange = onRideChange;

          // Mark closed rides with a data attribute so we can preserve their disabled state
          document.querySelectorAll('input[name="selected_ride_ids[]"]').forEach(function(cb) {
            if (cb.disabled) cb.setAttribute('data-closed', '1');

            // Restore visual state for pre-checked boxes (e.g. after validation error redirect)
            if (cb.checked) {
              var card = cb.closest('label');
              if (card) {
                card.style.borderColor = '#7c3aed';
                card.style.background  = '#faf5ff';
                card.style.boxShadow   = '0 0 0 3px rgba(124,58,237,.1)';
              }
            }
          });

          // Run on page load to set correct initial state
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
      $createdAt   = strtotime((string)($booking['created_at'] ?? 'now'));
      $expiresAt   = $createdAt + 180; // 3-min booking expiry
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
          <img src="https://api.qrserver.com/v1/create-qr-code/?size=220x220&data=<?= e(urlencode((string)($booking['booking_reference'] ?? ''))) ?>"
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
          <button onclick="regenerateQR()" id="regen-btn" style="background:none;border:none;color:#7c3aed;font-weight:700;cursor:pointer;font-size:.82rem;text-decoration:underline;">Regenerate QR</button>
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
            else { alert('Error: ' + (data.error || 'Unknown')); btn.textContent = 'Generate QR Code'; btn.disabled = false; }
          });
        }
        </script>
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
    <div id="payment-status-msg" style="display:none;padding:.85rem 1.25rem;border-radius:.75rem;margin-bottom:1rem;font-weight:700;"></div>
    <?php if ($intentId !== '' && !$isDemo): ?>
    <script>
    (function() {
      var intentId = <?= json_encode($intentId) ?>;
      var pollIv = setInterval(function() {
        fetch('tickets.php?poll_intent=' + encodeURIComponent(intentId))
          .then(r => r.json())
          .then(data => {
            if (data.status === 'succeeded') {
              clearInterval(pollIv);
              var msg = document.getElementById('payment-status-msg');
              msg.style.display = 'block';
              msg.style.background = '#dcfce7';
              msg.style.color = '#166534';
              msg.style.border = '1px solid #86efac';
              msg.textContent = '✅ Payment received! Redirecting…';
              setTimeout(function() {
                document.querySelector('form [name="action"][value="confirm_payment"]')
                  .closest('form').submit();
              }, 1500);
            }
          })
          .catch(function() {});
      }, 5000);
    })();
    </script>
    <?php endif; ?>

    <div class="tk-action-row">
      <form method="post" style="flex:1;">
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
