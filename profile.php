<?php
declare(strict_types=1);
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/layout.php';
require_once __DIR__ . '/lib/mailer.php';

$user = require_login();
$pdo  = db();
$flash = flash_get();

$st = $pdo->prepare('SELECT id, full_name, email, phone, role, email_verified FROM users WHERE id = ?');
$st->execute([(int)$user['id']]);
$profile = $st->fetch();
if (!$profile) {
    flash_set('error', 'User not found.');
    redirect(($user['role'] ?? '') === 'admin' ? 'admin/admin-dashboard.php' : (($user['role'] ?? '') === 'staff' ? 'staff/dashboard.php' : 'customer/dashboard.php'));
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

    // Send verification code
    if ($action === 'send_verify_code') {
        if ((int)($user['email_verified'] ?? 0) === 1) {
            flash_set('success', 'Your email is already verified.');
            redirect('profile.php');
        }
        $code    = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $expires = date('Y-m-d H:i:s', time() + 900); // 15 min
        $pdo->prepare('UPDATE users SET email_verify_token = ?, email_verify_expires = ? WHERE id = ?')
            ->execute([$code, $expires, (int)$user['id']]);
        $sent = send_verification_email((string)$user['email'], (string)$user['full_name'], $code);
        if ($sent) {
            flash_set('success', 'Verification code sent to ' . $user['email'] . '. Enter it below — expires in 15 minutes.');
        } else {
            flash_set('error', 'Could not send email. Check logs/email_failures.log for details.');
        }
        redirect('profile.php#verify');
    }

    // Submit verification code
    if ($action === 'verify_email') {
        $inputCode = trim((string)($_POST['verify_code'] ?? ''));
        $st = $pdo->prepare('SELECT email_verify_token, email_verify_expires FROM users WHERE id = ?');
        $st->execute([(int)$user['id']]);
        $row = $st->fetch();
        if (!$row || $row['email_verify_token'] === null) {
            flash_set('error', 'No verification code found. Please request a new one.');
            redirect('profile.php#verify');
        }
        if (new DateTime() > new DateTime((string)$row['email_verify_expires'])) {
            flash_set('error', 'Verification code has expired. Please request a new one.');
            redirect('profile.php#verify');
        }
        if (!hash_equals((string)$row['email_verify_token'], $inputCode)) {
            flash_set('error', 'Incorrect code. Please try again.');
            redirect('profile.php#verify');
        }
        $pdo->prepare('UPDATE users SET email_verified = 1, email_verify_token = NULL, email_verify_expires = NULL WHERE id = ?')
            ->execute([(int)$user['id']]);
        $_SESSION['user']['email_verified'] = 1;
        flash_set('success', '✅ Email verified! You will now receive payment reminder notifications.');
        redirect('profile.php');
    }
}

