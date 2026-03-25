<?php
declare(strict_types=1);

/**
 * Booking expiry — 3-minute pending time limit.
 * Can be included by other PHP files OR run directly via CLI.
 */

function expire_pending_bookings(PDO $pdo): int
{
    // Find all pending bookings older than 3 minutes
    $stmt = $pdo->prepare(
        "SELECT * FROM bookings
         WHERE payment_status = 'Pending'
           AND created_at < NOW() - INTERVAL 3 MINUTE"
    );
    $stmt->execute();
    $expired = $stmt->fetchAll();

    $count = 0;
    foreach ($expired as $booking) {
        $bookingId = (int)$booking['id'];

        // Cancel booking
        $pdo->prepare(
            "UPDATE bookings SET payment_status='Cancelled', status='Cancelled' WHERE id=?"
        )->execute([$bookingId]);

        // Cancel tickets
        try {
            $pdo->prepare("UPDATE tickets SET status='CANCELLED' WHERE booking_id=?")->execute([$bookingId]);
        } catch (Throwable $e) {}

        // Fetch tickets for email
        $tickets = [];
        try {
            $sel = $pdo->prepare("SELECT * FROM tickets WHERE booking_id=?");
            $sel->execute([$bookingId]);
            $tickets = $sel->fetchAll();
        } catch (Throwable $e) {}

        // Send cancellation email
        if (function_exists('send_cancellation_email')) {
            try {
                send_cancellation_email($booking, $tickets, $pdo);
            } catch (Throwable $e) {}
        }

        $count++;
    }

    return $count;
}

// CLI execution
if (php_sapi_name() === 'cli') {
    require_once __DIR__ . '/../config.php';
    $mailerPath = __DIR__ . '/../lib/mailer.php';
    if (file_exists($mailerPath)) require_once $mailerPath;
    echo expire_pending_bookings(db()) . PHP_EOL;
}
