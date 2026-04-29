<?php
declare(strict_types=1);

/**
 * AmusePark Mailer — sends via Brevo (free email API, no 2FA needed)
 * Fallback to PHPMailer SMTP if Brevo key not set.
 */

// Load PHPMailer if available (installed via Composer)
$_phpmailerAutoload = __DIR__ . '/../vendor/autoload.php';
if (file_exists($_phpmailerAutoload)) {
    require_once $_phpmailerAutoload;
}

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

/**
 * Generate a QR code as base64 PNG using chillerlan/php-qrcode (local, no HTTP).
 * Falls back to external API if library not available.
 */
function _make_qr_base64(string $data, int $size = 200): string
{
    // Try local generation first (chillerlan/php-qrcode)
    if (class_exists(\chillerlan\QRCode\QRCode::class)) {
        try {
            $options = new \chillerlan\QRCode\QROptions([
                'outputType'   => \chillerlan\QRCode\QRCode::OUTPUT_IMAGE_PNG,
                'eccLevel'     => \chillerlan\QRCode\QRCode::ECC_H,
                'scale'        => 6,
                'imageBase64'  => false,
                'quietzoneSize'=> 2,
            ]);
            $png = (new \chillerlan\QRCode\QRCode($options))->render($data);
            return 'data:image/png;base64,' . base64_encode($png);
        } catch (\Throwable $ex) {}
    }

    // Fallback: fetch from external API
    $url = 'https://api.qrserver.com/v1/create-qr-code/?size=' . $size . 'x' . $size . '&data=' . urlencode($data);
    try {
        $ctx = stream_context_create(['http' => ['timeout' => 8]]);
        $raw = @file_get_contents($url, false, $ctx);
        if ($raw !== false) {
            return 'data:image/png;base64,' . base64_encode($raw);
        }
    } catch (\Throwable $ex) {}

    // Last resort: return the URL directly
    return $url;
}
function _mailer_send(string $to, string $subject, string $htmlBody, string $textBody): bool
{
    $logDir = __DIR__ . '/../logs';
    if (!is_dir($logDir)) @mkdir($logDir, 0755, true);

    $sent = false;
    $via  = 'mail()';

    // ── Option 1: Mailjet HTTP API (free 200/day, no 2FA needed) ─────────────
    if (defined('MAILJET_API_KEY') && MAILJET_API_KEY !== '' && MAILJET_API_KEY !== 'your_mailjet_api_key_here') {
        $via      = 'Mailjet';
        $fromName = defined('SMTP_FROM_NAME') ? SMTP_FROM_NAME : 'AmusePark';
        $fromAddr = defined('SMTP_FROM')      ? SMTP_FROM      : 'noreply@amusepark.com';

        $payload = json_encode([
            'Messages' => [[
                'From'     => ['Email' => $fromAddr, 'Name' => $fromName],
                'To'       => [['Email' => $to]],
                'Subject'  => $subject,
                'HTMLPart' => $htmlBody,
                'TextPart' => $textBody,
            ]]
        ]);

        $ch = curl_init('https://api.mailjet.com/v3.1/send');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_USERPWD        => MAILJET_API_KEY . ':' . MAILJET_SECRET_KEY,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT        => 15,
        ]);
        $response = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        $sent = ($httpCode >= 200 && $httpCode < 300);
        if (!$sent) {
            file_put_contents($logDir . '/email_failures.log',
                '[' . date('Y-m-d H:i:s') . '] MAILJET ERROR to=' . $to
                . ' http=' . $httpCode . ' resp=' . $response . ' curl=' . $curlErr . "\n",
                FILE_APPEND | LOCK_EX);
        }

    // ── Option 2: Brevo HTTP API ──────────────────────────────────────────────
    } elseif (defined('BREVO_API_KEY') && BREVO_API_KEY !== '' && BREVO_API_KEY !== 'your_brevo_api_key_here') {
        $via      = 'Brevo';
        $fromName = defined('SMTP_FROM_NAME') ? SMTP_FROM_NAME : 'AmusePark';
        $fromAddr = defined('SMTP_FROM')      ? SMTP_FROM      : 'noreply@amusepark.com';

        $payload = json_encode([
            'sender'      => ['name' => $fromName, 'email' => $fromAddr],
            'to'          => [['email' => $to]],
            'subject'     => $subject,
            'htmlContent' => $htmlBody,
            'textContent' => $textBody,
        ]);

        $ch = curl_init('https://api.brevo.com/v3/smtp/email');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => [
                'accept: application/json',
                'api-key: ' . BREVO_API_KEY,
                'content-type: application/json',
            ],
            CURLOPT_TIMEOUT        => 15,
        ]);
        $response = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        $sent = ($httpCode >= 200 && $httpCode < 300);
        if (!$sent) {
            file_put_contents($logDir . '/email_failures.log',
                '[' . date('Y-m-d H:i:s') . '] BREVO ERROR to=' . $to
                . ' http=' . $httpCode . ' resp=' . $response . ' curl=' . $curlErr . "\n",
                FILE_APPEND | LOCK_EX);
        }
        $via      = 'Brevo';
        $fromName = defined('SMTP_FROM_NAME') ? SMTP_FROM_NAME : 'AmusePark';
        $fromAddr = defined('SMTP_FROM')      ? SMTP_FROM      : 'noreply@amusepark.com';

        $payload = json_encode([
            'sender'      => ['name' => $fromName, 'email' => $fromAddr],
            'to'          => [['email' => $to]],
            'subject'     => $subject,
            'htmlContent' => $htmlBody,
            'textContent' => $textBody,
        ]);

        $ch = curl_init('https://api.brevo.com/v3/smtp/email');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => [
                'accept: application/json',
                'api-key: ' . BREVO_API_KEY,
                'content-type: application/json',
            ],
            CURLOPT_TIMEOUT        => 15,
        ]);
        $response = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        $sent = ($httpCode >= 200 && $httpCode < 300);
        if (!$sent) {
            file_put_contents($logDir . '/email_failures.log',
                '[' . date('Y-m-d H:i:s') . '] BREVO ERROR to=' . $to
                . ' http=' . $httpCode . ' resp=' . $response . ' curl=' . $curlErr . "\n",
                FILE_APPEND | LOCK_EX);
        }

    // ── Option 2: PHPMailer SMTP ──────────────────────────────────────────────
    } elseif (defined('SMTP_USER') && SMTP_USER !== '' && SMTP_USER !== 'your_gmail@gmail.com'
              && class_exists(PHPMailer::class)) {
        $via = 'SMTP';
        try {
            $mail = new PHPMailer(true);
            $mail->isSMTP();
            $mail->Host       = defined('SMTP_HOST') ? SMTP_HOST : 'smtp.gmail.com';
            $mail->SMTPAuth   = true;
            $mail->Username   = SMTP_USER;
            $mail->Password   = SMTP_PASS;
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port       = defined('SMTP_PORT') ? (int)SMTP_PORT : 587;
            $mail->setFrom(defined('SMTP_FROM') ? SMTP_FROM : SMTP_USER,
                           defined('SMTP_FROM_NAME') ? SMTP_FROM_NAME : 'AmusePark');
            $mail->addAddress($to);
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body    = $htmlBody;
            $mail->AltBody = $textBody;
            $mail->send();
            $sent = true;
        } catch (PHPMailerException $ex) {
            file_put_contents($logDir . '/email_failures.log',
                '[' . date('Y-m-d H:i:s') . '] SMTP ERROR to=' . $to . ' err=' . $ex->getMessage() . "\n",
                FILE_APPEND | LOCK_EX);
        }

    // ── Option 3: PHP mail() fallback ─────────────────────────────────────────
    } else {
        $boundary = 'AP_' . md5(uniqid((string)mt_rand(), true));
        $headers  = "From: AmusePark <noreply@amusepark.com>\r\nMIME-Version: 1.0\r\n"
                  . "Content-Type: multipart/alternative; boundary=\"{$boundary}\"\r\n";
        $msg      = "--{$boundary}\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\n{$textBody}\r\n"
                  . "--{$boundary}\r\nContent-Type: text/html; charset=UTF-8\r\n\r\n{$htmlBody}\r\n"
                  . "--{$boundary}--";
        $sent = @mail($to, $subject, $msg, $headers);
    }

    $logEntry = '[' . date('Y-m-d H:i:s') . '] ' . ($sent ? 'SENT' : 'FAILED')
              . ' via=' . $via . ' to=' . $to . ' subject=' . $subject . "\n";
    file_put_contents($logDir . '/email_log.txt', $logEntry, FILE_APPEND | LOCK_EX);

    if (!$sent) {
        $safeRef = preg_replace('/[^a-z0-9]/i', '_', substr($subject, 0, 40));
        file_put_contents($logDir . '/last_email_' . $safeRef . '.html', $htmlBody);
    }

    return $sent;
}

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
function send_cancellation_email(array $booking, array $tickets, PDO $pdo, string $reason = 'expired'): bool
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
    $continueUrl     = $base . '/tickets.php';
    $viewUrl         = $base . '/my-bookings.php';
    $supportEmail    = 'support@amusepark.com';
    $cancelEmailLink = 'mailto:' . $supportEmail . '?subject=' . rawurlencode('Cancellation Inquiry: ' . $ref) . '&body=' . rawurlencode("Hello AmusePark Team,\n\nI have questions regarding my cancelled booking (Ref: " . $ref . ").");

    $isCancelledByUser = $reason === 'cancelled';
    $headerIcon  = $isCancelledByUser ? '🚫' : '⏰';
    $headerTitle = $isCancelledByUser ? 'Booking Cancelled' : 'Booking Expired';
    $headerSub   = $isCancelledByUser
        ? 'Your booking of ticket is cancelled'
        : 'Your reservation was not completed in time';
    $bodyReason  = $isCancelledByUser
        ? 'Your booking <strong style="color:#1e3a8a;">' . htmlspecialchars($ref, ENT_QUOTES, 'UTF-8') . '</strong> has been <strong style="color:#dc2626;">cancelled</strong>. As requested, your ticket is now void.'
        : 'Your booking <strong style="color:#1e3a8a;">' . htmlspecialchars($ref, ENT_QUOTES, 'UTF-8') . '</strong> has been <strong style="color:#dc2626;">automatically cancelled</strong> because payment was not completed within the 3-minute reservation window.';

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
    <div style="font-size:2.5rem;margin-bottom:.5rem;">' . $headerIcon . '</div>
    <h1 style="margin:0;color:#fff;font-size:1.6rem;font-weight:900;letter-spacing:-.02em;">' . $headerTitle . '</h1>
    <p style="margin:.5rem 0 0;color:rgba(255,255,255,0.7);font-size:.95rem;">' . $headerSub . '</p>
  </div>

  <!-- Body -->
  <div style="padding:2rem;">
    <p style="font-size:1rem;margin:0 0 1.25rem;">Dear <strong>' . $e($name) . '</strong>,</p>
    <p style="color:#374151;margin:0 0 1.5rem;line-height:1.6;">' . $bodyReason . '</p>

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
        Need help or wish to discuss this cancellation?
      </p>
      <div style="display:flex;justify-content:center;gap:.75rem;flex-wrap:wrap;">
        <a href="' . $viewUrl . '"
           style="display:inline-block;background:#64748b;color:#fff;padding:.65rem 1.5rem;border-radius:999px;font-weight:700;text-decoration:none;font-size:.88rem;">
          📅 View My Bookings
        </a>
        <a href="' . $cancelEmailLink . '"
           style="display:inline-block;background:#dc2626;color:#fff;padding:.65rem 1.5rem;border-radius:999px;font-weight:700;text-decoration:none;font-size:.88rem;">
          ✉ Email Support
        </a>
      </div>
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
    $cancelReason = $isCancelledByUser
        ? "Your booking {$ref} has been cancelled as requested."
        : "Your booking {$ref} has been automatically cancelled (payment not completed within 3 minutes).";
    $text  = "Dear {$name},\n\n";
    $text .= $cancelReason . "\n\n";
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
    $text .= "View Bookings    : {$viewUrl}\n";
    $text .= "Email Support    : {$supportEmail}\n\n";
    $text .= "Thank you for choosing AmusePark.\n";

    $subject  = ($isCancelledByUser ? 'Booking Cancelled' : 'Booking Expired') . ' – Ref: ' . $ref . ' | AmusePark';
    $sent = _mailer_send($to, $subject, $htmlBody, $text);
    if (!$sent) {
        $logDir = __DIR__ . '/../logs';
        if (!is_dir($logDir)) @mkdir($logDir, 0755, true);
        file_put_contents($logDir . '/last_email_' . preg_replace('/[^a-z0-9]/i', '_', $ref) . '.html', $htmlBody);
    }
    return $sent;
}

