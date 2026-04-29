<?php
/**
 * Shared layout helpers — nav, page-header, footer
 * Usage:
 *   render_nav($user, $activePage)   — sticky top nav
 *   render_page_header($title, $sub) — purple gradient banner
 *   render_footer()                  — dark footer
 */

function render_nav($user, string $active = ''): void {
    $isAdmin = ($user['role'] ?? '') === 'admin';
    $isStaff = ($user['role'] ?? '') === 'staff';
    // Detect how many subfolder levels deep we are relative to the app root.
    // e.g. /amusement/index.php        → dir = /amusement   → strip app base → '' → depth 0
    //      /amusement/admin/rides.php  → dir = /amusement/admin → strip → admin → depth 1
    $scriptPath = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
    $scriptDir  = trim(dirname($scriptPath), '/'); // e.g. 'amusement' or 'amusement/admin'

    // Find the app base dir (where config.php lives) relative to doc root
    $docRoot = str_replace('\\', '/', (string)realpath((string)($_SERVER['DOCUMENT_ROOT'] ?? '')));
    $appRoot = str_replace('\\', '/', (string)realpath(__DIR__ . '/..'));
    $appBase = '';
    if ($docRoot !== '' && stripos($appRoot, $docRoot) === 0) {
        $appBase = trim(substr($appRoot, strlen($docRoot)), '/'); // e.g. 'amusement'
    }

    // Strip the app base from scriptDir to get the relative subfolder
    $relDir = $appBase !== '' && stripos($scriptDir, $appBase) === 0
        ? trim(substr($scriptDir, strlen($appBase)), '/')
        : $scriptDir;

    // depth = number of subfolder levels inside the app (0 = app root, 1 = admin/ or customer/)
    $depth = ($relDir === '') ? 0 : substr_count($relDir, '/') + 1;
    $root  = $depth >= 2 ? '../../' : ($depth === 1 ? '../' : '');

    if ($isAdmin): ?>
<nav class="admin-nav">
  <a class="logo" href="<?= $root ?>index.php">
    <img src="<?= $root ?>hero.png.jpg" alt="AmusePark" style="height:36px;width:36px;border-radius:50%;object-fit:cover;flex-shrink:0;">
    Amuse<span>Park</span>
  </a>
  <ul>
    <li><a href="<?= $root ?>admin/admin-dashboard.php" <?= $active==='dashboard'?'class="active"':'' ?>>Dashboard</a></li>
    <li><a href="<?= $root ?>admin/rides.php"           <?= $active==='rides'    ?'class="active"':'' ?>>Rides</a></li>
    <li><a href="<?= $root ?>admin/bookings.php"        <?= $active==='bookings' ?'class="active"':'' ?>>Bookings</a></li>
    <li><a href="<?= $root ?>admin/ticket-types.php"    <?= $active==='tickets'  ?'class="active"':'' ?>>Ticket Types</a></li>
    <li><a href="<?= $root ?>profile.php"               <?= $active==='profile'  ?'class="active"':'' ?>>Profile</a></li>
    <li><a href="<?= $root ?>logout.php" style="color:#f87171;font-weight:700;">Logout</a></li>
  </ul>
</nav>
    <?php elseif ($isStaff): ?>
<nav class="admin-nav staff-nav">
  <a class="logo" href="<?= $root ?>index.php">
    <img src="<?= $root ?>hero.png.jpg" alt="AmusePark" style="height:36px;width:36px;border-radius:50%;object-fit:cover;flex-shrink:0;">
    Amuse<span>Park</span>
  </a>
  <ul>
    <li><a href="<?= $root ?>staff/dashboard.php" <?= $active==='dashboard'?'class="active"':'' ?>>Dashboard</a></li>
    <li><a href="<?= $root ?>staff/scanner.php"   <?= $active==='scanner'  ?'class="active"':'' ?>>Scanner</a></li>
    <li><a href="<?= $root ?>staff/bookings.php"  <?= $active==='bookings' ?'class="active"':'' ?>>Bookings</a></li>
    <li><a href="<?= $root ?>profile.php"         <?= $active==='profile'  ?'class="active"':'' ?>>Profile</a></li>
    <li><a href="<?= $root ?>logout.php" style="color:#f87171;font-weight:700;">Logout</a></li>
  </ul>
</nav>
    <?php else: ?>
<div class="site-top-bar">
  TODAY'S PARK HOURS: <span>🕐 MON – SUN (9:00 AM – 9:00 PM)</span>
</div>
<nav class="site-nav">
  <a class="logo" href="<?= $root ?>index.php">
    <img src="<?= $root ?>hero.png.jpg" alt="AmusePark" style="height:36px;width:36px;border-radius:50%;object-fit:cover;flex-shrink:0;">
    Amuse<span>Park</span>
  </a>
  <ul>
    <?php 
      $homeUrl = $root . 'index.php';
      if ($user && ($user['role'] ?? '') === 'customer') {
          $homeUrl = $root . 'customer/dashboard.php';
      }
    ?>
    <li><a href="<?= $homeUrl ?>"       <?= $active==='home'     ?'class="active"':'' ?>>Home</a></li>    <li><a href="<?= $root ?>rides.php"       <?= $active==='rides'    ?'class="active"':'' ?>>Rides</a></li>
    <li><a href="<?= $root ?>contact.php"     <?= $active==='contact'  ?'class="active"':'' ?>>Contact</a></li>
    <?php if ($user): ?>
      <li><a href="<?= $root ?>my-bookings.php" <?= $active==='bookings'?'class="active"':'' ?>>My Bookings</a></li>
      <li><a href="<?= $root ?>profile.php"     <?= $active==='profile' ?'class="active"':'' ?>>Profile</a></li>
      <li><a href="<?= $root ?>logout.php" style="color:#dc2626;font-weight:700;">Logout</a></li>
    <?php else: ?>
      <li><a href="<?= $root ?>login.php"    <?= $active==='login'    ?'class="active"':'' ?>>Login</a></li>
    <?php endif; ?>
    <li><a href="<?= $root ?>tickets.php" class="nav-cta <?= $active==='tickets'?'active':'' ?>">🎟 Buy Tickets</a></li>
    <li>
      <a href="<?= $root ?>cart.php" style="position:relative;display:inline-flex;align-items:center;gap:.35rem;" <?= $active==='cart'?'class="active"':'' ?>>
        🛒
        <?php
          $cartCount = array_sum($_SESSION['cart'] ?? []);
        ?>
        <span id="cart-nav-badge" style="
          display:<?= $cartCount > 0 ? 'inline-flex' : 'none' ?>;
          align-items:center;justify-content:center;
          background:var(--primary);color:#fff;
          font-size:.65rem;font-weight:800;
          width:18px;height:18px;border-radius:50%;
          position:absolute;top:-6px;right:-8px;
          line-height:1;
        "><?= (int)$cartCount ?></span>
      </a>
    </li>
  </ul>
</nav>
    <?php endif;

    // Auto-logout beacon — fires when user closes tab/browser while logged in
    // Does NOT fire on the payment step (step=2) since user switches to GCash and returns
    if ($user): ?>
    <?php endif;
    // NOTE: Auto-logout on tab close removed — it caused logout on refresh.
    // Sessions expire naturally and no-cache headers prevent back-button access.
    if ($user): ?>
<script>
(function() {
  // Only send abandoned payment notification when leaving step 2 without paying.
  // No auto-logout beacon — it was causing logout on page refresh.
  var _onPaymentPage = (window.location.href.indexOf('step=2') !== -1);
  if (!_onPaymentPage) return;

  var _paymentDone = false;
  document.addEventListener('submit', function() { _paymentDone = true; });
  document.addEventListener('click', function(e) {
    var a = e.target.closest('a');
    if (a && a.href) _paymentDone = true;
  });

  window.addEventListener('beforeunload', function() {
    if (!_paymentDone) {
      var fd = new FormData();
      fd.append('action', 'notify_abandoned');
      navigator.sendBeacon('<?= $root ?>tickets.php', fd);
    }
  });
})();
</script>
    <?php endif;
}

