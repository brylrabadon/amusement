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
    <li><a href="<?= $root ?>admin/scanner.php"         <?= $active==='scanner'  ?'class="active"':'' ?>>Scanner</a></li>
    <li><a href="<?= $root ?>profile.php"               <?= $active==='profile'  ?'class="active"':'' ?>>Profile</a></li>
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
    <li><a href="<?= $root ?>index.php"       <?= $active==='home'     ?'class="active"':'' ?>>Home</a></li>
    <li><a href="<?= $root ?>rides.php"       <?= $active==='rides'    ?'class="active"':'' ?>>Rides</a></li>
    <li><a href="<?= $root ?>contact.php"     <?= $active==='contact'  ?'class="active"':'' ?>>Contact</a></li>
    <?php if ($user): ?>
      <li><a href="<?= $root ?>my-bookings.php" <?= $active==='bookings'?'class="active"':'' ?>>My Bookings</a></li>
      <li><a href="<?= $root ?>profile.php"     <?= $active==='profile' ?'class="active"':'' ?>>Profile</a></li>
      <li><a href="<?= $root ?>logout.php" style="color:#dc2626;font-weight:700;">Logout</a></li>
    <?php else: ?>
      <li><a href="<?= $root ?>login.php"    <?= $active==='login'    ?'class="active"':'' ?>>Login</a></li>
      <li><a href="<?= $root ?>register.php" <?= $active==='register' ?'class="active"':'' ?>>Register</a></li>
    <?php endif; ?>
    <li><a href="<?= $root ?>tickets.php" class="nav-cta <?= $active==='tickets'?'active':'' ?>">🎟 Buy Tickets</a></li>
  </ul>
</nav>
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
    <img src="<?= $root ?>hero.png.jpg" alt="AmusePark" style="height:44px;width:44px;border-radius:50%;object-fit:cover;margin-right:.5rem;vertical-align:middle;">
    Amuse<span>Park</span>
  </div>
  <p>© 2026 AmusePark Philippines. All rights reserved.</p>
</footer>
<?php }

