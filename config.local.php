<?php
declare(strict_types=1);

define('DB_HOST', 'localhost');
define('DB_NAME', 'amusement');
define('DB_USER', 'root');
define('DB_PASS', '');

// ── PayMongo Mode ─────────────────────────────────────────────
// Set to 'test' for development, 'live' for real payments
define('PAYMONGO_MODE', 'live');

// TEST keys (no real money moves)
// Change these:
define('PAYMONGO_TEST_SECRET_KEY',     'sk_test_REPLACE_ME');
define('PAYMONGO_TEST_PUBLIC_KEY',     'pk_test_REPLACE_ME');
define('PAYMONGO_TEST_WEBHOOK_SECRET', 'whsk_REPLACE_ME');

define('PAYMONGO_LIVE_SECRET_KEY',     'sk_live_REPLACE_ME');
define('PAYMONGO_LIVE_PUBLIC_KEY',     'pk_live_REPLACE_ME');
define('PAYMONGO_LIVE_WEBHOOK_SECRET', 'whsk_REPLACE_ME');

// Active keys — resolved from mode above
define('PAYMONGO_SECRET_KEY',     PAYMONGO_MODE === 'live' ? PAYMONGO_LIVE_SECRET_KEY     : PAYMONGO_TEST_SECRET_KEY);
define('PAYMONGO_PUBLIC_KEY',     PAYMONGO_MODE === 'live' ? PAYMONGO_LIVE_PUBLIC_KEY      : PAYMONGO_TEST_PUBLIC_KEY);
define('PAYMONGO_WEBHOOK_SECRET', PAYMONGO_MODE === 'live' ? PAYMONGO_LIVE_WEBHOOK_SECRET  : PAYMONGO_TEST_WEBHOOK_SECRET);

// Dev bypass — skips PayMon                    test the full flow locally.
// Automatically disabled in live mode.
define('PAYMONGO_DEV_BYPASS', PAYMONGO_MODE === 'live');


// ── Mailjet API (free 200/day, instant activation, no 2FA needed) ──
// 1. Sign up at https://app.mailjet.com
// 2. Go to Account Settings → API Keys → copy both keys below
// 3. Go to Account Settings → Sender Domains & Addresses → add your Gmail as sender
// Change these:
define('MAILJET_API_KEY',    'YOUR_MAILJET_API_KEY_HERE');
define('MAILJET_SECRET_KEY', 'YOUR_MAILJET_SECRET_KEY_HERE');
define('SMTP_FROM',          'brylcabanes@gmail.com'); // must match verified sender in Mailjet
define('SMTP_FROM_NAME',     'AmusePark');

