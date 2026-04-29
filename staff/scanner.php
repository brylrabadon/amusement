<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/layout.php';

$user = require_staff();
$pdo  = db();

function process_scan(string $ticket_number, PDO $pdo): array
{
    $ticket_number = trim($ticket_number);
    if ($ticket_number === '') return ['success' => false, 'error' => 'Please enter a ticket number.'];

    // Handle old qr_code_data format: "AMUSEPARK|AP-XXXXX-XXXXXX|..."
    // Extract booking reference and look up the ticket number from it
    if (str_starts_with($ticket_number, 'AMUSEPARK|')) {
        $parts = explode('|', $ticket_number);
        $bookingRef = $parts[1] ?? '';
        if ($bookingRef !== '') {
            try {
                $refSt = $pdo->prepare(
                    'SELECT t.ticket_number FROM tickets t
                     JOIN bookings b ON b.id = t.booking_id
                     WHERE b.booking_reference = ?
                     ORDER BY t.ticket_number ASC LIMIT 1'
                );
                $refSt->execute([$bookingRef]);
                $found = $refSt->fetchColumn();
                if ($found) {
                    $ticket_number = (string)$found;
                }
            } catch (\Throwable $e) {}
        }
    }

    try {
        $stmt = $pdo->prepare(
            'SELECT t.id, t.ticket_number, t.status, t.booking_id, t.scanned_at,
                    b.booking_reference, b.visit_date,
                    b.customer_name, b.customer_email, b.payment_status,
                    b.total_amount, b.quantity,
                    tt.name AS ticket_type_name
             FROM tickets t
             JOIN bookings b ON b.id = t.booking_id
             JOIN ticket_types tt ON tt.id = b.ticket_type_id
             WHERE t.ticket_number = ? LIMIT 1'
        );
        $stmt->execute([$ticket_number]);
        $ticket = $stmt->fetch();
    } catch (\Throwable $e) {
        return ['success' => false, 'error' => 'Database error: ' . $e->getMessage()];
    }

    if (!$ticket) return ['success' => false, 'error' => 'Ticket not found.'];

    $status = (string)($ticket['status'] ?? '');

    // Fetch selected rides
    $rides = [];
    try {
        $rs = $pdo->prepare(
            'SELECT r.name FROM booking_rides br
             JOIN rides r ON r.id = br.ride_id
             WHERE br.booking_id = ? ORDER BY r.name'
        );
        $rs->execute([(int)$ticket['booking_id']]);
        foreach ($rs->fetchAll() as $row) $rides[] = (string)$row['name'];
    } catch (\Throwable $e) {}

    $details = [
        'ticket_number'   => (string)($ticket['ticket_number'] ?? ''),
        'status'          => $status,
        'booking_ref'     => (string)($ticket['booking_reference'] ?? ''),
        'customer_name'   => (string)($ticket['customer_name'] ?? ''),
        'customer_email'  => (string)($ticket['customer_email'] ?? ''),
        'ticket_type'     => (string)($ticket['ticket_type_name'] ?? ''),
        'quantity'        => (int)($ticket['quantity'] ?? 1),
        'visit_date'      => (string)($ticket['visit_date'] ?? ''),
        'total_amount'    => (float)($ticket['total_amount'] ?? 0),
        'payment_status'  => (string)($ticket['payment_status'] ?? ''),
        'scanned_at'      => (string)($ticket['scanned_at'] ?? ''),
        'rides'           => $rides,
    ];

    switch ($status) {
        case 'ACTIVE':
            if (($ticket['payment_status'] ?? '') !== 'Paid') {
                return ['success' => false, 'error' => 'Payment not completed for this booking.', 'details' => $details];
            }
            try {
                $pdo->prepare("UPDATE tickets SET status='USED', scanned_at=NOW() WHERE id=?")->execute([(int)$ticket['id']]);
                $details['status'] = 'USED';
                return ['success' => true, 'details' => $details];
            } catch (\Throwable $e) {
                return ['success' => false, 'error' => 'Database error: ' . $e->getMessage()];
            }
        case 'USED':
            return ['success' => false, 'error' => 'Ticket already used.', 'status' => 'USED', 'details' => $details];
        case 'CANCELLED':
            return ['success' => false, 'error' => 'Ticket is cancelled.', 'status' => 'CANCELLED', 'details' => $details];
        case 'EXPIRED':
            return ['success' => false, 'error' => 'Ticket has expired.', 'status' => 'EXPIRED', 'details' => $details];
        default:
            return ['success' => false, 'error' => 'Unknown ticket status: ' . $status];
    }
}

// AJAX endpoint
if (isset($_GET['ajax']) && isset($_GET['ticket'])) {
    header('Content-Type: application/json');
    echo json_encode(process_scan((string)$_GET['ticket'], $pdo));
    exit;
}

