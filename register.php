<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/auth.php';

$u = current_user();
if ($u) {
    if (($u['role'] ?? '') === 'admin') redirect('admin/admin-dashboard.php');
    redirect('customer/dashboard.php');
}

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pw = (string)($_POST['password'] ?? '');
    $pw2 = (string)($_POST['password_confirm'] ?? '');
    if ($pw !== $pw2) {
        $error = 'Passwords do not match.';
    } elseif (strlen($pw) < 8) {
        $error = 'Password must be at least 8 characters.';
    } else {
    $result = auth_register(
        $_POST['full_name'] ?? '',
        $_POST['email'] ?? '',
        $_POST['phone'] ?? '',
        $pw
    );
    if (($result['success'] ?? false) === true) {
        flash_set('success', 'Registration successful! You can now log in.', ['href' => 'login.php', 'label' => 'Login now']);
        redirect('register.php');
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
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Create Account - AmusePark</title>
  <link rel="stylesheet" href="css/style.css" />
  <style>
    /* Custom overrides to remove blue tint and update background */
    .auth-left {
      background-image: url('https://images.unsplash.com/photo-1513889961551-628c1e5e2ee9?q=80&w=2070');
      background-size: cover;
      background-position: center;
      position: relative;
    }

    /* Removing the blue and using a subtle dark gradient for text contrast */
    .auth-left-overlay {
      background: linear-gradient(to bottom, rgba(0,0,0,0.1) 0%, rgba(0,0,0,0.6) 100%) !important;
      display: flex;
      flex-direction: column;
      justify-content: center;
      padding: 3rem;
      height: 100%;
    }

    .hero-tag {
      background: rgba(255, 255, 255, 0.2);
      backdrop-filter: blur(8px);
      width: fit-content;
      padding: 0.5rem 1rem;
      border-radius: 50px;
      color: white;
      font-weight: 600;
    }

    h2, p {
        text-shadow: 0 2px 10px rgba(0,0,0,0.3);
    }
  </style>
</head>
<body>

<nav>
  <a class="logo" href="index.php">Amuse<span>Park</span></a>
</nav>

<div class="auth-page">
  <div class="auth-left">
    <div class="auth-left-overlay">
      <div class="hero-tag" style="margin-bottom:1.5rem;">⭐ Philippines' #1 Amusement Park</div>
      <h2 style="font-size:2.5rem;font-weight:900;line-height:1.2;margin-bottom:1rem; color: #fff;">
        CREATE<br/>ACCOUNT
      </h2>
      <p style="color:rgba(255,255,255,.9);font-size:1.1rem;">Join thousands of happy visitors!</p>
    </div>
  </div>

  <div class="auth-right">
    <div class="auth-card">
      <div style="text-align:center;margin-bottom:2rem;">
        <a class="logo" href="index.php" style="font-size:1.8rem;">Amuse<span>Park</span></a>
        <h2 style="font-size:1.5rem;font-weight:800;margin-top:1rem;margin-bottom:.25rem;">Create Account</h2>
        <p style="color:#64748b;font-size:.9rem;">Sign up and start booking your adventure!</p>
      </div>

      <?php if ($flash && ($flash['type'] ?? '') === 'success'): ?>
        <div class="auth-alert auth-alert-success" style="display:block;">
          <?= htmlspecialchars($flash['message'] ?? '') ?>
          <?php if (!empty($flash['action']) && is_array($flash['action'])): ?>
            <?php $href = (string)($flash['action']['href'] ?? ''); ?>
            <?php $label = (string)($flash['action']['label'] ?? ''); ?>
            <?php if ($href !== '' && $label !== ''): ?>
              <div style="margin-top:.5rem;">
                <a href="<?= htmlspecialchars($href) ?>" style="color:#166534;font-weight:800;text-decoration:underline;"><?= htmlspecialchars($label) ?></a>
              </div>
            <?php endif; ?>
          <?php endif; ?>
        </div>
      <?php endif; ?>

      <?php if ($flash && ($flash['type'] ?? '') === 'error'): ?>
        <div class="auth-alert auth-alert-error" style="display:block;"><?= htmlspecialchars($flash['message'] ?? '') ?></div>
      <?php endif; ?>
      
      <?php if ($error): ?>
        <div class="auth-alert auth-alert-error" style="display:block;"><?= htmlspecialchars($error) ?></div>
      <?php endif; ?>

      <form method="post">
        <div class="form-group">
          <label>Full Name</label>
          <input type="text" name="full_name" id="reg-name" placeholder="Juan D. Dela Cruz" required />
        </div>
        <div class="form-group">
          <label>Email Address</label>
          <input type="email" name="email" id="reg-email" placeholder="juan@email.com" required />
        </div>
        <div class="form-group">
          <label>Phone Number</label>
          <input type="tel" name="phone" id="reg-phone" placeholder="+63 9XX XXX XXXX" />
        </div>
        <div class="form-group">
          <label>Password</label>
          <input type="password" name="password" placeholder="Min. 8 characters" required />
        </div>
        <div class="form-group">
          <label>Confirm Password</label>
          <input type="password" name="password_confirm" placeholder="Re-enter your password" required />
        </div>
        <div style="margin-bottom:1.25rem;">
          <label style="display:flex;align-items:flex-start;gap:.5rem;font-weight:400;font-size:.85rem;cursor:pointer;">
            <input type="checkbox" required style="margin-top:3px;" />
            <span>I agree to the <a href="#" style="color:#1d4ed8;text-decoration:none;">Terms of Service</a> and <a href="#" style="color:#1d4ed8;text-decoration:none;">Privacy Policy</a></span>
          </label>
        </div>
        <button type="submit" class="btn btn-primary btn-full" style="font-size:1rem;padding:.85rem;">
          Create Account
        </button>
      </form>

      <div style="text-align:center;margin-top:1.5rem;font-size:.9rem;color:#64748b;">
        Already have an account? <a href="login.php" style="color:#1d4ed8;font-weight:600;text-decoration:none;">Log In</a>
      </div>
    </div>
  </div>
</div>
</body>
</html>