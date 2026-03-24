<?php
declare(strict_types=1);

/**
 * PayMongo Webhook Handler
 *
 * Register this URL in your PayMongo dashboard → Webhooks:
 *   https://yourdomain.com/amusement/paymongo-webhook.php
 *
 * Subscribe to events: payment.paid, payment.failed, qrph.expired
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/mailer.php';

if (file_exists(__DIR__ . '/cron/expire_bookings.php')) {
    require_once __DIR__ . '/cron/expire_bookings.php';
}

$rawBody  = (string)file_get_contents('php://input');
$sigHeader = (string)($_SERVER['HTTP_X_PAYMONGO_SIGNATURE'] ?? '');

// Verify signature if webhook secret is configured
if (PAYMONGO_WEBHOOK_SECRET !== '') {
    require_once __DIR__ . '/lib/paymongo.php';
    if (!paymongo_verify_webhook($rawBody, $sigHeader, PAYMONGO_WEBHOOK_SECRET)) {
        http_response_code(401);
        echo json_encode(['error' => 'Invalid signature']);
        exit;
    }
}

$payload = json_decode($rawBody, true);
if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON']);
    exit;
}

$eventType = (string)($payload['data']['attributes']['type'] ?? '');
$pdo       = db();

$logDir  = __DIR__ . '/logs';
if (!is_dir($logDir)) mkdir($logDir, 0755, true);
$logFile = $logDir . '/webhook.log';
file_put_contents($logFile, '[' . date('Y-m-d H:i:s') . '] event=' . $eventType . "\n", FILE_APPEND | LOCK_EX);

switch ($eventType) {

    // ── Payment succeeded ──────────────────────────────────────
    case 'payment.paid':
        $paymentData   = $payload['data']['attributes']['data']['attributes'] ?? [];
        $intentId      = (string)($paymentData['payment_intent_id'] ?? '');
        $paidAt        = isset($paymentData['paid_at'])
            ? date('Y-m-d H:i:s', (int)$paymentData['paid_at'])
            : date('Y-m-d H:i:s');
        $pmRef         = (string)($paymentData['external_reference_number'] ?? '');

        if ($intentId === '') {
            http_response_code(200);
            echo json_encode(['received' => true]);
            exit;
        }

        // Find the booking by payment intent ID
        $st = $pdo->prepare('SELECT * FROM bookings WHERE paymongo_intent_id = ? LIMIT 1');
        $st->execute([$intentId]);
        $booking = $st->fetch();

        if ($booking) {
            $pdo->prepare(
                "UPDATE bookings
                 SET payment_status = 'Paid',
                     payment_reference = ?,
                     updated_at = ?
                 WHERE id = ?"
            )->execute([$pmRef ?: ('PAYMONGO-' . $intentId), $paidAt, (int)$booking['id']]);

            // Activate tickets
            $pdo->prepare(
                "UPDATE tickets SET status = 'ACTIVE' WHERE booking_id = ? AND status = 'ACTIVE'"
            )->execute([(int)$booking['id']]);

            file_put_contents($logFile,
                '[' . date('Y-m-d H:i:s') . '] PAID booking_id=' . $booking['id'] . ' ref=' . $booking['booking_reference'] . "\n",
                FILE_APPEND | LOCK_EX
            );
        }
        break;

    // ── Payment failed ─────────────────────────────────────────
    case 'payment.failed':
        $paymentData = $payload['data']['attributes']['data']['attributes'] ?? [];
        $intentId    = (string)($paymentData['payment_intent_id'] ?? '');

        if ($intentId !== '') {
            // Revert to awaiting_payment_method — customer can retry
            $pdo->prepare(
                "UPDATE bookings SET paymongo_qr_image = NULL WHERE paymongo_intent_id = ?"
            )->execute([$intentId]);

            file_put_contents($logFile,
                '[' . date('Y-m-d H:i:s') . '] FAILED intent=' . $intentId . "\n",
                FILE_APPEND | LOCK_EX
            );
        }
        break;

    // ── QR Ph code expired (30 min) ────────────────────────────
    case 'qrph.expired':
        $codeId  = (string)($payload['data']['attributes']['data']['id'] ?? '');
        $intentId = (string)($payload['data']['attributes']['data']['attributes']['payment_intent_id'] ?? '');

        if ($intentId !== '') {
            // Clear the QR image so the UI knows to regenerate
            $pdo->prepare(
                "UPDATE bookings SET paymongo_qr_image = NULL WHERE paymongo_intent_id = ?"
            )->execute([$intentId]);

            file_put_contents($logFile,
                '[' . date('Y-m-d H:i:s') . '] QR_EXPIRED intent=' . $intentId . "\n",
                FILE_APPEND | LOCK_EX
            );
        }
        break;
}

http_response_code(200);
echo json_encode(['received' => true]);
