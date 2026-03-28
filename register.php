<?php
declare(strict_types=1);
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/layout.php';

$u = current_user();
if ($u) {
    if (($u['role'] ?? '') === 'admin') redirect('admin/admin-dashboard.php');
    redirect('customer/dashboard.php');
}

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pw  = (string)($_POST['password'] ?? '');
    $pw2 = (string)($_POST['password_confirm'] ?? '');
    if ($pw !== $pw2) {
        $error = 'Passwords do not match.';
    } elseif (strlen($pw) < 8) {
        $error = 'Password must be at least 8 characters.';
    } else {
        $result = auth_register($_POST['full_name'] ?? '', $_POST['email'] ?? '', $_POST['phone'] ?? '', $pw);
        if (($result['success'] ?? false) === true) {
            flash_set('success', 'Account created! You can now log in.');
            redirect('login.php');
        } else {
            $error = $result['message'] ?? 'Registration failed.';
        }
    }
}
$flash = flash_get();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Create Account - AmusePark</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
  <style>
    *, *::before, *::after { box-sizing: border-box; }
    :root {
      --primary: #1e3a8a;
      --primary-dark: #172554;
      --secondary: #fbbf24;
      --secondary-dark: #f59e0b;
      --dark: #0f172a;
      --light: #f8fafc;
    }
    html, body { height: 100%; margin: 0; font-family: 'Poppins', sans-serif; overflow: hidden; background: var(--light); }

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
      background: var(--dark);
    }
    .auth-video-panel video {
      position: absolute;
      inset: 0;
      width: 100%;
      height: 100%;
      object-fit: cover;
      object-position: center;
      opacity: .5;
    }
    .auth-video-overlay {
      position: absolute;
      inset: 0;
      background: linear-gradient(135deg, rgba(15,23,42,0.9) 0%, rgba(30,58,138,0.4) 60%, rgba(15,23,42,0.8) 100%);
    }
    .auth-video-brand {
      position: absolute;
      bottom: 4rem;
      left: 4rem;
      z-index: 2;
      color: #fff;
    }
    .auth-video-brand h2 {
      font-size: 3rem;
      font-weight: 800;
      margin: 0 0 .75rem;
      letter-spacing: -0.02em;
    }
    .auth-video-brand p {
      font-size: 1.1rem;
      color: rgba(255,255,255,0.8);
      margin: 0;
      max-width: 450px;
      line-height: 1.6;
    }

    /* RIGHT — form panel */
    .auth-form-panel {
      width: 500px;
      flex-shrink: 0;
      background: #fff;
      display: flex;
      flex-direction: column;
      justify-content: center;
      padding: 4rem;
      overflow-y: auto;
      box-shadow: -10px 0 50px rgba(0,0,0,0.05);
    }

    .auth-logo { display: flex; align-items: center; gap: .75rem; text-decoration: none; margin-bottom: 2rem; }
    .auth-logo img { height: 44px; width: 44px; border-radius: 12px; object-fit: cover; }
    .auth-logo-text { font-size: 1.5rem; font-weight: 800; color: var(--dark); }
    .auth-logo-text span { color: var(--secondary); }

    .auth-form-panel h1 { font-size: 2rem; font-weight: 800; color: var(--dark); margin: 0 0 .4rem; letter-spacing: -0.02em; }
    .auth-sub { color: #64748b; font-size: .95rem; margin-bottom: 2rem; font-weight: 500; }

    .auth-alert { padding: 1rem 1.25rem; border-radius: 12px; margin-bottom: 1.5rem; font-size: .9rem; font-weight: 600; }
    .auth-alert-error   { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }
    .auth-alert-success { background: #dcfce7; color: #166534; border: 1px solid #bbf7d0; }

    .auth-field { margin-bottom: 1.25rem; }
    .auth-field label { display: block; font-size: .8rem; font-weight: 700; color: var(--dark); text-transform: uppercase; letter-spacing: .05em; margin-bottom: .5rem; }
    .auth-field input {
      width: 100%; padding: .85rem 1.1rem; border: 1.5px solid #e2e8f0; border-radius: 12px;
      font-size: .95rem; color: var(--dark); background: var(--light);
      transition: all .3s; outline: none; font-family: inherit;
    }
    .auth-field input:focus { border-color: var(--primary); box-shadow: 0 0 0 4px rgba(30, 58, 138, 0.1); background: #fff; }

    .auth-submit {
      width: 100%; padding: 1rem; border-radius: 15px;
      background: var(--primary); color: #fff; font-weight: 800; font-size: 1.05rem;
      border: none; cursor: pointer; transition: all .3s;
      margin-top: .5rem; box-shadow: 0 10px 20px rgba(30, 58, 138, 0.2);
    }
    .auth-submit:hover { background: var(--primary-dark); transform: translateY(-3px); }

    .auth-footer { text-align: center; font-size: .95rem; color: #64748b; margin-top: 1.5rem; font-weight: 500; }
    .auth-footer a { color: var(--primary); font-weight: 700; text-decoration: none; }
    .auth-footer a:hover { text-decoration: underline; }

    .auth-back { display: block; text-align: center; margin-top: 1.5rem; font-size: .85rem; color: #94a3b8; text-decoration: none; font-weight: 600; transition: color .2s; }
    .auth-back:hover { color: var(--primary); }

    /* Mobile: stack vertically */
    @media (max-width: 992px) {
      html, body { overflow: auto; }
      .auth-wrap { flex-direction: column; height: auto; }
      .auth-video-panel { display: none; }
      .auth-form-panel { width: 100%; padding: 3rem 1.5rem; }
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
      <h2>Join AmusePark</h2>
      <p>Create your account and start booking your adventure.</p>
    </div>
  </div>

  <!-- RIGHT: form -->
  <div class="auth-form-panel">
    <a class="auth-logo" href="index.php">
      <img src="hero.png.jpg" alt="AmusePark"/>
      <span class="auth-logo-text">Amuse<span>Park</span></span>
    </a>
    <h1>Create your account</h1>
    <p class="auth-sub">Sign up and start booking your adventure</p>

    <?php if ($flash && ($flash['type'] ?? '') === 'success'): ?>
      <div class="auth-alert auth-alert-success">&#10003; <?= e($flash['message'] ?? '') ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
      <div class="auth-alert auth-alert-error">&#9888; <?= e($error) ?></div>
    <?php endif; ?>

    <form method="post">
      <div class="auth-row">
        <div class="auth-field">
          <label>Full Name</label>
          <input type="text" name="full_name" placeholder="Juan Dela Cruz" required autocomplete="name"/>
        </div>
        <div class="auth-field">
          <label>Phone</label>
          <input type="tel" name="phone" placeholder="+63 9XX XXX XXXX" autocomplete="tel"/>
        </div>
      </div>
      <div class="auth-field">
        <label>Email Address</label>
        <input type="email" name="email" placeholder="juan@email.com" required autocomplete="email"/>
      </div>
      <div class="auth-row">
        <div class="auth-field">
          <label>Password</label>
          <input type="password" name="password" placeholder="Min. 8 chars" required autocomplete="new-password"/>
        </div>
        <div class="auth-field">
          <label>Confirm</label>
          <input type="password" name="password_confirm" placeholder="Re-enter" required autocomplete="new-password"/>
        </div>
      </div>
      <label class="auth-terms">
        <input type="checkbox" required/>
        <span>I agree to the <a href="#" class="auth-link">Terms</a> and <a href="#" class="auth-link">Privacy Policy</a></span>
      </label>
      <button type="submit" class="auth-submit">Create Account</button>
    </form>

    <div class="auth-divider">or</div>
    <p class="auth-footer">Already have an account? <a href="login.php" class="auth-link">Log In</a></p>
    <a href="index.php" class="auth-back">Back to Home</a>
  </div>

</div>
</body>
</html>
