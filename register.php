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
        flash_set('success', 'Login successfully, Login now', ['href' => 'login.php', 'label' => 'Login now']);
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
</head>
<body>

<nav>
  <a class="logo" href="index.php">Amuse<span>Park</span></a>
</nav>

<div class="auth-page">
  <div class="auth-left">
    <img src="https://images.unsplash.com/photo-1563656157432-67560011e209?w=800&q=80" alt="AmusePark" />
    <div class="auth-left-overlay">
      <div class="hero-tag" style="margin-bottom:1.5rem;">⭐ Philippines' #1 Amusement Park</div>
      <h2 style="font-size:2.5rem;font-weight:900;line-height:1.2;margin-bottom:1rem;">
        CREATE<br/>ACCOUNT
      </h2>
      <p style="color:rgba(255,255,255,.8);font-size:1rem;">Join thousands of happy visitors!</p>
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
          <?= e($flash['message'] ?? '') ?>
          <?php if (!empty($flash['action']) && is_array($flash['action'])): ?>
            <?php $href = (string)($flash['action']['href'] ?? ''); ?>
            <?php $label = (string)($flash['action']['label'] ?? ''); ?>
            <?php if ($href !== '' && $label !== ''): ?>
              <div style="margin-top:.5rem;">
                <a href="<?= e($href) ?>" style="color:#166534;font-weight:800;text-decoration:underline;"><?= e($label) ?></a>
              </div>
            <?php endif; ?>
          <?php endif; ?>
        </div>
      <?php endif; ?>
      <?php if ($flash && ($flash['type'] ?? '') === 'error'): ?>
        <div class="auth-alert auth-alert-error" style="display:block;"><?= e($flash['message'] ?? '') ?></div>
      <?php endif; ?>
      <?php if ($error): ?>
        <div class="auth-alert auth-alert-error" style="display:block;"><?= e($error) ?></div>
      <?php endif; ?>

      <form method="post">
        <div class="form-group">
          <label>Full Name</label>
          <input type="text" name="full_name" id="reg-name" placeholder="XXX X. XXX" required />
        </div>
        <div class="form-group">
          <label>Email Address</label>
          <input type="email" name="email" id="reg-email" placeholder="xxxx@email.com" required />
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
            I agree to the <a href="#" style="color:#1d4ed8;text-decoration:none;">Terms of Service</a> and <a href="#" style="color:#1d4ed8;text-decoration:none;">Privacy Policy</a>
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

