<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/layout.php';

$user = require_admin();
$pdo  = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    $id     = (int)($_POST['id'] ?? 0);
    if ($id > 0) {
        if ($action === 'mark_paid') {
            $pdo->prepare("UPDATE bookings SET payment_status='Paid' WHERE id=?")->execute([$id]);
            flash_set('success', 'Booking marked as Paid.');
        }
        if ($action === 'mark_used') {
            $pdo->prepare("UPDATE bookings SET status='Used' WHERE id=?")->execute([$id]);
            try { $pdo->prepare("UPDATE tickets SET status='USED' WHERE booking_id=? AND status='ACTIVE'")->execute([$id]); } catch (\Throwable $e) {}
            flash_set('success', 'Booking marked as Used.');
        }
        if ($action === 'cancel') {
            $pdo->prepare("UPDATE bookings SET payment_status='Cancelled',status='Cancelled' WHERE id=?")->execute([$id]);
            try { $pdo->prepare("UPDATE tickets SET status='CANCELLED' WHERE booking_id=?")->execute([$id]); } catch (\Throwable $e) {}
            flash_set('success', 'Booking cancelled.');
        }
    }
    redirect('admin/bookings.php');
}

$q    = trim((string)($_GET['q']   ?? ''));
$pay  = trim((string)($_GET['pay'] ?? ''));
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 7;

$where = []; $params = [];
if ($q !== '') {
    $where[] = '(booking_reference LIKE ? OR customer_name LIKE ? OR customer_email LIKE ?)';
    $params  = array_merge($params, ['%'.$q.'%','%'.$q.'%','%'.$q.'%']);
}
if ($pay !== '') { $where[] = 'payment_status = ?'; $params[] = $pay; }

$whereSQL = $where ? ' WHERE '.implode(' AND ', $where) : '';

$total = (int)$pdo->prepare("SELECT COUNT(*) AS c FROM bookings{$whereSQL}")->execute($params) ? 0 : 0;
$cntSt = $pdo->prepare("SELECT COUNT(*) AS c FROM bookings{$whereSQL}");
$cntSt->execute($params);
$total = (int)$cntSt->fetch()['c'];
$totalPages = max(1, (int)ceil($total / $perPage));
$page   = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$st = $pdo->prepare("SELECT * FROM bookings{$whereSQL} ORDER BY created_at DESC LIMIT ? OFFSET ?");
$st->execute(array_merge($params, [$perPage, $offset]));
$bookings = $st->fetchAll();

// Stats
$stats = ['total'=>0,'paid'=>0,'pending'=>0,'cancelled'=>0,'revenue'=>0.0];
try {
    $stats['total']     = (int)$pdo->query("SELECT COUNT(*) FROM bookings")->fetchColumn();
    $stats['paid']      = (int)$pdo->query("SELECT COUNT(*) FROM bookings WHERE payment_status='Paid'")->fetchColumn();
    $stats['pending']   = (int)$pdo->query("SELECT COUNT(*) FROM bookings WHERE payment_status='Pending'")->fetchColumn();
    $stats['cancelled'] = (int)$pdo->query("SELECT COUNT(*) FROM bookings WHERE payment_status='Cancelled'")->fetchColumn();
    $stats['revenue']   = (float)$pdo->query("SELECT COALESCE(SUM(total_amount),0) FROM bookings WHERE payment_status='Paid'")->fetchColumn();
} catch (\Throwable $e) {}