/**
 * Send email verification code to the user.
 */
function send_verification_email(string $to, string $name, string $code): bool
{
    $base    = _mailer_base_url();
    $subject = 'Verify Your Email – AmusePark';
    $e       = fn(string $v) => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');

    $htmlBody = '<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;padding:0;background:#f1f5f9;font-family:Segoe UI,system-ui,sans-serif;color:#111827;">
<div style="max-width:600px;margin:2rem auto;background:#fff;border-radius:1.25rem;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,.1);">
  <div style="background:linear-gradient(135deg,#0f172a 0%,#1e3a8a 100%);padding:2.5rem 2rem;text-align:center;">
    <div style="font-size:2.5rem;margin-bottom:.5rem;">✉️</div>
    <h1 style="margin:0;color:#fff;font-size:1.6rem;font-weight:900;">Verify Your Email</h1>
    <p style="margin:.5rem 0 0;color:rgba(255,255,255,0.7);font-size:.95rem;">Enter this code in AmusePark to verify your email</p>
  </div>
  <div style="padding:2.5rem;text-align:center;">
    <p style="font-size:1rem;margin:0 0 1.5rem;">Hi <strong>' . $e($name) . '</strong>, use the code below to verify your email address.</p>
    <div style="background:#eff6ff;border:2px dashed #1e3a8a;border-radius:1rem;padding:2rem;margin-bottom:1.5rem;display:inline-block;min-width:260px;">
      <div style="font-size:.8rem;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.1em;margin-bottom:.5rem;">Your Verification Code</div>
      <div style="font-size:3rem;font-weight:900;color:#1e3a8a;letter-spacing:.3em;font-family:monospace;">' . $e($code) . '</div>
      <div style="font-size:.8rem;color:#94a3b8;margin-top:.75rem;">Expires in 15 minutes</div>
    </div>
    <p style="color:#64748b;font-size:.88rem;">If you did not request this, you can safely ignore this email.</p>
  </div>
  <div style="background:#0f172a;padding:1.25rem;text-align:center;">
    <p style="margin:0;color:#6b7280;font-size:.8rem;">&copy; ' . date('Y') . ' AmusePark Philippines. All rights reserved.</p>
  </div>
</div>
</body></html>';

    $text = "Hi {$name},\n\nYour AmusePark email verification code is: {$code}\n\nThis code expires in 15 minutes.\n\nIf you did not request this, ignore this email.\n";

    return _mailer_send($to, $subject, $htmlBody, $text);
}

