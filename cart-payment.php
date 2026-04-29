<?php
declare(strict_types=1);
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/layout.php';
require_once __DIR__ . '/lib/paymongo.php';
require_once __DIR__ . '/lib/mailer.php';

$user = require_login();
$pdo  = db();

$co = $_SESSION['cart_checkout'] ?? null;
if (!$co || empty($co['booking_ids'])) {
    flash_set('error', 'No pending cart checkout found.');
    redirect('cart.php');
}

$bookingIds  = $co['booking_ids'];
$intentId    = (string)($co['intent_id']   ?? '');
$qrImage     = (string)($co['qr_image']    ?? '');
$grandTotal  = (float)($co['grand_total']  ?? 0);
$name        = (string)($co['name']        ?? '');
$email       = (string)($co['email']       ?? '');
$visitDate   = (string)($co['visit_date']  ?? '');
$isDemo      = PAYMONGO_SECRET_KEY === '';

// Poll endpoint
if (isset($_GET['poll_intent']) && $_GET['poll_intent'] !== '') {
    header('Content-Type: application/json');
    $pid = (string)$_GET['poll_intent'];
    if (PAYMONGO_SECRET_KEY !== '' && preg_match('/^pi_[a-zA-Z0-9]+$/', $pid)) {
        $data   = paymongo_get_payment_intent($pid);
        $status = (string)($data['data']['attributes']['status'] ?? 'unknown');
        if ($status === 'succeeded') {
            foreach ($bookingIds as $bid) {
                $pdo->prepare("UPDATE bookings SET payment_status='Paid', payment_reference=? WHERE id=? AND payment_status='Pending'")
                    ->execute(['PAYMONGO-' . $pid, (int)$bid]);
            }
        }
        echo json_encode(['status' => $status]);
    } else {
        echo json_encode(['status' => 'unknown']);
    }
    exit;
}

// Confirm payment POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'confirm_cart_payment') {
    $paid = false;
    if ($intentId !== '' && PAYMONGO_SECRET_KEY !== '') {
        $intentData = paymongo_get_payment_intent($intentId);
        $piStatus   = (string)($intentData['data']['attributes']['status'] ?? '');
        if ($piStatus === 'succeeded') {
            $paid = true;
            foreach ($bookingIds as $bid) {
                $pdo->prepare("UPDATE bookings SET payment_status='Paid', payment_reference=? WHERE id=?")
                    ->execute(['PAYMONGO-' . $intentId, (int)$bid]);
            }
        }
    }
    if (!$paid && $isDemo) {
        foreach ($bookingIds as $bid) {
            $pdo->prepare("UPDATE bookings SET payment_status='Paid', payment_reference=? WHERE id=?")
                ->execute(['DEMO-' . time(), (int)$bid]);
        }
        $paid = true;
    }
    if (!$paid && defined('PAYMONGO_DEV_BYPASS') && PAYMONGO_DEV_BYPASS) {
        foreach ($bookingIds as $bid) {
            $pdo->prepare("UPDATE bookings SET payment_status='Paid', payment_reference=? WHERE id=?")
                ->execute(['DEV-' . time(), (int)$bid]);
        }
        $paid = true;
    }
    if ($paid) {
        // Send confirmation emails for each booking
        foreach ($bookingIds as $bid) {
            $bk = $pdo->prepare('SELECT * FROM bookings WHERE id=?');
            $bk->execute([(int)$bid]);
            $bkRow = $bk->fetch();
            if ($bkRow) {
                $tks = $pdo->prepare('SELECT ticket_number FROM tickets WHERE booking_id=? ORDER BY ticket_number ASC');
                $tks->execute([(int)$bid]);
                $tkNums = $tks->fetchAll(\PDO::FETCH_COLUMN);
                send_booking_confirmation_email($bkRow, $tkNums, []);
            }
        }
        unset($_SESSION['cart_checkout']);
        redirect('cart-confirmed.php');
    }
    flash_set('error', 'Payment not yet confirmed. Please complete the QR Ph payment first.');
    redirect('cart-payment.php');
}

// Load booking details for display
$bookings = [];
foreach ($bookingIds as $bid) {
    $st = $pdo->prepare('SELECT * FROM bookings WHERE id=?');
    $st->execute([(int)$bid]);
    $row = $st->fetch();
    if ($row) $bookings[] = $row;
}

