<?php
declare(strict_types=1);

/**
 * Send a cancellation email to the customer when a booking is cancelled.
 *
 * @param array $booking  Booking row (must include: customer_name, customer_email,
 *                        booking_reference, visit_date, ticket_type_name, quantity, total_amount)
 * @param array $tickets  Array of ticket rows (each must include: ticket_number)
 * @param PDO   $pdo      Database connection
 * @return bool           true on success, false if mail() failed
 */
function send_cancellation_email(array $booking, array $tickets, PDO $pdo): bool
{
    $to     = (string)($booking['customer_email'] ?? '');
    $ref    = (string)($booking['booking_reference'] ?? '');
    $name   = (string)($booking['customer_name'] ?? '');
    $email  = (string)($booking['customer_email'] ?? '');
    $date   = (string)($booking['visit_date'] ?? '');
    $type   = (string)($booking['ticket_type_name'] ?? '');
    $qty    = (string)($booking['quantity'] ?? '');
    $amount = (string)($booking['total_amount'] ?? '');

    $ticketNumbers = [];
    foreach ($tickets as $t) {
        $ticketNumbers[] = htmlspecialchars((string)($t['ticket_number'] ?? ''), ENT_QUOTES, 'UTF-8');
    }

    $today       = date('Y-m-d');
    $isRecoverable = $date >= $today;

    // Build the base URL dynamically or fall back to a placeholder
    $baseUrl = (isset($_SERVER['HTTP_HOST']) && $_SERVER['HTTP_HOST'] !== '')
        ? 'http' . ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 's' : '') . '://' . $_SERVER['HTTP_HOST']
        : 'http://localhost';

    $rebookUrl  = $baseUrl . '/tickets.php?prefill=' . rawurlencode($ref);
    $cancelUrl  = $baseUrl . '/my-bookings.php';

    // ---- Ticket numbers HTML ----
    $ticketHtml = '';
    if (count($ticketNumbers) > 0) {
        $ticketHtml = '<ul style="margin:0.5rem 0 0 1.25rem;padding:0;">';
        foreach ($ticketNumbers as $tn) {
            $ticketHtml .= '<li style="font-family:monospace;margin-bottom:0.25rem;">' . $tn . '</li>';
        }
        $ticketHtml .= '</ul>';
    } else {
        $ticketHtml = '<span style="color:#6b7280;font-style:italic;">No tickets issued.</span>';
    }

    // ---- Action section ----
    if ($isRecoverable) {
        $actionHtml = '
        <div style="background:#f0fdf4;border:1px solid #86efac;border-radius:0.75rem;padding:1.25rem;margin-top:1.5rem;">
          <p style="margin:0 0 0.75rem;font-weight:700;color:#14532d;">Your visit date is still upcoming — you can rebook!</p>
          <a href="' . $rebookUrl . '" style="display:inline-block;background:#16a34a;color:#fff;padding:0.65rem 1.5rem;border-radius:999px;font-weight:700;text-decoration:none;">
            🎟 Continue Booking
          </a>
        </div>';
    } else {
        $actionHtml = '
        <div style="background:#fef2f2;border:1px solid #fca5a5;border-radius:0.75rem;padding:1.25rem;margin-top:1.5rem;">
          <p style="margin:0 0 0.75rem;font-weight:700;color:#7f1d1d;">This booking has been permanently cancelled. The visit date has passed.</p>
          <a href="' . $cancelUrl . '" style="display:inline-block;background:#dc2626;color:#fff;padding:0.65rem 1.5rem;border-radius:999px;font-weight:700;text-decoration:none;">
            View My Bookings
          </a>
        </div>';
    }

    // ---- HTML body ----
    $htmlBody = '<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;padding:0;background:#f9fafb;font-family:Segoe UI,sans-serif;color:#111827;">
  <div style="max-width:600px;margin:2rem auto;background:#fff;border-radius:1rem;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,0.08);">
    <!-- Header -->
    <div style="background:linear-gradient(135deg,#7c3aed,#a855f7);padding:2rem;text-align:center;">
      <h1 style="margin:0;color:#fff;font-size:1.6rem;font-weight:900;">AmusePark</h1>
      <p style="margin:0.5rem 0 0;color:#e9d5ff;font-size:0.95rem;">Ticket Cancellation Notice</p>
    </div>
    <!-- Body -->
    <div style="padding:2rem;">
      <p style="font-size:1rem;margin-bottom:1.5rem;">Dear <strong>' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '</strong>,</p>
      <p style="margin-bottom:1.5rem;color:#374151;">Your booking has been <strong style="color:#dc2626;">cancelled</strong> because it was not completed within the required time. Here are the details:</p>

      <!-- Booking Details Table -->
      <table style="width:100%;border-collapse:collapse;font-size:0.9rem;margin-bottom:1rem;">
        <tr style="background:#f8fafc;">
          <td style="padding:0.75rem 1rem;font-weight:700;color:#374151;border-bottom:1px solid #e5e7eb;width:45%;">Booking Reference</td>
          <td style="padding:0.75rem 1rem;font-family:monospace;border-bottom:1px solid #e5e7eb;">' . htmlspecialchars($ref, ENT_QUOTES, 'UTF-8') . '</td>
        </tr>
        <tr>
          <td style="padding:0.75rem 1rem;font-weight:700;color:#374151;border-bottom:1px solid #e5e7eb;">Customer Name</td>
          <td style="padding:0.75rem 1rem;border-bottom:1px solid #e5e7eb;">' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '</td>
        </tr>
        <tr style="background:#f8fafc;">
          <td style="padding:0.75rem 1rem;font-weight:700;color:#374151;border-bottom:1px solid #e5e7eb;">Email</td>
          <td style="padding:0.75rem 1rem;border-bottom:1px solid #e5e7eb;">' . htmlspecialchars($email, ENT_QUOTES, 'UTF-8') . '</td>
        </tr>
        <tr>
          <td style="padding:0.75rem 1rem;font-weight:700;color:#374151;border-bottom:1px solid #e5e7eb;">Visit Date</td>
          <td style="padding:0.75rem 1rem;border-bottom:1px solid #e5e7eb;">' . htmlspecialchars($date, ENT_QUOTES, 'UTF-8') . '</td>
        </tr>
        <tr style="background:#f8fafc;">
          <td style="padding:0.75rem 1rem;font-weight:700;color:#374151;border-bottom:1px solid #e5e7eb;">Ticket Type</td>
          <td style="padding:0.75rem 1rem;border-bottom:1px solid #e5e7eb;">' . htmlspecialchars($type, ENT_QUOTES, 'UTF-8') . '</td>
        </tr>
        <tr>
          <td style="padding:0.75rem 1rem;font-weight:700;color:#374151;border-bottom:1px solid #e5e7eb;">Quantity</td>
          <td style="padding:0.75rem 1rem;border-bottom:1px solid #e5e7eb;">' . htmlspecialchars($qty, ENT_QUOTES, 'UTF-8') . '</td>
        </tr>
        <tr style="background:#f8fafc;">
          <td style="padding:0.75rem 1rem;font-weight:700;color:#374151;">Total Amount</td>
          <td style="padding:0.75rem 1rem;">&#8369;' . htmlspecialchars($amount, ENT_QUOTES, 'UTF-8') . '</td>
        </tr>
      </table>

      <!-- Ticket Numbers -->
      <div style="background:#f8fafc;border-radius:0.75rem;padding:1rem;margin-bottom:1rem;">
        <p style="margin:0 0 0.5rem;font-weight:700;color:#374151;">Ticket Number(s):</p>
        ' . $ticketHtml . '
      </div>

      ' . $actionHtml . '

      <p style="margin-top:2rem;color:#6b7280;font-size:0.85rem;">If you have questions, please contact our support team.</p>
    </div>
    <!-- Footer -->
    <div style="background:#111827;padding:1.25rem;text-align:center;">
      <p style="margin:0;color:#9ca3af;font-size:0.8rem;">&copy; 2026 AmusePark Philippines. All rights reserved.</p>
    </div>
  </div>
