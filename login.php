<?php
declare(strict_types=1);
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/layout.php';

$u = current_user();
if ($u) {
    $next = trim((string)($_GET['next'] ?? ''));
    if ($next !== '' && !str_contains($next, '//') && !str_starts_with($next, 'http')) {
        redirect($next);
    }
    if (($u['role'] ?? '') === 'admin') redirect('admin/admin-dashboard.php');
    redirect('index.php');
}

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $result = auth_login($_POST['email'] ?? '', $_POST['password'] ?? '');
    if (($result['success'] ?? false) === true) {
        $loggedUser = $result['user'];
        flash_set('success', 'Login successful!');
        $next = trim((string)($_GET['next'] ?? $_POST['next'] ?? ''));
        if ($next !== '' && !str_contains($next, '//') && !str_starts_with($next, 'http')) {
            redirect($next);
        }
        if (($loggedUser['role'] ?? '') === 'admin') redirect('admin/admin-dashboard.php');
        redirect('index.php');
    } else {
        $error = $result['message'] ?? 'Invalid email or password.';
    }
}
$flash = flash_get();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" /><meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Login - AmusePark</title>
  <link rel="stylesheet" href="css/style.css" />
  <style>
    body { background: #f9fafb; }
    .auth-split { display: grid; grid-template-columns: 1fr 1fr; min-height: calc(100vh - 108px); }
    .auth-image { position: relative; overflow: hidden; background: url('AmusementPark_1.jpg') center/cover no-repeat; }
    .auth-image::after { content:''; position:absolute; inset:0; background:linear-gradient(to top,rgba(0,0,0,.75) 0%,rgba(0,0,0,.2) 60%,transparent 100%); }
    .auth-image-content { position:absolute; bottom:0; left:0; right:0; z-index:2; padding:3rem; }
    .auth-image-tag { display:inline-block; background:#facc15; color:#000; border-radius:999px; padding:.3rem .9rem; font-size:.78rem; font-weight:800; margin-bottom:1rem; }
    .auth-image-content h2 { font-size:2.8rem; font-weight:900; color:#fff; line-height:1.1; margin-bottom:.75rem; text-shadow:0 2px 12px rgba(0,0,0,.4); }
    .auth-image-content p { color:rgba(255,255,255,.85); font-size:1rem; }
    .auth-form-side { display:flex; align-items:center; justify-content:center; padding:2.5rem 2rem; background:#fff; }
    .auth-box { width:100%; max-width:400px; }
    .auth-box .brand { font-size:1.7rem; font-weight:900; color:#111827; text-decoration:none; display:block; text-align:center; margin-bottom:1.5rem; }
    .auth-box .brand span { color:#facc15; }
    .auth-box h2 { font-size:1.5rem; font-weight:900; color:#111827; text-align:center; margin-bottom:.3rem; }
    .auth-box .sub { color:#6b7280; font-size:.9rem; text-align:center; margin-bottom:2rem; }
    .auth-alert { padding:.85rem 1rem; border-radius:.6rem; margin-bottom:1.25rem; font-size:.9rem; font-weight:600; }
    .auth-alert-error { background:#fee2e2; color:#991b1b; border:1px solid #fca5a5; }
    .auth-alert-success { background:#dcfce7; color:#166534; border:1px solid #86efac; }
    .auth-submit { width:100%; padding:.9rem; border-radius:999px; background:#7c3aed; color:#fff; font-weight:800; font-size:1rem; border:none; cursor:pointer; transition:background .2s; margin-top:.25rem; }
    .auth-submit:hover { background:#6d28d9; }
    .auth-link { color:#7c3aed; font-weight:700; text-decoration:none; }
    .auth-link:hover { text-decoration:underline; }
    @media (max-width:768px) { .auth-split { grid-template-columns:1fr; } .auth-image { display:none; } }
  </style>
</head>
<body>
<?php render_nav(null, 'login'); ?>
<div class="auth-split">
  <div class="auth-image">
    <div class="auth-image-content">
      <div class="auth-image-tag">⭐ Philippines' #1 Amusement Park</div>
      <h2>READY FOR<br>THE THRILL?</h2>
      <p>Experience the magic of AmusePark.</p>
    </div>
  </div>
  <div class="auth-form-side">
    <div class="auth-box">
      <a class="brand" href="index.php">Amuse<span>Park</span></a>
      <h2>Welcome Back!</h2>
      <p class="sub">Log in to manage your bookings</p>
      <?php if ($flash && ($flash['type'] ?? '') === 'success'): ?>
        <div class="auth-alert auth-alert-success"><?= e($flash['message'] ?? '') ?></div>
      <?php endif; ?>
      <?php if ($error || ($flash && ($flash['type'] ?? '') === 'error')): ?>
        <div class="auth-alert auth-alert-error"><?= e($error ?: ($flash['message'] ?? '')) ?></div>
      <?php endif; ?>
      <form method="post">
        <?php if (!empty($_GET['next'])): ?>
          <input type="hidden" name="next" value="<?= e($_GET['next']) ?>" />
        <?php endif; ?>
        <div class="form-group"><label>Email Address</label><input type="email" name="email" placeholder="juan@email.com" required /></div>
        <div class="form-group"><label>Password</label><input type="password" name="password" placeholder="Enter your password" required /></div>
        <button type="submit" class="auth-submit">Log In</button>
      </form>
      <p style="text-align:center;margin-top:1.5rem;font-size:.9rem;color:#6b7280;">
        Don't have an account? <a href="register.php" class="auth-link">Sign Up</a>
      </p>
    </div>
  </div>
</div>
<?php render_footer(); ?>
</body>
</html>
