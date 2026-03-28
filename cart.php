<?php
declare(strict_types=1);
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/layout.php';

$user = current_user();
$pdo  = db();

if (!isset($_SESSION['cart']) || !is_array($_SESSION['cart'])) {
    $_SESSION['cart'] = [];
}

// ── AJAX / JSON actions ──────────────────────────────────────────────────────
$isAjax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) ||
          (isset($_SERVER['HTTP_ACCEPT']) && str_contains($_SERVER['HTTP_ACCEPT'], 'application/json')) ||
          isset($_GET['action']) || (isset($_POST['action']) && $_POST['action'] !== 'checkout_page');

$action = (string)($_POST['action'] ?? $_GET['action'] ?? '');

if (in_array($action, ['add','update','remove','count','checkout'], true)) {
    header('Content-Type: application/json');

    if ($action === 'add') {
        $tid = (int)($_POST['ticket_type_id'] ?? 0);
        $qty = max(1, (int)($_POST['qty'] ?? 1));
        if ($tid > 0) {
            $st = $pdo->prepare('SELECT id FROM ticket_types WHERE id = ? AND is_active = 1');
            $st->execute([$tid]);
            if ($st->fetch()) {
                $_SESSION['cart'][$tid] = min(20, (int)($_SESSION['cart'][$tid] ?? 0) + $qty);
            }
        }
        echo json_encode(['success' => true, 'count' => array_sum($_SESSION['cart'])]);
        exit;
    }

    if ($action === 'update') {
        $tid = (int)($_POST['ticket_type_id'] ?? 0);
        $qty = (int)($_POST['qty'] ?? 0);
        if ($tid > 0) {
            if ($qty <= 0) unset($_SESSION['cart'][$tid]);
            else $_SESSION['cart'][$tid] = min(20, $qty);
        }
        echo json_encode(['success' => true, 'count' => array_sum($_SESSION['cart'])]);
        exit;
    }

    if ($action === 'remove') {
        $tid = (int)($_POST['ticket_type_id'] ?? 0);
        unset($_SESSION['cart'][$tid]);
        echo json_encode(['success' => true, 'count' => array_sum($_SESSION['cart'])]);
        exit;
    }

    if ($action === 'count') {
        echo json_encode(['count' => array_sum($_SESSION['cart'])]);
        exit;
    }

    if ($action === 'checkout') {
        if (!$user) {
            echo json_encode(['redirect' => 'login.php?next=' . urlencode('cart.php')]);
            exit;
        }
        echo json_encode(['redirect' => 'cart.php']);
        exit;
    }
}

// ── POST: checkout_page — start booking flow from cart ───────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'checkout_page') {
    if (!$user) {
        flash_set('error', 'Please log in to continue.');
        redirect('login.php?next=' . urlencode('cart.php'));
    }
    $tid = (int)($_POST['ticket_type_id'] ?? 0);
    $qty = max(1, (int)($_POST['qty'] ?? 1));
    if ($tid > 0) {
        $_SESSION['booking_flow'] = ['ticket_type_id' => $tid, 'quantity' => $qty];
        redirect('tickets.php?step=1');
    }
    redirect('cart.php');
}

// ── POST: clear cart ─────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'clear') {
    $_SESSION['cart'] = [];
    redirect('cart.php');
}

// ── Build cart items for display ─────────────────────────────────────────────
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
    } catch (Throwable $e) {}
}

