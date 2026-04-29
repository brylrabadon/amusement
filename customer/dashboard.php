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

// Recent bookings with pagination
$custPage = max(1, (int)($_GET['page'] ?? 1));
$custPerPage = 7;
$custTotal = 0;
$recentBookings = [];
try {
    $st = $pdo->prepare('SELECT COUNT(*) AS c FROM bookings WHERE user_id = ?');
    $st->execute([(int)$user['id']]);
    $custTotal = (int)$st->fetch()['c'];
    $custTotalPages = max(1, (int)ceil($custTotal / $custPerPage));
    $custPage = min($custPage, $custTotalPages);
    $custOffset = ($custPage - 1) * $custPerPage;

    $st = $pdo->prepare('SELECT * FROM bookings WHERE user_id = ? ORDER BY created_at DESC LIMIT ? OFFSET ?');
    $st->execute([(int)$user['id'], $custPerPage, $custOffset]);
    $recentBookings = $st->fetchAll();
} catch (\Throwable $e) {
    $custTotalPages = 1;
    $custOffset = 0;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" /><meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Dashboard - AmusePark</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="../css/style.css" />
  <style>
    body { background: #f8fafc; color: #0f172a; font-family: 'Poppins', sans-serif; }
    .dash-welcome {
      background: linear-gradient(135deg, #0f172a 0%, #1e3a8a 60%, #1d4ed8 100%);
      padding: 3rem 2rem; color: #fff; border-radius: 0 0 2rem 2rem; margin-bottom: 2rem;
    }
    .dash-welcome-inner { max-width: 1200px; margin: 0 auto; display: flex; align-items: center; justify-content: space-between; gap: 2rem; flex-wrap: wrap; }
    .dash-welcome h1 { font-size: 1.9rem; font-weight: 900; margin-bottom: .35rem; color: #fff; }
    .dash-welcome p  { color: rgba(255,255,255,0.75); font-size: .95rem; font-weight: 500; margin: 0; }
    .dash-cta-btns { display: flex; gap: .75rem; flex-wrap: wrap; }
    .dash-cta-btns a {
      padding: .7rem 1.5rem; border-radius: .75rem; font-weight: 700; font-size: .88rem;
      text-decoration: none; transition: all .2s; display: inline-flex; align-items: center; gap: .4rem;
    }
    .btn-white { background: rgba(255,255,255,0.15); color: #fff; border: 1px solid rgba(255,255,255,0.25); }
    .btn-white:hover { background: rgba(255,255,255,0.25); }
    .btn-yellow-sm { background: #fbbf24; color: #000; }
    .btn-yellow-sm:hover { background: #f59e0b; }

    .dash-body { max-width: 1200px; margin: 0 auto; padding: 0 1.5rem 5rem; }
    .section-heading {
      font-size: 1.25rem; font-weight: 800; color: #0f172a;
      margin-bottom: 1.5rem; display: flex; align-items: center; gap: .6rem;
      padding-bottom: .75rem; border-bottom: 2px solid #f1f5f9;
    }

    /* Package card */
    .pkg-card {
      background: #fff; border: 1px solid #e2e8f0; border-radius: 1.25rem;
      overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,0.05);
      display: flex; flex-direction: column; transition: transform .2s, box-shadow .2s;
    }
    .pkg-card:hover { transform: translateY(-4px); box-shadow: 0 12px 24px rgba(0,0,0,0.1); }
    .pkg-header {
      background: linear-gradient(135deg, #0f172a 0%, #1e3a8a 100%);
      padding: 1.5rem; display: flex; justify-content: space-between; align-items: flex-start;
    }
    .pkg-header h3 { font-size: 1.1rem; font-weight: 800; color: #fff; margin: 0 0 .2rem; }
    .pkg-cat { font-size: .72rem; color: rgba(255,255,255,0.6); font-weight: 700; text-transform: uppercase; letter-spacing: .08em; }
    .pkg-price .amount { font-size: 1.9rem; font-weight: 900; color: #fbbf24; line-height: 1; }
    .pkg-price .per { font-size: .72rem; color: rgba(255,255,255,0.6); text-align: right; margin-top: .2rem; font-weight: 600; }
    .pkg-body { padding: 1.5rem; flex: 1; display: flex; flex-direction: column; }
    .pkg-desc { color: #475569; font-size: .9rem; margin-bottom: 1.25rem; line-height: 1.6; }

    .allowance-badge {
      display: inline-flex; align-items: center; gap: .6rem;
      background: #eff6ff; border: 1px solid #dbeafe;
      border-radius: .75rem; padding: .65rem 1rem; margin-bottom: 1.25rem;
    }
    .allowance-badge .atext { font-size: .9rem; font-weight: 800; color: #1e3a8a; }
    .allowance-badge .asub  { font-size: .75rem; color: #64748b; display: block; font-weight: 600; margin-top: .1rem; }

    /* Ride checklist */
    .rides-label { font-size: .75rem; font-weight: 800; color: #94a3b8; text-transform: uppercase; letter-spacing: .08em; margin-bottom: .6rem; }
    .ride-counter-badge {
      display: inline-block; background: #eff6ff; border: 1px solid #dbeafe;
      border-radius: .5rem; padding: .3rem .85rem; font-size: .82rem; font-weight: 800;
      color: #1e3a8a; margin-bottom: .85rem;
    }
    .ride-counter-badge.over { background: #fef2f2; border-color: #fecaca; color: #dc2626; }

    .rides-checklist-box { border: 1px solid #e2e8f0; border-radius: .85rem; overflow: hidden; margin-bottom: 1.25rem; }
    .ride-row {
      display: flex; align-items: center; gap: .85rem;
      padding: .85rem 1rem; background: #fff; border-bottom: 1px solid #f1f5f9;
      cursor: pointer; transition: background .15s;
    }
    .ride-row:last-child { border-bottom: none; }
    .ride-row:hover { background: #f8fafc; }
    .ride-row input[type=checkbox] { width: 16px; height: 16px; accent-color: #1e3a8a; flex-shrink: 0; }
    .ride-row .rname { flex: 1; font-size: .9rem; font-weight: 600; color: #0f172a; }
    .ride-row .rcat { font-size: .7rem; font-weight: 700; padding: .2rem .55rem; border-radius: .4rem; text-transform: uppercase; letter-spacing: .04em; }

    /* Save + Book buttons */
    .pkg-actions { display: flex; gap: .6rem; margin-top: auto; padding-top: 1rem; }
    .btn-save-rides {
      flex: 1; padding: .75rem; border-radius: .75rem;
      background: #f1f5f9; color: #475569; border: 1px solid #e2e8f0;
      font-weight: 700; font-size: .85rem; cursor: pointer; transition: all .2s;
    }
    .btn-save-rides:hover { background: #e2e8f0; color: #0f172a; }
    .btn-book-pkg {
      flex: 1; padding: .75rem; border-radius: .75rem;
      background: #1e3a8a; color: #fff; border: none;
      font-weight: 800; font-size: .88rem; cursor: pointer; transition: all .2s;
      text-decoration: none; text-align: center; display: inline-flex;
      align-items: center; justify-content: center;
    }
    .btn-book-pkg:hover { background: #172554; }

    .saved-badge { display: none; align-items: center; gap: .4rem; font-size: .78rem; color: #16a34a; font-weight: 700; margin-bottom: .6rem; background: #f0fdf4; padding: .35rem .75rem; border-radius: 999px; border: 1px solid #dcfce7; }
    .saved-badge.show { display: inline-flex; }

    @media (max-width: 768px) {
      .dash-welcome { padding: 2.5rem 1.5rem; text-align: center; }
      .dash-welcome-inner { flex-direction: column; }
      .dash-cta-btns { justify-content: center; }
    }
  </style>
</head>
<body>
<?php render_nav($user, ''); ?>

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
    <div class="grid grid-3" style="margin-bottom:3rem;align-items:stretch;">
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
    <div class="section-heading">🕒 Your Recent Bookings</div>
    <?php foreach ($recentBookings as $b):
      $paySt = (string)($b['payment_status'] ?? '');
      $pc    = ['Paid' => '#16a34a', 'Pending' => '#ca8a04', 'Cancelled' => '#dc2626'][$paySt] ?? '#64748b';
    ?>
      <div style="background:#fff;border:1px solid #e2e8f0;border-radius:1.25rem;padding:1.25rem 1.5rem;display:flex;align-items:center;justify-content:space-between;gap:1.5rem;flex-wrap:wrap;margin-bottom:.75rem;transition:all .2s;box-shadow:0 2px 4px rgba(0,0,0,0.02);">
        <div style="display:flex;align-items:center;gap:1.25rem;">
          <div style="width:48px;height:48px;border-radius:12px;background:#f1f5f9;display:flex;align-items:center;justify-content:center;font-size:1.5rem;">🎟</div>
          <div>
            <div style="font-family:'JetBrains Mono',monospace;font-weight:800;color:var(--primary);font-size:.95rem;"><?= e($b['booking_reference']) ?></div>
            <div style="font-size:.85rem;color:#64748b;font-weight:600;margin-top:.1rem;"><?= e($b['ticket_type_name']) ?> × <?= (int)$b['quantity'] ?> · <?= date('M d, Y', strtotime((string)$b['visit_date'])) ?></div>
          </div>
        </div>
        <div style="display:flex;align-items:center;gap:1.5rem;">
          <div style="text-align:right;">
            <div style="font-size:.8rem;font-weight:800;color:<?= $pc ?>;text-transform:uppercase;letter-spacing:.05em;"><?= e($paySt) ?></div>
            <div style="font-size:.9rem;font-weight:800;color:#0f172a;margin-top:.1rem;">₱<?= number_format((float)($b['total_amount'] ?? 0), 0) ?></div>
          </div>
          <a href="../booking-detail.php?ref=<?= urlencode((string)$b['booking_reference']) ?>" 
             style="background:#f8fafc;color:var(--primary);border:1.5px solid #e2e8f0;padding:.6rem 1.25rem;border-radius:999px;font-size:.85rem;font-weight:800;text-decoration:none;transition:all .2s;">
            Details
          </a>
        </div>
      </div>
    <?php endforeach; ?>
    <div style="margin-top:1.25rem;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:1rem;">
      <a href="../my-bookings.php" style="color:var(--primary);font-weight:800;font-size:.9rem;text-decoration:none;display:inline-flex;align-items:center;gap:.35rem;">
        View all bookings <span>→</span>
      </a>
      <?php if ($custTotalPages > 1): ?>
      <div class="pagination" style="margin-top:0;">
        <div class="pagination-info">
          <?= $custOffset + 1 ?>–<?= min($custOffset + $custPerPage, $custTotal) ?> of <?= $custTotal ?>
        </div>
        <div class="pagination-pages">
          <a class="pg-btn <?= $custPage <= 1 ? 'disabled' : '' ?>" href="?page=<?= $custPage - 1 ?>">&#8592;</a>
          <?php
            $cs = max(1, $custPage - 2); $ce = min($custTotalPages, $custPage + 2);
            if ($cs > 1) { echo '<a class="pg-btn" href="?page=1">1</a>'; if ($cs > 2) echo '<span class="pg-btn pg-dots">…</span>'; }
            for ($i = $cs; $i <= $ce; $i++): ?>
              <a class="pg-btn <?= $i === $custPage ? 'active' : '' ?>" href="?page=<?= $i ?>"><?= $i ?></a>
            <?php endfor;
            if ($ce < $custTotalPages) { if ($ce < $custTotalPages - 1) echo '<span class="pg-btn pg-dots">…</span>'; echo '<a class="pg-btn" href="?page=' . $custTotalPages . '">' . $custTotalPages . '</a>'; }
          ?>
          <a class="pg-btn <?= $custPage >= $custTotalPages ? 'disabled' : '' ?>" href="?page=<?= $custPage + 1 ?>">&#8594;</a>
        </div>
      </div>
      <?php endif; ?>
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
  var container = document.getElementById('form-' + tid);
  if (!container) return;
  var checked   = container.querySelectorAll('input[type=checkbox]:checked');
  var badge     = document.getElementById('counter-' + tid);
  var count     = checked.length;
  
  if (badge) {
    badge.textContent = count + (max ? ' / ' + max : '') + ' selected';
    if (max && count > max) {
      badge.classList.add('over');
    } else {
      badge.classList.remove('over');
    }
  }

  var warn = document.getElementById('warn-' + tid);
  if (warn) warn.style.display = (max && count > max) ? 'block' : 'none';

  // Update visual state of rows
  container.querySelectorAll('.ride-row').forEach(function(row) {
    var cb = row.querySelector('input[type=checkbox]');
    if (cb.checked) {
      row.style.background = '#f0f9ff';
      row.style.borderColor = '#bae6fd';
    } else {
      row.style.background = '#fff';
      row.style.borderColor = '#f1f5f9';
    }
    
    if (max && !cb.checked && count >= max) {
      row.classList.add('disabled-row');
      cb.disabled = true;
    } else {
      row.classList.remove('disabled-row');
      cb.disabled = false;
    }
  });
}

function confirmSave(tid, max) {
  var container = document.getElementById('form-' + tid);
  var count = container.querySelectorAll('input[name="ride_ids[]"]:checked').length;
  if (count === 0) {
    alert('Please select at least one ride before saving.');
    return false;
  }
  if (max && count > max) {
    alert('You can only select up to ' + max + ' rides for this package.');
    return false;
  }
  return true;
}
</script>
</body>
</html>
