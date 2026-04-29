<?php
declare(strict_types=1);
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/layout.php';
require_once __DIR__ . '/lib/paymongo.php';
require_once __DIR__ . '/lib/mailer.php';

$user = require_login();
$pdo  = db();

function booking_ref_cart(): string {
    return 'AP-' . strtoupper(base_convert((string)time(), 10, 36)) . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
}
function today_ymd_cart(): string {
    return (new DateTimeImmutable('today'))->format('Y-m-d');
}

// Build cart items
$cartItems = [];
$cartTotal = 0.0;
if (!empty($_SESSION['cart'])) {
    $ids = array_map('intval', array_keys($_SESSION['cart']));
    $in  = implode(',', $ids);
    try {
        $rows = $pdo->query("SELECT * FROM ticket_types WHERE id IN ($in) AND is_active = 1")->fetchAll();
        foreach ($rows as $r) {
            $tid = (int)$r['id'];
            $qty = (int)($_SESSION['cart'][$tid] ?? 0);
            if ($qty <= 0) continue;
            $subtotal    = (float)$r['price'] * $qty;
            $cartTotal  += $subtotal;
            $cartItems[] = ['type' => $r, 'qty' => $qty, 'subtotal' => $subtotal];
        }
    } catch (\Throwable $e) {}
}

if (empty($cartItems)) {
    flash_set('error', 'Your cart is empty.');
    redirect('cart.php');
}

// Load all rides for selection
$allRides = [];
try {
    $allRides = $pdo->query('SELECT id, name, category, status FROM rides ORDER BY name ASC')->fetchAll();
} catch (\Throwable $e) {}

