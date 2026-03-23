<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/auth.php';

$user = require_admin();
$pdo = db();

function file_to_data_url(array $file): ?string
{
    if (!isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) return null;
    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) return null;

    $tmp = $file['tmp_name'];
    $data = file_get_contents($tmp);
    if ($data === false) return null;

    $mime = null;
    if (class_exists('finfo')) {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($tmp) ?: null;
    } elseif (function_exists('mime_content_type')) {
        $mime = mime_content_type($tmp) ?: null;
    }
    $mime = $mime ?: 'application/octet-stream';
    if (!str_starts_with($mime, 'image/')) return null;

    return 'data:' . $mime . ';base64,' . base64_encode($data);
}

// ---- Handle actions ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $stmt = $pdo->prepare('DELETE FROM rides WHERE id = ?');
            $stmt->execute([$id]);
            flash_set('success', 'Ride deleted.');
        }
        redirect('admin/rides.php');
    }

    if ($action === 'create' || $action === 'update') {
        $id = (int)($_POST['id'] ?? 0);
        $name = trim((string)($_POST['name'] ?? ''));
        if ($name === '') {
            flash_set('error', 'Ride name is required.');
            redirect('admin/rides.php');
        }

        $description = (string)($_POST['description'] ?? '');
        $category = (string)($_POST['category'] ?? 'Family');
        $status = (string)($_POST['status'] ?? 'Open');
        $duration = $_POST['duration_minutes'] !== '' ? (int)$_POST['duration_minutes'] : null;
        $minHeight = $_POST['min_height_cm'] !== '' ? (int)$_POST['min_height_cm'] : null;
        $capacity = $_POST['max_capacity'] !== '' ? (int)$_POST['max_capacity'] : null;
        $isFeatured = isset($_POST['is_featured']) ? 1 : 0;

        $imageUrl = file_to_data_url($_FILES['image_file'] ?? []);
        if (!$imageUrl) {
            $imageUrl = (string)($_POST['image_url_existing'] ?? '');
        }

        if ($action === 'create') {
            $stmt = $pdo->prepare(
                'INSERT INTO rides (name,description,category,duration_minutes,min_height_cm,max_capacity,price,status,image_url,is_featured)
                 VALUES (?,?,?,?,?,?,0,?,?,?)'
            );
            $stmt->execute([
                $name,
                $description,
                $category,
                $duration,
                $minHeight,
                $capacity,
                $status,
                $imageUrl,
                $isFeatured
            ]);
            flash_set('success', 'Ride added and saved to the database.');
            redirect('admin/rides.php');
        }

        if ($id <= 0) {
            flash_set('error', 'Invalid ride ID.');
            redirect('admin/rides.php');
        }

        $stmt = $pdo->prepare(
            'UPDATE rides
             SET name=?,description=?,category=?,duration_minutes=?,min_height_cm=?,max_capacity=?,status=?,image_url=?,is_featured=?
             WHERE id=?'
        );
        $stmt->execute([
            $name,
            $description,
            $category,
            $duration,
            $minHeight,
            $capacity,
            $status,
            $imageUrl,
            $isFeatured,
            $id
        ]);
        flash_set('success', 'Ride updated.');
        redirect('admin/rides.php');
    }
}

// ---- Read data ----
$edit = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
$add = isset($_GET['add']) ? 1 : 0;

$rideToEdit = null;
if ($edit > 0) {
    $st = $pdo->prepare('SELECT * FROM rides WHERE id = ?');
    $st->execute([$edit]);
    $rideToEdit = $st->fetch() ?: null;
}

$rides = $pdo->query('SELECT * FROM rides ORDER BY created_at DESC')->fetchAll();
$flash = flash_get();

$catColors = [
    'Thrill' => 'badge-red',
    'Family' => 'badge-green',
    'Kids' => 'badge-purple',
    'Water' => 'badge-blue',
    'Classic' => 'badge-gray',
];
$statusDot = [
    'Open' => '#16a34a',
    'Closed' => '#dc2626',
    'Maintenance' => '#ca8a04',
];

$modalOpen = $add || $rideToEdit;
$formAction = $rideToEdit ? 'update' : 'create';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" /><meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Manage Rides - AmusePark</title>
  <link rel="stylesheet" href="../css/style.css" />
