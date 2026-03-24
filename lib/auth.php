<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';

function current_user(): ?array
{
    $u = $_SESSION['user'] ?? null;
    return is_array($u) ? $u : null;
}

function require_login(?string $role = null): array
{
    $u = current_user();
    if (!$u) {
        flash_set('error', 'Please log in first.');
        redirect('login.php');
    }
    if ($role && ($u['role'] ?? null) !== $role) {
        flash_set('error', 'You are not allowed to access that page.');
        redirect('index.php');
    }
    return $u;
}

function require_admin(): array
{
    return require_login('admin');
}

function require_staff(): array
{
    $u = current_user();
    if (!$u) {
        flash_set('error', 'Please log in first.');
        redirect('login.php');
    }
    if (!in_array($u['role'] ?? '', ['admin', 'staff'], true)) {
        flash_set('error', 'You are not allowed to access that page.');
        redirect('index.php');
    }
    return $u;
}

function is_sha256_hex(string $hash): bool
{
    return (bool)preg_match('/^[a-f0-9]{64}$/i', $hash);
}

function auth_login(string $email, string $password): array
{
    $email = trim($email);
    if ($email === '' || $password === '') {
        return ['success' => false, 'message' => 'Missing email or password.'];
    }

    $stmt = db()->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    $row = $stmt->fetch();
    if (!$row) {
        return ['success' => false, 'message' => 'Invalid email or password.'];
    }

    $stored = (string)($row['password_hash'] ?? '');
    $ok = false;

    if (str_starts_with($stored, '$2')) {
        $ok = password_verify($password, $stored);
    } elseif (is_sha256_hex($stored)) {
        $sha = hash('sha256', $password);
        $ok = strtolower($sha) === strtolower($stored);
        if ($ok) {
            $newHash = password_hash($password, PASSWORD_BCRYPT);
            $up = db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
            $up->execute([$newHash, $row['id']]);
            $row['password_hash'] = $newHash;
        }
    }

    if (!$ok) {
        return ['success' => false, 'message' => 'Invalid email or password.'];
    }

    $user = [
        'id' => (int)$row['id'],
        'full_name' => (string)$row['full_name'],
        'email' => (string)$row['email'],
        'phone' => (string)($row['phone'] ?? ''),
        'role' => (string)($row['role'] ?? 'customer'),
    ];
    $_SESSION['user'] = $user;

    return ['success' => true, 'user' => $user];
}

function auth_register(string $full_name, string $email, string $phone, string $password): array
{
    $full_name = trim($full_name);
    $email = trim($email);
    $phone = trim($phone);

    if ($full_name === '' || $email === '' || $password === '') {
        return ['success' => false, 'message' => 'Missing required fields.'];
    }
    if (strlen($password) < 8) {
        return ['success' => false, 'message' => 'Password must be at least 8 characters.'];
    }

    $exists = db()->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
    $exists->execute([$email]);
    if ($exists->fetch()) {
        return ['success' => false, 'message' => 'Email is already registered.'];
    }

    $hash = password_hash($password, PASSWORD_BCRYPT);
    $ins = db()->prepare('INSERT INTO users (full_name, email, phone, password_hash, role) VALUES (?,?,?,?,?)');
    $ins->execute([$full_name, $email, $phone, $hash, 'customer']);

    $user = [
        'id' => (int)db()->lastInsertId(),
        'full_name' => $full_name,
        'email' => $email,
        'phone' => $phone,
        'role' => 'customer',
    ];

    return ['success' => true, 'user' => $user];
}

function auth_logout(): void
{
    unset($_SESSION['user']);
    // Keep flash messages if present; otherwise destroy full session.
    if (empty($_SESSION)) {
        session_destroy();
    }
}

