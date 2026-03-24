<?php
declare(strict_types=1);
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/layout.php';

$u = current_user();
if ($u) {
    if (($u['role'] ?? '') === 'admin') redirect('admin/admin-dashboard.php');
    if (($u['role'] ?? '') === 'staff') redirect('staff/dashboard.php');
    redirect('customer/dashboard.php');
}

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $result = auth_login($_POST['email'] ?? '', $_POST['password'] ?? '');
    if (($result['success'] ?? false) === true) {
        $role = $result['user']['role'] ?? 'customer';
        if ($role === 'admin') redirect('admin/admin-dashboard.php');
        if ($role === 'staff') redirect('staff/dashboard.php');
        redirect('customer/dashboard.php');
    } else {
        $error = $result['message'] ?? 'Login failed.';
    }
}
$flash = flash_get();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Login - AmusePark</title>
  <style>
    *, *::before, *::after { box-sizing: border-box; }
    html, body { height: 100%; margin: 0; font-family: 'Segoe UI', system-ui, sans-serif; overflow: hidden; }

    /* ── Split layout ── */
    .auth-wrap {
      display: flex;
      height: 100vh;
      width: 100vw;
    }

    /* LEFT — video panel */
    .auth-video-panel {
      flex: 1;
      position: relative;
      overflow: hidden;
    }
    .auth-video-panel video {
      position: absolute;
      inset: 0;
      width: 100%;
      height: 100%;
      object-fit: cover;
      object-position: center;
    }
    .auth-video-overlay {
      position: absolute;
      inset: 0;
      background: linear-gradient(135deg, rgba(10,6,24,.55) 0%, rgba(60,20,120,.35) 60%, rgba(10,6,24,.45) 100%);
    }
    .auth-video-brand {
      position: absolute;
      bottom: 2.5rem;
      left: 2.5rem;
      z-index: 2;
      color: #fff;
    }
    .auth-video-brand h2 {
      font-size: 2rem;
      font-weight: 900;
      margin: 0 0 .4rem;
      text-shadow: 0 2px 12px rgba(0,0,0,.5);
    }
    .auth-video-brand p {
      font-size: .95rem;
      color: rgba(255,255,255,.75);
      margin: 0;
      text-shadow: 0 1px 6px rgba(0,0,0,.4);
    }

    /* RIGHT — form panel */
    .auth-form-panel {
      width: 460px;
      flex-shrink: 0;
      background: #fff;
      display: flex;
      flex-direction: column;
      justify-content: center;
      padding: 3rem 3rem;
      overflow-y: auto;
    }

    .auth-logo { display: flex; align-items: center; gap: .55rem; text-decoration: none; margin-bottom: 2rem; }
    .auth-logo img { height: 40px; width: 40px; border-radius: 50%; object-fit: cover; }
    .auth-logo-text { font-size: 1.5rem; font-weight: 900; color: #0f0a1e; }
    .auth-logo-text span { color: #f59e0b; }

    .auth-form-panel h1 { font-size: 1.9rem; font-weight: 900; color: #0f0a1e; margin: 0 0 .3rem; }
    .auth-sub { color: #6b7280; font-size: .9rem; margin: 0 0 1.75rem; }

    .auth-alert { padding: .85rem 1rem; border-radius: .65rem; margin-bottom: 1rem; font-size: .88rem; font-weight: 600; }
    .auth-alert-error   { background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5; }
    .auth-alert-success { background: #dcfce7; color: #166534; border: 1px solid #86efac; }

    .auth-field { margin-bottom: 1rem; }
    .auth-field label { display: block; font-size: .82rem; font-weight: 700; color: #374151; margin-bottom: .4rem; }
    .auth-field input {
      width: 100%; padding: .78rem 1rem; border: 1.5px solid #e5e7eb; border-radius: .75rem;
      font-size: .92rem; color: #111827; background: #f9fafb;
      transition: border-color .2s, box-shadow .2s; outline: none;
    }
    .auth-field input:focus { border-color: #7c3aed; box-shadow: 0 0 0 3px rgba(124,58,237,.12); background: #fff; }

    .auth-forgot { display: block; text-align: right; font-size: .8rem; color: #7c3aed; font-weight: 700; text-decoration: none; margin-top: -.5rem; margin-bottom: 1rem; }
    .auth-forgot:hover { text-decoration: underline; }

    .auth-link { color: #7c3aed; font-weight: 700; text-decoration: none; }
    .auth-link:hover { text-decoration: underline; }

    .auth-submit {
      width: 100%; padding: .95rem; border-radius: 999px;
      background: #7c3aed; color: #fff; font-weight: 800; font-size: 1rem;
      border: none; cursor: pointer; transition: background .2s, transform .15s;
      margin-top: .25rem;
    }
    .auth-submit:hover { background: #6d28d9; transform: translateY(-1px); }

    .auth-divider { display: flex; align-items: center; gap: .75rem; margin: 1.5rem 0; color: #d1d5db; font-size: .8rem; }
    .auth-divider::before, .auth-divider::after { content: ''; flex: 1; height: 1px; background: #e5e7eb; }

    .auth-footer { text-align: center; font-size: .9rem; color: #6b7280; }
    .auth-back { display: block; text-align: center; margin-top: .75rem; font-size: .82rem; color: #9ca3af; text-decoration: none; }
    .auth-back:hover { color: #6b7280; }

    /* Mobile: stack vertically */
    @media (max-width: 768px) {
      html, body { overflow: auto; }
      .auth-wrap { flex-direction: column; height: auto; }
      .auth-video-panel { height: 240px; flex: none; }
      .auth-form-panel { width: 100%; padding: 2.5rem 1.5rem; }
    }
  </style>
</head>
<body>
<div class="auth-wrap">

  <!-- LEFT: video -->
  <div class="auth-video-panel">
    <video autoplay muted loop playsinline>
      <source src="aww.mp4" type="video/mp4"/>
    </video>
    <div class="auth-video-overlay"></div>
    <div class="auth-video-brand">
      <h2>Welcome to AmusePark</h2>
      <p>Thrilling rides &amp; unforgettable memories await.</p>
    </div>
  </div>

  <!-- RIGHT: form -->
  <div class="auth-form-panel">
    <a class="auth-logo" href="index.php">
      <img src="hero.png.jpg" alt="AmusePark"/>
      <span class="auth-logo-text">Amuse<span>Park</span></span>
    </a>
    <h1>Welcome back</h1>
    <p class="auth-sub">Log in to manage your bookings and tickets</p>

    <?php if ($flash && ($flash['type'] ?? '') === 'success'): ?>
      <div class="auth-alert auth-alert-success">&#10003; <?= e($flash['message'] ?? '') ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
      <div class="auth-alert auth-alert-error">&#9888; <?= e($error) ?></div>
    <?php endif; ?>

    <form method="post">
      <div class="auth-field">
        <label>Email Address</label>
        <input type="email" name="email" placeholder="juan@email.com" required autocomplete="email"/>
      </div>
      <div class="auth-field">
        <label>Password</label>
        <input type="password" name="password" placeholder="Your password" required autocomplete="current-password"/>
      </div>
      <a href="#" class="auth-forgot">Forgot password?</a>
      <button type="submit" class="auth-submit">Log In</button>
    </form>

    <div class="auth-divider">or</div>
    <p class="auth-footer">Don't have an account? <a href="register.php" class="auth-link">Sign Up</a></p>
    <a href="index.php" class="auth-back">Back to Home</a>
  </div>

</div>
</body>
</html>
