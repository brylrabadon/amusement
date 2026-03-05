<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/auth.php';

$user = require_admin();
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $stmt = $pdo->prepare('DELETE FROM ticket_types WHERE id = ?');
            $stmt->execute([$id]);
            flash_set('success', 'Ticket type deleted.');
        }
        redirect('admin/ticket-types.php');
    }

    if ($action === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        $current = (int)($_POST['current'] ?? 0);
        if ($id > 0) {
            $stmt = $pdo->prepare('UPDATE ticket_types SET is_active = ? WHERE id = ?');
            $stmt->execute([$current ? 0 : 1, $id]);
            flash_set('success', $current ? 'Ticket type deactivated.' : 'Ticket type activated.');
        }
        redirect('admin/ticket-types.php');
    }

    if ($action === 'create' || $action === 'update') {
        $id = (int)($_POST['id'] ?? 0);
        $name = trim((string)($_POST['name'] ?? ''));
        $priceRaw = (string)($_POST['price'] ?? '');
        if ($name === '' || $priceRaw === '') {
            flash_set('error', 'Name and price are required.');
            redirect('admin/ticket-types.php');
        }

        $description = (string)($_POST['description'] ?? '');
        $category = (string)($_POST['category'] ?? 'Single Day');
        $price = (float)$priceRaw;
        $maxRides = ($_POST['max_rides'] ?? '') !== '' ? (int)$_POST['max_rides'] : null;
        $isActive = isset($_POST['is_active']) ? 1 : 0;

        if ($action === 'create') {
            $stmt = $pdo->prepare(
                'INSERT INTO ticket_types (name, description, category, price, max_rides, is_active)
                 VALUES (?,?,?,?,?,?)'
            );
            $stmt->execute([$name, $description, $category, $price, $maxRides, $isActive]);
            flash_set('success', 'Ticket type added and saved to the database.');
            redirect('admin/ticket-types.php');
        }

        if ($id <= 0) {
            flash_set('error', 'Invalid ticket type ID.');
            redirect('admin/ticket-types.php');
        }

        $stmt = $pdo->prepare(
            'UPDATE ticket_types SET name=?, description=?, category=?, price=?, max_rides=?, is_active=? WHERE id=?'
        );
        $stmt->execute([$name, $description, $category, $price, $maxRides, $isActive, $id]);
        flash_set('success', 'Ticket type updated.');
        redirect('admin/ticket-types.php');
    }
}

$edit = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
$add = isset($_GET['add']) ? 1 : 0;
$typeToEdit = null;
if ($edit > 0) {
    $st = $pdo->prepare('SELECT * FROM ticket_types WHERE id = ?');
    $st->execute([$edit]);
    $typeToEdit = $st->fetch() ?: null;
}

