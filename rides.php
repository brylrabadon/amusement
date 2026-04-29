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

$catColors = ['Thrill'=>'badge-red','Family'=>'badge-green','Kids'=>'badge-blue','Water'=>'badge-blue','Classic'=>'badge-gray'];
$statusDot = ['Open'=>'#10b981','Closed'=>'#ef4444','Maintenance'=>'#f59e0b'];
$cats      = ['All','Thrill','Family','Kids','Water','Classic'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" /><meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Explore Rides - AmusePark</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="css/style.css?v=1.1" />
  <style>
    :root {
      --primary: #1e3a8a;
      --primary-dark: #172554;
      --secondary: #fbbf24;
      --secondary-dark: #f59e0b;
      --dark: #0f172a;
      --light: #f8fafc;
    }
    body { background: var(--light); font-family: 'Poppins', sans-serif; }
    .rides-filter-bar {
      background: #fff; border-bottom: 1px solid #e2e8f0;
      padding: 1.5rem 0; margin-bottom: 3rem; box-shadow: 0 4px 12px rgba(0,0,0,0.02);
    }
    .rides-filter-inner {
      max-width: 1200px; margin: 0 auto; padding: 0 1.5rem;
      display: flex; gap: 1.25rem; flex-wrap: wrap; align-items: center;
    }
    .rides-filter-inner input {
      flex: 1; min-width: 250px;
      border: 1.5px solid #e2e8f0; border-radius: 12px;
      padding: .75rem 1.25rem; font-size: .95rem; transition: all .3s;
    }
    .rides-filter-inner input:focus { border-color: var(--primary); outline: none; box-shadow: 0 0 0 4px rgba(30, 58, 138, 0.1); }
    .rides-filter-inner select {
      border: 1.5px solid #e2e8f0; border-radius: 12px;
      padding: .75rem 1.25rem; font-size: .95rem; background: #fff; cursor: pointer; transition: all .3s;
    }
    .rides-filter-inner select:focus { border-color: var(--primary); outline: none; }
    .ride-book-btn {
      display: inline-flex; width: 100%; padding: .85rem;
      background: var(--primary) !important; color: #fff !important; border: none;
      border-radius: 12px; font-weight: 700; font-size: .95rem;
      text-align: center; justify-content: center; text-decoration: none; cursor: pointer;
      transition: all .3s; box-shadow: 0 4px 12px rgba(30, 58, 138, 0.2);
      margin-top: auto;
    }
    .card { display: flex; flex-direction: column; }
    .card-body { display: flex; flex-direction: column; flex: 1; }
    .ride-book-btn:hover { background: var(--primary-dark); transform: translateY(-2px); color: #fff; }
    .ride-book-btn.disabled { background: #e2e8f0; color: #94a3b8; pointer-events: none; box-shadow: none; }
  </style>
</head>
<body>
<?php render_nav($user, 'rides'); ?>

<?php render_page_header('Explore Our Rides', 'Experience the thrill of AmusePark'); ?>

<div class="rides-filter-bar">
  <form class="rides-filter-inner" method="get">
    <input type="text" name="q" value="<?= e($q) ?>" placeholder="🔍 Search rides by name or description..." />
    <select name="cat">
      <?php foreach ($cats as $c): ?>
        <option value="<?= e($c) ?>" <?= $cat === $c ? 'selected' : '' ?>><?= e($c) ?></option>
      <?php endforeach; ?>
    </select>
    <button class="btn btn-primary" type="submit" style="padding:.75rem 1.5rem;">Filter</button>
    <a class="btn btn-outline" href="rides.php" style="padding:.75rem 1.5rem;">Reset</a>
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
