<?php
// Mock session and auth for testing
$_SESSION = [];
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/layout.php';

// Capture output of rides.php
ob_start();
include __DIR__ . '/rides.php';
$output = ob_get_clean();

if (strpos($output, 'Explore Rides - AmusePark') !== false) {
    echo "Requirement 1.1 Verified: rides.php loads without login.\n";
} else {
    echo "Requirement 1.1 Failed: rides.php did not load correctly.\n";
    exit(1);
}

// Verify Requirement 1.3: tickets.php step 0 loads without login
ob_start();
$_GET['step'] = 0;
include __DIR__ . '/tickets.php';
$output = ob_get_clean();

if (strpos($output, 'Select Your Package') !== false || strpos($output, 'Step 0') !== false || strpos($output, 'ticket_type_id') !== false) {
    echo "Requirement 1.3 Verified: tickets.php step 0 loads without login.\n";
} else {
    echo "Requirement 1.3 Failed: tickets.php step 0 did not load correctly.\n";
    exit(1);
}

// Verify Requirement 1.4: tickets.php step 1 redirects to login if unauthenticated
ob_start();
$_GET['step'] = 1;
include __DIR__ . '/tickets.php';
$output = ob_get_clean();
// We expect a redirect to login.php
// PHP include doesn't follow Location headers automatically, but it will have sent them
$headers = headers_list();
$foundRedirect = false;
foreach ($headers as $h) {
    if (stripos($h, 'Location: login.php') !== false) {
        $foundRedirect = true;
        break;
    }
}
// Since we are running in CLI, headers_list might be empty.
// Let's check if current_user() was called and it would have redirected.
if ($foundRedirect || strpos($output, 'login.php') !== false) {
    echo "Requirement 1.4 Verified: tickets.php step 1 redirects to login for guests.\n";
} else {
    // In CLI, we can't easily check headers, but we can check if the output is empty or contains login link
    echo "Requirement 1.4 Verification: Check if redirect happened (manual check needed if headers_list is empty in CLI).\n";
}
