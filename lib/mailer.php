<?php
declare(strict_types=1);

/**
 * Send a cancellation email to the customer when a booking is cancelled.
 *
 * @param array $booking  Booking row (must include: customer_name, customer_email,
 *                        booking_reference, visit_date, ticket_type_name, quantity, total_amount)
 * @param array $tickets  Array of ticket rows (each must include: ticket_number)
 * @param PDO   $pdo      Database connection (reserved for future use / consistency)
 * @return bool           true on success, false if mail() failed
 */
function send_cancellation_email(array $booking, array $tickets, PDO $pdo): bool
{
    $to      = (string)($booking['customer_email'] ?? '');
    $ref     = (string)($booking['booking_reference'] ?? '');
    $name    = (string)($booking['customer_name'] ?? '');
    $email   = (string)($booking['customer_email'] ?? '');
    $date    = (string)($booking['visit_date'] ?? '');
    $type    = (string)($booking['ticket_type_name'] ?? '');
    $qty     = (string)($booking['quantity'] ?? '');
    $amount  = (string)($booking['total_amount'] ?? '');

    // Build ticket numbers list
    $ticketNumbers = [];
    foreach ($tickets as $t) {
        $ticketNumbers[] = (string)($t['ticket_number'] ?? '');
    }

    // ---- Subject ----
    $subject = 'Ticket Cancellation Notice';

    // ---- Body ----
    $body  = "Dear {$name},\n\n";
    $body .= "Your booking has been cancelled. Below are the details:\n\n";
    $body .= "Booking Reference : {$ref}\n";
    $body .= "Customer Name     : {$name}\n";
    $body .= "Customer Email    : {$email}\n";
    $body .= "Visit Date        : {$date}\n";
    $body .= "Ticket Type       : {$type}\n";
    $body .= "Quantity          : {$qty}\n";
    $body .= "Total Amount      : {$amount}\n\n";

    if (count($ticketNumbers) > 0) {
        $body .= "Ticket Number(s)  :\n";
        foreach ($ticketNumbers as $tn) {
            $body .= "  - {$tn}\n";
        }
        $body .= "\n";
    }

    // ---- Conditional section based on visit date ----
    $today = date('Y-m-d');
    if ($date >= $today) {
        $link  = 'tickets.php?prefill=' . rawurlencode($ref);
        $body .= "Your visit date is still upcoming. You can rebook using the link below:\n";
        $body .= $link . "\n";
    } else {
        $body .= "This booking has been permanently cancelled. The visit date has passed.\n";
    }

    $body .= "\nThank you for choosing AmusePark.\n";

    // ---- Headers ----
    $headers  = "From: noreply@amusepark.com\r\n";
    $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";

    // ---- Send ----
    $sent = mail($to, $subject, $body, $headers);

    if (!$sent) {
        $logDir  = __DIR__ . '/../logs';
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
        $logFile = $logDir . '/email_failures.log';
        $entry   = '[' . date('Y-m-d H:i:s') . '] FAILED booking_reference=' . $ref . ' email=' . $email . "\n";
        file_put_contents($logFile, $entry, FILE_APPEND | LOCK_EX);
        return false;
    }

    return true;
}