$flash   = flash_get();
$isAdmin = ($user['role'] ?? '') === 'admin';
$isStaff = ($user['role'] ?? '') === 'staff';
$dashUrl = $isAdmin ? 'admin/admin-dashboard.php' : ($isStaff ? 'staff/dashboard.php' : 'customer/dashboard.php');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" /><meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Profile - AmusePark</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="css/style.css" />
  <style>
    :root { --primary: #1e3a8a; --primary-dark: #172554; --dark: #0f172a; }
    body { background: #f1f5f9; color: #0f172a; font-family: 'Poppins', sans-serif; }
    .profile-wrap { max-width: 900px; margin: 0 auto; padding: 2.5rem 1.5rem 5rem; }

    .profile-header-card {
      background: linear-gradient(135deg, #0f172a 0%, #1e3a8a 60%, #1d4ed8 100%);
      border-radius: 1.5rem; padding: 2.5rem; color: #fff;
      display: flex; align-items: center; gap: 2rem; margin-bottom: 2rem;
      box-shadow: 0 10px 30px rgba(15,23,42,0.25);
    }
    .profile-avatar-wrap {
      width: 100px; height: 100px; border-radius: 1.25rem;
      background: rgba(255,255,255,0.12); border: 3px solid rgba(255,255,255,0.25);
      display: flex; align-items: center; justify-content: center;
      font-size: 3rem; overflow: hidden; flex-shrink: 0;
    }
    .profile-avatar-wrap img { width: 100%; height: 100%; object-fit: cover; }
    .profile-header-info h1 { font-size: 1.75rem; font-weight: 800; margin: 0 0 .3rem; color: #fff; }
    .profile-header-info .email { font-size: .95rem; color: rgba(255,255,255,0.75); margin-bottom: .75rem; }
    .role-badge {
      display: inline-block; background: #fbbf24; color: #000;
      padding: .25rem .9rem; border-radius: 999px; font-size: .75rem;
      font-weight: 800; text-transform: uppercase; letter-spacing: .05em;
    }
    .member-since { font-size: .82rem; color: rgba(255,255,255,0.6); margin-top: .5rem; font-weight: 600; }

    .profile-grid { display: grid; grid-template-columns: 1fr 340px; gap: 1.5rem; }

    .profile-card {
      background: #fff; border-radius: 1.25rem; padding: 2rem;
      border: 1px solid #e2e8f0; margin-bottom: 1.5rem;
      box-shadow: 0 2px 8px rgba(0,0,0,0.05);
    }
    .profile-card h2 {
      font-size: 1rem; font-weight: 800; color: #0f172a;
      margin: 0 0 1.5rem; display: flex; align-items: center; gap: .6rem;
      padding-bottom: .85rem; border-bottom: 1px solid #f1f5f9;
    }

    .form-group { margin-bottom: 1.25rem; }
    .form-group label {
      display: block; font-size: .8rem; font-weight: 700; color: #64748b;
      text-transform: uppercase; letter-spacing: .05em; margin-bottom: .45rem;
    }
    .form-group input {
      width: 100%; background: #f8fafc; border: 1.5px solid #e2e8f0;
      color: #0f172a; border-radius: .75rem; padding: .75rem 1rem;
      font-size: .95rem; transition: border-color .2s;
    }
    .form-group input:focus { outline: none; border-color: #1e3a8a; background: #fff; }
    .form-group input:disabled { color: #94a3b8; cursor: not-allowed; }
    .form-group small { display: block; font-size: .75rem; color: #94a3b8; margin-top: .35rem; }

    .btn-save {
      width: 100%; background: #1e3a8a; color: #fff;
      border-radius: .75rem; padding: .85rem; font-weight: 800; font-size: .95rem;
      border: none; cursor: pointer; transition: background .2s;
    }
    .btn-save:hover { background: #172554; }

    .btn-secondary-outline {
      display: block; width: 100%; text-align: center; text-decoration: none;
      padding: .85rem; border-radius: .75rem; border: 1.5px solid #e2e8f0;
      color: #475569; font-weight: 700; font-size: .9rem; transition: all .2s;
    }
    .btn-secondary-outline:hover { border-color: #1e3a8a; color: #1e3a8a; background: #eff6ff; }

    @media (max-width: 768px) {
      .profile-header-card { flex-direction: column; text-align: center; padding: 2rem 1.5rem; }
      .profile-grid { grid-template-columns: 1fr; }
    }
  </style>
</head>
<body>
<?php
render_nav($user, 'profile');
?>

<div class="profile-wrap">
  
  <?php if ($flash && ($flash['message'] ?? '') !== ''): ?>
    <div style="padding:1.25rem 1.5rem;border-radius:1rem;margin-bottom:2rem;font-weight:700;display:flex;align-items:center;gap:.75rem;
      background:<?= ($flash['type']??'')!=='error'?'#dcfce7':'#fee2e2' ?>;
      border:1px solid <?= ($flash['type']??'')!=='error'?'#86efac':'#fca5a5' ?>;
      color:<?= ($flash['type']??'')!=='error'?'#166534':'#991b1b' ?>;">
      <span><?= ($flash['type']??'')!=='error'?'✅':'🚫' ?></span>
      <?= e($flash['message']) ?>
    </div>
  <?php endif; ?>

    <div class="profile-header-card">
    <div class="profile-avatar-wrap">
      <?php if (!empty($user['photo_url'])): ?>
        <img src="<?= e($user['photo_url']) ?>" alt="Profile">
      <?php else: ?>
        👤
      <?php endif; ?>
    </div>
    <div class="profile-header-info">
      <span class="role-badge"><?= e($user['role'] ?? 'Member') ?></span>
      <h1><?= e($user['full_name'] ?? 'User Profile') ?></h1>
      <div class="email"><?= e($user['email'] ?? '') ?></div>
      <div class="member-since">📅 Member since <?= date('M Y', strtotime($user['created_at'] ?? 'now')) ?></div>
      <?php if ((int)($user['email_verified'] ?? 0) === 1): ?>
        <div style="margin-top:.5rem;display:inline-flex;align-items:center;gap:.4rem;background:rgba(34,197,94,0.15);border:1px solid rgba(34,197,94,0.4);border-radius:999px;padding:.25rem .85rem;font-size:.78rem;font-weight:700;color:#86efac;">
          ✅ Email Verified
        </div>
      <?php else: ?>
        <div style="margin-top:.5rem;display:inline-flex;align-items:center;gap:.4rem;background:rgba(251,191,36,0.15);border:1px solid rgba(251,191,36,0.4);border-radius:999px;padding:.25rem .85rem;font-size:.78rem;font-weight:700;color:#fbbf24;">
          ⚠ Email Not Verified
        </div>
      <?php endif; ?>
    </div>
  </div>

  <div class="profile-grid">
    <div class="left-col">
      <div class="profile-card">
        <h2>👤 Personal Details</h2>
        <form method="post">
          <input type="hidden" name="action" value="update_profile" />
          <div class="form-group">
            <label>Full Name</label>
            <input type="text" name="full_name" value="<?= e($user['full_name'] ?? '') ?>" required placeholder="Enter your full name" />
          </div>
          <div class="form-group">
            <label>Email Address</label>
            <input type="email" value="<?= e($user['email'] ?? '') ?>" disabled style="background:#f8fafc;color:#94a3b8;border-style:dashed;" />
            <small style="color:#94a3b8;font-size:.75rem;margin-top:.5rem;display:block;">Email address is used for login and cannot be changed.</small>
          </div>
          <div class="form-group">
            <label>Phone Number</label>
            <input type="text" name="phone" value="<?= e($user['phone'] ?? '') ?>" placeholder="+63 9XX XXX XXXX" />
          </div>
          <button type="submit" class="btn-save">Update Profile</button>
        </form>
      </div>
    </div>

    <div class="right-col">
      <div class="profile-card">
        <h2>🔒 Security</h2>
        <form method="post">
          <input type="hidden" name="action" value="change_password" />
          <div class="form-group">
            <label>Current Password</label>
            <input type="password" name="current_password" placeholder="••••••••" autocomplete="current-password" />
          </div>
          <div class="form-group">
            <label>New Password</label>
            <input type="password" name="new_password" placeholder="Min. 8 characters" autocomplete="new-password" minlength="8" />
          </div>
          <div class="form-group">
            <label>Confirm New Password</label>
            <input type="password" name="confirm_password" placeholder="Repeat new password" autocomplete="new-password" />
          </div>
          <button type="submit" class="btn-secondary-outline">Change Password</button>
        </form>
      </div>

      <div style="margin-top:1rem;">
        <?php if ((int)($user['email_verified'] ?? 0) === 1): ?>
          <div class="profile-card" style="border-color:#86efac;background:#f0fdf4;">
            <h2 style="color:#166534;">✅ Email Verified</h2>
            <p style="font-size:.88rem;color:#166534;margin:0;">Your email is verified. You will receive payment reminder notifications if you leave a booking incomplete.</p>
          </div>
        <?php else: ?>
          <div id="verify" class="profile-card" style="border-color:#fde68a;background:#fffbeb;">
            <h2 style="color:#92400e;">📧 Verify Your Email</h2>
            <p style="font-size:.88rem;color:#78350f;margin:0 0 1.25rem;line-height:1.6;">
              Verify your email to receive a reminder if you leave a payment incomplete.
            </p>
            <form method="post" style="margin-bottom:1rem;">
              <input type="hidden" name="action" value="send_verify_code" />
              <button type="submit" class="btn-save" style="background:#d97706;">📨 Send Verification Code</button>
            </form>
            <form method="post">
              <input type="hidden" name="action" value="verify_email" />
              <div class="form-group">
                <label>Enter 6-Digit Code</label>
                <input type="text" name="verify_code" maxlength="6" placeholder="e.g. 482910"
                       style="letter-spacing:.3em;font-size:1.2rem;font-weight:700;text-align:center;" />
                <small>Check your inbox at <?= e($user['email'] ?? '') ?></small>
              </div>
              <button type="submit" class="btn-save">Verify Email</button>
            </form>
          </div>
        <?php endif; ?>
      </div>

      <div style="margin-top:1rem;">
        <a href="<?= $dashUrl ?>" class="btn-secondary-outline">← Back to Dashboard</a>
      </div>
    </div>
  </div>

</div>

<?php render_footer(); ?>
</body>
</html>
