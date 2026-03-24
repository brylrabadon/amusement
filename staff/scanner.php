<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/layout.php';

$user = require_staff();
$pdo  = db();

/**
 * Process a ticket scan — marks ACTIVE ticket as USED and returns full booking details.
 */
function process_scan(string $ticket_number, PDO $pdo): array
{
    $ticket_number = trim($ticket_number);
    if ($ticket_number === '') {
        return ['success' => false, 'error' => 'Please enter a ticket number.'];
    }

    try {
        $stmt = $pdo->prepare(
            'SELECT t.id, t.ticket_number, t.status, t.booking_id, t.scanned_at,
                    b.booking_reference, b.visit_date, b.ticket_type_id,
                    b.customer_name, b.customer_email, b.payment_status,
                    b.total_amount, b.quantity, b.created_at AS booked_at,
                    tt.name AS ticket_type_name
             FROM tickets t
             JOIN bookings b  ON b.id  = t.booking_id
             JOIN ticket_types tt ON tt.id = b.ticket_type_id
             WHERE t.ticket_number = ?
             LIMIT 1'
        );
        $stmt->execute([$ticket_number]);
        $ticket = $stmt->fetch();
    } catch (\Throwable $e) {
        return ['success' => false, 'error' => 'Database error: ' . $e->getMessage()];
    }

    if (!$ticket) {
        return ['success' => false, 'error' => 'Ticket not found.'];
    }

    $status = (string)($ticket['status'] ?? '');

    // Fetch rides for this ticket type
    $rides = [];
    try {
        $rideStmt = $pdo->prepare(
            'SELECT r.name FROM ticket_ride tr
             JOIN rides r ON r.id = tr.ride_id
             WHERE tr.ticket_type_id = ?
             ORDER BY r.name'
        );
        $rideStmt->execute([(int)$ticket['ticket_type_id']]);
        foreach ($rideStmt->fetchAll() as $row) {
            $rides[] = (string)$row['name'];
        }
    } catch (\Throwable $e) {
        // rides not critical
    }

    $details = [
        'ticket_number'     => (string)$ticket['ticket_number'],
        'booking_reference' => (string)$ticket['booking_reference'],
        'customer_name'     => (string)$ticket['customer_name'],
        'customer_email'    => (string)$ticket['customer_email'],
        'payment_status'    => (string)$ticket['payment_status'],
        'booked_at'         => (string)$ticket['booked_at'],
        'visit_date'        => (string)$ticket['visit_date'],
        'ticket_type_name'  => (string)$ticket['ticket_type_name'],
        'quantity'          => (string)$ticket['quantity'],
        'total_amount'      => (string)$ticket['total_amount'],
        'rides'             => $rides,
        'status'            => $status,
    ];

    switch ($status) {
        case 'ACTIVE':
            try {
                $upd = $pdo->prepare("UPDATE tickets SET status = 'USED', scanned_at = NOW() WHERE id = ?");
                $upd->execute([(int)$ticket['id']]);
                $details['status'] = 'USED';
                return ['success' => true, 'details' => $details];
            } catch (\Throwable $e) {
                return ['success' => false, 'error' => 'Database error: ' . $e->getMessage()];
            }

        case 'USED':
            return ['success' => false, 'error' => 'Ticket Already Used', 'status' => 'USED', 'details' => $details];

        case 'CANCELLED':
            return ['success' => false, 'error' => 'Ticket is cancelled.', 'status' => 'CANCELLED', 'details' => $details];

        case 'EXPIRED':
            return ['success' => false, 'error' => 'Ticket has expired.', 'status' => 'EXPIRED', 'details' => $details];

        default:
            return ['success' => false, 'error' => 'Unknown ticket status: ' . $status];
    }
}

// Handle AJAX scan request
if (isset($_GET['ajax']) && isset($_GET['ticket'])) {
    header('Content-Type: application/json');
    echo json_encode(process_scan((string)$_GET['ticket'], $pdo));
    exit;
}

