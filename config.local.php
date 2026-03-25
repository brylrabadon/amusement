<?php
declare(strict_types=1);

define('DB_HOST', 'localhost');
define('DB_NAME', 'amusepark');
define('DB_USER', 'root');
define('DB_PASS', '');

// ── PayMongo Mode ─────────────────────────────────────────────
// Set to 'test' for development, 'live' for real payments
define('PAYMONGO_MODE', 'test');

// TEST keys (no real money moves)
define('PAYMONGO_TEST_SECRET_KEY',     'sk_test_txyoJvPC3L2stkgneb54iMbk');
define('PAYMONGO_TEST_PUBLIC_KEY',     'pk_test_rbJM5u5rUZ3x4QMhsR4Rvgmu');
define('PAYMONGO_TEST_WEBHOOK_SECRET', 'whsk_AceR3g47wtTEx1W7h3aSgrwT');

// LIVE keys (real GCash payments)
define('PAYMONGO_LIVE_SECRET_KEY',     'sk_live_QJSfjqkFFezBEdS8ZnJ65qTF');
define('PAYMONGO_LIVE_PUBLIC_KEY',     'pk_live_pnUuSBNV4Xsf5CXnzNsU1Hgy');
define('PAYMONGO_LIVE_WEBHOOK_SECRET', 'whsk_AceR3g47wtTEx1W7h3aSgrwT');

// Active keys — resolved from mode above
define('PAYMONGO_SECRET_KEY',     PAYMONGO_MODE === 'live' ? PAYMONGO_LIVE_SECRET_KEY     : PAYMONGO_TEST_SECRET_KEY);
define('PAYMONGO_PUBLIC_KEY',     PAYMONGO_MODE === 'live' ? PAYMONGO_LIVE_PUBLIC_KEY      : PAYMONGO_TEST_PUBLIC_KEY);
define('PAYMONGO_WEBHOOK_SECRET', PAYMONGO_MODE === 'live' ? PAYMONGO_LIVE_WEBHOOK_SECRET  : PAYMONGO_TEST_WEBHOOK_SECRET);

// Dev bypass — skips PayMongo payment check so you can test the full flow locally.
// Automatically disabled in live mode.
define('PAYMONGO_DEV_BYPASS', PAYMONGO_MODE === 'test');