$errors = [];
$bookingIds = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name      = trim((string)($_POST['customer_name'] ?? ''));
    $email     = trim((string)($_POST['customer_email'] ?? ''));
    $phone     = trim((string)($_POST['customer_phone'] ?? ''));
    $visitDate = (string)($_POST['visit_date'] ?? '');

    if ($name === '')      $errors[] = 'Full name is required.';
    if ($email === '')     $errors[] = 'Email is required.';
    if ($visitDate === '') $errors[] = 'Visit date is required.';
    if ($visitDate !== '' && $visitDate < today_ymd_cart()) $errors[] = 'Visit date cannot be in the past.';

    // Validate rides per ticket type
    $allRideIds = array_map('intval', array_column($allRides, 'id'));
    $rideSelections = []; // tid => [ride_ids]
    foreach ($cartItems as $item) {
        $tid = (int)$item['type']['id'];
        $qty = (int)$item['qty'];
        $maxPerTicket = (isset($item['type']['max_rides']) && $item['type']['max_rides'] !== null)
            ? (int)$item['type']['max_rides'] : null;
        $maxTotal = $maxPerTicket !== null ? $maxPerTicket * $qty : null;

        $posted = isset($_POST['rides'][$tid]) && is_array($_POST['rides'][$tid])
            ? array_values(array_filter(array_map('intval', $_POST['rides'][$tid]), fn($id) => in_array($id, $allRideIds, true)))
            : [];

        if ($maxTotal !== null && count($posted) === 0)
            $errors[] = e($item['type']['name']) . ': Please select at least one ride.';
        if ($maxTotal !== null && count($posted) > $maxTotal)
            $errors[] = e($item['type']['name']) . ': Max ' . $maxTotal . ' rides allowed.';

        $rideSelections[$tid] = $posted;
    }

    if (empty($errors)) {
        $expiresAt = date('Y-m-d H:i:s', time() + 180);
        $grandTotal = 0.0;

        foreach ($cartItems as $item) {
            $tid  = (int)$item['type']['id'];
            $qty  = (int)$item['qty'];
            $type = $item['type'];
            $unitPrice = (float)$type['price'];
            $total     = $unitPrice * $qty;
            $grandTotal += $total;

            $selectedRideIds   = $rideSelections[$tid] ?? [];
            $selectedRideNames = [];
            if (count($selectedRideIds) > 0) {
                try {
                    $inR = implode(',', $selectedRideIds);
                    $selectedRideNames = $pdo->query("SELECT name FROM rides WHERE id IN ($inR) ORDER BY name ASC")->fetchAll(\PDO::FETCH_COLUMN);
                } catch (\Throwable $e) {}
            }

            $ref         = booking_ref_cart();
            $ridesSuffix = count($selectedRideNames) > 0 ? '|RIDES:' . implode(',', $selectedRideNames) : '';
            $qrData      = 'AMUSEPARK|' . $ref . '|' . $name . '|' . $visitDate . '|' . ($type['name'] ?? '') . 'x' . $qty . $ridesSuffix;

            $ins = $pdo->prepare(
                'INSERT INTO bookings (booking_reference, user_id, customer_name, customer_email, customer_phone, visit_date,
                    ticket_type_id, ticket_type_name, quantity, unit_price, total_amount, payment_status, payment_method, qr_code_data, status, expires_at, payment_deadline)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
            );
            $ins->execute([$ref, (int)$user['id'], $name, $email, $phone, $visitDate,
                $tid, (string)$type['name'], $qty, $unitPrice, $total,
                'Pending', 'QR Ph', $qrData, 'Active', $expiresAt, $expiresAt]);
            $bookingId = (int)$pdo->lastInsertId();

            // Individual tickets
            try {
                $insT = $pdo->prepare('INSERT INTO tickets (booking_id, ticket_number, status) VALUES (?, ?, ?)');
                for ($i = 1; $i <= $qty; $i++) {
                    $insT->execute([$bookingId, 'TK-' . $ref . '-' . str_pad((string)$i, 3, '0', STR_PAD_LEFT), 'ACTIVE']);
                }
            } catch (\Throwable $e) {}

            // Rides
            if (count($selectedRideIds) > 0) {
                try {
                    $insR = $pdo->prepare('INSERT IGNORE INTO booking_rides (booking_id, ride_id) VALUES (?, ?)');
                    foreach ($selectedRideIds as $rId) $insR->execute([$bookingId, $rId]);
                } catch (\Throwable $e) {}
            }

            $bookingIds[] = $bookingId;
        }

        // Generate one combined PayMongo QR for the grand total
        $combinedIntentId = '';
        $combinedQrImage  = '';
        if (PAYMONGO_SECRET_KEY !== '' && count($bookingIds) > 0) {
            $amountCentavos = (int)round($grandTotal * 100);
            $desc = 'AmusePark Cart — ' . count($cartItems) . ' ticket type(s)';
            $qrResult = paymongo_create_qrph($amountCentavos, $desc, $name, $email, $phone,
                ['booking_ids' => implode(',', $bookingIds)]);
            if ($qrResult['success']) {
                $combinedIntentId = $qrResult['payment_intent_id'];
                $combinedQrImage  = $qrResult['qr_image_url'];
                // Store intent on all bookings
                foreach ($bookingIds as $bid) {
                    $pdo->prepare('UPDATE bookings SET paymongo_intent_id=?, paymongo_qr_image=?, paymongo_qr_code_id=? WHERE id=?')
                        ->execute([$combinedIntentId, $combinedQrImage, $qrResult['qr_code_id'] ?? '', $bid]);
                }
            }
        }

        // Store in session for payment page
        $_SESSION['cart_checkout'] = [
            'booking_ids'   => $bookingIds,
            'intent_id'     => $combinedIntentId,
            'qr_image'      => $combinedQrImage,
            'grand_total'   => $grandTotal,
            'name'          => $name,
            'email'         => $email,
            'visit_date'    => $visitDate,
        ];
        $_SESSION['cart'] = []; // clear cart
        redirect('cart-payment.php');
    }
}

