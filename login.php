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
        // Honour ?next= redirect (e.g. from tickets.php checkout)
        $next = trim((string)($_GET['next'] ?? $_POST['next'] ?? ''));
        if ($next !== '' && !str_contains($next, '//') && !str_starts_with($next, 'http')) {
            redirect($next);
        }
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
  <style>
    /* Custom overrides to remove blue overlay and fix background */
    .auth-left {
        position: relative;
        background-color: #000; /* Fallback */
        overflow: hidden;
    }

    .auth-left img {
        width: 100%;
        height: 100%;
        object-fit: cover;
        display: block;
    }

    .auth-left-overlay {
        /* REMOVED BLUE TINT: Using a neutral dark gradient only at the bottom for text readability */
        background: linear-gradient(to top, rgba(0,0,0,0.8) 0%, rgba(0,0,0,0) 50%) !important;
        display: flex;
        flex-direction: column;
        justify-content: flex-end;
        padding: 3rem !important;
    }

    .auth-left-overlay h2, .auth-left-overlay p {
        text-shadow: 2px 2px 10px rgba(0,0,0,0.5);
    }
  </style>
</head>
<body>

<nav>
  <a class="logo" href="index.php">Amuse<span>Park</span></a>
  <ul>
    <li><a href="index.php">Home</a></li>
    <li><a href="rides.php">Rides</a></li>
    <li><a href="tickets.php">Tickets</a></li>
    <li><a href="contact.php">Contact</a></li>
    <li><a href="login.php" class="btn btn-yellow">Login</a></li>
  </ul>
</nav>

<div class="auth-page">
  <div class="auth-left">
    <img src="https://images.unsplash.com/photo-1513889961551-628c1e5e2ee9?q=80&w=2070" alt="AmusePark Adventure" />
    
    <div class="auth-left-overlay">
      <div class="hero-tag" style="margin-bottom:1.5rem; background: #fbbf24; color: #000; padding: 0.4rem 1rem; border-radius: 2rem; display: inline-block; width: fit-content; font-weight: 700; font-size: 0.8rem;">⭐ Philippines' #1 Amusement Park</div>
      <h2 style="font-size:3rem; font-weight:900; line-height:1.1; margin-bottom:1rem; color: #fff;">
        READY FOR THE<br/>THRILL?
      </h2>
      <p style="color:rgba(255,255,255,0.9); font-size:1.1rem; margin-bottom: 2rem;">Experience the magic of AmusePark.</p>
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
        <div class="auth-alert auth-alert-success" style="display:block; background: #dcfce7; color: #166534; padding: 1rem; border-radius: 0.5rem; margin-bottom: 1rem;">
          <?= e($flash['message'] ?? '') ?>
        </div>
      <?php endif; ?>

      <?php if ($error || ($flash && ($flash['type'] ?? '') === 'error')): ?>
        <div class="auth-alert auth-alert-error" style="display:block; background: #fee2e2; color: #991b1b; padding: 1rem; border-radius: 0.5rem; margin-bottom: 1rem;">
          <?= e($error ?: $flash['message']) ?>
        </div>
      <?php endif; ?>

      <form method="post">
        <?php if (!empty($_GET['next'])): ?>
          <input type="hidden" name="next" value="<?= e($_GET['next']) ?>" />
        <?php endif; ?>
        <div class="form-group" style="margin-bottom: 1.25rem;">
          <label style="display:block; margin-bottom: 0.5rem; font-weight: 600; color: #475569;">Email Address</label>
          <input type="email" name="email" placeholder="xxxx@email.com" required style="width:100%; padding: 0.75rem; border: 1px solid #e2e8f0; border-radius: 0.5rem;" />
        </div>
        <div class="form-group" style="margin-bottom: 1.5rem;">
          <label style="display:block; margin-bottom: 0.5rem; font-weight: 600; color: #475569;">Password</label>
          <input type="password" name="password" placeholder="Enter your password" required style="width:100%; padding: 0.75rem; border: 1px solid #e2e8f0; border-radius: 0.5rem;" />
        </div>
        <button type="submit" class="btn btn-primary btn-full" style="font-size:1rem; padding:.85rem; width: 100%; background: #1d4ed8; color: #fff; border: none; border-radius: 0.5rem; cursor: pointer; font-weight: 700;">
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