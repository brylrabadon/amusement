<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/auth.php';

$user = require_login('customer');
$pdo = db();

$q = trim((string)($_GET['q'] ?? ''));
$cat = (string)($_GET['cat'] ?? 'All');

$params = [];
$where = [];
if ($q !== '') {
    $where[] = '(name LIKE ? OR description LIKE ?)';
    $params[] = '%' . $q . '%';
    $params[] = '%' . $q . '%';
}
if ($cat !== '' && $cat !== 'All') {
    $where[] = 'category = ?';
    $params[] = $cat;
}

$sql = 'SELECT * FROM rides';
if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
$sql .= ' ORDER BY created_at DESC';

$st = $pdo->prepare($sql);
$st->execute($params);
$rides = $st->fetchAll();

$catColors = ['Thrill'=>'badge-red','Family'=>'badge-green','Kids'=>'badge-purple','Water'=>'badge-blue','Classic'=>'badge-gray'];
$statusDot = ['Open'=>'#16a34a','Closed'=>'#dc2626','Maintenance'=>'#ca8a04'];
$cats = ['All','Thrill','Family','Kids','Water','Classic'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" /><meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Rides - AmusePark</title>
  <link rel="stylesheet" href="css/style.css" />
</head>
<body>
<nav>
  <a class="logo" href="index.php">Amuse<span>Park</span></a>
  <ul>
    <li><a href="rides.php" class="active">Rides</a></li>
    <li><a href="tickets.php">Tickets</a></li>
    <li><a href="my-bookings.php">My Bookings</a></li>
    <li><a href="logout.php" style="color:#dc2626;font-weight:600;">Logout</a></li>
  </ul>
</nav>

<div class="page-header">
  <h1>Available Rides</h1>
  <p>Discover all our thrilling attractions</p>
</div>

<div class="container">
  <form class="filter-row" method="get">
    <input type="text" name="q" value="<?= e($q) ?>" placeholder="🔍 Search rides..." />
    <select name="cat" style="width:180px;">
      <?php foreach ($cats as $c): ?>
        <option value="<?= e($c) ?>" <?= $cat === $c ? 'selected' : '' ?>><?= e($c) ?></option>
      <?php endforeach; ?>
    </select>
    <button class="btn btn-outline" type="submit">Filter</button>
    <a class="btn btn-outline" href="rides.php">Reset</a>
  </form>

  <div class="grid grid-3" id="rides-grid">
    <?php if (!count($rides)): ?>
      <div class="empty"><div class="empty-icon">🎢</div><p>No rides found.</p></div>
    <?php endif; ?>

    <?php foreach ($rides as $r): ?>
      <?php
        $status = (string)($r['status'] ?? 'Open');
        $category = (string)($r['category'] ?? '');
      ?>
      <div class="card">
        <?php if (!empty($r['image_url'])): ?>
          <img src="<?= e($r['image_url']) ?>" class="card-img" alt="<?= e($r['name'] ?? '') ?>" style="display:block;" />
        <?php else: ?>
          <div class="card-img">🎢</div>
        <?php endif; ?>
        <div class="card-body">
          <div style="display:flex;justify-content:space-between;align-items:start;margin-bottom:.5rem;">
            <h3 class="card-title"><?= e($r['name'] ?? '') ?></h3>
            <span class="badge <?= e($catColors[$category] ?? 'badge-gray') ?>"><?= e($category) ?></span>
          </div>
          <div style="display:flex;align-items:center;gap:.5rem;margin-bottom:.5rem;flex-wrap:wrap;">
            <span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:<?= e($statusDot[$status] ?? '#ccc') ?>;"></span>
            <span style="font-size:.8rem;color:#64748b;"><?= e($status) ?></span>
            <?= $status === 'Maintenance' ? '<span class="badge badge-red">Temporarily Closed</span>' : '' ?>
            <?= !empty($r['is_featured']) ? '<span class="badge badge-yellow" style="background:#fef9c3;color:#ca8a04;">Featured</span>' : '' ?>
          </div>
          <p class="card-text"><?= e($r['description'] ?? '') ?></p>
          <div style="display:flex;justify-content:space-between;align-items:center;font-size:.9rem;color:#94a3b8;margin-bottom:1rem;">
            <div style="display:flex;gap:1rem;flex-wrap:wrap;">
              <?= !empty($r['duration_minutes']) ? '<span>⏱ ' . (int)$r['duration_minutes'] . ' min</span>' : '' ?>
              <?= !empty($r['min_height_cm']) ? '<span>📏 ' . (int)$r['min_height_cm'] . 'cm min</span>' : '' ?>
              <?= !empty($r['max_capacity']) ? '<span>👥 ' . (int)$r['max_capacity'] . ' max</span>' : '' ?>
            </div>
            <div style="font-weight:700;color:#1d4ed8;">₱<?= number_format((float)($r['price'] ?? 0), 0) ?></div>
          </div>
          <a href="tickets.php" class="btn btn-primary btn-full" <?= $status !== 'Open' ? 'style="opacity:.5;pointer-events:none;"' : '' ?>>
            <?= $status === 'Open' ? 'Book Now' : e($status) ?>
          </a>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
</div>
</body>
</html>

