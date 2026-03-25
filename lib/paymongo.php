<?php
declare(strict_types=1);

/**
 * PayMongo API helper — QR Ph via Payment Intent workflow
 * Docs: https://developers.paymongo.com/docs/qr-ph
 *
 * Workflow:
 *  1. Create Payment Intent  (payment_method_allowed: ['qrph'])
 *  2. Create Payment Method  (type: 'qrph', billing: {name, email, phone})
 *  3. Attach Payment Method  → response contains next_action.code.image_url (base64 QR)
 *  4. Display QR to customer (30-min expiry)
 *  5. Poll or webhook for payment.paid event
 */

function paymongo_request(string $method, string $path, array $body = []): array
{
    $url = 'https://api.paymongo.com' . $path;
    $ch  = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => strtoupper($method),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Accept: application/json',
            'Authorization: Basic ' . base64_encode(PAYMONGO_SECRET_KEY . ':'),
        ],
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    if (!empty($body)) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }
    $raw    = (string)curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $decoded = json_decode($raw, true);
    return ['status' => $status, 'body' => is_array($decoded) ? $decoded : []];
}

/**
 * Step 1 — Create a Payment Intent with qrph allowed.
 */
function paymongo_create_payment_intent(int $amountCentavos, string $description, array $metadata = []): array
{
    // PayMongo rejects empty string values in metadata — filter them out
    $cleanMeta = array_filter($metadata, fn($v) => $v !== '' && $v !== null);

    $attrs = [
        'amount'                 => $amountCentavos,
        'payment_method_allowed' => ['qrph'],
        'currency'               => 'PHP',
        'description'            => $description,
    ];
    if (!empty($cleanMeta)) {
        $attrs['metadata'] = $cleanMeta;
    }

    $res = paymongo_request('POST', '/v1/payment_intents', [
        'data' => ['attributes' => $attrs],
    ]);
    return $res['body'];
}

/**
 * Step 2 — Create a QR Ph Payment Method.
 */
function paymongo_create_qrph_payment_method(string $name, string $email, string $phone = ''): array
{
    $billing = ['name' => $name, 'email' => $email];
    if ($phone !== '') $billing['phone'] = $phone;

    $res = paymongo_request('POST', '/v1/payment_methods', [
        'data' => [
            'attributes' => [
                'type'    => 'qrph',
                'billing' => $billing,
            ],
        ],
    ]);
    return $res['body'];
}

/**
 * Step 3 — Attach Payment Method to Payment Intent.
 * Returns next_action.code.image_url (base64 QR image) on success.
 */
function paymongo_attach_payment_method(string $paymentIntentId, string $paymentMethodId, string $returnUrl = ''): array
{
    $attrs = ['payment_method' => $paymentMethodId];
    if ($returnUrl !== '') $attrs['return_url'] = $returnUrl;

    $res = paymongo_request('POST', '/v1/payment_intents/' . $paymentIntentId . '/attach', [
        'data' => ['attributes' => $attrs],
    ]);
    return $res['body'];
}

/**
 * Retrieve a Payment Intent (poll status).
 */
function paymongo_get_payment_intent(string $paymentIntentId): array
{
    $res = paymongo_request('GET', '/v1/payment_intents/' . $paymentIntentId);
    return $res['body'];
}

/**
 * Full QR Ph flow: create intent → create method → attach → return QR image.
 *
 * Returns:
 *   success: bool
 *   payment_intent_id: string
 *   client_key: string          (for client-side polling)
 *   qr_image_url: string        (base64 data URI)
 *   qr_code_id: string
 *   error: string               (on failure)
 */
