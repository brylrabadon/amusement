<?php
declare(strict_types=1);
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/layout.php';

$user = require_login();
$pdo  = db();
$flash = flash_get();

$st = $pdo->prepare('SELECT id, full_name, email, phone, role FROM users WHERE id = ?');
$st->execute([(int)$user['id']]);
$profile = $st->fetch();
if (!$profile) {
    flash_set('error', 'User not found.');
    redirect(($user['role'] ?? '') === 'admin' ? 'admin/admin-dashboard.php' : 'customer/dashboard.php');
}
$user = array_merge($user, $profile);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'update_profile') {
        $fullName = trim((string)($_POST['full_name'] ?? ''));
        $phone    = trim((string)($_POST['phone'] ?? ''));
        if ($fullName === '') { flash_set('error', 'Full name is required.'); redirect('profile.php'); }
        $pdo->prepare('UPDATE users SET full_name = ?, phone = ? WHERE id = ?')
            ->execute([$fullName, $phone, (int)$user['id']]);
        $_SESSION['user']['full_name'] = $fullName;
        $_SESSION['user']['phone']     = $phone;
        flash_set('success', 'Profile updated.');
        redirect('profile.php');
    }

    if ($action === 'change_password') {
        $current = (string)($_POST['current_password'] ?? '');
        $newPass = (string)($_POST['new_password'] ?? '');
        $confirm = (string)($_POST['confirm_password'] ?? '');
        if ($current === '' || $newPass === '' || $confirm === '') { flash_set('error', 'Please fill in all password fields.'); redirect('profile.php'); }
        if (strlen($newPass) < 8) { flash_set('error', 'New password must be at least 8 characters.'); redirect('profile.php'); }
        if ($newPass !== $confirm) { flash_set('error', 'New passwords do not match.'); redirect('profile.php'); }
        $st = $pdo->prepare('SELECT password_hash FROM users WHERE id = ?');
        $st->execute([(int)$user['id']]);
        $row = $st->fetch();
        if (!$row || !password_verify($current, (string)$row['password_hash'])) {
            flash_set('error', 'Current password is incorrect.'); redirect('profile.php');
        }
        $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
            ->execute([password_hash($newPass, PASSWORD_BCRYPT), (int)$user['id']]);
        flash_set('success', 'Password changed successfully.');
        redirect('profile.php');
    }
}

$flash   = flash_get();
$isAdmin = ($user['role'] ?? '') === 'admin';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" /><meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Profile - AmusePark</title>
  <link rel="stylesheet" href="css/style.css" />
  <style>
    body { background: #f9fafb; }
    .profile-card { background: #fff; border-radius: 1.25rem; padding: 2rem; margin-bottom: 1.5rem; box-shadow: 0 2px 12px rgba(0,0,0,.06); }
    .profile-card h2 { font-size: 1.15rem; font-weight: 800; color: #111827; margin-bottom: 1.25rem; }
  </style>
</head>
<body>
<<<<<<< HEAD
<?php
// For admin, render_nav expects paths relative to root — profile.php is at root so pass $user directly
render_nav($user, 'profile');
?>
<?php render_page_header($isAdmin ? 'Admin Profile' : 'My Profile', $isAdmin ? 'Manage your admin account' : 'Your account details'); ?>
=======
<?php if ($isAdmin): ?>
<nav class="admin-nav">
  <a class="logo" href="index.php">Amuse<span>Park</span></a>
  <ul>
    <li><a href="admin/admin-dashboard.php">Dashboard</a></li>
    <li><a href="admin/rides.php">Rides</a></li>
    <li><a href="admin/bookings.php">Bookings</a></li>
    <li><a href="admin/ticket-types.php">Ticket Types</a></li>
    <li><a href="admin/scanner.php">Scanner</a></li>
    <li><a href="profile.php" class="active">Profile</a></li>
    <li><a href="logout.php" style="color:#dc2626;font-weight:600;">Logout</a></li>
  </ul>
</nav>
<?php else: ?>
<nav>
  <a class="logo" href="index.php">Amuse<span>Park</span></a>
  <ul>
    <li><a href="rides.php">Rides</a></li>
    <li><a href="tickets.php">Buy Tickets</a></li>
    <li><a href="my-bookings.php">My Bookings</a></li>
    <li><a href="profile.php" class="active">Profile</a></li>
    <li><a href="logout.php" style="color:#dc2626;font-weight:600;">Logout</a></li>
  </ul>
</nav>
<?php endif; ?>

<div class="page-header">
  <h1><?= $isAdmin ? 'Admin' : 'My' ?> Profile</h1>
  <p><?= $isAdmin ? 'Manage your admin account' : 'Your account details' ?></p>
</div>
>>>>>>> 944246f7d1f7012ed1c7107d999e7fdfb8af41b5

<div class="container" style="max-width:560px;">
  <?php if ($flash && ($flash['message'] ?? '') !== ''): ?>
    <div style="padding:1rem 1.25rem;border-radius:.75rem;margin-bottom:1.25rem;font-weight:600;
      background:<?= ($flash['type']??'')!=='error'?'#dcfce7':'#fee2e2' ?>;
      border:1px solid <?= ($flash['type']??'')!=='error'?'#86efac':'#fca5a5' ?>;
      color:<?= ($flash['type']??'')!=='error'?'#166534':'#991b1b' ?>;">
      <?= e($flash['message']) ?>
    </div>
  <?php endif; ?>

  <div class="profile-card">
    <h2>Account Info</h2>
    <form method="post">
      <input type="hidden" name="action" value="update_profile" />
      <div class="form-group">
        <label>Full Name</label>
        <input type="text" name="full_name" value="<?= e($user['full_name'] ?? '') ?>" required />
      </div>
      <div class="form-group">
        <label>Email</label>
        <input type="email" value="<?= e($user['email'] ?? '') ?>" disabled style="background:#f3f4f6;color:#9ca3af;" />
        <small style="color:#9ca3af;font-size:.78rem;">Email cannot be changed here.</small>
      </div>
      <div class="form-group">
        <label>Phone</label>
        <input type="text" name="phone" value="<?= e($user['phone'] ?? '') ?>" placeholder="+63 9XX XXX XXXX" />
      </div>
      <div class="form-group">
        <label>Role</label>
        <div><span class="badge <?= $isAdmin ? 'badge-yellow' : 'badge-blue' ?>"><?= e(ucfirst($user['role'] ?? 'customer')) ?></span></div>
      </div>
      <button type="submit" class="btn btn-primary">Save Profile</button>
    </form>
  </div>

  <div class="profile-card">
    <h2>Change Password</h2>
    <form method="post">
      <input type="hidden" name="action" value="change_password" />
      <div class="form-group">
        <label>Current Password</label>
        <input type="password" name="current_password" placeholder="Enter current password" autocomplete="current-password" />
      </div>
      <div class="form-group">
        <label>New Password</label>
        <input type="password" name="new_password" placeholder="At least 8 characters" autocomplete="new-password" minlength="8" />
      </div>
      <div class="form-group">
        <label>Confirm New Password</label>
        <input type="password" name="confirm_password" placeholder="Repeat new password" autocomplete="new-password" />
      </div>
      <button type="submit" class="btn btn-outline">Change Password</button>
    </form>
  </div>

  <p>
    <a href="<?= $isAdmin ? 'admin/admin-dashboard.php' : 'customer/dashboard.php' ?>" class="btn btn-outline">← Back to Dashboard</a>
  </p>
</div>

<?php render_footer(); ?>
</body>
</html>
