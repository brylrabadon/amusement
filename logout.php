<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/auth.php';

auth_logout();
flash_set('success', 'Logged out successfully.');
redirect('login.php');

