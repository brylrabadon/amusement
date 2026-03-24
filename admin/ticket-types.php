<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/layout.php';

$user = require_admin();
$pdo  = db();

function ticket_ride_label(array $t, PDO $pdo): string {
    if (!empty($t['max_rides'])) {
        if ((int)$t['max_rides'] === 5) return 'Super Five — 5 rides only';
        return (int)$t['max_rides'] . ' rides included';
    }
    try {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM ticket_ride WHERE ticket_type_id = ?');
        $stmt->execute([(int)$t['id']]);
        $count = (int)$stmt->fetchColumn();
        if ($count > 0) return $count . ' specific ride' . ($count === 1 ? '' : 's') . ' included';
    } catch (\Throwable $e) {}
    return 'Unlimited rides';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $pdo->prepare('DELETE FROM ticket_types WHERE id = ?')->execute([$id]);
            flash_set('success', 'Ticket type deleted.');
        }
        redirect('admin/ticket-types.php');
    }

    if ($action === 'toggle') {
        $id      = (int)($_POST['id'] ?? 0);
        $current = (int)($_POST['current'] ?? 0);
        if ($id > 0) {
            $pdo->prepare('UPDATE ticket_types SET is_active = ? WHERE id = ?')
                ->execute([$current ? 0 : 1, $id]);
            flash_set('success', $current ? 'Ticket type deactivated.' : 'Ticket type activated.');
        }
        redirect('admin/ticket-types.php');
    }

    if ($action === 'create' || $action === 'update') {
        $id       = (int)($_POST['id'] ?? 0);
        $name     = trim((string)($_POST['name'] ?? ''));
        $priceRaw = (string)($_POST['price'] ?? '');

        if ($name === '' || $priceRaw === '') {
            flash_set('error', 'Name and price are required.');
            redirect('admin/ticket-types.php');
        }

        $description  = (string)($_POST['description'] ?? '');
        $category     = (string)($_POST['category'] ?? 'Single Day');
        $price        = (float)$priceRaw;
        $ticketPreset = (string)($_POST['ticket_preset'] ?? 'custom_type');
        $isActive     = isset($_POST['is_active']) ? 1 : 0;

        // Determine max_rides from preset
        $maxRides = null;
        if ($ticketPreset === 'super_five') {
            $maxRides = 5;
        } elseif ($ticketPreset === 'annual_pass') {
            $maxRides = null; // unlimited
        } elseif ($ticketPreset === 'custom_type') {
            $raw = (string)($_POST['max_rides_custom'] ?? '');
            $maxRides = $raw !== '' ? (int)$raw : null;
        }

        if ($action === 'create') {
            $pdo->prepare(
                'INSERT INTO ticket_types (name, description, category, price, max_rides, is_active) VALUES (?,?,?,?,?,?)'
            )->execute([$name, $description, $category, $price, $maxRides, $isActive]);
            flash_set('success', 'Ticket type created.');
        } else {
            $pdo->prepare(
                'UPDATE ticket_types SET name=?, description=?, category=?, price=?, max_rides=?, is_active=? WHERE id=?'
            )->execute([$name, $description, $category, $price, $maxRides, $isActive, $id]);
            flash_set('success', 'Ticket type updated.');
        }
        redirect('admin/ticket-types.php');
    }
}

$edit       = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
$add        = isset($_GET['add'])  ? 1 : 0;
$preset     = (string)($_GET['preset'] ?? '');
$typeToEdit = null;

if ($edit > 0) {
    $st = $pdo->prepare('SELECT * FROM ticket_types WHERE id = ?');
    $st->execute([$edit]);
    $typeToEdit = $st->fetch() ?: null;
}

$types     = $pdo->query('SELECT * FROM ticket_types ORDER BY price ASC')->fetchAll();
$flash     = flash_get();
$modalOpen = $add || $typeToEdit;
$categories = ['Single Day', 'Season Pass', 'Group', 'VIP', 'Annual'];

// Preset defaults for the modal
$presetDefaults = [
    'super_five'  => ['name' => 'Super 5 Ticket', 'description' => 'Choose any 5 rides and experience the thrill!', 'category' => 'Single Day', 'price' => '', 'max_rides' => 5],
    'annual_pass' => ['name' => 'Annual Pass', 'description' => 'Unlock a Year of FUNtastic Adventures! Annual Pass for 13 years old and above!', 'category' => 'Annual', 'price' => '', 'max_rides' => null],
    'custom_type' => ['name' => '', 'description' => '', 'category' => 'Single Day', 'price' => '', 'max_rides' => null],
];