$flash = flash_get();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/><meta name="viewport" content="width=device-width,initial-scale=1"/>
  <title>Bookings — AmusePark Admin</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="../css/style.css"/>
  <style>
    *,*::before,*::after{box-sizing:border-box}
    body{font-family:'Poppins',sans-serif;background:#f1f5f9;color:#0f172a;margin:0}

    .bk-hero{background:linear-gradient(135deg,#0f172a 0%,#1e3a8a 100%);padding:3rem 2rem 5rem;position:relative;overflow:hidden}
    .bk-hero::before{content:'';position:absolute;inset:0;background:url('https://www.transparenttextures.com/patterns/cubes.png');opacity:.08}
    .bk-hero-inner{max-width:1300px;margin:0 auto;position:relative;z-index:1}
    .bk-hero h1{font-size:2rem;font-weight:900;color:#fff;margin:0 0 .35rem;letter-spacing:-.02em}
    .bk-hero p{color:rgba(255,255,255,.65);font-size:.95rem;margin:0}

    .bk-stats{display:grid;grid-template-columns:repeat(5,1fr);gap:1rem;max-width:1300px;margin:-2.5rem auto 0;padding:0 2rem;position:relative;z-index:10}
    .bk-stat{background:#fff;border-radius:1.25rem;padding:1.25rem 1.5rem;border:1px solid #e2e8f0;box-shadow:0 4px 12px rgba(0,0,0,.06);transition:transform .2s}
    .bk-stat:hover{transform:translateY(-3px)}
    .bk-stat-label{font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#94a3b8;margin-bottom:.4rem}
    .bk-stat-value{font-size:1.6rem;font-weight:900;color:#0f172a;line-height:1}
    .bk-stat-value.green{color:#16a34a}.bk-stat-value.yellow{color:#d97706}.bk-stat-value.red{color:#dc2626}.bk-stat-value.blue{color:#1e3a8a}

    .bk-body{max-width:1300px;margin:0 auto;padding:2rem}

    .filter-card{background:#fff;border:1px solid #e2e8f0;border-radius:1.25rem;padding:1.25rem 1.5rem;margin-bottom:1.5rem;display:flex;flex-wrap:wrap;gap:.75rem;align-items:center}
    .filter-card input,.filter-card select{background:#f8fafc;border:1.5px solid #e2e8f0;color:#0f172a;border-radius:.75rem;padding:.6rem 1rem;font-size:.88rem;font-family:inherit;transition:border-color .2s}
    .filter-card input:focus,.filter-card select:focus{border-color:#1e3a8a;outline:none}
    .filter-card input[type=text]{flex:1;min-width:220px}
    .f-btn{padding:.6rem 1.25rem;border-radius:.75rem;font-weight:700;font-size:.88rem;cursor:pointer;border:none;font-family:inherit;transition:all .2s}
    .f-btn-primary{background:#1e3a8a;color:#fff}.f-btn-primary:hover{background:#172554}
    .f-btn-reset{background:#f1f5f9;color:#475569;border:1.5px solid #e2e8f0}.f-btn-reset:hover{border-color:#1e3a8a;color:#1e3a8a}

    .bk-table-card{background:#fff;border:1px solid #e2e8f0;border-radius:1.25rem;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,.04)}
    .bk-table-card table{width:100%;border-collapse:collapse}
    .bk-table-card thead th{background:#f8fafc;padding:.85rem 1.1rem;font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#64748b;border-bottom:1px solid #e2e8f0;text-align:left;white-space:nowrap}
    .bk-table-card tbody tr{border-bottom:1px solid #f1f5f9;transition:background .15s}
    .bk-table-card tbody tr:last-child{border-bottom:none}
    .bk-table-card tbody tr:hover{background:#f8fafc}
    .bk-table-card tbody td{padding:.9rem 1.1rem;font-size:.88rem;vertical-align:middle}

    .ref-pill{font-family:monospace;font-weight:800;background:#eff6ff;color:#1e3a8a;padding:.2rem .6rem;border-radius:.4rem;font-size:.82rem;text-decoration:none;border-bottom:1px dashed #93c5fd}
    .ref-pill:hover{background:#dbeafe}

    .badge{display:inline-flex;align-items:center;gap:.3rem;padding:.25rem .75rem;border-radius:999px;font-size:.72rem;font-weight:700}
    .badge-green{background:#dcfce7;color:#166534}.badge-yellow{background:#fef9c3;color:#854d0e}
    .badge-red{background:#fee2e2;color:#991b1b}.badge-blue{background:#dbeafe;color:#1e40af}.badge-gray{background:#f1f5f9;color:#475569}

    .act-btn{display:inline-flex;align-items:center;gap:.3rem;padding:.3rem .75rem;border-radius:.5rem;font-size:.78rem;font-weight:700;cursor:pointer;border:none;font-family:inherit;transition:all .2s}
    .act-paid{background:#dcfce7;color:#166534}.act-paid:hover{background:#bbf7d0}
    .act-cancel{background:#fee2e2;color:#991b1b}.act-cancel:hover{background:#fecaca}
    .act-used{background:#dbeafe;color:#1e40af}.act-used:hover{background:#bfdbfe}
    .act-tickets{background:#f1f5f9;color:#475569;border:1.5px solid #e2e8f0}.act-tickets:hover{border-color:#1e3a8a;color:#1e3a8a}

    .ticket-expand-row{display:none}
    .ticket-expand-row td{background:#f8fafc;padding:1rem 1.5rem;border-top:1px solid #e2e8f0}
    .ticket-chip{display:inline-block;font-family:monospace;font-size:.78rem;background:#fff;color:#1e3a8a;border:1px solid #dbeafe;padding:.2rem .55rem;border-radius:.35rem;margin:.15rem}

    .flash{padding:1rem 1.25rem;border-radius:.85rem;margin-bottom:1.5rem;font-weight:600;font-size:.9rem}
    .flash-success{background:#f0fdf4;color:#166534;border:1px solid #bbf7d0}
    .flash-error{background:#fef2f2;color:#991b1b;border:1px solid #fecaca}

    @media(max-width:1024px){.bk-stats{grid-template-columns:repeat(3,1fr)}}
    @media(max-width:640px){.bk-stats{grid-template-columns:1fr 1fr};.bk-body{padding:1.25rem 1rem}}

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

<div class="bk-hero">
  <div class="bk-hero-inner">
    <h1>📋 Bookings Management</h1>
    <p>View, filter and manage all customer ticket bookings</p>
  </div>
</div>

<div class="bk-stats">
  <div class="bk-stat"><div class="bk-stat-label">Total Bookings</div><div class="bk-stat-value blue"><?= $stats['total'] ?></div></div>
  <div class="bk-stat"><div class="bk-stat-label">Paid</div><div class="bk-stat-value green"><?= $stats['paid'] ?></div></div>
  <div class="bk-stat"><div class="bk-stat-label">Pending</div><div class="bk-stat-value yellow"><?= $stats['pending'] ?></div></div>
  <div class="bk-stat"><div class="bk-stat-label">Cancelled</div><div class="bk-stat-value red"><?= $stats['cancelled'] ?></div></div>
  <div class="bk-stat"><div class="bk-stat-label">Total Revenue</div><div class="bk-stat-value green">₱<?= number_format($stats['revenue'],0) ?></div></div>
</div>

<div class="bk-body">
  <?php if ($flash && ($flash['message']??'')!==''): ?>
    <div class="flash <?= ($flash['type']??'')==='error'?'flash-error':'flash-success' ?>">
      <?= ($flash['type']??'')==='error'?'⚠ ':'✅ ' ?><?= e($flash['message']) ?>
    </div>
  <?php endif; ?>

  <form class="filter-card" method="get">
    <input type="text" name="q" value="<?= e($q) ?>" placeholder="🔍 Search reference, name, email…"/>
    <select name="pay">
      <option value="">All Payments</option>
      <?php foreach(['Pending','Paid','Cancelled','Refunded'] as $p): ?>
        <option value="<?= e($p) ?>" <?= $pay===$p?'selected':'' ?>><?= e($p) ?></option>
      <?php endforeach; ?>
    </select>
    <button class="f-btn f-btn-primary" type="submit">Filter</button>
    <a class="f-btn f-btn-reset" href="bookings.php">Reset</a>
    <span style="margin-left:auto;font-size:.82rem;color:#64748b;font-weight:600;"><?= $total ?> result<?= $total!==1?'s':'' ?></span>
  </form>

  <div class="bk-table-card">
    <table>
      <thead>
        <tr>
          <th>Reference</th><th>Customer</th><th>Ticket</th><th>Visit Date</th>
          <th>Amount</th><th>Payment</th><th>Status</th><th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!count($bookings)): ?>
          <tr><td colspan="8" style="text-align:center;padding:3rem;color:#94a3b8;">No bookings found</td></tr>
        <?php endif; ?>
        <?php foreach($bookings as $b):
          $bId   = (int)$b['id'];
          $paySt = (string)($b['payment_status']??'');
          $bkSt  = (string)($b['status']??'Active');
          $tNums = [];
          try{$ts=$pdo->prepare('SELECT ticket_number FROM tickets WHERE booking_id=? ORDER BY ticket_number');$ts->execute([$bId]);$tNums=$ts->fetchAll(\PDO::FETCH_COLUMN);}catch(\Throwable $e){}
          $stColors=['Active'=>'badge-green','Used'=>'badge-blue','Cancelled'=>'badge-red','Expired'=>'badge-gray'];
          $pyColors=['Paid'=>'badge-green','Pending'=>'badge-yellow','Cancelled'=>'badge-red','Refunded'=>'badge-blue'];
        ?>
          <tr>
            <td><a href="../booking-detail.php?ref=<?= urlencode((string)($b['booking_reference']??'')) ?>" class="ref-pill"><?= e($b['booking_reference']??'') ?></a></td>
            <td>
              <div style="font-weight:700"><?= e($b['customer_name']??'') ?></div>
              <div style="color:#94a3b8;font-size:.75rem"><?= e($b['customer_email']??'') ?></div>
            </td>
            <td>
              <div style="font-weight:600"><?= e($b['ticket_type_name']??'') ?></div>
              <div style="color:#64748b;font-size:.75rem">×<?= (int)($b['quantity']??1) ?> ticket<?= (int)($b['quantity']??1)>1?'s':'' ?></div>
            </td>
            <td style="color:#475569"><?= e((string)($b['visit_date']??'')) ?></td>
            <td style="font-weight:800;color:#1e3a8a">₱<?= number_format((float)($b['total_amount']??0),0) ?></td>
            <td><span class="badge <?= $pyColors[$paySt]??'badge-gray' ?>"><?= e($paySt) ?></span></td>
            <td><span class="badge <?= $stColors[$bkSt]??'badge-gray' ?>"><?= e($bkSt) ?></span></td>
            <td>
              <div style="display:flex;gap:.35rem;flex-wrap:wrap">
                <?php if($paySt==='Pending'): ?>
                  <form method="post" style="margin:0"><input type="hidden" name="action" value="mark_paid"/><input type="hidden" name="id" value="<?= $bId ?>"/><button class="act-btn act-paid">✓ Paid</button></form>
                  <form method="post" style="margin:0" onsubmit="return confirm('Cancel this booking?')"><input type="hidden" name="action" value="cancel"/><input type="hidden" name="id" value="<?= $bId ?>"/><button class="act-btn act-cancel">✕ Cancel</button></form>
                <?php endif; ?>
                <?php if($bkSt==='Active'&&$paySt==='Paid'): ?>
                  <form method="post" style="margin:0"><input type="hidden" name="action" value="mark_used"/><input type="hidden" name="id" value="<?= $bId ?>"/><button class="act-btn act-used">📱 Used</button></form>
                <?php endif; ?>
                <button class="act-btn act-tickets" onclick="toggleRow(<?= $bId ?>)">🎫 <?= count($tNums) ?></button>
              </div>
            </td>
          </tr>
          <tr class="ticket-expand-row" id="expand-<?= $bId ?>">
            <td colspan="8">
              <div style="font-size:.75rem;font-weight:700;color:#1e3a8a;text-transform:uppercase;letter-spacing:.06em;margin-bottom:.5rem">Individual Tickets</div>
              <?php if(count($tNums)): foreach($tNums as $tn): ?><span class="ticket-chip"><?= e($tn) ?></span><?php endforeach;
              else: ?><span style="color:#94a3b8;font-size:.82rem">No tickets generated.</span><?php endif; ?>
              <?php if(!empty($b['created_at'])): ?><div style="margin-top:.6rem;font-size:.75rem;color:#94a3b8">Booked: <?= e((string)$b['created_at']) ?><?php if(!empty($b['payment_reference'])): ?> · Ref: <code><?= e($b['payment_reference']) ?></code><?php endif; ?></div><?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <!-- Pagination -->
  <?php if($totalPages>1): ?>
  <div class="pagination" style="margin-top:1.5rem">
    <div class="pagination-info">Showing <?= $offset+1 ?>–<?= min($offset+$perPage,$total) ?> of <?= $total ?> bookings</div>
    <div class="pagination-pages">
      <?php
        $qs = http_build_query(array_filter(['q'=>$q,'pay'=>$pay]));
        $base = 'bookings.php?'.($qs?$qs.'&':'');
      ?>
      <a class="pg-btn <?= $page<=1?'disabled':'' ?>" href="<?= $base ?>page=<?= $page-1 ?>">&#8592;</a>
      <?php
        $s=max(1,$page-2);$e=min($totalPages,$page+2);
        if($s>1){echo '<a class="pg-btn" href="'.$base.'page=1">1</a>';if($s>2)echo '<span class="pg-btn pg-dots">…</span>';}
        for($i=$s;$i<=$e;$i++) echo '<a class="pg-btn '.($i===$page?'active':'').'" href="'.$base.'page='.$i.'">'.$i.'</a>';
        if($e<$totalPages){if($e<$totalPages-1)echo '<span class="pg-btn pg-dots">…</span>';echo '<a class="pg-btn" href="'.$base.'page='.$totalPages.'">'.$totalPages.'</a>';}
      ?>
      <a class="pg-btn <?= $page>=$totalPages?'disabled':'' ?>" href="<?= $base ?>page=<?= $page+1 ?>">&#8594;</a>
    </div>
  </div>
  <?php endif; ?>
</div>

<script>
function toggleRow(id){var r=document.getElementById('expand-'+id);if(r)r.style.display=r.style.display==='none'?'table-row':'none';}
</script>
<?php render_footer(); ?>
</body>
</html>