$types = $pdo->query('SELECT * FROM ticket_types ORDER BY price ASC')->fetchAll();
$flash = flash_get();
$modalOpen = $add || $typeToEdit;
$formAction = $typeToEdit ? 'update' : 'create';
$categories = ['Single Day','Season Pass','Group','VIP','Child','Senior'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" /><meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Ticket Types - AmusePark</title>
  <link rel="stylesheet" href="../css/style.css" />
</head>
<body>
<nav class="admin-nav">
  <a class="logo" href="../index.php">Amuse<span>Park</span></a>
  <ul>
    <li><a href="admin-dashboard.php">Dashboard</a></li>
    <li><a href="rides.php">Rides</a></li>
    <li><a href="bookings.php">Bookings</a></li>
    <li><a href="ticket-types.php" class="active">Ticket Types</a></li>
    <li><a href="../logout.php" style="color:#dc2626;font-weight:600;">Logout</a></li>
  </ul>
</nav>

<div class="page-header" style="display:flex;align-items:center;justify-content:space-between;padding:2rem 3rem;">
  <div><h1 style="text-align:left;">Ticket & Pricing Settings</h1><p style="text-align:left;color:#bfdbfe;">Manage ticket types and prices</p></div>
  <a class="btn btn-yellow" href="ticket-types.php?add=1">+ Add Ticket Type</a>
</div>

<div class="container">
  <?php if ($flash && ($flash['message'] ?? '') !== ''): ?>
    <div class="card" style="padding:1rem;margin-bottom:1rem;border-left:4px solid <?= ($flash['type'] ?? '') === 'error' ? '#dc2626' : '#16a34a' ?>;">
      <strong><?= e(($flash['type'] ?? '') === 'error' ? 'Error' : 'Success') ?>:</strong>
      <?= e($flash['message']) ?>
    </div>
  <?php endif; ?>

  <div class="grid grid-2" id="types-grid">
    <?php if (!count($types)): ?>
      <div class="empty"><div class="empty-icon">🎟</div><p>No ticket types yet.</p></div>
    <?php endif; ?>

    <?php foreach ($types as $t): ?>
      <div class="card" style="padding:1.25rem;border-left:4px solid <?= !empty($t['is_active']) ? '#1d4ed8' : '#e2e8f0' ?>;<?= empty($t['is_active']) ? 'opacity:.6' : '' ?>;">
        <div style="display:flex;justify-content:space-between;align-items:start;margin-bottom:.5rem;">
          <div>
            <strong style="font-size:1.1rem;"><?= e($t['name']) ?></strong>
            <div><span class="badge badge-blue" style="margin-top:.35rem;"><?= e($t['category']) ?></span></div>
          </div>
          <div style="font-size:1.75rem;font-weight:900;color:#1d4ed8;">₱<?= number_format((float)$t['price'], 0) ?></div>
        </div>
        <p style="color:#64748b;font-size:.85rem;margin-bottom:.5rem;"><?= e($t['description'] ?? '') ?></p>
        <?php if (!empty($t['max_rides'])): ?>
          <p style="color:#7c3aed;font-size:.8rem;margin-bottom:.75rem;">Includes <?= (int)$t['max_rides'] ?> rides</p>
        <?php endif; ?>
        <div style="display:flex;gap:.5rem;flex-wrap:wrap;">
          <form method="post" style="display:inline;">
            <input type="hidden" name="action" value="toggle" />
            <input type="hidden" name="id" value="<?= (int)$t['id'] ?>" />
            <input type="hidden" name="current" value="<?= (int)$t['is_active'] ?>" />
            <button class="btn btn-outline btn-sm" type="submit"><?= !empty($t['is_active']) ? 'Deactivate' : 'Activate' ?></button>
          </form>
          <a class="btn btn-outline btn-sm" href="ticket-types.php?edit=<?= (int)$t['id'] ?>">✏ Edit</a>
          <form method="post" style="display:inline;">
            <input type="hidden" name="action" value="delete" />
            <input type="hidden" name="id" value="<?= (int)$t['id'] ?>" />
            <button class="btn btn-danger btn-sm" type="submit">🗑</button>
          </form>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
</div>

<div class="modal-overlay <?= $modalOpen ? 'show' : '' ?>" id="modal">
  <div class="modal">
    <div class="modal-header">
      <h2 id="modal-title"><?= $typeToEdit ? 'Edit Ticket Type' : 'Add Ticket Type' ?></h2>
      <a class="modal-close" href="ticket-types.php" aria-label="Close">✕</a>
    </div>

    <form method="post">
      <input type="hidden" name="action" value="<?= e($formAction) ?>" />
      <?php if ($typeToEdit): ?>
        <input type="hidden" name="id" value="<?= (int)$typeToEdit['id'] ?>" />
      <?php endif; ?>

      <div class="form-group"><label>Name *</label><input name="name" value="<?= e((string)($typeToEdit['name'] ?? '')) ?>" /></div>
      <div class="form-group"><label>Description</label><textarea name="description" rows="2"><?= e((string)($typeToEdit['description'] ?? '')) ?></textarea></div>

      <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;">
        <div class="form-group">
          <label>Category</label>
          <?php $curCat = (string)($typeToEdit['category'] ?? 'Single Day'); ?>
          <select name="category">
            <?php foreach ($categories as $c): ?>
              <option value="<?= e($c) ?>" <?= $curCat === $c ? 'selected' : '' ?>><?= e($c) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group"><label>Price (₱) *</label><input name="price" type="number" step="0.01" value="<?= e((string)($typeToEdit['price'] ?? '')) ?>" /></div>
      </div>

      <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;">
        <div class="form-group"><label>Max Rides</label><input name="max_rides" type="number" placeholder="Unlimited" value="<?= e(isset($typeToEdit['max_rides']) ? (string)$typeToEdit['max_rides'] : '') ?>" /></div>
        <div style="display:flex;align-items:center;gap:.5rem;margin-top:1.75rem;">
          <input type="checkbox" name="is_active" id="f-active" <?= $typeToEdit ? (!empty($typeToEdit['is_active']) ? 'checked' : '') : 'checked' ?> />
          <label for="f-active" style="margin:0;">Active</label>
        </div>
      </div>

      <div style="display:flex;gap:.75rem;margin-top:1rem;">
        <a class="btn btn-outline" href="ticket-types.php" style="flex:1; text-align:center;">Cancel</a>
        <button class="btn btn-primary" type="submit" style="flex:1;"><?= $typeToEdit ? 'Update' : 'Save' ?></button>
      </div>
    </form>
  </div>
</div>

</body>
</html>

