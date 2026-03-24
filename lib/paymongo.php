<?php
declare(strict_types=1);

/**
 * PayMongo API helper — QR Ph Payment Intent workflow
 *
 * Docs: https://developers.paymongo.com/docs/qr-ph
 */

/**
 * Make an authenticated request to the PayMongo API.
 *
 * @param string $method  GET | POST
 * @param string $path    e.g. '/v1/payment_intents'
 * @param array  $body    Request body (will be JSON-encoded)
 * @return array{status: int, body: array}
 */
function paymongo_request(string $method, string $path, array $body = []): array
{
    $secretKey = PAYMONGO_SECRET_KEY;
    $url       = 'https://api.paymongo.com' . $path;

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => strtoupper($method),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Accept: application/json',
            'Authorization: Basic ' . base64_encode($secretKey . ':'),
        ],
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    if (!empty($body)) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }

    $raw    = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $decoded = json_decode((string)$raw, true);
    return ['status' => $status, 'body' => is_array($decoded) ? $decoded : []];
}

/**
 * Step 1 — Create a Payment Intent for QR Ph.
 *
 * @param int    $amountCentavos  Amount in centavos (e.g. 35000 = ₱350.00)
 * @param string $description     e.g. "AmusePark One-Day Pass x1"
 * @param array  $metadata        Optional key-value pairs
 * @return array PayMongo response body
 */
function paymongo_create_payment_intent(int $amountCentavos, string $description, array $metadata = []): array
{
    $res = paymongo_request('POST', '/v1/payment_intents', [
        'data' => [
            'attributes' => [
                'amount'                 => $amountCentavos,
                'payment_method_allowed' => ['qrph'],
                'currency'               => 'PHP',
                'description'            => $description,
                'metadata'               => $metadata,
            ],
        ],
    ]);
    return $res['body'];
}

/**
 * Step 2 — Create a QR Ph Payment Method.
 *
 * @param string $name   Customer full name
 * @param string $email  Customer email
 * @param string $phone  Customer phone (optional, e.g. "+639XXXXXXXXX")
 * @return array PayMongo response body
 */
function paymongo_create_qrph_payment_method(string $name, string $email, string $phone = ''): array
{
    $billing = [
        'name'  => $name,
        'email' => $email,
    ];
    if ($phone !== '') {
        $billing['phone'] = $phone;
    }

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
 * Step 3 — Attach the Payment Method to the Payment Intent.
 * Returns the attach response which contains next_action.code.image_url (base64 QR image).
 *
 * @param string $paymentIntentId  e.g. "pi_xxxx"
 * @param string $paymentMethodId  e.g. "pm_xxxx"
 * @param string $returnUrl        URL to redirect after payment (for web redirect flows)
 * @return array PayMongo response body
 */
function paymongo_attach_payment_method(string $paymentIntentId, string $paymentMethodId, string $returnUrl = ''): array
{
    $attrs = ['payment_method' => $paymentMethodId];
    if ($returnUrl !== '') {
        $attrs['return_url'] = $returnUrl;
    }

    $res = paymongo_request('POST', '/v1/payment_intents/' . $paymentIntentId . '/attach', [
        'data' => ['attributes' => $attrs],
    ]);
    return $res['body'];
}

/**
 * Retrieve a Payment Intent by ID (to poll status).
 *
 * @param string $paymentIntentId
 * @return array PayMongo response body
 */
function paymongo_get_payment_intent(string $paymentIntentId): array
{
    $res = paymongo_request('GET', '/v1/payment_intents/' . $paymentIntentId);
    return $res['body'];
}

/**
 * Full QR Ph flow — creates intent + method + attaches, returns QR image URL.
 *
 * @param int    $amountCentavos
 * @param string $description
 * @param string $customerName
 * @param string $customerEmail
 * @param string $customerPhone
 * @param array  $metadata
 * @return array{
 *   success: bool,
 *   payment_intent_id?: string,
 *   qr_image_url?: string,
 *   qr_code_id?: string,
 *   error?: string
 * }
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
    if ($intentId === '') {
        $errMsg = $intentRes['errors'][0]['detail'] ?? 'Failed to create payment intent.';
        return ['success' => false, 'error' => $errMsg];
    }

    // 2. Create QR Ph Payment Method
    $pmRes = paymongo_create_qrph_payment_method($customerName, $customerEmail, $customerPhone);
    $pmId  = $pmRes['data']['id'] ?? '';
    if ($pmId === '') {
        $errMsg = $pmRes['errors'][0]['detail'] ?? 'Failed to create payment method.';
        return ['success' => false, 'error' => $errMsg];
    }

    // 3. Attach — this generates the QR image
    $attachRes  = paymongo_attach_payment_method($intentId, $pmId);
    $nextAction = $attachRes['data']['attributes']['next_action'] ?? [];
    $qrImageUrl = $nextAction['code']['image_url'] ?? '';
    $qrCodeId   = $nextAction['code']['id'] ?? '';

    if ($qrImageUrl === '') {
        $errMsg = $attachRes['errors'][0]['detail'] ?? 'Failed to generate QR code.';
        return ['success' => false, 'error' => $errMsg];
    }

    return [
        'success'            => true,
        'payment_intent_id'  => $intentId,
        'qr_image_url'       => $qrImageUrl,   // base64 data URI: "data:image/png;base64,..."
        'qr_code_id'         => $qrCodeId,
    ];
}

/**
 * Verify a PayMongo webhook signature.
 *
 * @param string $rawBody   Raw request body (file_get_contents('php://input'))
 * @param string $signature X-Paymongo-Signature header value
 * @param string $secret    Webhook secret from PayMongo dashboard
 * @return bool
 */
function paymongo_verify_webhook(string $rawBody, string $signature, string $secret): bool
{
    if ($secret === '' || $signature === '') return false;

    // Signature format: "t=<timestamp>,te=<test_hmac>,li=<live_hmac>"
    $parts = [];
    foreach (explode(',', $signature) as $part) {
        [$k, $v] = array_pad(explode('=', $part, 2), 2, '');
        $parts[$k] = $v;
    }

    $timestamp = $parts['t'] ?? '';
    $hmacTest  = $parts['te'] ?? '';
    $hmacLive  = $parts['li'] ?? '';

    if ($timestamp === '') return false;

    $payload  = $timestamp . '.' . $rawBody;
    $expected = hash_hmac('sha256', $payload, $secret);

    // Accept either test or live signature
    return hash_equals($expected, $hmacTest) || hash_equals($expected, $hmacLive);
}
