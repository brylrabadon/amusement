<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/auth.php';

// If already logged in, go to the right dashboard.
$u = current_user();
if ($u) {
    if (($u['role'] ?? '') === 'admin') redirect('admin/admin-dashboard.php');
    redirect('customer/dashboard.php');
}

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $result = auth_login($_POST['email'] ?? '', $_POST['password'] ?? '');
    if (($result['success'] ?? false) === true) {
        $user = $result['user'];
        flash_set('success', 'Login successful!');
        if (($user['role'] ?? '') === 'admin') redirect('admin/admin-dashboard.php');
        redirect('customer/dashboard.php');
    } else {
        $error = $result['message'] ?? 'Invalid email or password.';
    }
}

$flash = flash_get();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Login - AmusePark</title>
  <link rel="stylesheet" href="css/style.css" />
</head>
<body>

<nav>
  <a class="logo" href="index.php">Amuse<span>Park</span></a>
  <ul>
    <li><a href="index.php">Home</a></li>
    <li><a href="contact.php">Contact</a></li>
    <li><a href="login.php" class="btn btn-yellow">Login</a></li>
  </ul>
</nav>

<div class="auth-page">
  <div class="auth-left">
    <img src="https://images.unsplash.com/photo-1563656157432-67560011e209?w=800&q=80" alt="AmusePark" />
    <div class="auth-left-overlay">
      <div class="hero-tag" style="margin-bottom:1.5rem;">⭐ Philippines' #1 Amusement Park</div>
      <h2 style="font-size:2.5rem;font-weight:900;line-height:1.2;margin-bottom:1rem;">
        LOOKING FOR<br/>ADVENTURE?
      </h2>
      <p style="color:rgba(255,255,255,.8);font-size:1rem;">Ride. Laugh. Scream. Repeat.</p>
    </div>
  </div>

  <div class="auth-right">
    <div class="auth-card">
      <div style="text-align:center;margin-bottom:2rem;">
        <a class="logo" href="index.php" style="font-size:1.8rem;">Amuse<span>Park</span></a>
        <h2 style="font-size:1.5rem;font-weight:800;margin-top:1rem;margin-bottom:.25rem;">Welcome Back!</h2>
        <p style="color:#64748b;font-size:.9rem;">Log in to manage your bookings</p>
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
          <label>Email Address</label>
          <input type="email" name="email" placeholder="xxxx@email.com" required />
        </div>
        <div class="form-group">
          <label>Password</label>
          <input type="password" name="password" placeholder="Enter your password" required />
        </div>
        <button type="submit" class="btn btn-primary btn-full" style="font-size:1rem;padding:.85rem;">
          Log In
        </button>
      </form>

      <div style="text-align:center;margin-top:1.5rem;font-size:.9rem;color:#64748b;">
        Don't have an account? <a href="register.php" style="color:#1d4ed8;font-weight:600;text-decoration:none;">Sign Up</a>
      </div>
    </div>
  </div>
</div>
</body>
</html>