$flash = flash_get();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Cart - AmusePark</title>
  <link rel="stylesheet" href="css/style.css"/>
  <style>
    :root {
      --primary: #1e3a8a;
      --primary-dark: #172554;
      --secondary: #fbbf24;
      --secondary-dark: #f59e0b;
      --dark: #0f172a;
      --light: #f8fafc;
    }
    body { background: var(--light); color: var(--dark); font-family: 'Poppins', sans-serif; }

    .cart-hero {
      background: linear-gradient(135deg, var(--dark) 0%, var(--primary-dark) 100%);
      padding: 6rem 2rem 5rem; text-align: center;
      position: relative; overflow: hidden;
    }
    .cart-hero::before {
      content: ''; position: absolute; inset: 0;
      background: url('https://www.transparenttextures.com/patterns/cubes.png'); opacity: 0.1;
    }
    .cart-hero h1 { font-size: clamp(2.5rem, 6vw, 4rem); font-weight: 900; color: #fff; margin: 0 0 1rem; position: relative; }
    .cart-hero p  { color: rgba(255,255,255,0.7); font-size: 1.1rem; margin: 0; position: relative; }

    .cart-wrap { max-width: 1100px; margin: 0 auto; padding: 4rem 1.5rem; }

    .cart-grid { display: grid; grid-template-columns: 1fr 350px; gap: 2.5rem; align-items: start; }

    /* Items panel */
    .cart-panel {
      background: #fff; border: 1px solid #e5e7eb;
      border-radius: 1.25rem; overflow: hidden;
    }
    .cart-panel-header {
      padding: 1.25rem 1.75rem; border-bottom: 1px solid #f3f4f6;
      display: flex; justify-content: space-between; align-items: center;
    }
    .cart-panel-header h2 { font-size: 1.1rem; font-weight: 800; color: #111827; margin: 0; }

    .cart-item {
      display: flex; align-items: center; gap: 1.25rem;
      padding: 1.25rem 1.75rem; border-bottom: 1px solid #f9fafb;
      transition: background .15s;
    }
    .cart-item:last-child { border-bottom: none; }
    .cart-item:hover { background: #fafbff; }

    .cart-item-icon {
      width: 52px; height: 52px; border-radius: .85rem;
      background: #eff6ff; color: var(--primary);
      display: flex; align-items: center; justify-content: center;
      font-size: 1.5rem; flex-shrink: 0;
    }
    .cart-item-info { flex: 1; min-width: 0; }
    .cart-item-name { font-size: 1.15rem; font-weight: 800; color: var(--dark); margin-bottom: .2rem; }
    .cart-item-desc { font-size: .9rem; color: #64748b; }
    .cart-item-price { font-size: .9rem; color: var(--primary); font-weight: 700; margin-top: .4rem; }

    .cart-qty-ctrl {
      display: flex; align-items: center; gap: .5rem; flex-shrink: 0;
    }
    .qty-btn {
      width: 30px; height: 30px; border-radius: 50%;
      border: 1.5px solid #e5e7eb; background: #fff;
      font-size: 1rem; font-weight: 700; color: #374151;
      cursor: pointer; display: flex; align-items: center; justify-content: center;
      transition: border-color .15s, background .15s;
    }
    .qty-btn:hover { border-color: var(--primary); color: var(--primary); background: #eff6ff; }
    .qty-val { font-size: 1rem; font-weight: 800; color: var(--dark); min-width: 24px; text-align: center; }

    .cart-item-subtotal { font-size: 1.2rem; font-weight: 900; color: var(--dark); min-width: 100px; text-align: right; flex-shrink: 0; }

    .cart-remove-btn {
      background: none; border: none; color: #d1d5db; cursor: pointer;
      font-size: 1.1rem; padding: .25rem; border-radius: .4rem;
      transition: color .15s, background .15s; flex-shrink: 0;
    }
    .cart-remove-btn:hover { color: #ef4444; background: #fee2e2; }

    /* Book individual btn */
    .cart-book-btn {
      display: inline-flex; align-items: center; gap: .4rem;
      background: var(--primary); color: #fff; font-weight: 700; font-size: .85rem;
      padding: .6rem 1.25rem; border-radius: 999px; border: none; cursor: pointer;
      transition: all .2s; text-decoration: none; margin-top: .75rem;
    }
    .cart-book-btn:hover { background: var(--primary-dark); transform: translateY(-1px); }

    /* Empty state */
    .cart-empty {
      text-align: center; padding: 4rem 2rem;
    }
    .cart-empty-icon { font-size: 4rem; margin-bottom: 1rem; }
    .cart-empty h3 { font-size: 1.3rem; font-weight: 800; color: #111827; margin: 0 0 .5rem; }
    .cart-empty p  { color: #9ca3af; font-size: .95rem; margin: 0 0 1.5rem; }

    /* Summary panel */
    .cart-summary {
      background: #fff; border: 1px solid #e5e7eb;
      border-radius: 1.25rem; padding: 1.75rem;
      position: sticky; top: 1.5rem;
    }
    .cart-summary h3 { font-size: 1rem; font-weight: 800; color: #111827; margin: 0 0 1.25rem; }
    .summary-row {
      display: flex; justify-content: space-between; align-items: center;
      font-size: .9rem; color: #6b7280; margin-bottom: .75rem;
    }
    .summary-row.total {
      font-size: 1.15rem; font-weight: 900; color: #111827;
      border-top: 1px solid #f3f4f6; padding-top: .75rem; margin-top: .25rem;
    }
    .summary-row.total span:last-child { color: var(--primary); }

    .checkout-btn {
      width: 100%; padding: 1rem; border-radius: 999px;
      background: var(--primary); color: #fff; font-weight: 900; font-size: 1rem;
      border: none; cursor: pointer; transition: all .2s;
      margin-top: 1.25rem; box-shadow: 0 10px 15px -3px rgba(30,58,138,0.2);
    }
    .checkout-btn:hover { background: var(--primary-dark); transform: translateY(-1px); }
    .checkout-btn:disabled { background: #c4b5fd; cursor: not-allowed; transform: none; }

    .continue-link {
      display: block; text-align: center; margin-top: 1rem;
      font-size: .9rem; color: var(--primary); font-weight: 700; text-decoration: none;
    }
    .continue-link:hover { text-decoration: underline; }

    .flash { padding: 1rem 1.25rem; border-radius: .75rem; margin-bottom: 1.5rem; font-weight: 600; font-size: .9rem; }
    .flash.error   { background: #fee2e2; border: 1px solid #fca5a5; color: #991b1b; }
    .flash.success { background: #dcfce7; border: 1px solid #86efac; color: #166534; }

    @media (max-width: 768px) {
      .cart-grid { grid-template-columns: 1fr; }
      .cart-summary { position: static; }
    }
  </style>
</head>
<body>
<?php render_nav($user, ''); ?>

<div class="cart-hero">
  <h1>🛒 Your Cart</h1>
  <p><?= count($cartItems) ?> item<?= count($cartItems) !== 1 ? 's' : '' ?> in your cart</p>
</div>

<div class="cart-wrap">

  <?php if ($flash): ?>
    <div class="flash <?= ($flash['type'] ?? '') === 'error' ? 'error' : 'success' ?>">
      <?= ($flash['type'] ?? '') === 'error' ? '⚠ ' : '✅ ' ?><?= e($flash['message']) ?>
    </div>
  <?php endif; ?>

  <?php if (empty($cartItems)): ?>
    <div class="cart-panel">
      <div class="cart-empty">
        <div class="cart-empty-icon">🛒</div>
        <h3>Your cart is empty</h3>
        <p>Browse our ticket packages and add them to your cart.</p>
        <a href="tickets.php" style="display:inline-flex;align-items:center;gap:.5rem;background:var(--primary);color:#fff;font-weight:800;padding:.85rem 2rem;border-radius:999px;text-decoration:none;font-size:.95rem;">
          🎟 Browse Tickets
        </a>
      </div>
    </div>
  <?php else: ?>
    <div class="cart-grid">

      <!-- Items -->
      <div class="cart-panel">
        <div class="cart-panel-header">
          <h2>Ticket Packages</h2>
          <form method="post" style="margin:0;">
            <input type="hidden" name="action" value="clear"/>
            <button type="submit" style="background:none;border:none;color:#9ca3af;font-size:.82rem;cursor:pointer;font-weight:600;">
              Clear all
            </button>
          </form>
        </div>

        <?php foreach ($cartItems as $item):
          $t   = $item['type'];
          $tid = (int)$t['id'];
          $qty = $item['qty'];
          $maxR = isset($t['max_rides']) && $t['max_rides'] !== null ? (int)$t['max_rides'] : null;
        ?>
          <div class="cart-item" id="cart-item-<?= $tid ?>">
            <div class="cart-item-icon">🎟</div>
            <div class="cart-item-info">
              <div class="cart-item-name"><?= e($t['name']) ?></div>
              <div class="cart-item-desc"><?= e($t['description'] ?? 'Full day park access') ?></div>
              <div class="cart-item-price">
                ₱<?= number_format((float)$t['price'], 0) ?> / person
                <?php if ($maxR !== null): ?>
                  &nbsp;·&nbsp; 🎢 Up to <?= $maxR ?> rides
                <?php else: ?>
                  &nbsp;·&nbsp; 🎢 Unlimited rides
                <?php endif; ?>
              </div>
              <!-- Book this ticket directly -->
              <form method="post" style="display:inline;">
                <input type="hidden" name="action" value="checkout_page"/>
                <input type="hidden" name="ticket_type_id" value="<?= $tid ?>"/>
                <input type="hidden" name="qty" value="<?= $qty ?>" class="hidden-qty-<?= $tid ?>"/>
                <button type="submit" class="cart-book-btn">🎟 Book This Now</button>
              </form>
            </div>

            <!-- Qty controls -->
            <div class="cart-qty-ctrl">
              <button class="qty-btn" onclick="updateQty(<?= $tid ?>, -1)">−</button>
              <span class="qty-val" id="qty-<?= $tid ?>"><?= $qty ?></span>
              <button class="qty-btn" onclick="updateQty(<?= $tid ?>, 1)">+</button>
            </div>

            <div class="cart-item-subtotal" id="sub-<?= $tid ?>">
              ₱<?= number_format($item['subtotal'], 0) ?>
            </div>

            <button class="cart-remove-btn" onclick="removeItem(<?= $tid ?>)" title="Remove">✕</button>
          </div>
        <?php endforeach; ?>
      </div>

      <!-- Summary -->
      <div class="cart-summary">
        <h3>Order Summary</h3>
        <?php foreach ($cartItems as $item): ?>
          <div class="summary-row">
            <span><?= e($item['type']['name']) ?> × <?= $item['qty'] ?></span>
            <span id="sum-sub-<?= (int)$item['type']['id'] ?>">₱<?= number_format($item['subtotal'], 0) ?></span>
          </div>
        <?php endforeach; ?>
        <div class="summary-row total">
          <span>Total</span>
          <span id="cart-total-display">₱<?= number_format($cartTotal, 0) ?></span>
        </div>

        <?php if (!$user): ?>
          <a href="login.php?next=<?= urlencode('cart.php') ?>" class="checkout-btn" style="display:block;text-align:center;text-decoration:none;">
            Log In to Checkout
          </a>
        <?php else: ?>
          <p style="font-size:.82rem;color:#9ca3af;margin:.75rem 0 0;text-align:center;">
            Each ticket is booked separately. Click "Book This Now" on any item to start the booking flow.
          </p>
        <?php endif; ?>

        <a href="tickets.php" class="continue-link">+ Add more tickets</a>
      </div>

    </div>
  <?php endif; ?>

</div>

<script>
var prices = {
  <?php foreach ($cartItems as $item): ?>
  <?= (int)$item['type']['id'] ?>: <?= (float)$item['type']['price'] ?>,
  <?php endforeach; ?>
};

function updateQty(tid, delta) {
  var el  = document.getElementById('qty-' + tid);
  var qty = Math.max(1, Math.min(20, parseInt(el.textContent, 10) + delta));
  el.textContent = qty;

  // Update hidden qty inputs for the "Book This Now" form
  document.querySelectorAll('.hidden-qty-' + tid).forEach(function(i) { i.value = qty; });

  // Update subtotal display
  var price = prices[tid] || 0;
  var sub   = price * qty;
  var subEl = document.getElementById('sub-' + tid);
  var sumEl = document.getElementById('sum-sub-' + tid);
  if (subEl) subEl.textContent = '₱' + Math.round(sub).toLocaleString();
  if (sumEl) sumEl.textContent = '₱' + Math.round(sub).toLocaleString();

  recalcTotal();

  // Persist to server
  fetch('cart.php', {
    method: 'POST',
    headers: {'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest'},
    body: 'action=update&ticket_type_id=' + tid + '&qty=' + qty
  }).then(function(r) { return r.json(); }).then(function(d) { updateNavBadge(d.count); });
}

function removeItem(tid) {
  fetch('cart.php', {
    method: 'POST',
    headers: {'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest'},
    body: 'action=remove&ticket_type_id=' + tid
  }).then(function(r) { return r.json(); }).then(function(d) {
    updateNavBadge(d.count);
    var el = document.getElementById('cart-item-' + tid);
    if (el) {
      el.style.transition = 'opacity .25s, transform .25s';
      el.style.opacity = '0';
      el.style.transform = 'translateX(20px)';
      setTimeout(function() { window.location.reload(); }, 280);
    }
  });
}

function recalcTotal() {
  var total = 0;
  Object.keys(prices).forEach(function(tid) {
    var qEl = document.getElementById('qty-' + tid);
    if (qEl) total += prices[tid] * parseInt(qEl.textContent, 10);
  });
  var el = document.getElementById('cart-total-display');
  if (el) el.textContent = '₱' + Math.round(total).toLocaleString();
}

function updateNavBadge(count) {
  var badge = document.getElementById('cart-nav-badge');
  if (badge) {
    badge.textContent = count;
    badge.style.display = count > 0 ? 'inline-flex' : 'none';
  }
}
</script>
</body>
</html>
