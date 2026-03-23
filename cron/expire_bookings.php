<?php
declare(strict_types=1);

/**
 * Booking expiry cron script.
 *
 * Can be included by other PHP files to use expire_pending_bookings(),
 * or run directly via CLI: php cron/expire_bookings.php
 *
 * Requirements: 7.1, 7.2, 7.4
 */

/**
 * Expire all pending bookings older than 3 minutes.
 *
 * For each expired booking:
 *  - Sets payment_status = 'Cancelled' and status = 'Cancelled'
 *  - Sets tickets.status = 'CANCELLED' for all associated tickets
 *  - Sends a cancellation email if send_cancellation_email() is available
 *
 * @param PDO $pdo
 * @return int Number of bookings expired
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

        // Cancel the booking
        $upd = $pdo->prepare(
            "UPDATE bookings
             SET payment_status = 'Cancelled', status = 'Cancelled'
             WHERE id = ?"
        );
        $upd->execute([$bookingId]);

        // Cancel associated tickets (wrap in try/catch in case tickets table doesn't exist yet)
        try {
            $updTickets = $pdo->prepare(
                "UPDATE tickets SET status = 'CANCELLED' WHERE booking_id = ?"
            );
            $updTickets->execute([$bookingId]);
        } catch (Throwable $e) {
            // tickets table may not exist yet — silently continue
        }

        // Fetch associated tickets for the email
        $tickets = [];
        try {
            $sel = $pdo->prepare(
                "SELECT * FROM tickets WHERE booking_id = ?"
            );
            $sel->execute([$bookingId]);
            $tickets = $sel->fetchAll();
        } catch (Throwable $e) {
            // tickets table may not exist yet — use empty array
        }

        // Send cancellation email if the function is available
        if (function_exists('send_cancellation_email')) {
            send_cancellation_email($booking, $tickets, $pdo);
        }

        $count++;
    }

    return $count;
}

// Only run when executed directly as CLI
if (php_sapi_name() === 'cli') {
    require_once __DIR__ . '/../config.php';

    // Include mailer if it exists
    $mailerPath = __DIR__ . '/../lib/mailer.php';
    if (file_exists($mailerPath)) {
        require_once $mailerPath;
    }

    $expired = expire_pending_bookings(db());
    echo $expired . PHP_EOL;
}