/**
 * Send abandoned payment reminder email.
 * Generates a one-time resume token (valid 3 min) embedded in the link.
 * When clicked: if token valid → restore session → step 2. If expired → show expired page.
 */
function send_abandoned_payment_email(array $booking): bool
{
    $to     = (string)($booking['customer_email'] ?? '');
    $name   = (string)($booking['customer_name'] ?? '');
    $ref    = (string)($booking['booking_reference'] ?? '');
    $type   = (string)($booking['ticket_type_name'] ?? '');
    $qty    = (int)($booking['quantity'] ?? 1);
    $amount = number_format((float)($booking['total_amount'] ?? 0), 2);
    $date   = (string)($booking['visit_date'] ?? '');
    $bookingId = (int)($booking['id'] ?? 0);

    if ($to === '' || $bookingId === 0) return false;

    // Generate a one-time resume token valid for 3 minutes
    $token   = bin2hex(random_bytes(32));
    $expires = date('Y-m-d H:i:s', time() + 180); // 3 minutes
    try {
        $pdo = db();
        $pdo->prepare('UPDATE bookings SET resume_token = ?, resume_expires = ? WHERE id = ?')
            ->execute([$token, $expires, $bookingId]);
    } catch (\Throwable $e) {}

    $base        = _mailer_base_url();
    $paymentUrl  = $base . '/tickets.php?resume=' . urlencode($token);
    $bookingsUrl = $base . '/my-bookings.php';
    $e           = fn(string $v) => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');

    $htmlBody = '<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;padding:0;background:#f1f5f9;font-family:Segoe UI,system-ui,sans-serif;color:#111827;">
<div style="max-width:600px;margin:2rem auto;background:#fff;border-radius:1.25rem;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,.1);">

  <!-- Header -->
  <div style="background:linear-gradient(135deg,#0f172a 0%,#1e3a8a 100%);padding:2.5rem 2rem;text-align:center;">
    <div style="font-size:2.5rem;margin-bottom:.75rem;">⏳</div>
    <h1 style="margin:0;color:#fff;font-size:1.6rem;font-weight:900;">Your payment is incomplete</h1>
    <p style="margin:.6rem 0 0;color:rgba(255,255,255,0.75);font-size:.95rem;">Complete your payment to confirm your booking</p>
  </div>

  <!-- Urgency banner -->
  <div style="background:#fef2f2;border-bottom:2px solid #fca5a5;padding:1rem 2rem;text-align:center;">
    <div style="font-weight:900;color:#dc2626;font-size:1rem;">🚨 Act fast — this link expires in 3 minutes</div>
    <div style="font-size:.82rem;color:#b91c1c;margin-top:.25rem;">After 3 minutes the link will no longer work</div>
  </div>

  <!-- Body -->
  <div style="padding:2rem 2.5rem;">
    <p style="font-size:1rem;margin:0 0 1rem;">Hi <strong>' . $e($name) . '</strong>,</p>
    <p style="color:#374151;margin:0 0 1.75rem;line-height:1.7;">
      You left the payment page before completing your QR Ph payment for booking
      <strong style="color:#1e3a8a;">' . $e($ref) . '</strong>.
      Click the button below within <strong style="color:#dc2626;">3 minutes</strong> to resume your payment — after that the link will expire.
    </p>

    <!-- Booking summary -->
    <div style="background:#eff6ff;border:1px solid #dbeafe;border-radius:.85rem;overflow:hidden;margin-bottom:1.75rem;">
      <div style="background:#1e3a8a;padding:.75rem 1.25rem;">
        <span style="color:#fff;font-weight:800;font-size:.88rem;text-transform:uppercase;letter-spacing:.05em;">📋 Booking Details</span>
      </div>
      <table style="width:100%;border-collapse:collapse;font-size:.9rem;">
        <tr>
          <td style="padding:.7rem 1.25rem;font-weight:700;color:#374151;border-bottom:1px solid #e5e7eb;width:40%;">Reference</td>
          <td style="padding:.7rem 1.25rem;font-family:monospace;font-weight:800;color:#1e3a8a;border-bottom:1px solid #e5e7eb;">' . $e($ref) . '</td>
        </tr>
        <tr style="background:#f8fafc;">
          <td style="padding:.7rem 1.25rem;font-weight:700;color:#374151;border-bottom:1px solid #e5e7eb;">Ticket</td>
          <td style="padding:.7rem 1.25rem;border-bottom:1px solid #e5e7eb;">' . $e($type) . ' &times; ' . $qty . '</td>
        </tr>
        <tr>
          <td style="padding:.7rem 1.25rem;font-weight:700;color:#374151;border-bottom:1px solid #e5e7eb;">Visit Date</td>
          <td style="padding:.7rem 1.25rem;border-bottom:1px solid #e5e7eb;">' . $e($date) . '</td>
        </tr>
        <tr style="background:#f8fafc;">
          <td style="padding:.7rem 1.25rem;font-weight:700;color:#374151;">Total Amount</td>
          <td style="padding:.7rem 1.25rem;font-weight:900;color:#1e3a8a;font-size:1.05rem;">&#8369;' . $e($amount) . '</td>
        </tr>
      </table>
    </div>

    <!-- CTA -->
    <div style="background:#fefce8;border:2px solid #fde68a;border-radius:.85rem;padding:1.5rem;text-align:center;margin-bottom:1.5rem;">
      <p style="margin:0 0 .4rem;font-weight:800;color:#78350f;font-size:1rem;">⚡ This link expires in 3 minutes</p>
      <p style="margin:0 0 1.25rem;color:#92400e;font-size:.85rem;">Click immediately to resume your payment</p>
      <a href="' . $paymentUrl . '"
         style="display:inline-block;background:#1e3a8a;color:#fff;padding:.9rem 2.5rem;border-radius:999px;font-weight:900;text-decoration:none;font-size:1rem;box-shadow:0 8px 20px rgba(30,58,138,0.25);">
        💳 Complete My Payment
      </a>
    </div>

    <div style="text-align:center;margin-bottom:1rem;">
      <a href="' . $bookingsUrl . '" style="color:#64748b;font-size:.85rem;font-weight:600;text-decoration:none;">📅 View My Bookings</a>
    </div>
    <p style="color:#94a3b8;font-size:.8rem;text-align:center;margin:0;">
      Questions? <a href="mailto:support@amusepark.com" style="color:#1e3a8a;font-weight:600;">support@amusepark.com</a>
    </p>
  </div>

  <div style="background:#0f172a;padding:1.25rem;text-align:center;">
    <p style="margin:0;color:#6b7280;font-size:.8rem;">&copy; ' . date('Y') . ' AmusePark Philippines. All rights reserved.</p>
  </div>
</div>
</body></html>';

    $text  = "Hi {$name},\n\n";
    $text .= "You left the payment page. Click the link below within 3 MINUTES to resume — after that it expires.\n\n";
    $text .= "Reference : {$ref}\nTicket    : {$type} x{$qty}\nVisit Date: {$date}\nTotal     : PHP {$amount}\n\n";
    $text .= "Complete payment (expires in 3 min): {$paymentUrl}\n\n";
    $text .= "Questions? support@amusepark.com\n";

    $subject = '⏳ Complete your AmusePark payment now – ' . $ref . ' (link expires in 3 min)';
    return _mailer_send($to, $subject, $htmlBody, $text);
}
{
    $to     = (string)($booking['customer_email'] ?? '');
    $name   = (string)($booking['customer_name'] ?? '');
    $ref    = (string)($booking['booking_reference'] ?? '');
    $type   = (string)($booking['ticket_type_name'] ?? '');
    $qty    = (int)($booking['quantity'] ?? 1);
    $amount = number_format((float)($booking['total_amount'] ?? 0), 2);
    $date   = (string)($booking['visit_date'] ?? '');

    if ($to === '') return false;

    $base        = _mailer_base_url();
    $paymentUrl  = $base . '/tickets.php';   // start new booking (old one expired)
    $bookingsUrl = $base . '/my-bookings.php';
    $e           = fn(string $v) => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');

    $htmlBody = '<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;padding:0;background:#f1f5f9;font-family:Segoe UI,system-ui,sans-serif;color:#111827;">
<div style="max-width:600px;margin:2rem auto;background:#fff;border-radius:1.25rem;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,.1);">

  <!-- Header -->
  <div style="background:linear-gradient(135deg,#0f172a 0%,#1e3a8a 100%);padding:2.5rem 2rem;text-align:center;">
    <div style="font-size:2.5rem;margin-bottom:.75rem;">⏳</div>
    <h1 style="margin:0;color:#fff;font-size:1.6rem;font-weight:900;letter-spacing:-.02em;">Your payment is incomplete</h1>
    <p style="margin:.6rem 0 0;color:rgba(255,255,255,0.75);font-size:.95rem;">Complete your payment to confirm your booking</p>
  </div>

  <!-- Urgency banner -->
  <div style="background:#fef2f2;border-bottom:2px solid #fca5a5;padding:1rem 2rem;text-align:center;display:flex;align-items:center;justify-content:center;gap:.75rem;">
    <span style="font-size:1.4rem;">🚨</span>
    <div>
      <div style="font-weight:900;color:#dc2626;font-size:1rem;">Booking expired after 3 minutes</div>
      <div style="font-size:.82rem;color:#b91c1c;margin-top:.2rem;">Your reservation was automatically cancelled — but you can book again now</div>
    </div>
  </div>

  <!-- Body -->
  <div style="padding:2rem 2.5rem;">
    <p style="font-size:1rem;margin:0 0 1rem;">Hi <strong>' . $e($name) . '</strong>,</p>
    <p style="color:#374151;margin:0 0 1.75rem;line-height:1.7;">
      You left the payment page and your 3-minute reservation window expired, so booking
      <strong style="color:#1e3a8a;">' . $e($ref) . '</strong> was automatically cancelled.
      Don\'t worry — you can start a new booking right now and your preferred date may still be available.
    </p>

    <!-- Booking summary -->
    <div style="background:#eff6ff;border:1px solid #dbeafe;border-radius:.85rem;overflow:hidden;margin-bottom:1.75rem;">
      <div style="background:#1e3a8a;padding:.75rem 1.25rem;">
        <span style="color:#fff;font-weight:800;font-size:.88rem;text-transform:uppercase;letter-spacing:.05em;">📋 Booking Details</span>
      </div>
      <table style="width:100%;border-collapse:collapse;font-size:.9rem;">
        <tr>
          <td style="padding:.7rem 1.25rem;font-weight:700;color:#374151;border-bottom:1px solid #e5e7eb;width:40%;">Reference</td>
          <td style="padding:.7rem 1.25rem;font-family:monospace;font-weight:800;color:#1e3a8a;border-bottom:1px solid #e5e7eb;">' . $e($ref) . '</td>
        </tr>
        <tr style="background:#f8fafc;">
          <td style="padding:.7rem 1.25rem;font-weight:700;color:#374151;border-bottom:1px solid #e5e7eb;">Ticket</td>
          <td style="padding:.7rem 1.25rem;border-bottom:1px solid #e5e7eb;">' . $e($type) . ' &times; ' . $qty . '</td>
        </tr>
        <tr>
          <td style="padding:.7rem 1.25rem;font-weight:700;color:#374151;border-bottom:1px solid #e5e7eb;">Visit Date</td>
          <td style="padding:.7rem 1.25rem;border-bottom:1px solid #e5e7eb;">' . $e($date) . '</td>
        </tr>
        <tr style="background:#f8fafc;">
          <td style="padding:.7rem 1.25rem;font-weight:700;color:#374151;border-bottom:1px solid #e5e7eb;">Total Amount</td>
          <td style="padding:.7rem 1.25rem;font-weight:900;color:#1e3a8a;font-size:1.05rem;">&#8369;' . $e($amount) . '</td>
        </tr>
        <tr>
          <td style="padding:.7rem 1.25rem;font-weight:700;color:#dc2626;">Status</td>
          <td style="padding:.7rem 1.25rem;font-weight:800;color:#dc2626;">❌ Cancelled — payment not completed within 3 minutes</td>
        </tr>
      </table>
    </div>

    <!-- CTA -->
    <div style="background:#fefce8;border:1px solid #fde68a;border-radius:.85rem;padding:1.5rem;text-align:center;margin-bottom:1.5rem;">
      <p style="margin:0 0 .5rem;font-weight:800;color:#78350f;font-size:1rem;">
        Want to try again?
      </p>
      <p style="margin:0 0 1.25rem;color:#92400e;font-size:.88rem;">
        Start a new booking — you have <strong>3 minutes</strong> to complete payment once you begin.
      </p>
      <a href="' . $paymentUrl . '"
         style="display:inline-block;background:#1e3a8a;color:#fff;padding:.9rem 2.5rem;border-radius:999px;font-weight:900;text-decoration:none;font-size:1rem;box-shadow:0 8px 20px rgba(30,58,138,0.25);">
        💳 Book Again &amp; Pay Now
      </a>
    </div>

    <div style="text-align:center;margin-bottom:1rem;">
      <a href="' . $bookingsUrl . '"
         style="color:#64748b;font-size:.85rem;font-weight:600;text-decoration:none;">
        📅 View My Bookings
      </a>
    </div>

    <p style="color:#94a3b8;font-size:.8rem;text-align:center;margin:0;">
      Questions? <a href="mailto:support@amusepark.com" style="color:#1e3a8a;font-weight:600;">support@amusepark.com</a>
    </p>
  </div>

  <!-- Footer -->
  <div style="background:#0f172a;padding:1.25rem;text-align:center;">
    <p style="margin:0;color:#6b7280;font-size:.8rem;">&copy; ' . date('Y') . ' AmusePark Philippines. All rights reserved.</p>
  </div>
</div>
</body></html>';

    $text  = "Hi {$name},\n\n";
    $text .= "Your booking {$ref} was automatically cancelled because payment was not completed within 3 minutes.\n\n";
    $text .= "Booking Details:\n";
    $text .= "  Reference : {$ref}\n";
    $text .= "  Ticket    : {$type} x{$qty}\n";
    $text .= "  Visit Date: {$date}\n";
    $text .= "  Total     : PHP {$amount}\n";
    $text .= "  Status    : Cancelled (3-minute payment window expired)\n\n";
    $text .= "Book again (you have 3 mins to pay once you start): {$paymentUrl}\n";
    $text .= "View bookings: {$bookingsUrl}\n\n";
    $text .= "Questions? support@amusepark.com\n";

    $subject = '⏳ Your AmusePark booking expired – ' . $ref . ' | Book again now';
    return _mailer_send($to, $subject, $htmlBody, $text);
}
{
    $to     = (string)($booking['customer_email'] ?? '');
    $name   = (string)($booking['customer_name'] ?? '');
    $ref    = (string)($booking['booking_reference'] ?? '');
    $type   = (string)($booking['ticket_type_name'] ?? '');
    $qty    = (int)($booking['quantity'] ?? 1);
    $amount = number_format((float)($booking['total_amount'] ?? 0), 2);
    $date   = (string)($booking['visit_date'] ?? '');

    if ($to === '') return false;

    $base        = _mailer_base_url();
    $paymentUrl  = $base . '/tickets.php?step=2';   // resumes at payment step
    $bookingsUrl = $base . '/my-bookings.php';
    $e           = fn(string $v) => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');

    $htmlBody = '<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;padding:0;background:#f1f5f9;font-family:Segoe UI,system-ui,sans-serif;color:#111827;">
<div style="max-width:600px;margin:2rem auto;background:#fff;border-radius:1.25rem;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,.1);">

  <!-- Header -->
  <div style="background:linear-gradient(135deg,#0f172a 0%,#1e3a8a 100%);padding:2.5rem 2rem;text-align:center;">
    <div style="font-size:2.5rem;margin-bottom:.75rem;">⏳</div>
    <h1 style="margin:0;color:#fff;font-size:1.6rem;font-weight:900;letter-spacing:-.02em;">Your payment is incomplete</h1>
    <p style="margin:.6rem 0 0;color:rgba(255,255,255,0.75);font-size:.95rem;">Complete your payment to confirm your booking</p>
  </div>

  <!-- Body -->
  <div style="padding:2rem 2.5rem;">
    <p style="font-size:1rem;margin:0 0 1rem;">Hi <strong>' . $e($name) . '</strong>,</p>
    <p style="color:#374151;margin:0 0 1.75rem;line-height:1.7;">
      You were almost there! You left the payment page before completing your QR Ph payment for booking
      <strong style="color:#1e3a8a;">' . $e($ref) . '</strong>.
      Your booking has been cancelled, but you can start a new one anytime — your preferred date may still be available.
    </p>

    <!-- Booking summary -->
    <div style="background:#eff6ff;border:1px solid #dbeafe;border-radius:.85rem;overflow:hidden;margin-bottom:1.75rem;">
      <div style="background:#1e3a8a;padding:.75rem 1.25rem;">
        <span style="color:#fff;font-weight:800;font-size:.88rem;text-transform:uppercase;letter-spacing:.05em;">📋 Booking Details</span>
      </div>
      <table style="width:100%;border-collapse:collapse;font-size:.9rem;">
        <tr>
          <td style="padding:.7rem 1.25rem;font-weight:700;color:#374151;border-bottom:1px solid #e5e7eb;width:40%;">Reference</td>
          <td style="padding:.7rem 1.25rem;font-family:monospace;font-weight:800;color:#1e3a8a;border-bottom:1px solid #e5e7eb;">' . $e($ref) . '</td>
        </tr>
        <tr style="background:#f8fafc;">
          <td style="padding:.7rem 1.25rem;font-weight:700;color:#374151;border-bottom:1px solid #e5e7eb;">Ticket</td>
          <td style="padding:.7rem 1.25rem;border-bottom:1px solid #e5e7eb;">' . $e($type) . ' &times; ' . $qty . '</td>
        </tr>
        <tr>
          <td style="padding:.7rem 1.25rem;font-weight:700;color:#374151;border-bottom:1px solid #e5e7eb;">Visit Date</td>
          <td style="padding:.7rem 1.25rem;border-bottom:1px solid #e5e7eb;">' . $e($date) . '</td>
        </tr>
        <tr style="background:#f8fafc;">
          <td style="padding:.7rem 1.25rem;font-weight:700;color:#374151;">Total Amount</td>
          <td style="padding:.7rem 1.25rem;font-weight:900;color:#1e3a8a;font-size:1.05rem;">&#8369;' . $e($amount) . '</td>
        </tr>
      </table>
    </div>

    <!-- CTA -->
    <div style="background:#fefce8;border:1px solid #fde68a;border-radius:.85rem;padding:1.5rem;text-align:center;margin-bottom:1.5rem;">
      <p style="margin:0 0 1rem;font-weight:700;color:#78350f;font-size:.95rem;">
        Ready to complete your booking? Start a new one now.
      </p>
      <a href="' . $paymentUrl . '"
         style="display:inline-block;background:#1e3a8a;color:#fff;padding:.9rem 2.5rem;border-radius:999px;font-weight:900;text-decoration:none;font-size:1rem;box-shadow:0 8px 20px rgba(30,58,138,0.25);letter-spacing:.01em;">
        💳 Continue to Payment
      </a>
    </div>

    <div style="text-align:center;margin-bottom:1rem;">
      <a href="' . $bookingsUrl . '"
         style="color:#64748b;font-size:.85rem;font-weight:600;text-decoration:none;">
        📅 View My Bookings
      </a>
    </div>

    <p style="color:#94a3b8;font-size:.8rem;text-align:center;margin:0;">
      Questions? <a href="mailto:support@amusepark.com" style="color:#1e3a8a;font-weight:600;">support@amusepark.com</a>
    </p>
  </div>

  <!-- Footer -->
  <div style="background:#0f172a;padding:1.25rem;text-align:center;">
    <p style="margin:0;color:#6b7280;font-size:.8rem;">&copy; ' . date('Y') . ' AmusePark Philippines. All rights reserved.</p>
  </div>
</div>
</body></html>';

    $text  = "Hi {$name},\n\n";
    $text .= "You left the payment page before completing your booking {$ref}.\n\n";
    $text .= "Booking Details:\n";
    $text .= "  Reference : {$ref}\n";
    $text .= "  Ticket    : {$type} x{$qty}\n";
    $text .= "  Visit Date: {$date}\n";
    $text .= "  Total     : PHP {$amount}\n\n";
    $text .= "Continue to payment: {$paymentUrl}\n";
    $text .= "View bookings: {$bookingsUrl}\n\n";
    $text .= "Questions? support@amusepark.com\n";

    $subject = '⏳ Complete your AmusePark payment – ' . $ref;
    return _mailer_send($to, $subject, $htmlBody, $text);
}

