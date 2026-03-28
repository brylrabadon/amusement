<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/layout.php';

$user = require_login();
$pdo  = db();

// Handle ride selection form POST (save to session, then redirect to avoid re-POST on refresh)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_rides'])) {
    $tid = (int)($_POST['ticket_type_id'] ?? 0);
    $selectedIds = [];
    if (isset($_POST['ride_ids']) && is_array($_POST['ride_ids'])) {
        $selectedIds = array_map('intval', $_POST['ride_ids']);
        $selectedIds = array_values(array_filter($selectedIds, fn($v) => $v > 0));
    }
    $_SESSION['dash_ride_selection'][$tid] = $selectedIds;
    // PRG — redirect back to dashboard so refresh doesn't re-POST
    header('Location: ' . $_SERVER['REQUEST_URI']);
    exit;
}

$flash = flash_get();

// Fetch active ticket types
$types = $pdo->query("SELECT * FROM ticket_types WHERE is_active = 1 ORDER BY price ASC")->fetchAll();

// Fetch rides per ticket type
$typeRides = [];
foreach ($types as $t) {
    $tid = (int)$t['id'];
    try {
        $st = $pdo->prepare(
            'SELECT r.id, r.name, r.category, r.status
             FROM ticket_ride tr JOIN rides r ON r.id = tr.ride_id
             WHERE tr.ticket_type_id = ? ORDER BY r.name ASC'
        );
        $st->execute([$tid]);
        $typeRides[$tid] = $st->fetchAll();
    } catch (\Throwable $e) {
        $typeRides[$tid] = [];
    }
}

// Saved ride selections from session
$savedSelections = $_SESSION['dash_ride_selection'] ?? [];