function paymongo_create_qrph(
    int    $amountCentavos,
    string $description,
    string $customerName,
    string $customerEmail,
    string $customerPhone = '',
    array  $metadata = []
): array {
    // 1. Create Payment Intent
    $intentRes = paymongo_create_payment_intent($amountCentavos, $description, $metadata);
    $intentId  = $intentRes['data']['id'] ?? '';
    $clientKey = $intentRes['data']['attributes']['client_key'] ?? '';

    if ($intentId === '') {
        $logDir = __DIR__ . '/../logs';
        if (is_dir($logDir)) {
            file_put_contents($logDir . '/paymongo_debug.log',
                '[' . date('Y-m-d H:i:s') . '] INTENT FAILED: ' . json_encode($intentRes) . "\n",
                FILE_APPEND | LOCK_EX
            );
        }
        return ['success' => false, 'error' => $intentRes['errors'][0]['detail'] ?? 'Failed to create payment intent.'];
    }

    // 2. Create QR Ph Payment Method
    $pmRes = paymongo_create_qrph_payment_method($customerName, $customerEmail, $customerPhone);
    $pmId  = $pmRes['data']['id'] ?? '';

    if ($pmId === '') {
        return ['success' => false, 'error' => $pmRes['errors'][0]['detail'] ?? 'Failed to create payment method.'];
    }

    // 3. Attach — generates QR image
    $attachRes  = paymongo_attach_payment_method($intentId, $pmId);
    $attrs      = $attachRes['data']['attributes'] ?? [];
    $nextAction = $attrs['next_action'] ?? [];
    $qrImageUrl = $nextAction['code']['image_url'] ?? '';
    $qrCodeId   = $nextAction['code']['id'] ?? '';

    // After attach, status should be awaiting_next_action
    $piStatus = $attrs['status'] ?? '';

    if ($qrImageUrl === '') {
        $errMsg = $attachRes['errors'][0]['detail'] ?? ('Attach failed. PI status: ' . $piStatus);
        // Log full response for debugging
        $logDir = __DIR__ . '/../logs';
        if (is_dir($logDir)) {
            file_put_contents($logDir . '/paymongo_debug.log',
                '[' . date('Y-m-d H:i:s') . '] ATTACH FAILED: ' . json_encode($attachRes) . "\n",
                FILE_APPEND | LOCK_EX
            );
        }
        return ['success' => false, 'error' => $errMsg];
    }

    return [
        'success'           => true,
        'payment_intent_id' => $intentId,
        'client_key'        => $clientKey,
        'qr_image_url'      => $qrImageUrl,  // "data:image/png;base64,..."
        'qr_code_id'        => $qrCodeId,
    ];
}

/**
 * Re-attach a new QR Ph payment method to an existing Payment Intent
 * (used when QR expires after 30 min — PI reverts to awaiting_payment_method).
 */
function paymongo_regenerate_qrph(
    string $paymentIntentId,
    string $customerName,
    string $customerEmail,
    string $customerPhone = ''
): array {
    // Create a fresh payment method
    $pmRes = paymongo_create_qrph_payment_method($customerName, $customerEmail, $customerPhone);
    $pmId  = $pmRes['data']['id'] ?? '';

    if ($pmId === '') {
        return ['success' => false, 'error' => $pmRes['errors'][0]['detail'] ?? 'Failed to create payment method.'];
    }

    // Re-attach to existing intent
    $attachRes  = paymongo_attach_payment_method($paymentIntentId, $pmId);
    $nextAction = $attachRes['data']['attributes']['next_action'] ?? [];
    $qrImageUrl = $nextAction['code']['image_url'] ?? '';
    $qrCodeId   = $nextAction['code']['id'] ?? '';

    if ($qrImageUrl === '') {
        return ['success' => false, 'error' => $attachRes['errors'][0]['detail'] ?? 'Failed to regenerate QR.'];
    }

    return [
        'success'      => true,
        'qr_image_url' => $qrImageUrl,
        'qr_code_id'   => $qrCodeId,
    ];
}

/**
 * Verify a PayMongo webhook signature.
 */
function paymongo_verify_webhook(string $rawBody, string $signature, string $secret): bool
{
    if ($secret === '' || $signature === '') return false;
    $parts = [];
    foreach (explode(',', $signature) as $part) {
        [$k, $v] = array_pad(explode('=', $part, 2), 2, '');
        $parts[$k] = $v;
    }
    $timestamp = $parts['t'] ?? '';
    $hmacTest  = $parts['te'] ?? '';
    $hmacLive  = $parts['li'] ?? '';
    if ($timestamp === '') return false;
    $expected = hash_hmac('sha256', $timestamp . '.' . $rawBody, $secret);
    return hash_equals($expected, $hmacTest) || hash_equals($expected, $hmacLive);
}