</head>
<body>
<nav class="admin-nav">
  <a class="logo" href="../index.php">Amuse<span>Park</span></a>
  <ul>
    <li><a href="admin-dashboard.php">Dashboard</a></li>
    <li><a href="rides.php" class="active">Rides</a></li>
    <li><a href="bookings.php">Bookings</a></li>
    <li><a href="ticket-types.php">Ticket Types</a></li>
     <li><a href="../profile.php">Profile</a></li>
    <li><a href="../logout.php" style="color:#dc2626;font-weight:600;">Logout</a></li>
  </ul>
</nav>

<div class="page-header">
  <div class="container" style="display:flex; align-items:center; justify-content:space-between; text-align:left; padding:0;">
    <div>
      <h1>Ride Management</h1>
      <p>Add, edit, or remove park attractions</p>
    </div>
    <a class="btn btn-yellow" href="rides.php?add=1">+ Add Ride</a>
  </div>
</div>

<div class="container">
  <?php if ($flash && ($flash['message'] ?? '') !== ''): ?>
    <div class="card" style="padding:1rem;margin-bottom:1rem;border-left:4px solid <?= ($flash['type'] ?? '') === 'error' ? '#dc2626' : '#16a34a' ?>;">
      <strong><?= e(($flash['type'] ?? '') === 'error' ? 'Error' : 'Success') ?>:</strong>
      <?= e($flash['message']) ?>
    </div>
  <?php endif; ?>

  <div class="stats-grid" style="margin-bottom: 2rem;">
    <div class="stat-card">
      <div class="stat-label">Total Rides</div>
      <div class="stat-value"><?= count($rides) ?></div>
    </div>
  </div>

  <div class="grid grid-2" id="rides-grid">
    <?php if (!count($rides)): ?>
      <div class="empty" style="grid-column: 1/-1;">
        <div class="empty-icon">🎢</div>
        <p>No rides found. Add your first attraction!</p>
      </div>
    <?php endif; ?>

    <?php foreach ($rides as $r): ?>
      <div class="card" style="padding:1.25rem; display:flex; gap:1.25rem;">
        <div style="width:120px; height:120px; flex-shrink:0;">
          <?php if (!empty($r['image_url'])): ?>
            <img src="<?= e($r['image_url']) ?>" style="width:100%; height:100%; object-fit:cover; border-radius:0.75rem; box-shadow:0 2px 8px rgba(0,0,0,0.1);">
          <?php else: ?>
            <div class="card-img" style="font-size:1.5rem; height:100%; border-radius:0.75rem;">🎡</div>
          <?php endif; ?>
        </div>
        <div style="flex:1;">
          <div style="display:flex; justify-content:space-between; align-items:flex-start;">
            <div>
              <div style="display:flex; align-items:center; gap:0.5rem; margin-bottom:0.25rem;">
                <h3 style="font-size:1.1rem; margin:0;"><?= e($r['name']) ?></h3>
                <?php if (!empty($r['is_featured'])): ?>
                  <span class="badge badge-yellow">Featured</span>
                <?php endif; ?>
              </div>
              <div style="display:flex; gap:0.5rem; align-items:center; margin-bottom:0.5rem;">
                <?php $cat = (string)($r['category'] ?? ''); ?>
                <span class="badge <?= e($catColors[$cat] ?? 'badge-gray') ?>"><?= e($cat) ?></span>
                <?php $st = (string)($r['status'] ?? ''); ?>
                <small style="color:#64748b; display:flex; align-items:center; gap:3px;">
                  <span style="width:8px; height:8px; border-radius:50%; background:<?= e($statusDot[$st] ?? '#94a3b8') ?>;"></span>
                  <?= e($st) ?>
                </small>
              </div>
            </div>
            <div style="text-align:right;">
              <span class="badge badge-blue"><?= e((string)($r['status'] ?? '')) ?></span>
            </div>
          </div>
          <p style="color:#64748b; font-size:0.85rem; line-height:1.4; margin-bottom:0.75rem;">
            <?= e($r['description'] ?: 'No description provided.') ?>
          </p>
          <div style="display:flex; gap:0.5rem;">
            <a class="btn btn-outline btn-sm" href="rides.php?edit=<?= (int)$r['id'] ?>">Edit</a>
            <form method="post" style="display:inline;">
              <input type="hidden" name="action" value="delete" />
              <input type="hidden" name="id" value="<?= (int)$r['id'] ?>" />
              <button class="btn btn-danger btn-sm" type="submit">Delete</button>
            </form>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