$flash = flash_get();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/><meta name="viewport" content="width=device-width,initial-scale=1"/>
  <title>Cart Checkout — AmusePark</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="css/style.css"/>
  <style>
    *,*::before,*::after{box-sizing:border-box}
    body{font-family:'Poppins',sans-serif;background:#f1f5f9;color:#0f172a;margin:0}
    .co-hero{background:linear-gradient(135deg,#0f172a 0%,#1e3a8a 100%);padding:3rem 2rem;text-align:center}
    .co-hero h1{font-size:1.75rem;font-weight:900;color:#fff;margin:0 0 .3rem}
    .co-hero p{color:rgba(255,255,255,.65);font-size:.9rem;margin:0}
    .co-wrap{max-width:760px;margin:0 auto;padding:2rem 1.5rem 4rem}
    .co-card{background:#fff;border:1px solid #e2e8f0;border-radius:1.25rem;padding:1.75rem;margin-bottom:1.5rem;box-shadow:0 2px 8px rgba(0,0,0,.04)}
    .co-card h2{font-size:1rem;font-weight:800;color:#0f172a;margin:0 0 1.25rem;padding-bottom:.75rem;border-bottom:1px solid #f1f5f9}
    .form-group{margin-bottom:1.1rem}
    .form-group label{display:block;font-size:.78rem;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.05em;margin-bottom:.4rem}
    .form-group input{width:100%;background:#f8fafc;border:1.5px solid #e2e8f0;color:#0f172a;border-radius:.75rem;padding:.75rem 1rem;font-size:.95rem;font-family:inherit;transition:border-color .2s}
    .form-group input:focus{outline:none;border-color:#1e3a8a;background:#fff}
    .ride-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:.6rem;margin-top:.75rem}
    .ride-cb{display:flex;align-items:center;gap:.6rem;background:#f8fafc;border:1.5px solid #e2e8f0;border-radius:.75rem;padding:.6rem .85rem;cursor:pointer;transition:all .2s;font-size:.85rem;font-weight:600}
    .ride-cb:hover{border-color:#1e3a8a;background:#eff6ff}
    .ride-cb input{accent-color:#1e3a8a;width:16px;height:16px;flex-shrink:0}
    .ride-cb.checked{border-color:#1e3a8a;background:#eff6ff}
    .ride-counter{display:inline-block;background:#eff6ff;border:2px solid #dbeafe;border-radius:.6rem;padding:.3rem .85rem;font-size:.82rem;font-weight:800;color:#1e3a8a;margin-bottom:.6rem}
    .submit-btn{width:100%;background:#1e3a8a;color:#fff;border:none;border-radius:999px;padding:1rem;font-size:1rem;font-weight:900;cursor:pointer;font-family:inherit;box-shadow:0 8px 20px rgba(30,58,138,.2);transition:all .2s}
    .submit-btn:hover{background:#172554;transform:translateY(-2px)}
    .error-box{background:#fef2f2;border:1px solid #fca5a5;border-radius:.85rem;padding:1rem 1.25rem;margin-bottom:1.5rem;color:#991b1b;font-weight:600;font-size:.9rem}
    .ticket-badge{display:inline-flex;align-items:center;gap:.4rem;background:#eff6ff;border:1px solid #dbeafe;border-radius:999px;padding:.25rem .85rem;font-size:.78rem;font-weight:700;color:#1e3a8a;margin-bottom:.75rem}
  </style>
</head>
<body>
<?php render_nav($user, ''); ?>

<div class="co-hero">
  <h1>🛒 Cart Checkout</h1>
  <p>Fill in your details once — book all <?= count($cartItems) ?> ticket type<?= count($cartItems) !== 1 ? 's' : '' ?> at the same time</p>
</div>

<div class="co-wrap">

  <?php if (!empty($errors)): ?>
    <div class="error-box">
      <?php foreach ($errors as $err): ?>
        <div>⚠ <?= e($err) ?></div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <form method="post">

    <!-- Customer details -->
    <div class="co-card">
      <h2>👤 Your Details</h2>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;">
        <div class="form-group">
          <label>Full Name *</label>
          <input name="customer_name" value="<?= e($user['full_name'] ?? '') ?>" placeholder="Juan dela Cruz" required/>
        </div>
        <div class="form-group">
          <label>Email *</label>
          <input name="customer_email" type="email" value="<?= e($user['email'] ?? '') ?>" placeholder="juan@email.com" required/>
        </div>
        <div class="form-group">
          <label>Phone</label>
          <input name="customer_phone" value="<?= e($user['phone'] ?? '') ?>" placeholder="+63 9XX XXX XXXX"/>
        </div>
        <div class="form-group">
          <label>Visit Date *</label>
          <input name="visit_date" type="date" min="<?= date('Y-m-d') ?>" required/>
        </div>
      </div>
    </div>

    <!-- Ride selection per ticket type -->
    <?php foreach ($cartItems as $item):
      $tid = (int)$item['type']['id'];
      $qty = (int)$item['qty'];
      $maxPerTicket = (isset($item['type']['max_rides']) && $item['type']['max_rides'] !== null) ? (int)$item['type']['max_rides'] : null;
      $maxTotal = $maxPerTicket !== null ? $maxPerTicket * $qty : null;
    ?>
      <div class="co-card">
        <h2>🎢 <?= e($item['type']['name']) ?> × <?= $qty ?></h2>
        <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.5rem;margin-bottom:.75rem;">
          <div style="font-size:.85rem;color:#64748b;">
            <?php if ($maxTotal !== null): ?>
              Pick up to <strong style="color:#1e3a8a;"><?= $maxTotal ?> rides</strong>
              <?php if ($qty > 1): ?>(<?= $maxPerTicket ?> per ticket × <?= $qty ?>)<?php endif; ?>
            <?php else: ?>
              Select any rides you want (unlimited)
            <?php endif; ?>
          </div>
          <?php if ($maxTotal !== null): ?>
            <div class="ride-counter" id="counter-<?= $tid ?>">0 / <?= $maxTotal ?> selected</div>
          <?php endif; ?>
        </div>
        <div class="ride-grid">
          <?php foreach ($allRides as $r):
            $rId    = (int)$r['id'];
            $isOpen = ($r['status'] ?? 'Open') === 'Open';
          ?>
            <label class="ride-cb" id="lbl-<?= $tid ?>-<?= $rId ?>" style="<?= !$isOpen ? 'opacity:.5;pointer-events:none;' : '' ?>">
              <input type="checkbox" name="rides[<?= $tid ?>][]" value="<?= $rId ?>"
                     <?= !$isOpen ? 'disabled' : '' ?>
                     onchange="updateCounter(<?= $tid ?>, <?= $maxTotal ?? 'null' ?>)"/>
              <?= e($r['name']) ?>
              <?php if (!$isOpen): ?><span style="font-size:.7rem;color:#dc2626;">(<?= e($r['status']) ?>)</span><?php endif; ?>
            </label>
          <?php endforeach; ?>
        </div>
        <?php if ($maxTotal !== null): ?>
          <div id="warn-<?= $tid ?>" style="display:none;margin-top:.5rem;color:#dc2626;font-size:.82rem;font-weight:700;">
            ⚠ Max <?= $maxTotal ?> rides for this ticket.
          </div>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>

    <!-- Order summary -->
    <div class="co-card">
      <h2>💳 Order Summary</h2>
      <?php foreach ($cartItems as $item): ?>
        <div style="display:flex;justify-content:space-between;padding:.5rem 0;border-bottom:1px solid #f1f5f9;font-size:.9rem;">
          <span><?= e($item['type']['name']) ?> × <?= $item['qty'] ?></span>
          <span style="font-weight:700;">₱<?= number_format($item['subtotal'], 0) ?></span>
        </div>
      <?php endforeach; ?>
      <div style="display:flex;justify-content:space-between;padding:.75rem 0 0;font-size:1.1rem;font-weight:900;color:#1e3a8a;">
        <span>Total</span>
        <span>₱<?= number_format($cartTotal, 0) ?></span>
      </div>
    </div>

    <button type="submit" class="submit-btn">💳 Proceed to Payment →</button>
  </form>
</div>

<script>
function updateCounter(tid, max) {
  var checked = document.querySelectorAll('input[name="rides[' + tid + '][]"]:checked').length;
  var counter = document.getElementById('counter-' + tid);
  var warn    = document.getElementById('warn-' + tid);
  if (counter && max !== null) {
    counter.textContent = checked + ' / ' + max + ' selected';
    counter.style.borderColor = checked >= max ? '#1e3a8a' : '#dbeafe';
  }
  if (warn) warn.style.display = (max !== null && checked > max) ? 'block' : 'none';
  // Disable unchecked boxes when limit reached
  if (max !== null) {
    document.querySelectorAll('input[name="rides[' + tid + '][]"]').forEach(function(cb) {
      if (!cb.checked && !cb.disabled) {
        cb.closest('label').style.opacity = checked >= max ? '.45' : '1';
        cb.closest('label').style.pointerEvents = checked >= max ? 'none' : '';
      }
    });
  }
  // Highlight checked
  document.querySelectorAll('input[name="rides[' + tid + '][]"]').forEach(function(cb) {
    var lbl = cb.closest('label');
    if (lbl) {
      lbl.style.borderColor = cb.checked ? '#1e3a8a' : '';
      lbl.style.background  = cb.checked ? '#eff6ff' : '';
    }
  });
}
</script>

<?php render_footer(); ?>
</body>
</html>
