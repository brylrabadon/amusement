<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/layout.php';

$user = require_admin();
$pdo = db();

/**
 * Process a ticket scan by ticket number.
 *
 * @param string $ticket_number
 * @param PDO    $pdo
 * @return array{success: bool, error?: string, status?: string, ticket_number?: string,
 *               customer_name?: string, booking_reference?: string, visit_date?: string, rides?: list<string>}
 */
function process_scan(string $ticket_number, PDO $pdo): array
{
    $ticket_number = trim($ticket_number);

    if ($ticket_number === '') {
        return ['success' => false, 'error' => 'Please enter a ticket number.'];
    }

    try {
        $stmt = $pdo->prepare(
            'SELECT t.id, t.ticket_number, t.status, t.booking_id,
                    b.booking_reference, b.visit_date, b.ticket_type_id,
                    b.customer_name
             FROM tickets t
             JOIN bookings b ON b.id = t.booking_id
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

    switch ($status) {
        case 'ACTIVE':
            try {
                $upd = $pdo->prepare(
                    "UPDATE tickets SET status = 'USED', scanned_at = NOW() WHERE id = ?"
                );
                $upd->execute([(int)$ticket['id']]);

                // Fetch rides for this ticket type
                $rides = [];
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

                return [
                    'success'           => true,
                    'ticket_number'     => (string)$ticket['ticket_number'],
                    'customer_name'     => (string)$ticket['customer_name'],
                    'booking_reference' => (string)$ticket['booking_reference'],
                    'visit_date'        => (string)$ticket['visit_date'],
                    'rides'             => $rides,
                ];
            } catch (\Throwable $e) {
                return ['success' => false, 'error' => 'Database error: ' . $e->getMessage()];
            }

        case 'USED':
            return ['success' => false, 'error' => 'Ticket Already Used', 'status' => 'USED'];

        case 'CANCELLED':
            return ['success' => false, 'error' => 'Ticket is cancelled.', 'status' => 'CANCELLED'];

        case 'EXPIRED':
            return ['success' => false, 'error' => 'Ticket has expired.', 'status' => 'EXPIRED'];

        default:
            return ['success' => false, 'error' => 'Unknown ticket status: ' . $status];
    }
}

// Handle GET scan request
$result = null;
if (isset($_GET['ticket'])) {
    $result = process_scan((string)$_GET['ticket'], $pdo);
}

$ticketInput = e((string)($_GET['ticket'] ?? ''));
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>QR Ticket Scanner - AmusePark</title>
  <link rel="stylesheet" href="../css/style.css" />
  <style>
    body { background-color: #f8fafc; color: #1e293b; }

    .page-header {
      background-image: linear-gradient(rgba(0,0,0,0.35), rgba(0,0,0,0.35)),
                        url('https://images.unsplash.com/photo-1513889961551-628c1e5e2ee9?q=80&w=2070');
      background-size: cover;
      background-position: center;
      padding: 5rem 2rem;
      color: white;
      border-radius: 0 0 2.5rem 2.5rem;
      margin-bottom: -4rem;
      text-align: left;
    }
    .page-header h1 {
      font-size: 2.5rem;
      font-weight: 800;
      margin: 0;
      text-shadow: 2px 2px 8px rgba(0,0,0,0.5);
    }
    .page-header p {
      font-size: 1.1rem;
      margin-top: 0.75rem;
      font-weight: 500;
      text-shadow: 1px 1px 4px rgba(0,0,0,0.5);
    }

    .scanner-card {
      background: white;
      border-radius: 1.25rem;
      padding: 2.5rem;
      box-shadow: 0 10px 25px -5px rgba(0,0,0,0.07);
      border: 1px solid #e2e8f0;
      margin-bottom: 2rem;
    }

    .scanner-form {
      display: flex;
      gap: 1rem;
      flex-wrap: wrap;
      align-items: flex-end;
    }
    .scanner-form label {
      display: block;
      font-weight: 700;
      margin-bottom: 0.5rem;
      color: #374151;
    }
    .scanner-form input[type="text"] {
      flex: 1;
      min-width: 220px;
      padding: 0.75rem 1rem;
      border: 2px solid #e2e8f0;
      border-radius: 0.75rem;
      font-size: 1rem;
      font-family: monospace;
      transition: border-color 0.2s;
    }
    .scanner-form input[type="text"]:focus {
      outline: none;
      border-color: #1d4ed8;
    }
    .btn-scan {
      padding: 0.75rem 1.75rem;
      background: #1d4ed8;
      color: white;
      border: none;
      border-radius: 0.75rem;
      font-weight: 700;
      font-size: 1rem;
      cursor: pointer;
      transition: background 0.2s;
    }
    .btn-scan:hover { background: #1e40af; }

    /* Result cards */
    .result-card {
      border-radius: 1.25rem;
      padding: 2rem;
      margin-top: 1.5rem;
    }
    .result-success {
      background: #f0fdf4;
      border: 2px solid #86efac;
      color: #14532d;
    }
    .result-warning {
      background: #fffbeb;
      border: 2px solid #fcd34d;
      color: #78350f;
    }
    .result-error {
      background: #fef2f2;
      border: 2px solid #fca5a5;
      color: #7f1d1d;
    }

    .result-heading {
      font-size: 1.4rem;
      font-weight: 800;
      margin: 0 0 1.25rem 0;
      display: flex;
      align-items: center;
      gap: 0.5rem;
    }
    .result-row {
      display: flex;
      gap: 0.5rem;
      margin-bottom: 0.6rem;
      font-size: 0.95rem;
    }
    .result-label {
      font-weight: 700;
      min-width: 160px;
      flex-shrink: 0;
    }
    .result-value {
      font-family: monospace;
    }
    .rides-list {
      list-style: none;
      padding: 0;
      margin: 0.25rem 0 0 0;
    }
    .rides-list li::before { content: "🎢 "; }
    .rides-list li { margin-bottom: 0.25rem; }
  </style>
</head>
<body>

<?php render_nav($user, 'scanner'); ?>

<div class="page-header">
  <div class="container">
    <h1>🔍 QR Ticket Scanner</h1>
    <p>Scan or manually enter a ticket number to validate park entry.</p>
  </div>
</div>

<div class="container" style="position:relative;z-index:10;">

  <div class="scanner-card">
    <form class="scanner-form" method="get" action="scanner.php">
      <div style="flex:1;min-width:220px;">
        <label for="ticket-input">Ticket Number</label>
        <input
          type="text"
          id="ticket-input"
          name="ticket"
          value="<?= $ticketInput ?>"
          placeholder="e.g. TK-AP-XXXXXX-001"
          autocomplete="off"
          autofocus
        />
      </div>
      <button class="btn-scan" type="submit">🔎 Scan / Verify</button>
    </form>

    <?php if ($result !== null): ?>
      <?php if ($result['success']): ?>
        <div class="result-card result-success">
          <div class="result-heading">✅ Ticket Marked as USED</div>
          <div class="result-row">
            <span class="result-label">Customer Name</span>
            <span><?= e($result['customer_name'] ?? '') ?></span>
          </div>
          <div class="result-row">
            <span class="result-label">Booking Reference</span>
            <span class="result-value"><?= e($result['booking_reference'] ?? '') ?></span>
          </div>
          <div class="result-row">
            <span class="result-label">Visit Date</span>
            <span><?= e($result['visit_date'] ?? '') ?></span>
          </div>
          <div class="result-row">
            <span class="result-label">Ticket Number</span>
            <span class="result-value"><?= e($result['ticket_number'] ?? '') ?></span>
          </div>
          <?php if (!empty($result['rides'])): ?>
            <div class="result-row" style="align-items:flex-start;">
              <span class="result-label">Included Rides</span>
              <ul class="rides-list">
                <?php foreach ($result['rides'] as $ride): ?>
                  <li><?= e($ride) ?></li>
                <?php endforeach; ?>
              </ul>
            </div>
          <?php else: ?>
            <div class="result-row">
              <span class="result-label">Included Rides</span>
              <span style="color:#6b7280;font-style:italic;">No rides assigned to this ticket type.</span>
            </div>
          <?php endif; ?>
        </div>

      <?php elseif (($result['status'] ?? '') === 'USED'): ?>
        <div class="result-card result-warning">
          <div class="result-heading">⚠️ Ticket Already Used</div>
          <p style="margin:0;">This ticket has already been scanned and used for entry. It cannot be used again.</p>
        </div>

      <?php else: ?>
        <div class="result-card result-error">
          <div class="result-heading">🚫 Entry Denied</div>
          <p style="margin:0;"><?= e($result['error'] ?? 'An error occurred.') ?></p>
        </div>
      <?php endif; ?>
    <?php endif; ?>
  </div>

</div>

</body>
</html>
