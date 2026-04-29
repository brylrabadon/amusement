<?php
declare(strict_types=1);
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/layout.php';

$user = require_login();
$pdo  = db();

$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 7;

$total = 0;
try {
    $cs = $pdo->prepare('SELECT COUNT(*) AS c FROM bookings WHERE user_id = ?');
    $cs->execute([(int)$user['id']]);
    $total = (int)$cs->fetch()['c'];
} catch (\Throwable $e) {}

$totalPages = max(1, (int)ceil($total / $perPage));
$page   = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$bookings = [];
try {
    $st = $pdo->prepare('SELECT * FROM bookings WHERE user_id = ? ORDER BY created_at DESC LIMIT ? OFFSET ?');
    $st->execute([(int)$user['id'], $perPage, $offset]);
    $bookings = $st->fetchAll();
} catch (\Throwable $e) {}

$ticketCounts = [];
try {
    $ids = array_column($bookings, 'id');
    if ($ids) {
        $in  = implode(',', array_map('intval', $ids));
        $tcs = $pdo->query("SELECT booking_id, COUNT(*) AS cnt FROM tickets WHERE booking_id IN ($in) GROUP BY booking_id");
        foreach ($tcs->fetchAll() as $row) $ticketCounts[(int)$row['booking_id']] = (int)$row['cnt'];
    }
} catch (\Throwable $e) {}

$myStats = ['total'=>$total,'paid'=>0,'pending'=>0,'spent'=>0.0];
try {
    $ss = $pdo->prepare("SELECT payment_status, COUNT(*) AS c, COALESCE(SUM(total_amount),0) AS s FROM bookings WHERE user_id=? GROUP BY payment_status");
    $ss->execute([(int)$user['id']]);
    foreach ($ss->fetchAll() as $row) {
        if ($row['payment_status'] === 'Paid')  { $myStats['paid']  += (int)$row['c']; $myStats['spent'] += (float)$row['s']; }
        if ($row['payment_status'] === 'Pending') $myStats['pending'] += (int)$row['c'];
    }
} catch (\Throwable $e) {}