/**
 * Send booking confirmation email with QR code ticket(s).
 */
function send_booking_confirmation_email(array $booking, array $ticketNumbers, array $rideNames = []): bool
{
    $to     = (string)($booking['customer_email'] ?? '');
    $name   = (string)($booking['customer_name'] ?? '');
    $ref    = (string)($booking['booking_reference'] ?? '');
    $type   = (string)($booking['ticket_type_name'] ?? '');
    $qty    = (int)($booking['quantity'] ?? 1);
    $amount = number_format((float)($booking['total_amount'] ?? 0), 2);
    $date   = (string)($booking['visit_date'] ?? '');
    $qrData = (string)($booking['qr_code_data'] ?? $ref);
    $paidAt = (string)($booking['updated_at'] ?? date('Y-m-d H:i:s'));

    if ($to === '') return false;

    $base        = _mailer_base_url();
    $bookingsUrl = $base . '/my-bookings.php';
    $e           = fn(string $v) => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');

    // QR code — generate locally as base64 so Gmail shows it without "load images" prompt
    $qrImgSrc = _make_qr_base64($qrData, 220);

    // Ticket rows HTML
    $ticketHtml = '';
    foreach ($ticketNumbers as $tn) {
        $tnImgSrc   = _make_qr_base64((string)$tn, 120);
        $ticketHtml .= '
        <tr style="background:#f8fafc;">
          <td style="padding:.6rem 1rem;font-family:monospace;font-weight:800;color:#1e3a8a;border-bottom:1px solid #e5e7eb;">
            🎫 ' . $e((string)$tn) . '
          </td>
          <td style="padding:.6rem 1rem;border-bottom:1px solid #e5e7eb;text-align:right;">
            <img src="' . $tnImgSrc . '" width="60" height="60" alt="QR" style="border-radius:6px;vertical-align:middle;" />
          </td>
        </tr>';
    }
    if ($ticketHtml === '') {
        $ticketHtml = '<tr><td colspan="2" style="padding:.6rem 1rem;color:#9ca3af;font-style:italic;">No individual tickets issued.</td></tr>';
    }

    // Rides HTML
    $ridesHtml = '';
    if (!empty($rideNames)) {
        $ridesHtml = '<div style="margin-bottom:1.5rem;">
          <div style="font-weight:800;font-size:.85rem;color:#64748b;text-transform:uppercase;letter-spacing:.05em;margin-bottom:.6rem;">🎢 Selected Rides</div>
          <div style="display:flex;flex-wrap:wrap;gap:.4rem;">';
        foreach ($rideNames as $rn) {
            $ridesHtml .= '<span style="background:#eff6ff;color:#1e3a8a;border:1px solid #dbeafe;border-radius:999px;padding:.25rem .85rem;font-size:.82rem;font-weight:700;">' . $e((string)$rn) . '</span>';
        }
        $ridesHtml .= '</div></div>';
    }

    $htmlBody = '<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;padding:0;background:#f1f5f9;font-family:Segoe UI,system-ui,sans-serif;color:#111827;">
<div style="max-width:600px;margin:2rem auto;background:#fff;border-radius:1.25rem;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,.1);">

  <!-- Header -->
  <div style="background:linear-gradient(135deg,#0f172a 0%,#1e3a8a 100%);padding:2.5rem 2rem;text-align:center;">
    <div style="font-size:3rem;margin-bottom:.75rem;">🎉</div>
    <h1 style="margin:0;color:#fff;font-size:1.75rem;font-weight:900;letter-spacing:-.02em;">Booking Confirmed!</h1>
    <p style="margin:.6rem 0 0;color:rgba(255,255,255,0.8);font-size:.95rem;">Your AmusePark tickets are ready</p>
    <div style="margin-top:1rem;display:inline-block;background:rgba(251,191,36,0.2);border:1px solid rgba(251,191,36,0.4);border-radius:999px;padding:.35rem 1.25rem;font-family:monospace;font-weight:900;color:#fbbf24;font-size:1rem;letter-spacing:.05em;">' . $e($ref) . '</div>
  </div>

  <!-- Body -->
  <div style="padding:2rem 2.5rem;">
    <p style="font-size:1rem;margin:0 0 1.25rem;">Hi <strong>' . $e($name) . '</strong> 👋</p>
    <p style="color:#374151;margin:0 0 1.75rem;line-height:1.7;">
      Your payment was successful and your booking is confirmed. Show the QR code below at the park entrance.
    </p>

    <!-- Entry QR Code -->
    <div style="text-align:center;margin-bottom:1.75rem;">
      <div style="font-weight:800;font-size:.85rem;color:#64748b;text-transform:uppercase;letter-spacing:.05em;margin-bottom:.75rem;">🎟 Your Entry QR Code</div>
      <div style="display:inline-block;border:4px solid #1e3a8a;border-radius:1rem;padding:1rem;background:#fff;box-shadow:0 8px 20px rgba(30,58,138,0.12);">
        <img src="' . $qrImgSrc . '" width="200" height="200" alt="Entry QR Code" style="display:block;border-radius:.5rem;" />
      </div>
      <div style="margin-top:.6rem;font-size:.8rem;color:#94a3b8;">Present this at the park entrance</div>
    </div>

    <!-- Booking details -->
    <div style="background:#eff6ff;border:1px solid #dbeafe;border-radius:.85rem;overflow:hidden;margin-bottom:1.5rem;">
      <div style="background:#1e3a8a;padding:.75rem 1.25rem;">
        <span style="color:#fff;font-weight:800;font-size:.88rem;text-transform:uppercase;letter-spacing:.05em;">📋 Booking Details</span>
      </div>
      <table style="width:100%;border-collapse:collapse;font-size:.9rem;">
        <tr>
          <td style="padding:.65rem 1.25rem;font-weight:700;color:#374151;border-bottom:1px solid #e5e7eb;width:40%;">Reference</td>
          <td style="padding:.65rem 1.25rem;font-family:monospace;font-weight:800;color:#1e3a8a;border-bottom:1px solid #e5e7eb;">' . $e($ref) . '</td>
        </tr>
        <tr style="background:#f8fafc;">
          <td style="padding:.65rem 1.25rem;font-weight:700;color:#374151;border-bottom:1px solid #e5e7eb;">Customer</td>
          <td style="padding:.65rem 1.25rem;border-bottom:1px solid #e5e7eb;">' . $e($name) . '</td>
        </tr>
        <tr>
          <td style="padding:.65rem 1.25rem;font-weight:700;color:#374151;border-bottom:1px solid #e5e7eb;">Ticket</td>
          <td style="padding:.65rem 1.25rem;border-bottom:1px solid #e5e7eb;">' . $e($type) . ' &times; ' . $qty . '</td>
        </tr>
        <tr style="background:#f8fafc;">
          <td style="padding:.65rem 1.25rem;font-weight:700;color:#374151;border-bottom:1px solid #e5e7eb;">Visit Date</td>
          <td style="padding:.65rem 1.25rem;border-bottom:1px solid #e5e7eb;">' . $e($date) . '</td>
        </tr>
        <tr>
          <td style="padding:.65rem 1.25rem;font-weight:700;color:#374151;border-bottom:1px solid #e5e7eb;">Total Paid</td>
          <td style="padding:.65rem 1.25rem;font-weight:900;color:#16a34a;font-size:1.05rem;">&#8369;' . $e($amount) . '</td>
        </tr>
        <tr style="background:#f8fafc;">
          <td style="padding:.65rem 1.25rem;font-weight:700;color:#374151;">Payment Time</td>
          <td style="padding:.65rem 1.25rem;">' . $e($paidAt) . '</td>
        </tr>
      </table>
    </div>

    ' . $ridesHtml . '

    <!-- Individual tickets -->
    <div style="margin-bottom:1.5rem;">
      <div style="font-weight:800;font-size:.85rem;color:#64748b;text-transform:uppercase;letter-spacing:.05em;margin-bottom:.6rem;">🎫 Your Tickets</div>
      <table style="width:100%;border-collapse:collapse;font-size:.9rem;border:1px solid #e5e7eb;border-radius:.75rem;overflow:hidden;">
        ' . $ticketHtml . '
      </table>
    </div>

    <!-- View bookings -->
    <div style="text-align:center;margin-bottom:1.5rem;">
      <a href="' . $bookingsUrl . '"
         style="display:inline-block;background:#1e3a8a;color:#fff;padding:.85rem 2.5rem;border-radius:999px;font-weight:900;text-decoration:none;font-size:.95rem;box-shadow:0 8px 20px rgba(30,58,138,0.2);">
        📅 View My Bookings
      </a>
    </div>

    <div style="background:#f0fdf4;border:1px solid #86efac;border-radius:.85rem;padding:1.25rem;text-align:center;margin-bottom:1rem;">
      <p style="margin:0;font-weight:700;color:#166534;font-size:.9rem;">
        🎢 See you at AmusePark on <strong>' . $e($date) . '</strong>!
      </p>
    </div>

    <p style="color:#94a3b8;font-size:.8rem;text-align:center;margin:0;">
      Questions? <a href="mailto:support@amusepark.com" style="color:#1e3a8a;font-weight:600;">support@amusepark.com</a>
    </p>
  </div>

  <div style="background:#0f172a;padding:1.25rem;text-align:center;">
    <p style="margin:0;color:#6b7280;font-size:.8rem;">&copy; ' . date('Y') . ' AmusePark Philippines. All rights reserved.</p>
  </div>
</div>
</body></html>';

    $text  = "Hi {$name},\n\nYour booking is CONFIRMED! 🎉\n\n";
    $text .= "Reference : {$ref}\nTicket    : {$type} x{$qty}\nVisit Date: {$date}\nTotal Paid: PHP {$amount}\nPaid At   : {$paidAt}\n\n";
    if (!empty($rideNames)) {
        $text .= "Selected Rides: " . implode(', ', $rideNames) . "\n\n";
    }
    $text .= "Your Tickets:\n";
    foreach ($ticketNumbers as $tn) { $text .= "  - {$tn}\n"; }
    $text .= "\nView your bookings: {$bookingsUrl}\n\n";
    $text .= "See you at AmusePark on {$date}!\n\nQuestions? support@amusepark.com\n";

    $subject = '🎉 Booking Confirmed – ' . $ref . ' | AmusePark';
    return _mailer_send($to, $subject, $htmlBody, $text);
}
