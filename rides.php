<?php
declare(strict_types=1);
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/layout.php';

$user = current_user();
$pdo  = db();

$q   = trim((string)($_GET['q'] ?? ''));
$cat = (string)($_GET['cat'] ?? 'All');

$params = [];
$where  = [];
if ($q !== '') {
    $where[]  = '(name LIKE ? OR description LIKE ?)';
    $params[] = '%' . $q . '%';
    $params[] = '%' . $q . '%';
}
if ($cat !== '' && $cat !== 'All') {
    $where[]  = 'category = ?';
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
$cats      = ['All','Thrill','Family','Kids','Water','Classic'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" /><meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Rides - AmusePark</title>
  <link rel="stylesheet" href="css/style.css" />
  <style>
    body { background: #f9fafb; }
    .rides-filter-bar {
      background: #fff; border-bottom: 1px solid #e5e7eb;
      padding: 1.25rem 0; margin-bottom: 2rem;
    }
    .rides-filter-inner {
      max-width: 1100px; margin: 0 auto; padding: 0 1.5rem;
      display: flex; gap: 1rem; flex-wrap: wrap; align-items: center;
    }
    .rides-filter-inner input {
      flex: 1; min-width: 200px;
      border: 1.5px solid #e5e7eb; border-radius: 999px;
      padding: .6rem 1.2rem; font-size: .9rem;
    }
    .rides-filter-inner input:focus { border-color: #7c3aed; outline: none; }
    .rides-filter-inner select {
      border: 1.5px solid #e5e7eb; border-radius: 999px;
      padding: .6rem 1.2rem; font-size: .9rem; background: #fff;
    }
    .rides-filter-inner select:focus { border-color: #7c3aed; outline: none; }
    .ride-price { font-weight: 800; color: #7c3aed; }
    .ride-book-btn {
      display: block; width: 100%; padding: .65rem;
      background: #7c3aed; color: #fff; border: none;
      border-radius: 999px; font-weight: 700; font-size: .9rem;
      text-align: center; text-decoration: none; cursor: pointer;
      transition: background .2s;
    }
    .ride-book-btn:hover { background: #6d28d9; }
    .ride-book-btn.disabled { background: #e5e7eb; color: #9ca3af; pointer-events: none; }
  </style>
</head>
<body>
<?php render_nav($user, 'rides'); ?>

<?php render_page_header('Our Rides', 'Discover all our thrilling attractions'); ?>

<div class="rides-filter-bar">
  <form class="rides-filter-inner" method="get">
    <input type="text" name="q" value="<?= e($q) ?>" placeholder="🔍 Search rides..." />
    <select name="cat">
      <?php foreach ($cats as $c): ?>
        <option value="<?= e($c) ?>" <?= $cat === $c ? 'selected' : '' ?>><?= e($c) ?></option>
      <?php endforeach; ?>
    </select>
    <button class="btn btn-primary btn-sm" type="submit" style="border-radius:999px;padding:.6rem 1.4rem;">Filter</button>
    <a class="btn btn-outline btn-sm" href="rides.php" style="border-radius:999px;padding:.6rem 1.4rem;">Reset</a>
  </form>
</div>

<div class="container">
  <div class="grid grid-3" id="rides-grid">
    <?php if (!count($rides)): ?>
      <div class="empty" style="grid-column:1/-1;">
        <div class="empty-icon">🎢</div>
        <p>No rides found.</p>
      </div>
    <?php endif; ?>

    <?php foreach ($rides as $r):
      $status   = (string)($r['status'] ?? 'Open');
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
            <span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:<?= e($statusDot[$status] ?? '#ccc') ?>;flex-shrink:0;"></span>
            <span style="font-size:.8rem;color:#6b7280;"><?= e($status) ?></span>
            <?= $status === 'Maintenance' ? '<span class="badge badge-red">Temporarily Closed</span>' : '' ?>
            <?= !empty($r['is_featured']) ? '<span class="badge badge-yellow">Featured</span>' : '' ?>
          </div>
          <p class="card-text"><?= e($r['description'] ?? '') ?></p>
          <div style="display:flex;justify-content:space-between;align-items:center;font-size:.85rem;color:#9ca3af;margin-bottom:1rem;flex-wrap:wrap;gap:.5rem;">
            <div style="display:flex;gap:.75rem;flex-wrap:wrap;">
              <?= !empty($r['duration_minutes']) ? '<span>⏱ ' . (int)$r['duration_minutes'] . ' min</span>' : '' ?>
              <?= !empty($r['min_height_cm'])    ? '<span>📏 ' . (int)$r['min_height_cm']    . 'cm</span>'  : '' ?>
              <?= !empty($r['max_capacity'])     ? '<span>👥 ' . (int)$r['max_capacity']     . ' max</span>': '' ?>
            </div>
            <div class="ride-price">₱<?= number_format((float)($r['price'] ?? 0), 0) ?></div>
          </div>
          <?php if ($status === 'Open'): ?>
            <a href="<?= $user ? 'tickets.php' : 'login.php?next=tickets.php' ?>" class="ride-book-btn">
              🎟 Book Now
            </a>
          <?php else: ?>
            <span class="ride-book-btn disabled"><?= e($status) ?></span>
          <?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
</div>

<?php render_footer(); ?>
</body>
</html>
