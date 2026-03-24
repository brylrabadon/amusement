<?php
declare(strict_types=1);
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/layout.php';

$user = require_login();
$pdo  = db();

$st = $pdo->prepare('SELECT * FROM bookings WHERE user_id = ? ORDER BY created_at DESC');
$st->execute([(int)$user['id']]);
$bookings = $st->fetchAll();

// Fetch ticket counts per booking
$ticketCounts = [];
try {
    $tcst = $pdo->prepare('SELECT booking_id, COUNT(*) as cnt FROM tickets WHERE booking_id IN (SELECT id FROM bookings WHERE user_id = ?) GROUP BY booking_id');
    $tcst->execute([(int)$user['id']]);
    foreach ($tcst->fetchAll() as $row) {
        $ticketCounts[(int)$row['booking_id']] = (int)$row['cnt'];
    }
} catch (\Throwable $e) {}

$flash    = flash_get();
$payBadge = ['Paid'=>'badge-green','Pending'=>'badge-yellow','Cancelled'=>'badge-red','Refunded'=>'badge-blue'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" /><meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>My Bookings - AmusePark</title>
  <link rel="stylesheet" href="css/style.css" />
  <style>
    body { background: #f9fafb; }
    .booking-ref { font-weight: 800; font-size: 1.05rem; color: #7c3aed; }
    .booking-card { padding: 1.5rem; transition: box-shadow .2s; }
    .booking-card:hover { box-shadow: 0 6px 24px rgba(124,58,237,.1); }
  </style>
</head>
<body>
<?php render_nav($user, 'bookings'); ?>
<?php render_page_header('My Bookings', 'Your ticket bookings and QR codes'); ?>

<div class="container" style="max-width:820px;">
  <?php if ($flash && ($flash['message'] ?? '') !== ''): ?>
    <div style="padding:1rem 1.25rem;border-radius:.75rem;margin-bottom:1.25rem;font-weight:600;
      background:<?= ($flash['type']??'')!=='error'?'#dcfce7':'#fee2e2' ?>;
      border:1px solid <?= ($flash['type']??'')!=='error'?'#86efac':'#fca5a5' ?>;
      color:<?= ($flash['type']??'')!=='error'?'#166534':'#991b1b' ?>;">
      <?= e($flash['message']) ?>
    </div>
  <?php endif; ?>

  <div style="margin-bottom:1.5rem;">
    <a class="btn btn-primary" href="tickets.php">+ Buy New Tickets</a>
  </div>

  <?php if (!count($bookings)): ?>
    <div class="empty">
      <div class="empty-icon">🎟</div>
      <p>You have no bookings yet.</p>
      <a class="btn btn-primary" href="tickets.php" style="margin-top:1rem;display:inline-block;">Buy Tickets</a>
    </div>
  <?php else: ?>
    <div style="display:grid;gap:1rem;">
      <?php foreach ($bookings as $b):
        $pay = (string)($b['payment_status'] ?? 'Pending');
        $tc  = $ticketCounts[(int)$b['id']] ?? 0;
      ?>
        <div class="card booking-card">
          <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:1rem;">
            <div>
              <div class="booking-ref"><?= e($b['booking_reference'] ?? '') ?></div>
              <div style="color:#6b7280;font-size:.9rem;margin-top:.2rem;">
                <?= e($b['ticket_type_name'] ?? '') ?> × <?= (int)($b['quantity'] ?? 1) ?>
              </div>
              <div style="font-size:.88rem;color:#374151;margin-top:.4rem;">
                📅 Visit: <strong><?= e((string)($b['visit_date'] ?? '')) ?></strong>
              </div>
              <?php if ($tc > 0): ?>
                <div style="font-size:.8rem;color:#7c3aed;margin-top:.3rem;">
                  🎫 <?= $tc ?> ticket<?= $tc !== 1 ? 's' : '' ?> generated
                </div>
              <?php endif; ?>
              <div style="margin-top:.6rem;">
                <span class="badge <?= e($payBadge[$pay] ?? 'badge-gray') ?>"><?= e($pay) ?></span>
              </div>
            </div>
            <div style="text-align:right;flex-shrink:0;">
              <div style="font-size:1.4rem;font-weight:900;color:#111827;">
                ₱<?= number_format((float)($b['total_amount'] ?? 0), 0) ?>
              </div>
              <a class="btn btn-outline btn-sm" href="booking-qr.php?id=<?= (int)$b['id'] ?>"
                 style="margin-top:.6rem;display:inline-block;">View QR</a>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<?php render_footer(); ?>
</body>
</html>