$result      = null;
$ticketInput = '';
if (isset($_GET['ticket']) && !isset($_GET['ajax'])) {
    $ticketInput = (string)$_GET['ticket'];
    $result      = process_scan($ticketInput, $pdo);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Ticket Scanner — AmusePark Staff</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="../css/style.css"/>
  <style>
    *,*::before,*::after{box-sizing:border-box}
    body{font-family:'Poppins',sans-serif;background:#f1f5f9;color:#0f172a;min-height:100vh;margin:0}

    .scanner-hero{background:linear-gradient(135deg,#0f172a 0%,#1e3a8a 60%,#0f172a 100%);padding:3rem 2rem 4rem;text-align:center;position:relative;overflow:hidden}
    .scanner-hero::before{content:'';position:absolute;inset:0;background:radial-gradient(ellipse at 50% 0%,rgba(99,102,241,.2) 0%,transparent 70%)}
    .scanner-hero h1{position:relative;z-index:1;font-size:2.25rem;font-weight:900;color:#fff;margin:0 0 .4rem;letter-spacing:-.02em}
    .scanner-hero p{position:relative;z-index:1;color:rgba(255,255,255,.6);font-size:.95rem;margin:0}
    .hero-badge{position:relative;z-index:1;display:inline-flex;align-items:center;gap:.4rem;background:rgba(99,102,241,.2);border:1px solid rgba(99,102,241,.4);color:#a5b4fc;border-radius:999px;padding:.35rem 1.1rem;font-size:.78rem;font-weight:700;margin-bottom:1rem;text-transform:uppercase;letter-spacing:.05em}

    .scanner-wrap{max-width:680px;margin:0 auto;padding:2rem 1.5rem 4rem}

    .s-card{background:#fff;border:1px solid #e2e8f0;border-radius:1.5rem;padding:2rem;margin-bottom:1.5rem;box-shadow:0 4px 12px rgba(0,0,0,.06)}
    .s-card h3{font-size:.8rem;font-weight:800;color:#94a3b8;text-transform:uppercase;letter-spacing:.07em;margin:0 0 1.25rem;display:flex;align-items:center;gap:.5rem}

    /* Camera */
    #qr-reader{width:100%;border-radius:1rem;overflow:hidden;border:3px solid #1e3a8a;background:#0f172a;min-height:300px}
    #qr-reader video{border-radius:.85rem;}
    .cam-btns{display:flex;gap:.75rem;margin-top:1rem}
    .btn-cam{flex:1;padding:.85rem;border-radius:.75rem;font-weight:800;font-size:.95rem;cursor:pointer;border:none;font-family:inherit;transition:all .2s}
    .btn-start{background:#1e3a8a;color:#fff}.btn-start:hover{background:#172554}
    .btn-stop{background:#dc2626;color:#fff;display:none}.btn-stop:hover{background:#b91c1c}
    #camera-status{font-size:.85rem;color:#64748b;margin-top:.75rem;text-align:center;font-weight:600;min-height:1.5rem}

    /* Manual input */
    .divider{display:flex;align-items:center;gap:1rem;margin:1.5rem 0;color:#94a3b8;font-size:.82rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em}
    .divider::before,.divider::after{content:'';flex:1;height:1px;background:#e2e8f0}

    .scan-form{display:flex;gap:.75rem}
    .scan-input{flex:1;background:#f8fafc;border:1.5px solid #e2e8f0;color:#0f172a;border-radius:.85rem;padding:.85rem 1.25rem;font-size:1rem;font-family:inherit;transition:border-color .2s}
    .scan-input:focus{border-color:#1e3a8a;outline:none;box-shadow:0 0 0 3px rgba(30,58,138,.1)}
    .scan-input::placeholder{color:#94a3b8}
    .btn-scan{background:#1e3a8a;color:#fff;border:none;border-radius:.85rem;padding:.85rem 1.75rem;font-weight:800;font-size:.95rem;cursor:pointer;font-family:inherit;transition:all .2s;white-space:nowrap}
    .btn-scan:hover{background:#172554;transform:translateY(-1px)}

    /* Result */
    .result-card{border-radius:1.5rem;padding:1.75rem;margin-bottom:1.5rem;border:1px solid}
    .result-success{background:#f0fdf4;border-color:#86efac}
    .result-error{background:#fef2f2;border-color:#fca5a5}
    .result-warn{background:#fffbeb;border-color:#fde68a}

    .result-header{display:flex;align-items:center;gap:1rem;margin-bottom:1.25rem}
    .result-icon{width:52px;height:52px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:1.5rem;flex-shrink:0}
    .icon-success{background:#dcfce7}.icon-error{background:#fee2e2}.icon-warn{background:#fef9c3}
    .result-title{font-size:1.15rem;font-weight:800;margin:0 0 .2rem}
    .result-title.success{color:#16a34a}.result-title.error{color:#dc2626}.result-title.warn{color:#d97706}
    .result-msg{font-size:.88rem;color:#64748b;margin:0}

    .detail-grid{display:grid;grid-template-columns:1fr 1fr;gap:.6rem 1.5rem;font-size:.88rem;margin-top:1rem}
    .detail-label{color:#94a3b8;font-weight:600;font-size:.78rem;text-transform:uppercase;letter-spacing:.04em;margin-bottom:.15rem}
    .detail-value{color:#0f172a;font-weight:700}
    .detail-value.mono{font-family:monospace;color:#1e3a8a}

    .rides-list{display:flex;flex-wrap:wrap;gap:.4rem;margin-top:.5rem}
    .ride-chip{background:#eff6ff;border:1px solid #dbeafe;color:#1e3a8a;border-radius:999px;padding:.2rem .75rem;font-size:.78rem;font-weight:700}

    .scan-again-btn{display:block;width:100%;padding:.9rem;border-radius:.85rem;background:#f1f5f9;border:1px solid #e2e8f0;color:#475569;font-weight:700;font-size:.9rem;cursor:pointer;font-family:inherit;transition:all .2s;text-align:center;text-decoration:none;margin-top:1rem}
    .scan-again-btn:hover{background:#e2e8f0;color:#0f172a}

    /* Live indicator */
    .live-dot{display:inline-block;width:8px;height:8px;border-radius:50%;background:#4ade80;animation:pulse 1.5s infinite}
    @keyframes pulse{0%,100%{opacity:1;transform:scale(1)}50%{opacity:.5;transform:scale(1.3)}}
  </style>
</head>
<body>
<?php render_nav($user, 'scanner'); ?>

<div class="scanner-hero">
  <div class="hero-badge"><span class="live-dot"></span> Live Scanner</div>
  <h1>🔍 Ticket Scanner</h1>
  <p>Scan QR codes or enter ticket numbers to validate entry</p>
</div>

<div class="scanner-wrap">

  <?php if ($result !== null): ?>
    <?php
      $isSuccess = (bool)($result['success'] ?? false);
      $errStatus = (string)($result['status'] ?? '');
      $isWarn    = !$isSuccess && in_array($errStatus, ['USED','CANCELLED','EXPIRED'], true);
      $cardClass = $isSuccess ? 'result-success' : ($isWarn ? 'result-warn' : 'result-error');
      $iconClass = $isSuccess ? 'icon-success' : ($isWarn ? 'icon-warn' : 'icon-error');
      $titleClass= $isSuccess ? 'success' : ($isWarn ? 'warn' : 'error');
      $icon      = $isSuccess ? '✅' : ($isWarn ? '⚠️' : '❌');
      $title     = $isSuccess ? 'Entry Granted!' : ($isWarn ? 'Cannot Admit' : 'Scan Failed');
      $msg       = (string)($result['error'] ?? ($isSuccess ? 'Ticket validated successfully.' : ''));
      $d         = $result['details'] ?? [];
    ?>
    <div class="result-card <?= $cardClass ?>">
      <div class="result-header">
        <div class="result-icon <?= $iconClass ?>"><?= $icon ?></div>
        <div>
          <div class="result-title <?= $titleClass ?>"><?= $title ?></div>
          <div class="result-msg"><?= e($msg) ?></div>
        </div>
      </div>

      <?php if (!empty($d)): ?>
        <div class="detail-grid">
          <div><div class="detail-label">Ticket No.</div><div class="detail-value mono"><?= e($d['ticket_number'] ?? '') ?></div></div>
          <div><div class="detail-label">Status</div><div class="detail-value"><?= e($d['status'] ?? '') ?></div></div>
          <div><div class="detail-label">Booking Ref</div><div class="detail-value mono"><?= e($d['booking_ref'] ?? '') ?></div></div>
          <div><div class="detail-label">Customer</div><div class="detail-value"><?= e($d['customer_name'] ?? '') ?></div></div>
          <div><div class="detail-label">Ticket Type</div><div class="detail-value"><?= e($d['ticket_type'] ?? '') ?></div></div>
          <div><div class="detail-label">Visit Date</div><div class="detail-value"><?= e($d['visit_date'] ?? '') ?></div></div>
          <div><div class="detail-label">Amount</div><div class="detail-value">₱<?= number_format((float)($d['total_amount'] ?? 0), 0) ?></div></div>
          <div><div class="detail-label">Payment</div><div class="detail-value"><?= e($d['payment_status'] ?? '') ?></div></div>
        </div>
        <?php if (!empty($d['rides'])): ?>
          <div style="margin-top:1rem"><div class="detail-label">Selected Rides</div>
            <div class="rides-list"><?php foreach($d['rides'] as $r): ?><span class="ride-chip"><?= e($r) ?></span><?php endforeach; ?></div>
          </div>
        <?php endif; ?>
      <?php endif; ?>
    </div>
    <a href="scanner.php" class="scan-again-btn">🔄 Scan Another Ticket</a>

  <?php else: ?>

    <!-- Camera scanner -->
    <div class="s-card">
      <h3>📷 Camera Scanner</h3>
      <div id="qr-reader"></div>
      <div id="camera-status">Camera not started</div>
      <div class="cam-btns">
        <button class="btn-cam btn-start" id="btn-start" onclick="startCamera()">▶ Start Camera</button>
        <button class="btn-cam btn-stop"  id="btn-stop"  onclick="stopCamera()">■ Stop Camera</button>
      </div>
    </div>

    <div class="divider">or enter manually</div>

    <!-- Manual input -->
    <div class="s-card">
      <h3>⌨️ Manual Entry</h3>
      <form class="scan-form" method="get" action="scanner.php">
        <input class="scan-input" type="text" name="ticket"
               value="<?= e($ticketInput) ?>"
               placeholder="e.g. TK-AP-XXXXX-001"
               autocomplete="off" autofocus />
        <button class="btn-scan" type="submit">Scan</button>
      </form>
    </div>

  <?php endif; ?>

</div>

<!-- html5-qrcode library -->
<script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
<script>
var html5QrCode = null;
var scannerStarted = false;

function startCamera() {
  if (scannerStarted) return;
  var statusEl = document.getElementById('camera-status');
  var btnStart = document.getElementById('btn-start');
  var btnStop  = document.getElementById('btn-stop');

  statusEl.textContent = 'Requesting camera access…';
  btnStart.disabled = true;

  html5QrCode = new Html5Qrcode('qr-reader');

  Html5Qrcode.getCameras().then(function(cameras) {
    if (!cameras || cameras.length === 0) {
      statusEl.textContent = '❌ No camera found. Use manual entry below.';
      btnStart.disabled = false;
      return;
    }

    // Prefer rear camera on mobile, use first available on desktop
    var cameraId = cameras[0].id;
    for (var i = 0; i < cameras.length; i++) {
      var label = (cameras[i].label || '').toLowerCase();
      if (label.indexOf('back') !== -1 || label.indexOf('rear') !== -1 || label.indexOf('environment') !== -1) {
        cameraId = cameras[i].id;
        break;
      }
    }

    html5QrCode.start(
      cameraId,
      {
        fps: 15,
        qrbox: function(w, h) {
          var size = Math.min(w, h) * 0.7;
          return { width: size, height: size };
        },
        aspectRatio: 1.0
      },
      function(decodedText) {
        // QR scanned — stop camera and process
        stopCamera();
        processScannedCode(decodedText);
      },
      function(err) { /* ignore scan errors — they fire constantly while scanning */ }
    ).then(function() {
      scannerStarted = true;
      btnStart.style.display = 'none';
      btnStop.style.display  = 'flex';
      statusEl.innerHTML = '<span style="color:#16a34a;font-weight:700;">🟢 Camera active — point QR code at the box</span>';
    }).catch(function(err) {
      btnStart.disabled = false;
      statusEl.textContent = '❌ ' + err + ' — Try allowing camera in browser settings.';
    });

  }).catch(function(err) {
    btnStart.disabled = false;
    statusEl.textContent = '❌ Camera access denied. Please allow camera permission and try again.';
  });
}

function stopCamera() {
  if (html5QrCode && scannerStarted) {
    html5QrCode.stop().then(function() {
      html5QrCode.clear();
      html5QrCode = null;
      scannerStarted = false;
      var btnStart = document.getElementById('btn-start');
      var btnStop  = document.getElementById('btn-stop');
      if (btnStart) { btnStart.style.display = 'flex'; btnStart.disabled = false; }
      if (btnStop)  btnStop.style.display = 'none';
      var statusEl = document.getElementById('camera-status');
      if (statusEl) statusEl.textContent = 'Camera stopped';
    }).catch(function() {
      scannerStarted = false;
    });
  }
}

function processScannedCode(code) {
  code = code.trim();
  if (!code) return;

  // Show processing state
  var statusEl = document.getElementById('camera-status');
  if (statusEl) statusEl.innerHTML = '<span style="color:#1e3a8a;font-weight:700;">⏳ Processing ticket…</span>';

  // Redirect to scanner with the scanned code
  window.location.href = 'scanner.php?ticket=' + encodeURIComponent(code);
}

// Auto-start camera when page loads (user can still click Start Camera if it fails)
document.addEventListener('DOMContentLoaded', function() {
  // Small delay to let the page render first
  setTimeout(function() {
    startCamera();
  }, 500);
});
</script>

<?php render_footer(); ?>
</body>
</html>