$flash = flash_get();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/><meta name="viewport" content="width=device-width,initial-scale=1"/>
  <title>My Bookings — AmusePark</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="css/style.css"/>
  <style>
    *,*::before,*::after{box-sizing:border-box}
    body{font-family:'Poppins',sans-serif;background:#f1f5f9;color:#0f172a;margin:0}
    .mb-hero{background:linear-gradient(135deg,#0f172a 0%,#1e3a8a 100%);padding:3rem 2rem 5.5rem;position:relative;overflow:hidden}
    .mb-hero::before{content:'';position:absolute;inset:0;background:url('https://www.transparenttextures.com/patterns/cubes.png');opacity:.08}
    .mb-hero-inner{max-width:900px;margin:0 auto;position:relative;z-index:1;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:1rem}
    .mb-hero h1{font-size:2rem;font-weight:900;color:#fff;margin:0 0 .3rem}
    .mb-hero p{color:rgba(255,255,255,.65);font-size:.92rem;margin:0}
    .mb-hero-btn{display:inline-flex;align-items:center;gap:.5rem;background:#fbbf24;color:#000;padding:.7rem 1.5rem;border-radius:999px;font-weight:800;font-size:.9rem;text-decoration:none;transition:all .2s;flex-shrink:0}
    .mb-hero-btn:hover{background:#f59e0b;transform:translateY(-2px)}
    .mb-stats{display:grid;grid-template-columns:repeat(4,1fr);gap:1rem;max-width:900px;margin:-2.75rem auto 0;padding:0 2rem;position:relative;z-index:10}
    .mb-stat{background:#fff;border-radius:1.25rem;padding:1.1rem 1.25rem;border:1px solid #e2e8f0;box-shadow:0 4px 12px rgba(0,0,0,.06);text-align:center}
    .mb-stat-label{font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#94a3b8;margin-bottom:.3rem}
    .mb-stat-value{font-size:1.5rem;font-weight:900;color:#0f172a;line-height:1}
    .mb-stat-value.green{color:#16a34a}.mb-stat-value.yellow{color:#d97706}.mb-stat-value.blue{color:#1e3a8a}
    .mb-body{max-width:900px;margin:0 auto;padding:2rem}
    .flash{padding:1rem 1.25rem;border-radius:.85rem;margin-bottom:1.5rem;font-weight:600;font-size:.9rem}
    .flash-success{background:#f0fdf4;color:#166534;border:1px solid #bbf7d0}
    .flash-error{background:#fef2f2;color:#991b1b;border:1px solid #fecaca}
    .bk-card{background:#fff;border:1px solid #e2e8f0;border-radius:1.5rem;padding:1.5rem;margin-bottom:1rem;box-shadow:0 2px 8px rgba(0,0,0,.04);transition:all .25s;display:flex;gap:1.25rem;align-items:flex-start}
    .bk-card:hover{box-shadow:0 8px 24px rgba(30,58,138,.1);border-color:#c7d2fe;transform:translateY(-2px)}
    .bk-icon{width:52px;height:52px;border-radius:14px;background:linear-gradient(135deg,#1e3a8a,#1d4ed8);display:flex;align-items:center;justify-content:center;font-size:1.4rem;flex-shrink:0}
    .bk-main{flex:1;min-width:0}
    .bk-ref{font-family:monospace;font-weight:900;color:#1e3a8a;font-size:1rem;letter-spacing:.03em}
    .bk-type{color:#475569;font-size:.88rem;font-weight:600;margin-top:.15rem}
    .bk-meta{display:flex;flex-wrap:wrap;gap:.5rem 1.25rem;margin-top:.6rem}
    .bk-meta-item{font-size:.8rem;color:#64748b;font-weight:600;display:flex;align-items:center;gap:.3rem}
    .bk-right{text-align:right;flex-shrink:0;display:flex;flex-direction:column;align-items:flex-end;gap:.5rem}
    .bk-amount{font-size:1.35rem;font-weight:900;color:#0f172a}
    .bk-badge{display:inline-flex;align-items:center;padding:.25rem .75rem;border-radius:999px;font-size:.72rem;font-weight:700}
    .badge-paid{background:#dcfce7;color:#166534}.badge-pending{background:#fef9c3;color:#854d0e}
    .badge-cancelled{background:#fee2e2;color:#991b1b}.badge-refunded{background:#dbeafe;color:#1e40af}.badge-default{background:#f1f5f9;color:#475569}
    .bk-actions{display:flex;gap:.5rem;margin-top:.5rem;flex-wrap:wrap}
    .bk-btn{display:inline-flex;align-items:center;gap:.3rem;padding:.4rem 1rem;border-radius:999px;font-size:.8rem;font-weight:700;text-decoration:none;transition:all .2s;border:1.5px solid #e2e8f0;color:#475569;background:#fff}
    .bk-btn:hover{border-color:#1e3a8a;color:#1e3a8a;background:#eff6ff}
    .bk-btn-primary{background:#1e3a8a;color:#fff;border-color:#1e3a8a}
    .bk-btn-primary:hover{background:#172554;border-color:#172554}
    .empty-state{text-align:center;padding:4rem 2rem;background:#fff;border-radius:1.5rem;border:1px solid #e2e8f0}
    .empty-state .icon{font-size:3.5rem;margin-bottom:1rem}
    .empty-state h3{font-size:1.2rem;font-weight:800;color:#0f172a;margin:0 0 .5rem}
    .empty-state p{color:#64748b;font-size:.9rem;margin:0 0 1.5rem}
    @media(max-width:640px){.mb-stats{grid-template-columns:1fr 1fr}.mb-body{padding:1.25rem 1rem}.bk-card{flex-direction:column}.bk-right{text-align:left;align-items:flex-start}}

    /* ── Pagination pill ── */
    .pagination{display:flex;align-items:center;justify-content:space-between;gap:1rem;margin-top:1.5rem;flex-wrap:wrap}
    .pagination-info{font-size:.85rem;color:#64748b;font-weight:600}
    .pagination-pages{display:inline-flex;align-items:center;gap:.2rem;background:#f1f5f9;border-radius:999px;padding:.4rem .65rem;box-shadow:0 2px 10px rgba(0,0,0,.07)}
    .pg-btn{display:inline-flex;align-items:center;justify-content:center;width:36px;height:36px;border-radius:50%;font-size:.88rem;font-weight:600;text-decoration:none;border:none;color:#64748b;background:transparent;transition:all .2s;cursor:pointer;line-height:1}
    .pg-btn:hover{background:#1e3a8a;color:#fff}
    .pg-btn.active{background:#1e3a8a;color:#fff;box-shadow:0 4px 10px rgba(30,58,138,.3);font-weight:800}
    .pg-btn.disabled{opacity:.35;pointer-events:none}
    .pg-btn.pg-dots{cursor:default;width:24px;font-size:.8rem}
    .pg-btn.pg-dots:hover{background:transparent;color:#64748b}
  </style>
</head>
<body>
<?php render_nav($user,'bookings'); ?>

<div class="mb-hero">
  <div class="mb-hero-inner">
    <div><h1>🎟 My Bookings</h1><p>Your ticket history and QR codes</p></div>
    <a href="tickets.php" class="mb-hero-btn">+ Buy New Tickets</a>
  </div>
</div>

<div class="mb-stats">
  <div class="mb-stat"><div class="mb-stat-label">Total</div><div class="mb-stat-value blue"><?= $myStats['total'] ?></div></div>
  <div class="mb-stat"><div class="mb-stat-label">Paid</div><div class="mb-stat-value green"><?= $myStats['paid'] ?></div></div>
  <div class="mb-stat"><div class="mb-stat-label">Pending</div><div class="mb-stat-value yellow"><?= $myStats['pending'] ?></div></div>
  <div class="mb-stat"><div class="mb-stat-label">Total Spent</div><div class="mb-stat-value green">₱<?= number_format($myStats['spent'],0) ?></div></div>
</div>

<div class="mb-body">
  <?php if($flash&&($flash['message']??'')!==''): ?>
    <div class="flash <?= ($flash['type']??'')==='error'?'flash-error':'flash-success' ?>"><?= ($flash['type']??'')==='error'?'⚠ ':'✅ ' ?><?= e($flash['message']) ?></div>
  <?php endif; ?>

  <?php if(!count($bookings)): ?>
    <div class="empty-state">
      <div class="icon">🎟</div>
      <h3>No bookings yet</h3>
      <p>You haven't booked any tickets. Start your adventure today!</p>
      <a href="tickets.php" class="bk-btn bk-btn-primary" style="display:inline-flex">🎢 Browse Tickets</a>
    </div>
  <?php else: ?>
    <?php
      $badgeMap=['Paid'=>'badge-paid','Pending'=>'badge-pending','Cancelled'=>'badge-cancelled','Refunded'=>'badge-refunded'];
      $icons=['Paid'=>'🎉','Pending'=>'⏳','Cancelled'=>'❌','Refunded'=>'↩'];
    ?>
    <?php foreach($bookings as $b):
      $pay=$b['payment_status']??'Pending';
      $tc=$ticketCounts[(int)$b['id']]??0;
      $visitDate=!empty($b['visit_date'])?date('M d, Y',strtotime((string)$b['visit_date'])):'—';
      $bookedAt=!empty($b['created_at'])?date('M d, Y',strtotime((string)$b['created_at'])):'—';
    ?>
      <div class="bk-card">
        <div class="bk-icon"><?= $icons[$pay]??'🎟' ?></div>
        <div class="bk-main">
          <div class="bk-ref"><?= e($b['booking_reference']??'') ?></div>
          <div class="bk-type"><?= e($b['ticket_type_name']??'') ?> &times; <?= (int)($b['quantity']??1) ?></div>
          <div class="bk-meta">
            <span class="bk-meta-item">📅 Visit: <strong><?= $visitDate ?></strong></span>
            <span class="bk-meta-item">🗓 Booked: <?= $bookedAt ?></span>
            <?php if($tc>0): ?><span class="bk-meta-item">🎫 <?= $tc ?> ticket<?= $tc!==1?'s':'' ?></span><?php endif; ?>
          </div>
          <div class="bk-actions">
            <a href="booking-detail.php?ref=<?= urlencode((string)($b['booking_reference']??'')) ?>" class="bk-btn">📄 Details</a>
            <?php if($pay==='Paid'): ?><a href="booking-qr.php?id=<?= (int)$b['id'] ?>" class="bk-btn bk-btn-primary">📱 View QR</a><?php endif; ?>
            <?php if($pay==='Pending'): ?><a href="tickets.php?step=2" class="bk-btn bk-btn-primary">💳 Pay Now</a><?php endif; ?>
          </div>
        </div>
        <div class="bk-right">
          <div class="bk-amount">₱<?= number_format((float)($b['total_amount']??0),0) ?></div>
          <span class="bk-badge <?= $badgeMap[$pay]??'badge-default' ?>"><?= e($pay) ?></span>
        </div>
      </div>
    <?php endforeach; ?>

    <?php if($totalPages>1): ?>
    <div class="pagination" style="margin-top:1.5rem">
      <div class="pagination-info">Showing <?= $offset+1 ?>–<?= min($offset+$perPage,$total) ?> of <?= $total ?> bookings</div>
      <div class="pagination-pages">
        <?php
          echo '<a class="pg-btn '.($page<=1?'disabled':'').'" href="?page='.($page-1).'">&#8592;</a>';
          $s=max(1,$page-2);$e=min($totalPages,$page+2);
          if($s>1){echo '<a class="pg-btn" href="?page=1">1</a>';if($s>2)echo '<span class="pg-btn pg-dots">…</span>';}
          for($i=$s;$i<=$e;$i++)echo '<a class="pg-btn '.($i===$page?'active':'').'" href="?page='.$i.'">'.$i.'</a>';
          if($e<$totalPages){if($e<$totalPages-1)echo '<span class="pg-btn pg-dots">…</span>';echo '<a class="pg-btn" href="?page='.$totalPages.'">'.$totalPages.'</a>';}
          echo '<a class="pg-btn '.($page>=$totalPages?'disabled':'').'" href="?page='.($page+1).'">&#8594;</a>';
        ?>
      </div>
    </div>
    <?php endif; ?>
  <?php endif; ?>
</div>

<?php render_footer(); ?>
</body>
</html>