// Expiry from first booking
$expiresAt   = strtotime((string)($bookings[0]['expires_at'] ?? 'now'));
$secondsLeft = max(0, $expiresAt - time());
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/><meta name="viewport" content="width=device-width,initial-scale=1"/>
  <title>Cart Payment — AmusePark</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="css/style.css"/>
  <style>
    *,*::before,*::after{box-sizing:border-box}
    body{font-family:'Poppins',sans-serif;background:#f1f5f9;color:#0f172a;margin:0}
    .pay-hero{background:linear-gradient(135deg,#0f172a 0%,#1e3a8a 100%);padding:3rem 2rem;text-align:center}
    .pay-hero h1{font-size:1.75rem;font-weight:900;color:#fff;margin:0 0 .3rem}
    .pay-hero p{color:rgba(255,255,255,.65);font-size:.9rem;margin:0}
    .pay-wrap{max-width:600px;margin:0 auto;padding:2rem 1.5rem 4rem}
    .pay-card{background:#fff;border:1px solid #e2e8f0;border-radius:1.25rem;padding:1.75rem;margin-bottom:1.5rem;box-shadow:0 2px 8px rgba(0,0,0,.04);text-align:center}
    .countdown{background:#fffbeb;border:1px solid #fde68a;border-radius:999px;padding:.85rem 1.5rem;display:flex;align-items:center;gap:.75rem;margin-bottom:1.5rem}
    .countdown.urgent{background:#fee2e2;border-color:#fecaca}
    .timer-text{font-weight:800;color:#92400e;font-size:.95rem}
    .confirm-btn{width:100%;background:#16a34a;color:#fff;border:none;border-radius:999px;padding:1rem;font-size:1rem;font-weight:900;cursor:pointer;font-family:inherit;box-shadow:0 8px 20px rgba(22,163,74,.2);transition:all .2s;margin-top:1rem}
    .confirm-btn:hover{background:#15803d}
  </style>
</head>
<body>
<?php render_nav($user, ''); ?>

<div class="pay-hero">
  <h1>💳 Complete Payment</h1>
  <p>Scan the QR code to pay for all <?= count($bookings) ?> booking<?= count($bookings) !== 1 ? 's' : '' ?></p>
</div>

<div class="pay-wrap">

  <!-- Countdown -->
  <div class="countdown" id="expiry-banner">
    <span style="font-size:1.3rem;">⏱</span>
    <div>
      <div class="timer-text">Expires in <span id="countdown-display"><?= gmdate('i:s', $secondsLeft) ?></span></div>
      <div style="font-size:.82rem;color:#b45309;">Complete payment before time runs out</div>
    </div>
  </div>

  <!-- QR Code -->
  <div class="pay-card">
    <div style="font-size:.75rem;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:.06em;margin-bottom:.75rem;">Scan to Pay</div>
    <?php if ($isDemo): ?>
      <div style="background:#fef9c3;border:1px solid #fcd34d;border-radius:.75rem;padding:.85rem;margin-bottom:1rem;font-size:.85rem;color:#92400e;">
        ⚠ Demo mode — no real payment required
      </div>
      <div style="border:4px solid #1e3a8a;border-radius:1.25rem;padding:1rem;display:inline-block;">
        <img src="https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=DEMO-CART-<?= implode(',', $bookingIds) ?>"
             style="width:200px;height:200px;display:block;" alt="Demo QR"/>
      </div>
    <?php elseif ($qrImage !== ''): ?>
      <div style="border:4px solid #1e3a8a;border-radius:1.25rem;padding:1rem;display:inline-block;">
        <img src="<?= e($qrImage) ?>" style="width:200px;height:200px;display:block;" alt="QR Ph"/>
      </div>
    <?php else: ?>
      <div style="color:#dc2626;font-weight:700;">QR generation failed. Please try again.</div>
    <?php endif; ?>

    <div style="margin-top:1rem;font-size:.85rem;color:#64748b;">Open GCash / Maya → Scan QR → Pay</div>

    <!-- Total -->
    <div style="margin-top:1.25rem;background:#eff6ff;border:1px solid #dbeafe;border-radius:1rem;padding:1rem;">
      <div style="font-size:.75rem;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.05em;margin-bottom:.25rem;">Total Amount</div>
      <div style="font-size:2rem;font-weight:900;color:#1e3a8a;">₱<?= number_format($grandTotal, 0) ?></div>
    </div>

    <!-- Bookings summary -->
    <div style="margin-top:1rem;text-align:left;">
      <?php foreach ($bookings as $bk): ?>
        <div style="display:flex;justify-content:space-between;padding:.4rem 0;border-bottom:1px solid #f1f5f9;font-size:.85rem;">
          <span style="font-weight:600;"><?= e($bk['ticket_type_name'] ?? '') ?> × <?= (int)$bk['quantity'] ?></span>
          <span style="font-family:monospace;color:#1e3a8a;font-size:.78rem;"><?= e($bk['booking_reference'] ?? '') ?></span>
        </div>
      <?php endforeach; ?>
    </div>

    <div id="payment-status-msg" style="display:none;margin-top:1rem;padding:.85rem;border-radius:.75rem;font-weight:700;"></div>

    <form method="post" id="confirm-form">
      <input type="hidden" name="action" value="confirm_cart_payment"/>
      <button type="submit" class="confirm-btn" id="confirm-btn">✅ I've Paid — Confirm All Bookings</button>
    </form>
  </div>

</div>

<script>
// Countdown
(function() {
  var secs = <?= (int)$secondsLeft ?>;
  var el = document.getElementById('countdown-display');
  var banner = document.getElementById('expiry-banner');
  if (!el || secs <= 0) { window.location.href = 'cart.php?expired=1'; return; }
  var iv = setInterval(function() {
    secs--;
    if (secs <= 0) { clearInterval(iv); window.location.href = 'cart.php?expired=1'; return; }
    var m = Math.floor(secs/60), s = secs%60;
    el.textContent = m + ':' + (s<10?'0':'') + s;
    if (secs <= 30) banner.classList.add('urgent');
  }, 1000);
})();

<?php if ($intentId !== '' && !$isDemo): ?>
// Poll for payment
(function() {
  var intentId = <?= json_encode($intentId) ?>;
  var iv = setInterval(function() {
    fetch('cart-payment.php?poll_intent=' + encodeURIComponent(intentId))
      .then(function(r){ return r.json(); })
      .then(function(d) {
        if (d.status === 'succeeded') {
          clearInterval(iv);
          var msg = document.getElementById('payment-status-msg');
          msg.style.display = 'block';
          msg.style.background = '#dcfce7';
          msg.style.color = '#166534';
          msg.textContent = '✅ Payment received! Confirming bookings…';
          setTimeout(function() {
            document.getElementById('confirm-form').submit();
          }, 1500);
        }
      }).catch(function(){});
  }, 4000);
})();
<?php endif; ?>
</script>

<?php render_footer(); ?>
</body>
</html>
