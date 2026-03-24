<?php
declare(strict_types=1);

// Central config + shared helpers for the PHP version of AmusePark.
// Update the DB credentials to match your MySQL setup.

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// ---- Database configuration ----
// You can override credentials in `config.local.php` (recommended) or via environment variables.
// For XAMPP defaults, MySQL root typically has a BLANK password.
$local = __DIR__ . '/config.local.php';
if (is_file($local)) {
    require_once $local;
}

defined('DB_HOST') || define('DB_HOST', (string)(getenv('AMUSEPARK_DB_HOST') ?: 'localhost'));
defined('DB_NAME') || define('DB_NAME', (string)(getenv('AMUSEPARK_DB_NAME') ?: 'amusepark'));
defined('DB_USER') || define('DB_USER', (string)(getenv('AMUSEPARK_DB_USER') ?: 'root'));
defined('DB_PASS') || define('DB_PASS', (string)(getenv('AMUSEPARK_DB_PASS') ?: ''));

// PayMongo — override in config.local.php
defined('PAYMONGO_SECRET_KEY')    || define('PAYMONGO_SECRET_KEY',    (string)(getenv('PAYMONGO_SECRET_KEY')    ?: ''));
defined('PAYMONGO_PUBLIC_KEY')    || define('PAYMONGO_PUBLIC_KEY',     (string)(getenv('PAYMONGO_PUBLIC_KEY')    ?: ''));
defined('PAYMONGO_WEBHOOK_SECRET')|| define('PAYMONGO_WEBHOOK_SECRET', (string)(getenv('PAYMONGO_WEBHOOK_SECRET')?: ''));

function render_db_error_page(Throwable $e): never
{
    http_response_code(500);
    $msg = $e->getMessage();
    // Avoid leaking secrets; PDO error doesn't include password, but keep it minimal.
    $safe = htmlspecialchars((string)$msg, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>Database connection error</title>';
    echo '<style>body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;background:#0b1220;color:#e5e7eb;margin:0}';
    echo '.wrap{max-width:880px;margin:0 auto;padding:40px 18px}.card{background:#111827;border:1px solid #1f2937;border-radius:14px;padding:18px}';
    echo 'code{background:#0b1020;padding:2px 6px;border-radius:8px;color:#93c5fd}';
    echo 'a{color:#93c5fd}</style></head><body><div class="wrap"><div class="card">';
    echo '<h2 style="margin:0 0 10px 0">Database connection failed</h2>';
    echo '<div style="color:#9ca3af;margin-bottom:14px">Fix your MySQL credentials, then refresh the page.</div>';
    echo '<div style="margin:12px 0"><strong>Error:</strong> <code>' . $safe . '</code></div>';
    echo '<div style="margin-top:14px;color:#cbd5e1;line-height:1.6">';
    echo '<div style="margin-bottom:10px"><strong>Quick fix (XAMPP):</strong> MySQL user <code>root</code> usually has a blank password, so in <code>config.php</code> set <code>DB_PASS</code> to an empty string.</div>';
    echo '<div style="margin-bottom:10px"><strong>Recommended:</strong> create <code>config.local.php</code> (same folder as <code>config.php</code>) and define DB constants there.</div>';
    echo '<div>After fixing, open <code>login.php</code> again.</div>';
    echo '</div></div></div></body></html>';
    exit;
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;

    try {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    } catch (Throwable $e) {
        render_db_error_page($e);
    }
    return $pdo;
}

function e(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function app_base_url(): string
{
    static $base = null;
    if (is_string($base)) return $base;

    $docRoot = str_replace('\\', '/', realpath((string)($_SERVER['DOCUMENT_ROOT'] ?? '')) ?: '');
    $appRoot = str_replace('\\', '/', realpath(__DIR__) ?: __DIR__);

    if ($docRoot !== '' && stripos($appRoot, $docRoot) === 0) {
        $rel = substr($appRoot, strlen($docRoot));
        $rel = trim(str_replace('\\', '/', $rel), '/');
        $base = $rel === '' ? '/' : '/' . $rel . '/';
        return $base;
    }

    // Hard fallback — derive from SCRIPT_NAME by stripping everything after the app folder
    $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? '/'));
    // App folder name is the directory name of __DIR__
    $appFolder = basename($appRoot); // e.g. 'amusement'
    $pos = stripos($script, '/' . $appFolder . '/');
    if ($pos !== false) {
        $base = substr($script, 0, $pos + strlen('/' . $appFolder . '/'));
        return $base;
    }

    $base = '/';
    return $base;
}

function url(string $path): string
{
    if (preg_match('~^https?://~i', $path)) return $path;
    if (str_starts_with($path, '/')) return $path;
    return app_base_url() . ltrim($path, '/');
}

function redirect(string $path): never
{
    header('Location: ' . url($path));
    exit;
}

function flash_set(string $type, string $message, ?array $action = null): void
{
    $flash = ['type' => $type, 'message' => $message];
    if (is_array($action) && isset($action['href'], $action['label'])) {
        $flash['action'] = [
            'href' => (string)$action['href'],
            'label' => (string)$action['label'],
        ];
    }
    $_SESSION['flash'] = $flash;
}

function flash_get(): ?array
{
    if (!isset($_SESSION['flash'])) return null;
    $f = $_SESSION['flash'];
    unset($_SESSION['flash']);
    return is_array($f) ? $f : null;
}