// Handle regular GET scan
$result      = null;
$ticketInput = '';
if (isset($_GET['ticket'])) {
    $ticketInput = (string)$_GET['ticket'];
    $result      = process_scan($ticketInput, $pdo);
}
$ticketInputE = e($ticketInput);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Staff Scanner - AmusePark</title>
  <link rel="stylesheet" href="../css/style.css" />
  <style>
    body { background: #f8fafc; color: #1e293b; }

    .page-header {
      background-image: linear-gradient(rgba(0,0,0,0.35), rgba(0,0,0,0.35)),
                        url('https://images.unsplash.com/photo-1513889961551-628c1e5e2ee9?q=80&w=2070');
      background-size: cover; background-position: center;
      padding: 5rem 2rem; color: white;
      border-radius: 0 0 2.5rem 2.5rem; margin-bottom: -4rem; text-align: left;
    }
    .page-header h1 { font-size: 2.5rem; font-weight: 800; margin: 0; text-shadow: 2px 2px 8px rgba(0,0,0,.5); }
    .page-header p  { font-size: 1.1rem; margin-top: .75rem; font-weight: 500; text-shadow: 1px 1px 4px rgba(0,0,0,.5); }

    .scanner-card {
      background: #fff; border-radius: 1.25rem; padding: 2.5rem;
      box-shadow: 0 10px 25px -5px rgba(0,0,0,.07); border: 1px solid #e2e8f0; margin-bottom: 2rem;
    }

    /* Camera */
    .camera-section { margin-bottom: 2rem; }
    .camera-section h3 { font-weight: 700; margin-bottom: 1rem; color: #374151; }
    #qr-reader { width: 100%; max-width: 480px; border-radius: 1rem; overflow: hidden; border: 2px solid #e2e8f0; }
    #camera-status { font-size: .9rem; color: #6b7280; margin-top: .5rem; }
    .btn-camera { padding: .65rem 1.5rem; background: #7c3aed; color: #fff; border: none; border-radius: .75rem; font-weight: 700; cursor: pointer; transition: background .2s; }
    .btn-camera:hover { background: #6d28d9; }
    .btn-camera-stop { background: #dc2626; }
    .btn-camera-stop:hover { background: #b91c1c; }

    /* Manual form */
    .divider { display: flex; align-items: center; gap: 1rem; margin: 1.5rem 0; color: #94a3b8; font-size: .85rem; font-weight: 600; }
    .divider::before, .divider::after { content: ''; flex: 1; height: 1px; background: #e2e8f0; }
    .scanner-form { display: flex; gap: 1rem; flex-wrap: wrap; align-items: flex-end; }
    .scanner-form label { display: block; font-weight: 700; margin-bottom: .5rem; color: #374151; }
    .scanner-form input[type="text"] {
      flex: 1; min-width: 220px; padding: .75rem 1rem;
      border: 2px solid #e2e8f0; border-radius: .75rem; font-size: 1rem; font-family: monospace;
    }
    .scanner-form input[type="text"]:focus { outline: none; border-color: #7c3aed; }
    .btn-scan { padding: .75rem 1.75rem; background: #1d4ed8; color: #fff; border: none; border-radius: .75rem; font-weight: 700; font-size: 1rem; cursor: pointer; transition: background .2s; }
    .btn-scan:hover { background: #1e40af; }

    /* Result cards */
    .result-card { border-radius: 1.25rem; padding: 2rem; margin-top: 1.5rem; }
    .result-success { background: #f0fdf4; border: 2px solid #86efac; color: #14532d; }
    .result-warning { background: #fffbeb; border: 2px solid #fcd34d; color: #78350f; }
    .result-error   { background: #fef2f2; border: 2px solid #fca5a5; color: #7f1d1d; }
    .result-heading { font-size: 1.4rem; font-weight: 800; margin: 0 0 1.25rem; display: flex; align-items: center; gap: .5rem; }
    .result-row { display: flex; gap: .5rem; margin-bottom: .6rem; font-size: .95rem; }
    .result-label { font-weight: 700; min-width: 170px; flex-shrink: 0; }
    .result-value { font-family: monospace; }
    .rides-list { list-style: none; padding: 0; margin: .25rem 0 0; }
    .rides-list li::before { content: "🎢 "; }
    .rides-list li { margin-bottom: .25rem; }
    .btn-details { margin-top: 1rem; padding: .6rem 1.25rem; background: #7c3aed; color: #fff; border: none; border-radius: .75rem; font-weight: 700; cursor: pointer; font-size: .9rem; }
    .btn-details:hover { background: #6d28d9; }

    /* Popup / Modal */
    .modal-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.55); z-index: 300; align-items: center; justify-content: center; }
    .modal-overlay.show { display: flex; }
    .modal { background: #fff; border-radius: 1.25rem; padding: 2rem; width: 90%; max-width: 540px; max-height: 90vh; overflow-y: auto; box-shadow: 0 20px 60px rgba(0,0,0,.2); }
    .modal-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; }
    .modal-header h2 { font-size: 1.25rem; font-weight: 800; margin: 0; }
    .modal-close { background: none; border: none; font-size: 1.5rem; cursor: pointer; color: #94a3b8; line-height: 1; }
    .modal-close:hover { color: #374151; }
    .detail-table { width: 100%; border-collapse: collapse; font-size: .9rem; }
    .detail-table tr:nth-child(even) td { background: #f8fafc; }
    .detail-table td { padding: .65rem .85rem; border-bottom: 1px solid #f1f5f9; }
    .detail-table td:first-child { font-weight: 700; color: #374151; width: 45%; }
    .detail-table td:last-child { font-family: monospace; }
    .status-badge { display: inline-block; padding: .2rem .75rem; border-radius: 999px; font-size: .8rem; font-weight: 700; }
    .status-active    { background: #dcfce7; color: #16a34a; }
    .status-used      { background: #dbeafe; color: #1d4ed8; }
    .status-cancelled { background: #fee2e2; color: #dc2626; }
    .status-expired   { background: #fef9c3; color: #ca8a04; }
    .status-paid      { background: #dcfce7; color: #16a34a; }
    .status-pending   { background: #fef9c3; color: #ca8a04; }
  </style>
</head>
<body>

<?php render_nav($user, 'scanner'); ?>

<div class="page-header">
  <div class="container">
    <h1>🔍 Ticket Scanner</h1>
    <p>Scan QR codes or enter ticket numbers to validate park entry.</p>
  </div>
</div>

<div class="container" style="position:relative;z-index:10;">

  <div class="scanner-card">

    <!-- Camera QR Scanner -->
    <div class="camera-section">
      <h3>📷 Camera QR Scan</h3>
      <div id="qr-reader"></div>
      <p id="camera-status">Camera is off. Click Start to begin scanning.</p>
      <div style="display:flex;gap:.75rem;margin-top:.75rem;flex-wrap:wrap;">
        <button class="btn-camera" id="btn-start-cam">▶ Start Camera</button>
        <button class="btn-camera btn-camera-stop" id="btn-stop-cam" style="display:none;">⏹ Stop Camera</button>
      </div>
    </div>

    <div class="divider">OR ENTER MANUALLY</div>

    <!-- Manual Input -->
    <form class="scanner-form" method="get" action="scanner.php">
      <div style="flex:1;min-width:220px;">
        <label for="ticket-input">Ticket Number</label>
        <input
          type="text"
          id="ticket-input"
          name="ticket"
          value="<?= $ticketInputE ?>"
          placeholder="e.g. TK-AP-XXXXXX-001"
          autocomplete="off"
        />
      </div>
      <button class="btn-scan" type="submit">🔎 Verify</button>
    </form>

    <?php if ($result !== null): ?>
      <?php
        $details = $result['details'] ?? [];
        $statusVal = $result['status'] ?? ($result['success'] ? 'USED' : '');
      ?>

      <?php if ($result['success']): ?>
        <div class="result-card result-success">
          <div class="result-heading">✅ Entry Granted — Ticket Marked as USED</div>
          <div class="result-row">
            <span class="result-label">Customer Name</span>
            <span><?= e($details['customer_name'] ?? '') ?></span>
          </div>
          <div class="result-row">
            <span class="result-label">Booking Reference</span>
            <span class="result-value"><?= e($details['booking_reference'] ?? '') ?></span>
          </div>
          <div class="result-row">
            <span class="result-label">Visit Date</span>
            <span><?= e($details['visit_date'] ?? '') ?></span>
          </div>
          <div class="result-row">
            <span class="result-label">Ticket Number</span>
            <span class="result-value"><?= e($details['ticket_number'] ?? '') ?></span>
          </div>
          <?php if (!empty($details['rides'])): ?>
            <div class="result-row" style="align-items:flex-start;">
              <span class="result-label">Included Rides</span>
              <ul class="rides-list">
                <?php foreach ($details['rides'] as $ride): ?>
                  <li><?= e($ride) ?></li>
                <?php endforeach; ?>
              </ul>
            </div>
          <?php endif; ?>
          <button class="btn-details" onclick="openPopup(<?= htmlspecialchars(json_encode($details), ENT_QUOTES, 'UTF-8') ?>)">
            📋 View Full Booking Details
          </button>
        </div>

      <?php elseif ($statusVal === 'USED'): ?>
        <div class="result-card result-warning">
          <div class="result-heading">⚠️ Ticket Already Used</div>
          <p style="margin:0 0 1rem;">This ticket has already been scanned and used for entry.</p>
          <?php if (!empty($details)): ?>
            <button class="btn-details" style="background:#d97706;" onclick="openPopup(<?= htmlspecialchars(json_encode($details), ENT_QUOTES, 'UTF-8') ?>)">
              📋 View Booking Details
            </button>
          <?php endif; ?>
        </div>

      <?php elseif ($statusVal === 'CANCELLED'): ?>
        <div class="result-card result-error">
          <div class="result-heading">🚫 Ticket Cancelled</div>
          <p style="margin:0 0 1rem;">This ticket has been cancelled and cannot be used for entry.</p>
          <?php if (!empty($details)): ?>
            <button class="btn-details" style="background:#dc2626;" onclick="openPopup(<?= htmlspecialchars(json_encode($details), ENT_QUOTES, 'UTF-8') ?>)">
              📋 View Booking Details
            </button>
          <?php endif; ?>
        </div>

      <?php elseif ($statusVal === 'EXPIRED'): ?>
        <div class="result-card result-error">
          <div class="result-heading">⏰ Ticket Expired</div>
          <p style="margin:0 0 1rem;">This ticket has expired and is no longer valid.</p>
          <?php if (!empty($details)): ?>
            <button class="btn-details" style="background:#dc2626;" onclick="openPopup(<?= htmlspecialchars(json_encode($details), ENT_QUOTES, 'UTF-8') ?>)">
              📋 View Booking Details
            </button>
          <?php endif; ?>
        </div>

      <?php else: ?>
        <div class="result-card result-error">
          <div class="result-heading">🚫 Entry Denied</div>
          <p style="margin:0;"><?= e($result['error'] ?? 'An error occurred.') ?></p>
        </div>
      <?php endif; ?>
    <?php endif; ?>

  </div><!-- /.scanner-card -->

</div><!-- /.container -->

<!-- Booking Details Popup -->
<div class="modal-overlay" id="details-modal">
  <div class="modal">
    <div class="modal-header">
      <h2>📋 Booking Details</h2>
      <button class="modal-close" onclick="closePopup()" aria-label="Close">&times;</button>
    </div>
    <div id="modal-body"></div>
  </div>
</div>

<!-- html5-qrcode library -->
<script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
<script>
let html5QrCode = null;

document.getElementById('btn-start-cam').addEventListener('click', startCamera);
document.getElementById('btn-stop-cam').addEventListener('click', stopCamera);

function startCamera() {
  document.getElementById('camera-status').textContent = 'Starting camera…';
  html5QrCode = new Html5Qrcode('qr-reader');
  html5QrCode.start(
    { facingMode: 'environment' },
    { fps: 10, qrbox: { width: 250, height: 250 } },
    onScanSuccess,
    onScanError
  ).then(() => {
    document.getElementById('camera-status').textContent = 'Camera active — point at a QR code.';
    document.getElementById('btn-start-cam').style.display = 'none';
    document.getElementById('btn-stop-cam').style.display  = 'inline-block';
  }).catch(err => {
    document.getElementById('camera-status').textContent = 'Camera error: ' + err;
  });
}

function stopCamera() {
  if (html5QrCode) {
    html5QrCode.stop().then(() => {
      html5QrCode.clear();
      html5QrCode = null;
      document.getElementById('camera-status').textContent = 'Camera stopped.';
      document.getElementById('btn-start-cam').style.display = 'inline-block';
      document.getElementById('btn-stop-cam').style.display  = 'none';
    });
  }
}

function onScanSuccess(decodedText) {
  stopCamera();
  document.getElementById('camera-status').textContent = 'QR scanned: ' + decodedText;
  // Auto-submit via AJAX
  fetch('scanner.php?ajax=1&ticket=' + encodeURIComponent(decodedText))
    .then(r => r.json())
    .then(data => showAjaxResult(decodedText, data))
    .catch(() => {
      // Fallback: redirect to page
      window.location.href = 'scanner.php?ticket=' + encodeURIComponent(decodedText);
    });
}

function onScanError() { /* suppress per-frame errors */ }

function showAjaxResult(ticketNum, data) {
  const details = data.details || {};
  const status  = data.status || (data.success ? 'USED' : '');

  let html = '';
  if (data.success) {
    html = `<div class="result-card result-success" style="margin-top:0;">
      <div class="result-heading">✅ Entry Granted — Ticket Marked as USED</div>
      <div class="result-row"><span class="result-label">Customer Name</span><span>${esc(details.customer_name)}</span></div>
      <div class="result-row"><span class="result-label">Booking Reference</span><span class="result-value">${esc(details.booking_reference)}</span></div>
      <div class="result-row"><span class="result-label">Visit Date</span><span>${esc(details.visit_date)}</span></div>
      <div class="result-row"><span class="result-label">Ticket Number</span><span class="result-value">${esc(details.ticket_number)}</span></div>
      <button class="btn-details" onclick='openPopup(${JSON.stringify(details)})'>📋 View Full Booking Details</button>
    </div>`;
  } else if (status === 'USED') {
    html = `<div class="result-card result-warning" style="margin-top:0;">
      <div class="result-heading">⚠️ Ticket Already Used</div>
      <p style="margin:0 0 1rem;">This ticket has already been scanned and used for entry.</p>
      ${details.booking_reference ? `<button class="btn-details" style="background:#d97706;" onclick='openPopup(${JSON.stringify(details)})'>📋 View Booking Details</button>` : ''}
    </div>`;
  } else if (status === 'CANCELLED') {
    html = `<div class="result-card result-error" style="margin-top:0;">
      <div class="result-heading">🚫 Ticket Cancelled</div>
      <p style="margin:0 0 1rem;">This ticket has been cancelled.</p>
      ${details.booking_reference ? `<button class="btn-details" style="background:#dc2626;" onclick='openPopup(${JSON.stringify(details)})'>📋 View Booking Details</button>` : ''}
    </div>`;
  } else if (status === 'EXPIRED') {
    html = `<div class="result-card result-error" style="margin-top:0;">
      <div class="result-heading">⏰ Ticket Expired</div>
      <p style="margin:0 0 1rem;">This ticket has expired.</p>
      ${details.booking_reference ? `<button class="btn-details" style="background:#dc2626;" onclick='openPopup(${JSON.stringify(details)})'>📋 View Booking Details</button>` : ''}
    </div>`;
  } else {
    html = `<div class="result-card result-error" style="margin-top:0;">
      <div class="result-heading">🚫 Entry Denied</div>
      <p style="margin:0;">${esc(data.error || 'An error occurred.')}</p>
    </div>`;
  }

  // Inject result below the form
  let existing = document.getElementById('ajax-result');
  if (!existing) {
    existing = document.createElement('div');
    existing.id = 'ajax-result';
    document.querySelector('.scanner-card').appendChild(existing);
  }
  existing.innerHTML = html;
  existing.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}

function esc(str) {
  if (!str) return '';
  return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// Booking details popup
function openPopup(details) {
  const statusClass = {
    'ACTIVE':    'status-active',
    'USED':      'status-used',
    'CANCELLED': 'status-cancelled',
    'EXPIRED':   'status-expired',
  }[details.status] || '';

  const payClass = details.payment_status === 'Paid' ? 'status-paid' : 'status-pending';

  let ridesHtml = '';
  if (details.rides && details.rides.length > 0) {
    ridesHtml = details.rides.map(r => `🎢 ${esc(r)}`).join('<br>');
  } else {
    ridesHtml = '<span style="color:#6b7280;font-style:italic;">No rides assigned.</span>';
  }

  const bookedAt = details.booked_at
    ? new Date(details.booked_at).toLocaleString('en-PH', { dateStyle: 'medium', timeStyle: 'short' })
    : '—';

  document.getElementById('modal-body').innerHTML = `
    <table class="detail-table">
      <tr><td>Reference No.</td><td>${esc(details.booking_reference)}</td></tr>
      <tr><td>Ticket Number</td><td>${esc(details.ticket_number)}</td></tr>
      <tr><td>Ticket Status</td><td><span class="status-badge ${statusClass}">${esc(details.status)}</span></td></tr>
      <tr><td>Customer Name</td><td>${esc(details.customer_name)}</td></tr>
      <tr><td>Customer Email</td><td>${esc(details.customer_email)}</td></tr>
      <tr><td>Payment Status</td><td><span class="status-badge ${payClass}">${esc(details.payment_status)}</span></td></tr>
      <tr><td>Booked At</td><td>${bookedAt}</td></tr>
      <tr><td>Visit Date</td><td>${esc(details.visit_date)}</td></tr>
      <tr><td>Ticket Type</td><td>${esc(details.ticket_type_name)}</td></tr>
      <tr><td>Quantity</td><td>${esc(details.quantity)}</td></tr>
      <tr><td>Total Amount</td><td>&#8369;${esc(details.total_amount)}</td></tr>
      <tr><td style="vertical-align:top;">Included Rides</td><td>${ridesHtml}</td></tr>
    </table>`;

  document.getElementById('details-modal').classList.add('show');
}

function closePopup() {
  document.getElementById('details-modal').classList.remove('show');
}

// Close modal on overlay click
document.getElementById('details-modal').addEventListener('click', function(e) {
  if (e.target === this) closePopup();
});
</script>
</body>
</html>
