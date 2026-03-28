<?php
declare(strict_types=1);

/**
 * AmusePark Mailer — booking cancellation + confirmation emails
 */

/**
 * Build the app base URL from server globals.
 */
function _mailer_base_url(): string
{
    if (!isset($_SERVER['HTTP_HOST']) || $_SERVER['HTTP_HOST'] === '') {
        return 'http://localhost/amusement';
    }
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'];

    // Derive app subfolder from SCRIPT_NAME (e.g. /amusement/tickets.php → /amusement)
    $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
    $docRoot = str_replace('\\', '/', (string)realpath((string)($_SERVER['DOCUMENT_ROOT'] ?? '')) ?: '');
    $appRoot = str_replace('\\', '/', (string)realpath(__DIR__ . '/..') ?: '');
    $sub = '';
    if ($docRoot !== '' && stripos($appRoot, $docRoot) === 0) {
        $sub = trim(substr($appRoot, strlen($docRoot)), '/');
        $sub = $sub !== '' ? '/' . $sub : '';
    }
    return $scheme . '://' . $host . $sub;
}

/**
 * Send a cancellation email when a booking expires / is cancelled.
 * Includes: ticket numbers, full booking details, Continue Booking + Cancel Booking links.
 */
function send_cancellation_email(array $booking, array $tickets, PDO $pdo): bool
{
    $to      = (string)($booking['customer_email'] ?? '');
    $ref     = (string)($booking['booking_reference'] ?? '');
    $name    = (string)($booking['customer_name'] ?? '');
    $email   = (string)($booking['customer_email'] ?? '');
    $phone   = (string)($booking['customer_phone'] ?? '—');
    $date    = (string)($booking['visit_date'] ?? '');
    $type    = (string)($booking['ticket_type_name'] ?? '');
    $qty     = (int)($booking['quantity'] ?? 1);
    $amount  = number_format((float)($booking['total_amount'] ?? 0), 2);
    $booked  = (string)($booking['created_at'] ?? '');
    $deadline = (string)($booking['payment_deadline'] ?? $booking['expires_at'] ?? '');

    if ($to === '') return false;

    $base = _mailer_base_url();
    $continueUrl = $base . '/tickets.php?prefill=' . rawurlencode($ref);
    $cancelUrl   = $base . '/my-bookings.php';

    // Ticket numbers
    $ticketRows = '';
    foreach ($tickets as $t) {
        $tn = htmlspecialchars((string)($t['ticket_number'] ?? ''), ENT_QUOTES, 'UTF-8');
        $ticketRows .= '<tr><td colspan="2" style="padding:.5rem 1rem;font-family:monospace;background:#f8fafc;border-bottom:1px solid #e5e7eb;">'
            . '🎫 ' . $tn . '</td></tr>';
    }
    if ($ticketRows === '') {
        $ticketRows = '<tr><td colspan="2" style="padding:.5rem 1rem;color:#9ca3af;font-style:italic;">No tickets issued.</td></tr>';
    }

    $h = 'htmlspecialchars';
    $e = fn(string $v) => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');

    $htmlBody = '<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;padding:0;background:#f1f5f9;font-family:Segoe UI,system-ui,sans-serif;color:#111827;">
<div style="max-width:600px;margin:2rem auto;background:#fff;border-radius:1.25rem;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,.1);">

  <!-- Header -->
  <div style="background:linear-gradient(135deg,#0f172a 0%,#1e3a8a 100%);padding:2.5rem 2rem;text-align:center;">
    <div style="font-size:2.5rem;margin-bottom:.5rem;">⏰</div>
    <h1 style="margin:0;color:#fff;font-size:1.6rem;font-weight:900;letter-spacing:-.02em;">Booking Expired</h1>
    <p style="margin:.5rem 0 0;color:rgba(255,255,255,0.7);font-size:.95rem;">Your reservation was not completed in time</p>
  </div>

  <!-- Body -->
  <div style="padding:2rem;">
    <p style="font-size:1rem;margin:0 0 1.25rem;">Dear <strong>' . $e($name) . '</strong>,</p>
    <p style="color:#374151;margin:0 0 1.5rem;line-height:1.6;">
      Your booking <strong style="color:#1e3a8a;">' . $e($ref) . '</strong> has been
      <strong style="color:#dc2626;">automatically cancelled</strong> because payment was not completed
      within the 3-minute reservation window.
    </p>

    <!-- Booking Details -->
    <div style="background:#eff6ff;border:1px solid #dbeafe;border-radius:.85rem;overflow:hidden;margin-bottom:1.5rem;">
      <div style="background:#1e3a8a;padding:.75rem 1rem;">
        <span style="color:#fff;font-weight:800;font-size:.9rem;">📋 Booking Details</span>
      </div>
      <table style="width:100%;border-collapse:collapse;font-size:.9rem;">
        <tr>
          <td style="padding:.65rem 1rem;font-weight:700;color:#374151;border-bottom:1px solid #e5e7eb;width:45%;">Reference No.</td>
          <td style="padding:.65rem 1rem;font-family:monospace;font-weight:700;color:#1e3a8a;border-bottom:1px solid #e5e7eb;">' . $e($ref) . '</td>
        </tr>
        <tr style="background:#f8fafc;">
          <td style="padding:.65rem 1rem;font-weight:700;color:#374151;border-bottom:1px solid #e5e7eb;">Customer Name</td>
          <td style="padding:.65rem 1rem;border-bottom:1px solid #e5e7eb;">' . $e($name) . '</td>
        </tr>
        <tr>
          <td style="padding:.65rem 1rem;font-weight:700;color:#374151;border-bottom:1px solid #e5e7eb;">Email</td>
          <td style="padding:.65rem 1rem;border-bottom:1px solid #e5e7eb;">' . $e($email) . '</td>
        </tr>
        <tr style="background:#f8fafc;">
          <td style="padding:.65rem 1rem;font-weight:700;color:#374151;border-bottom:1px solid #e5e7eb;">Phone</td>
          <td style="padding:.65rem 1rem;border-bottom:1px solid #e5e7eb;">' . $e($phone) . '</td>
        </tr>
        <tr>
          <td style="padding:.65rem 1rem;font-weight:700;color:#374151;border-bottom:1px solid #e5e7eb;">Ticket Type</td>
          <td style="padding:.65rem 1rem;border-bottom:1px solid #e5e7eb;">' . $e($type) . '</td>
        </tr>
        <tr style="background:#f8fafc;">
          <td style="padding:.65rem 1rem;font-weight:700;color:#374151;border-bottom:1px solid #e5e7eb;">Quantity</td>
          <td style="padding:.65rem 1rem;border-bottom:1px solid #e5e7eb;">' . $e((string)$qty) . '</td>
        </tr>
        <tr>
          <td style="padding:.65rem 1rem;font-weight:700;color:#374151;border-bottom:1px solid #e5e7eb;">Visit Date</td>
          <td style="padding:.65rem 1rem;border-bottom:1px solid #e5e7eb;">' . $date . '</td>
        </tr>
        <tr style="background:#f8fafc;">
          <td style="padding:.65rem 1rem;font-weight:700;color:#374151;border-bottom:1px solid #e5e7eb;">Total Amount</td>
          <td style="padding:.65rem 1rem;font-weight:800;color:#1e3a8a;border-bottom:1px solid #e5e7eb;">&#8369;' . $e($amount) . '</td>
        </tr>
        <tr>
          <td style="padding:.65rem 1rem;font-weight:700;color:#374151;border-bottom:1px solid #e5e7eb;">Booked At</td>
          <td style="padding:.65rem 1rem;border-bottom:1px solid #e5e7eb;">' . $e($booked) . '</td>
        </tr>
        <tr style="background:#f8fafc;">
          <td style="padding:.65rem 1rem;font-weight:700;color:#dc2626;">Payment Deadline</td>
          <td style="padding:.65rem 1rem;color:#dc2626;font-weight:700;">' . $e($deadline) . '</td>
        </tr>
        ' . $ticketRows . '
      </table>
    </div>

    <!-- Action Buttons -->
    <div style="background:#f0fdf4;border:1px solid #86efac;border-radius:.85rem;padding:1.5rem;margin-bottom:1.5rem;text-align:center;">
      <p style="margin:0 0 1rem;font-weight:700;color:#14532d;font-size:.95rem;">
        Want to book again? Your visit date may still be available.
      </p>
      <a href="' . $continueUrl . '"
         style="display:inline-block;background:#fbbf24;color:#000000;padding:.75rem 2rem;border-radius:999px;font-weight:900;text-decoration:none;font-size:.95rem;margin-right:.5rem;box-shadow:0 8px 15px rgba(251,191,36,0.2);">
        🎟 Continue Booking
      </a>
    </div>

    <div style="background:#fef2f2;border:1px solid #fca5a5;border-radius:.85rem;padding:1.25rem;text-align:center;">
      <p style="margin:0 0 .75rem;color:#7f1d1d;font-size:.88rem;">
        If you no longer wish to book, you can dismiss this notice.
      </p>
      <a href="' . $cancelUrl . '"
         style="display:inline-block;background:#dc2626;color:#fff;padding:.65rem 1.5rem;border-radius:999px;font-weight:700;text-decoration:none;font-size:.88rem;">
        ✕ Cancel Booking
      </a>
    </div>

    <p style="margin-top:2rem;color:#9ca3af;font-size:.82rem;text-align:center;">
      Questions? Contact us at <a href="mailto:support@amusepark.com" style="color:#1e3a8a;">support@amusepark.com</a>
    </p>
  </div>

  <!-- Footer -->
  <div style="background:#0f0a1e;padding:1.25rem;text-align:center;">
    <p style="margin:0;color:#6b7280;font-size:.8rem;">&copy; ' . date('Y') . ' AmusePark Philippines. All rights reserved.</p>
  </div>
</div>
</body>
</html>';

    // Plain text fallback
    $text  = "Dear {$name},\n\n";
    $text .= "Your booking {$ref} has been automatically cancelled (payment not completed within 3 minutes).\n\n";
    $text .= "Booking Reference : {$ref}\n";
    $text .= "Customer Name     : {$name}\n";
    $text .= "Email             : {$email}\n";
    $text .= "Phone             : {$phone}\n";
    $text .= "Ticket Type       : {$type}\n";
    $text .= "Quantity          : {$qty}\n";
    $text .= "Visit Date        : {$date}\n";
    $text .= "Total Amount      : PHP {$amount}\n";
    $text .= "Booked At         : {$booked}\n";
    $text .= "Payment Deadline  : {$deadline}\n\n";
    foreach ($tickets as $t) {
        $text .= "Ticket: " . ($t['ticket_number'] ?? '') . "\n";
    }
    $text .= "\nContinue Booking : {$continueUrl}\n";
    $text .= "Cancel Booking   : {$cancelUrl}\n\n";
    $text .= "Thank you for choosing AmusePark.\n";

    $subject  = 'Booking Expired – Ref: ' . $ref . ' | AmusePark';
    $boundary = 'AP_' . md5(uniqid((string)mt_rand(), true));
    $headers  = "From: AmusePark <noreply@amusepark.com>\r\n";
    $headers .= "Reply-To: support@amusepark.com\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: multipart/alternative; boundary=\"{$boundary}\"\r\n";

    $msg  = "--{$boundary}\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\n" . $text . "\r\n";
    $msg .= "--{$boundary}\r\nContent-Type: text/html; charset=UTF-8\r\n\r\n" . $htmlBody . "\r\n";
    $msg .= "--{$boundary}--";

    $sent = @mail($to, $subject, $msg, $headers);

    if (!$sent) {
        $logDir = __DIR__ . '/../logs';
        if (!is_dir($logDir)) mkdir($logDir, 0755, true);
        file_put_contents(
            $logDir . '/email_failures.log',
            '[' . date('Y-m-d H:i:s') . '] FAILED ref=' . $ref . ' to=' . $to . "\n",
            FILE_APPEND | LOCK_EX
        );
    }

    return $sent;
}