// Determine current preset for edit mode
$curMax = $typeToEdit ? (isset($typeToEdit['max_rides']) && $typeToEdit['max_rides'] !== null ? (int)$typeToEdit['max_rides'] : null) : null;
$curPreset = 'custom_type';
if ($typeToEdit) {
    if ($curMax === 5) $curPreset = 'super_five';
    elseif ($curMax === null && strtolower((string)($typeToEdit['category'] ?? '')) === 'annual') $curPreset = 'annual_pass';
    elseif ($curMax !== null) $curPreset = 'custom_type';
}
if (!$typeToEdit && $preset !== '') $curPreset = $preset;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Ticket Types - AmusePark Admin</title>
  <link rel="stylesheet" href="../css/style.css" />
  <style>
    .badge-status { font-size:.75rem; padding:.2rem .55rem; border-radius:1rem; font-weight:700; text-transform:uppercase; }
    .status-active   { background:#dcfce7; color:#166534; }
    .status-inactive { background:#fee2e2; color:#991b1b; }

    /* Preset picker */
    .preset-grid { display:grid; grid-template-columns:repeat(3,1fr); gap:.75rem; margin-bottom:1.25rem; }
    .preset-btn {
      border:2px solid #e2e8f0; border-radius:.85rem; padding:1rem .75rem;
      text-align:center; cursor:pointer; background:#fff; transition:all .18s;
      position:relative;
    }
    .preset-btn:hover { border-color:#7c3aed; background:#faf5ff; }
    .preset-btn.active { border-color:#7c3aed; background:#faf5ff; box-shadow:0 0 0 3px rgba(124,58,237,.12); }
    .preset-btn .picon { font-size:1.6rem; margin-bottom:.35rem; }
    .preset-btn .ptitle { font-size:.82rem; font-weight:800; color:#111827; }
    .preset-btn .psub   { font-size:.72rem; color:#6b7280; margin-top:.15rem; }
    .preset-btn .pcheck {
      position:absolute; top:.45rem; right:.45rem;
      width:18px; height:18px; border-radius:50%;
      background:#7c3aed; color:#fff; font-size:.7rem;
      display:none; align-items:center; justify-content:center; font-weight:900;
    }
    .preset-btn.active .pcheck { display:flex; }
  </style>
</head>
<body>
<?php render_nav($user, 'tickets'); ?>

<div class="page-header" style="display:flex;align-items:center;justify-content:space-between;padding:2rem 3rem;background:#1e3a8a;color:#fff;">
  <div>
    <h1 style="text-align:left;margin:0;">Ticket &amp; Pricing Settings</h1>
    <p style="text-align:left;color:#bfdbfe;margin-top:.25rem;">Manage park entry options and ride limits</p>
  </div>
  <a class="btn btn-yellow" href="ticket-types.php?add=1">+ Add Ticket Type</a>
</div>

<div class="container" style="margin-top:2rem;">
  <?php if ($flash): ?>
    <div class="card" style="padding:1rem;margin-bottom:1.5rem;border-left:4px solid <?= $flash['type']==='error'?'#dc2626':'#16a34a' ?>;">
      <strong><?= $flash['type']==='error'?'Error':'Success' ?>:</strong> <?= e($flash['message']) ?>
    </div>
  <?php endif; ?>

  <!-- Quick-add preset shortcuts -->
  <?php if (!count($types)): ?>
    <div class="card" style="padding:1.5rem;margin-bottom:2rem;border:2px dashed #e9d5ff;">
      <p style="font-weight:700;color:#7c3aed;margin-bottom:1rem;">🚀 Quick-start — pick a preset to create your first ticket type:</p>
      <div style="display:flex;gap:.75rem;flex-wrap:wrap;">
        <a href="ticket-types.php?add=1&preset=super_five"  class="btn btn-outline">⚡ Super 5 Ticket</a>
        <a href="ticket-types.php?add=1&preset=annual_pass" class="btn btn-outline">🎫 Annual Pass</a>
        <a href="ticket-types.php?add=1&preset=custom_type" class="btn btn-outline">✏️ Custom Ticket</a>
      </div>
    </div>
  <?php endif; ?>

  <div class="grid grid-2">
    <?php foreach ($types as $t): ?>
      <?php
        $isAnnual = strtolower((string)($t['category'] ?? '')) === 'annual';
        $isSuper5 = (int)($t['max_rides'] ?? 0) === 5;
        $badgeIcon = $isAnnual ? '🎫' : ($isSuper5 ? '⚡' : '🎟');
      ?>
      <div class="card" style="padding:1.5rem;position:relative;<?= empty($t['is_active'])?'opacity:.75;background:#f1f5f9;':'' ?>">
        <div style="display:flex;justify-content:space-between;align-items:start;margin-bottom:1rem;">
          <div>
            <div style="display:flex;align-items:center;gap:.5rem;">
              <span style="font-size:1.3rem;"><?= $badgeIcon ?></span>
              <strong style="font-size:1.2rem;"><?= e($t['name']) ?></strong>
              <span class="badge-status <?= $t['is_active']?'status-active':'status-inactive' ?>">
                <?= $t['is_active']?'Active':'Hidden' ?>
              </span>
            </div>
            <span class="badge badge-blue" style="margin-top:.4rem;"><?= e($t['category']) ?></span>
          </div>
          <div style="text-align:right;">
            <div style="font-size:1.75rem;font-weight:900;color:#1d4ed8;">&#8369;<?= number_format((float)$t['price'],0) ?></div>
          </div>
        </div>
        <p style="color:#475569;font-size:.9rem;margin-bottom:.75rem;min-height:2.5rem;"><?= e($t['description']) ?></p>
        <p style="color:#7c3aed;font-weight:600;font-size:.85rem;margin-bottom:1.25rem;display:flex;align-items:center;gap:.4rem;">
          <span style="font-size:1.1rem;">🎢</span> <?= e(ticket_ride_label($t, $pdo)) ?>
        </p>
        <div style="display:flex;gap:.5rem;border-top:1px solid #e2e8f0;padding-top:1rem;">
          <form method="post" style="flex:1;">
            <input type="hidden" name="action" value="toggle" />
            <input type="hidden" name="id" value="<?= (int)$t['id'] ?>" />
            <input type="hidden" name="current" value="<?= (int)$t['is_active'] ?>" />
            <button class="btn btn-outline btn-sm btn-full" type="submit"><?= $t['is_active']?'Deactivate':'Activate' ?></button>
          </form>
          <a class="btn btn-outline btn-sm" href="ticket-types.php?edit=<?= (int)$t['id'] ?>" style="flex:1;text-align:center;">Edit</a>
          <form method="post" onsubmit="return confirm('Delete this ticket type?');">
            <input type="hidden" name="action" value="delete" />
            <input type="hidden" name="id" value="<?= (int)$t['id'] ?>" />
            <button class="btn btn-danger btn-sm" type="submit">🗑</button>
          </form>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
</div>

<!-- ── ADD / EDIT MODAL ── -->
<div class="modal-overlay <?= $modalOpen?'show':'' ?>">
  <div class="modal" style="max-width:580px;">
    <div class="modal-header">
      <h2><?= $typeToEdit?'Edit Ticket Type':'Add Ticket Type' ?></h2>
      <a class="modal-close" href="ticket-types.php">✕</a>
    </div>

    <form method="post" style="padding:1.5rem;" id="tt-form">
      <input type="hidden" name="action" value="<?= $typeToEdit?'update':'create' ?>" />
      <?php if ($typeToEdit): ?><input type="hidden" name="id" value="<?= (int)$typeToEdit['id'] ?>" /><?php endif; ?>
      <input type="hidden" name="ticket_preset" id="ticket_preset" value="<?= e($curPreset) ?>" />

      <!-- Preset picker (hidden on edit) -->
      <?php if (!$typeToEdit): ?>
      <div class="form-group">
        <label style="font-weight:700;margin-bottom:.6rem;display:block;">Choose Ticket Type</label>
        <div class="preset-grid">

          <div class="preset-btn <?= $curPreset==='super_five'?'active':'' ?>" onclick="selectPreset('super_five')">
            <div class="pcheck">✓</div>
            <div class="picon">⚡</div>
            <div class="ptitle">Super 5 Ticket</div>
            <div class="psub">5 rides per visit</div>
          </div>

          <div class="preset-btn <?= $curPreset==='annual_pass'?'active':'' ?>" onclick="selectPreset('annual_pass')">
            <div class="pcheck">✓</div>
            <div class="picon">🎫</div>
            <div class="ptitle">Annual Pass</div>
            <div class="psub">Unlimited · 13+ yrs</div>
          </div>

          <div class="preset-btn <?= $curPreset==='custom_type'?'active':'' ?>" onclick="selectPreset('custom_type')">
            <div class="pcheck">✓</div>
            <div class="picon">✏️</div>
            <div class="ptitle">Custom Ticket</div>
            <div class="psub">Set your own rides</div>
          </div>

        </div>
      </div>
      <?php endif; ?>

      <div class="form-group">
        <label>Ticket Name *</label>
        <input name="name" id="f-name" placeholder="e.g. Regular Day Pass"
               value="<?= e((string)($typeToEdit['name'] ?? '')) ?>" required />
      </div>

      <div class="form-group">
        <label>Description</label>
        <textarea name="description" id="f-desc" rows="2"
                  placeholder="Briefly describe what's included..."><?= e((string)($typeToEdit['description'] ?? '')) ?></textarea>
      </div>

      <div style="display:grid;grid-template-columns:1fr 1fr;gap:1.25rem;">
        <div class="form-group">
          <label>Category</label>
          <select name="category" id="f-category">
            <?php foreach ($categories as $c): ?>
              <option value="<?= e($c) ?>" <?= ($typeToEdit['category'] ?? 'Single Day')===$c?'selected':'' ?>><?= e($c) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label>Price (&#8369;) *</label>
          <input name="price" id="f-price" type="number" step="1"
                 value="<?= e((string)($typeToEdit['price'] ?? '')) ?>" required />
        </div>
      </div>

      <!-- Ride allowance — shown only for custom_type or edit -->
      <div class="form-group" id="custom-rides-row"
           style="<?= (!$typeToEdit && $curPreset !== 'custom_type') ? 'display:none;' : '' ?>">
        <label>Number of Rides Allowed</label>
        <div style="display:flex;align-items:center;gap:.75rem;margin-top:.35rem;">
          <input type="number" name="max_rides_custom" id="f-max-rides" min="1"
                 style="width:100px;"
                 placeholder="e.g. 3"
                 value="<?= ($curPreset==='custom_type' && $curMax !== null) ? $curMax : (($typeToEdit && $curMax !== null && $curPreset==='custom_type') ? $curMax : '') ?>" />
          <span style="color:#6b7280;font-size:.9rem;">rides per visit <em style="color:#94a3b8;">(leave blank = unlimited)</em></span>
        </div>
      </div>

      <div style="display:flex;align-items:center;gap:.75rem;margin:1.25rem 0;">
        <input type="checkbox" name="is_active" id="f-active" style="width:18px;height:18px;"
               <?= ($typeToEdit['is_active'] ?? 1) ? 'checked' : '' ?> />
        <label for="f-active" style="margin:0;font-weight:600;">Make this ticket available for purchase</label>
      </div>

      <div style="display:flex;gap:1rem;">
        <a class="btn btn-outline" href="ticket-types.php" style="flex:1;text-align:center;">Cancel</a>
        <button class="btn btn-primary" type="submit" style="flex:1;">Save Ticket Type</button>
      </div>
    </form>
  </div>
</div>

<script>
var presetData = {
  super_five: {
    name: 'Super 5 Ticket',
    desc: 'Choose any 5 rides and experience the thrill!',
    category: 'Single Day',
    showCustomRides: false
  },
  annual_pass: {
    name: 'Annual Pass',
    desc: 'Unlock a Year of FUNtastic Adventures! Annual Pass for 13 years old and above!',
    category: 'Annual',
    showCustomRides: false
  },
  custom_type: {
    name: '',
    desc: '',
    category: 'Single Day',
    showCustomRides: true
  }
};

function selectPreset(key) {
  // Update hidden input
  document.getElementById('ticket_preset').value = key;

  // Highlight selected card
  document.querySelectorAll('.preset-btn').forEach(function(b) { b.classList.remove('active'); });
  event.currentTarget.classList.add('active');

  var d = presetData[key];
  if (!d) return;

  // Fill name & desc only if currently empty or was a preset value
  var nameEl = document.getElementById('f-name');
  var descEl = document.getElementById('f-desc');
  if (nameEl) nameEl.value = d.name;
  if (descEl) descEl.value = d.desc;

  // Set category
  var catEl = document.getElementById('f-category');
  if (catEl) {
    for (var i = 0; i < catEl.options.length; i++) {
      if (catEl.options[i].value === d.category) { catEl.selectedIndex = i; break; }
    }
  }

  // Show/hide custom rides count
  var customRow = document.getElementById('custom-rides-row');
  if (customRow) customRow.style.display = d.showCustomRides ? '' : 'none';
}

// Init on load
(function() {
  var preset = document.getElementById('ticket_preset');
  if (!preset) return;
  var key = preset.value;
  var customRow = document.getElementById('custom-rides-row');
  if (customRow && presetData[key]) {
    customRow.style.display = presetData[key].showCustomRides ? '' : 'none';
  }
})();
</script>
</body>
</html>