function render_page_header(string $title, string $sub = ''): void { ?>
<div class="page-header">
  <h1><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></h1>
  <?php if ($sub !== ''): ?><p><?= htmlspecialchars($sub, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
</div>
<?php }

function render_footer(): void {
    // Compute root path same way as render_nav
    $scriptPath = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
    $scriptDir  = trim(dirname($scriptPath), '/');
    $docRoot = str_replace('\\', '/', (string)realpath((string)($_SERVER['DOCUMENT_ROOT'] ?? '')));
    $appRoot = str_replace('\\', '/', (string)realpath(__DIR__ . '/..'));
    $appBase = '';
    if ($docRoot !== '' && stripos($appRoot, $docRoot) === 0) {
        $appBase = trim(substr($appRoot, strlen($docRoot)), '/');
    }
    $relDir = $appBase !== '' && stripos($scriptDir, $appBase) === 0
        ? trim(substr($scriptDir, strlen($appBase)), '/')
        : $scriptDir;
    $depth = ($relDir === '') ? 0 : substr_count($relDir, '/') + 1;
    $root  = $depth >= 2 ? '../../' : ($depth === 1 ? '../' : '');
    ?>
<footer class="site-footer">
  <div class="footer-logo">
    <img src="<?= $root ?>hero.png.jpg" alt="AmusePark" style="height:48px;width:48px;border-radius:12px;object-fit:cover;margin-right:.75rem;vertical-align:middle;">
    Amuse<span>Park</span>
  </div>
  <p>© 2026 AmusePark Philippines. All rights reserved.</p>
</footer>
<?php }