// Recent bookings
$recentBookings = [];
try {
    $st = $pdo->prepare('SELECT * FROM bookings WHERE user_id = ? ORDER BY created_at DESC LIMIT 3');
    $st->execute([(int)$user['id']]);
    $recentBookings = $st->fetchAll();
} catch (\Throwable $e) {}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" /><meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Dashboard - AmusePark</title>
  <link rel="stylesheet" href="../css/style.css" />
  <style>
    body { background: #f9fafb; }
    .dash-welcome { background: linear-gradient(135deg, var(--dark) 0%, var(--primary-dark) 100%); padding:2.5rem 2rem; color:#fff; }
    .dash-welcome-inner { max-width:1100px; margin:0 auto; display:flex; align-items:center; justify-content:space-between; gap:1.5rem; flex-wrap:wrap; }
    .dash-welcome h1 { font-size:1.6rem; font-weight:900; margin-bottom:.25rem; }
    .dash-welcome p  { color:rgba(255,255,255,0.7); font-size:.95rem; }
    .dash-cta-btns { display:flex; gap:.75rem; flex-wrap:wrap; }
    .dash-cta-btns a { padding:.65rem 1.5rem; border-radius:999px; font-weight:700; font-size:.9rem; text-decoration:none; transition:all .2s; }
    .btn-white { background:#fff; color:var(--primary); }
    .btn-white:hover { background:#f8fafc; }
    .btn-yellow-sm { background:#facc15; color:#000; }
    .btn-yellow-sm:hover { background:#fbbf24; }
    .dash-body { max-width:1100px; margin:2.5rem auto; padding:0 1.5rem; }
    .section-heading { font-size:1.2rem; font-weight:900; color:#111827; margin-bottom:1.25rem; }

    /* Package card */
    .pkg-card { background:#fff; border:2px solid #f3f4f6; border-radius:1.25rem; overflow:hidden; box-shadow:0 2px 12px rgba(0,0,0,.06); display:flex; flex-direction:column; }
    .pkg-header { background: linear-gradient(135deg, var(--dark) 0%, var(--primary-dark) 100%); padding:1.4rem 1.75rem; display:flex; justify-content:space-between; align-items:flex-start; }
    .pkg-header h3 { font-size:1.15rem; font-weight:900; color:#fff; margin-bottom:.15rem; }
    .pkg-cat { font-size:.72rem; color:rgba(255,255,255,0.7); font-weight:600; text-transform:uppercase; letter-spacing:.06em; }
    .pkg-price .amount { font-size:1.9rem; font-weight:900; color:#facc15; line-height:1; }
    .pkg-price .per { font-size:.75rem; color:rgba(255,255,255,.7); text-align:right; }
    .pkg-body { padding:1.4rem 1.75rem; flex:1; display:flex; flex-direction:column; }
    .pkg-desc { color:#6b7280; font-size:.88rem; margin-bottom:1rem; }

    .allowance-badge { display:inline-flex; align-items:center; gap:.5rem; background:#eff6ff; border:1.5px solid #dbeafe; border-radius:.6rem; padding:.5rem .9rem; margin-bottom:1.25rem; }
    .allowance-badge .atext { font-size:.88rem; font-weight:700; color:var(--primary); }
    .allowance-badge .asub  { font-size:.75rem; color:#9ca3af; display:block; font-weight:400; }

    /* Ride checklist inside form */
    .rides-label { font-size:.72rem; font-weight:700; color:#9ca3af; text-transform:uppercase; letter-spacing:.08em; margin-bottom:.5rem; }
    .ride-counter-badge { display:inline-block; background:#eff6ff; border:1.5px solid #dbeafe; border-radius:.5rem; padding:.3rem .75rem; font-size:.82rem; font-weight:700; color:var(--primary); margin-bottom:.6rem; }
    .ride-counter-badge.over { background:#fee2e2; border-color:#fca5a5; color:#dc2626; }

    .rides-checklist-box { border:1.5px solid #dbeafe; border-radius:.75rem; overflow:hidden; margin-bottom:1rem; }
    .ride-row { display:flex; align-items:center; gap:.75rem; padding:.75rem 1rem; background:#fff; border-bottom:1px solid #eff6ff; cursor:pointer; transition:background .15s; }
    .ride-row:last-child { border-bottom:none; }
    .ride-row:hover { background:#f8fafc; }
    .ride-row.disabled-row { cursor:not-allowed; opacity:.6; }
    .ride-row input[type=checkbox] { width:16px; height:16px; accent-color:var(--primary); flex-shrink:0; }
    .ride-row .rdot { width:7px; height:7px; border-radius:50%; flex-shrink:0; }
    .ride-row .rname { flex:1; font-size:.9rem; font-weight:600; color:#111827; }
    .ride-row .rcat { font-size:.72rem; font-weight:700; padding:.2rem .5rem; border-radius:.35rem; }
    .no-rides-note { color:#9ca3af; font-size:.85rem; font-style:italic; padding:.75rem 1rem; }

    /* Save + Book buttons */
    .pkg-actions { display:flex; gap:.6rem; margin-top:auto; padding-top:.75rem; }
    .btn-save-rides { flex:1; padding:.65rem; border-radius:999px; background:#eff6ff; color:var(--primary); border:1.5px solid #dbeafe; font-weight:700; font-size:.88rem; cursor:pointer; transition:all .2s; }
    .btn-save-rides:hover { background:#ede9fe; }
    .btn-book-pkg { flex:1; padding:.65rem; border-radius:999px; background:var(--primary); color:#fff; border:none; font-weight:800; font-size:.88rem; cursor:pointer; transition:background .2s; text-decoration:none; text-align:center; display:flex; align-items:center; justify-content:center; }
    .btn-book-pkg:hover { background:#6d28d9; }

    /* Saved indicator */
    .saved-badge { display:none; align-items:center; gap:.35rem; font-size:.78rem; color:#16a34a; font-weight:700; margin-bottom:.5rem; }
    .saved-badge.show { display:flex; }

    /* Recent bookings */
    .booking-row { background:#fff; border:1px solid #f3f4f6; border-radius:.75rem; padding:1rem 1.25rem; display:flex; align-items:center; justify-content:space-between; gap:1rem; flex-wrap:wrap; margin-bottom:.75rem; }
    .booking-ref  { font-family:monospace; font-weight:700; color:var(--primary); font-size:.9rem; }
    .booking-meta { color:#6b7280; font-size:.85rem; }
  </style>
</head>
<body>
<?php render_nav($user, ''); ?>

<div class="dash-welcome">
  <div class="dash-welcome-inner">
    <div>
      <h1>Welcome back, <?= e($user['full_name'] ?? '') ?> 👋</h1>
      <p>Browse packages, pick your rides, then book your visit.</p>
    </div>
    <div class="dash-cta-btns">
      <a href="../rides.php" class="btn-white">🎢 View Rides</a>
      <a href="../my-bookings.php" class="btn-white">My Bookings</a>
      <a href="../tickets.php" class="btn-yellow-sm">🎟 Buy Tickets</a>
    </div>
  </div>
</div>

<div class="dash-body">

  <?php if ($flash && ($flash['message'] ?? '') !== ''): ?>
    <div style="padding:1rem 1.25rem;border-radius:.75rem;margin-bottom:1.5rem;font-weight:600;
      background:<?= ($flash['type']??'')!=='error'?'#dcfce7':'#fee2e2' ?>;
      border:1px solid <?= ($flash['type']??'')!=='error'?'#86efac':'#fca5a5' ?>;
      color:<?= ($flash['type']??'')!=='error'?'#166534':'#991b1b' ?>;">
      <?= e($flash['message']) ?>
    </div>
  <?php endif; ?>

  <div class="section-heading">🎟 Available Ticket Packages</div>

  <?php if (!count($types)): ?>
    <div style="text-align:center;padding:3rem;color:#9ca3af;">
      <div style="font-size:2.5rem;margin-bottom:.75rem;">🎟</div>
      <p>No ticket packages available right now. Check back soon!</p>
    </div>
  <?php else: ?>
    <div class="grid grid-3" style="margin-bottom:3rem;align-items:start;">
      <?php foreach ($types as $t):
        $tid      = (int)$t['id'];
        $rides    = $typeRides[$tid] ?? [];
        $max      = (isset($t['max_rides']) && $t['max_rides'] !== null && $t['max_rides'] !== '') ? (int)$t['max_rides'] : null;
        $hasRides = count($rides) > 0;
        $saved    = $savedSelections[$tid] ?? [];

        if ($max !== null) {
            $allowText = $max . ' ride' . ($max === 1 ? '' : 's') . ' per visit';
            $allowSub  = 'Pick up to ' . $max . ' ride' . ($max === 1 ? '' : 's') . ' below';
        } elseif ($hasRides) {
            $allowText = count($rides) . ' ride' . (count($rides) === 1 ? '' : 's') . ' included';
            $allowSub  = 'Select the rides you want';
        } else {
            $allowText = 'Unlimited rides';
            $allowSub  = 'Access to all available rides';
        }

        $catBg = ['Thrill'=>'#fee2e2','Family'=>'#dcfce7','Kids'=>'#eff6ff','Water'=>'#dbeafe','Classic'=>'#f1f5f9'];
      ?>
        <div class="pkg-card">
          <div class="pkg-header">
            <div>
              <h3><?= e($t['name']) ?></h3>
              <div class="pkg-cat"><?= e($t['category'] ?? 'Single Day') ?></div>
            </div>
            <div class="pkg-price">
              <div class="amount">₱<?= number_format((float)$t['price'], 0) ?></div>
              <div class="per">per person</div>
            </div>
          </div>

          <div class="pkg-body">
            <?php if (!empty($t['description'])): ?>
              <p class="pkg-desc"><?= e($t['description']) ?></p>
            <?php endif; ?>

            <div class="allowance-badge">
              <span style="font-size:1.1rem;">🎢</span>
              <div>
                <span class="atext"><?= e($allowText) ?></span>
                <span class="asub"><?= e($allowSub) ?></span>
              </div>
            </div>

            <?php if ($hasRides): ?>
              <!-- Ride selection form — POST → PRG → refresh-safe -->
              <form method="post" id="form-<?= $tid ?>">
                <input type="hidden" name="save_rides" value="1" />
                <input type="hidden" name="ticket_type_id" value="<?= $tid ?>" />

                <div class="rides-label">Select Your Rides</div>

                <?php if ($max !== null): ?>
                  <div class="ride-counter-badge" id="counter-<?= $tid ?>">
                    <?= count($saved) ?> / <?= $max ?> selected
                  </div>
                <?php endif; ?>

                <?php if (count($saved) > 0): ?>
                  <div class="saved-badge show" style="margin-bottom:.5rem;">
                    ✅ <?= count($saved) ?> ride<?= count($saved) === 1 ? '' : 's' ?> saved
                  </div>
                <?php endif; ?>

                <div class="rides-checklist-box">
                  <?php foreach ($rides as $r):
                    $rId    = (int)$r['id'];
                    $isOpen = ($r['status'] ?? 'Open') === 'Open';
                    $checked = in_array($rId, $saved, true);
                  ?>
                    <label class="ride-row <?= !$isOpen ? 'disabled-row' : '' ?>">
                      <input type="checkbox"
                             name="ride_ids[]"
                             value="<?= $rId ?>"
                             <?= $checked ? 'checked' : '' ?>
                             <?= !$isOpen ? 'disabled' : '' ?>
                             onchange="onRideChange(<?= $tid ?>, <?= $max ?? 'null' ?>)" />
                      <span class="rdot" style="background:<?= $isOpen ? '#16a34a' : '#dc2626' ?>;"></span>
                      <span class="rname" style="<?= !$isOpen ? 'text-decoration:line-through;color:#9ca3af;' : '' ?>">
                        <?= e($r['name']) ?>
                        <?php if (!$isOpen): ?><span style="font-size:.75rem;color:#dc2626;"> (<?= e($r['status'] ?? '') ?>)</span><?php endif; ?>
                      </span>
                      <span class="rcat" style="background:<?= $catBg[$r['category'] ?? ''] ?? '#f9fafb' ?>;color:#374151;">
                        <?= e($r['category'] ?? '') ?>
                      </span>
                    </label>
                  <?php endforeach; ?>
                </div>

                <?php if ($max !== null): ?>
                  <div id="warn-<?= $tid ?>" style="display:none;padding:.5rem .75rem;background:#fee2e2;border-radius:.5rem;font-size:.82rem;color:#991b1b;font-weight:600;margin-bottom:.5rem;">
                    ⚠ Max <?= $max ?> rides allowed for this package.
                  </div>
                <?php endif; ?>

                <div class="pkg-actions">
                  <button type="submit" class="btn-save-rides"
                          onclick="return confirmSave(<?= $tid ?>, <?= $max ?? 'null' ?>)">
                    💾 Save Selection
                  </button>
                  <a href="../tickets.php?pkg=<?= $tid ?>" class="btn-book-pkg">
                    Book Now →
                  </a>
                </div>
              </form>

            <?php else: ?>
              <div class="rides-label">Included Rides</div>
              <div class="rides-checklist-box">
                <p class="no-rides-note">All park rides are included with this pass.</p>
              </div>
              <div class="pkg-actions">
                <a href="../tickets.php?pkg=<?= $tid ?>" class="btn-book-pkg" style="flex:1;">
                  Book Now →
                </a>
              </div>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <!-- Recent Bookings -->
  <?php if (count($recentBookings)): ?>
    <div class="section-heading">📋 Recent Bookings</div>
    <?php foreach ($recentBookings as $b):
      $statusColor = match($b['payment_status'] ?? '') {
        'Paid'      => '#16a34a',
        'Pending'   => '#ca8a04',
        'Cancelled' => '#dc2626',
        default     => '#6b7280',
      };
    ?>
      <div class="booking-row">
        <div>
          <div class="booking-ref"><?= e($b['booking_reference'] ?? '') ?></div>
          <div class="booking-meta">
            <?= e($b['ticket_type_name'] ?? '') ?> × <?= (int)($b['quantity'] ?? 1) ?>
            &nbsp;·&nbsp; Visit: <?= e($b['visit_date'] ?? '') ?>
          </div>
        </div>
        <div style="display:flex;align-items:center;gap:1rem;">
          <span style="font-weight:700;color:<?= $statusColor ?>;font-size:.88rem;"><?= e($b['payment_status'] ?? '') ?></span>
          <a href="../my-bookings.php" class="btn btn-outline btn-sm">View</a>
        </div>
      </div>
    <?php endforeach; ?>
    <div style="text-align:center;margin-top:1rem;">
      <a href="../my-bookings.php" class="btn btn-outline">View All Bookings</a>
    </div>
  <?php endif; ?>

  <?php if (($user['role'] ?? '') === 'admin'): ?>
    <div style="margin-top:2rem;padding:1rem 1.25rem;background:#eff6ff;border-radius:.75rem;border:1px solid #bfdbfe;">
      <strong>Admin:</strong>
      <a href="../admin/admin-dashboard.php" style="color:#1d4ed8;font-weight:800;text-decoration:none;">Go to Admin Dashboard →</a>
    </div>
  <?php endif; ?>

</div>

<?php render_footer(); ?>

<script>
function onRideChange(tid, max) {
  if (max === null) return;
  var checked = document.querySelectorAll('#form-' + tid + ' input[name="ride_ids[]"]:checked').length;
  var counter = document.getElementById('counter-' + tid);
  var warn    = document.getElementById('warn-' + tid);
  if (counter) {
    counter.textContent = checked + ' / ' + max + ' selected';
    counter.classList.toggle('over', checked > max);
  }
  if (warn) warn.style.display = checked > max ? 'block' : 'none';
  // Lock unchecked boxes at limit
  var all = document.querySelectorAll('#form-' + tid + ' input[name="ride_ids[]"]:not([data-closed])');
  all.forEach(function(cb) {
    if (!cb.checked) cb.disabled = checked >= max;
  });
  if (checked < max) {
    all.forEach(function(cb) { cb.disabled = false; });
  }
}

function confirmSave(tid, max) {
  var checked = document.querySelectorAll('#form-' + tid + ' input[name="ride_ids[]"]:checked').length;
  if (checked === 0) {
    alert('Please select at least one ride before saving.');
    return false;
  }
  if (max !== null && checked > max) {
    alert('You can only select up to ' + max + ' rides for this package.');
    return false;
  }
  return true;
}
</script>
</body>
</html>