</div>

<div class="modal-overlay <?= $modalOpen ? 'show' : '' ?>" id="modal">
  <div class="modal">
    <div class="modal-header">
      <h2 id="modal-title"><?= $rideToEdit ? 'Edit Ride' : 'Add New Ride' ?></h2>
      <a class="modal-close" href="rides.php" aria-label="Close">✕</a>
    </div>

    <form method="post" enctype="multipart/form-data">
      <input type="hidden" name="action" value="<?= e($formAction) ?>" />
      <?php if ($rideToEdit): ?>
        <input type="hidden" name="id" value="<?= (int)$rideToEdit['id'] ?>" />
      <?php endif; ?>
      <input type="hidden" name="image_url_existing" value="<?= e((string)($rideToEdit['image_url'] ?? '')) ?>" />

      <div class="form-group">
        <label>Ride Name *</label>
        <input type="text" name="name" value="<?= e((string)($rideToEdit['name'] ?? '')) ?>" placeholder="e.g. Space Mountain" />
      </div>

      <div class="form-group">
        <label>Description</label>
        <textarea name="description" rows="3" placeholder="Describe the ride experience..."><?= e((string)($rideToEdit['description'] ?? '')) ?></textarea>
      </div>

      <div style="display:grid; grid-template-columns:1fr 1fr; gap:1rem;">
        <div class="form-group">
          <label>Category</label>
          <?php $curCat = (string)($rideToEdit['category'] ?? 'Thrill'); ?>
          <select name="category">
            <?php foreach (['Thrill','Family','Kids','Water','Classic'] as $c): ?>
              <option value="<?= e($c) ?>" <?= $curCat === $c ? 'selected' : '' ?>><?= e($c) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label>Status</label>
          <?php $curStatus = (string)($rideToEdit['status'] ?? 'Open'); ?>
          <select name="status">
            <?php foreach (['Open','Closed','Maintenance'] as $s): ?>
              <option value="<?= e($s) ?>" <?= $curStatus === $s ? 'selected' : '' ?>><?= e($s) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <div style="display:grid; grid-template-columns:1fr 1fr 1fr; gap:1rem;">
        <div class="form-group"><label>Dur (min)</label><input name="duration_minutes" type="number" value="<?= e(isset($rideToEdit['duration_minutes']) ? (string)$rideToEdit['duration_minutes'] : '') ?>" /></div>
        <div class="form-group"><label>Min Ht (cm)</label><input name="min_height_cm" type="number" value="<?= e(isset($rideToEdit['min_height_cm']) ? (string)$rideToEdit['min_height_cm'] : '') ?>" /></div>
        <div class="form-group"><label>Capacity</label><input name="max_capacity" type="number" value="<?= e(isset($rideToEdit['max_capacity']) ? (string)$rideToEdit['max_capacity'] : '') ?>" /></div>
      </div>

      <div class="form-group">
        <label>Ride Image</label>
        <input name="image_file" type="file" accept="image/*" />
        <?php if (!empty($rideToEdit['image_url'])): ?>
          <div style="margin-top:0.5rem;">
            <img src="<?= e((string)$rideToEdit['image_url']) ?>" style="width:100px; height:60px; object-fit:cover; border-radius:4px; border:1px solid #ddd;">
          </div>
        <?php endif; ?>
      </div>

      <div style="display:flex; align-items:center; gap:.5rem; margin-bottom:1.5rem;">
        <input type="checkbox" name="is_featured" id="f-feat" style="width:auto;" <?= !empty($rideToEdit['is_featured']) ? 'checked' : '' ?> />
        <label for="f-feat" style="margin:0;">Featured ride on homepage</label>
      </div>

      <div style="display:flex; gap:.75rem;">
        <a class="btn btn-outline" href="rides.php" style="flex:1; text-align:center;">Cancel</a>
        <button class="btn btn-primary" type="submit" style="flex:1;">
          <?= $rideToEdit ? 'Update Ride' : 'Save Ride' ?>
        </button>
      </div>
    </form>
  </div>
</div>

</body>
</html>