</body>
</html>';

    // ---- Plain text fallback ----
    $textBody  = "Dear {$name},\n\n";
    $textBody .= "Your booking has been cancelled.\n\n";
    $textBody .= "Booking Reference : {$ref}\n";
    $textBody .= "Customer Name     : {$name}\n";
    $textBody .= "Customer Email    : {$email}\n";
    $textBody .= "Visit Date        : {$date}\n";
    $textBody .= "Ticket Type       : {$type}\n";
    $textBody .= "Quantity          : {$qty}\n";
    $textBody .= "Total Amount      : {$amount}\n\n";
    foreach ($ticketNumbers as $tn) {
        $textBody .= "Ticket: {$tn}\n";
    }
    if ($isRecoverable) {
        $textBody .= "\nYour visit date is still upcoming. Rebook here:\n{$rebookUrl}\n";
    } else {
        $textBody .= "\nThis booking has been permanently cancelled. View bookings:\n{$cancelUrl}\n";
    }
    $textBody .= "\nThank you for choosing AmusePark.\n";

    // ---- Subject ----
    $subject = 'Ticket Cancellation Notice – Ref: ' . $ref;

    // ---- Multipart headers ----
    $boundary = 'AmusePark_' . md5(uniqid((string)mt_rand(), true));
    $headers  = "From: AmusePark <noreply@amusepark.com>\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: multipart/alternative; boundary=\"{$boundary}\"\r\n";

    $message  = "--{$boundary}\r\n";
    $message .= "Content-Type: text/plain; charset=UTF-8\r\n\r\n";
    $message .= $textBody . "\r\n";
    $message .= "--{$boundary}\r\n";
    $message .= "Content-Type: text/html; charset=UTF-8\r\n\r\n";
    $message .= $htmlBody . "\r\n";
    $message .= "--{$boundary}--";

    $sent = mail($to, $subject, $message, $headers);

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
