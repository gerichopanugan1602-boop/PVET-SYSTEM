<?php
/**
 * Opens the HTML document, renders the sidebar and opens <main>.
 * Closed by includes/footer.php.
 *
 * Expects: $pageTitle (string), $activePage ('home' | 'assessment' | 'records' | 'dashboard' | 'export' | 'login')
 */

require_once __DIR__ . '/auth.php';   // starts the session + is_admin()

$pageTitle  = $pageTitle  ?? 'Swine Farmer Service Recommender System';
$activePage = $activePage ?? '';
$isAdmin    = is_admin();

// Icons are inline SVG (24x24, stroke style) so no extra library is needed.
// 'admin' => true  : shown only when logged in as admin.
// 'public' => true : shown only to visitors who are NOT logged in.
$navItems = [
    'home' => [
        'href'   => 'home.php',
        'label'  => 'Home',
        'public' => true,
        'icon'   => '<path d="M3 10.5 12 3l9 7.5"/><path d="M5 9.5V20h5v-6h4v6h5V9.5"/>',
    ],
    'assessment' => [
        'href'   => 'assessment.php',
        'label'  => 'Assessment',
        'public' => true,
        'icon'   => '<rect x="5" y="4" width="14" height="17" rx="2"/><path d="M9 2.5h6v3H9z"/><path d="m9 13.5 2.2 2.2L15.5 11"/>',
    ],
    'guide' => [
        'href'   => 'guide.php',
        'label'  => 'Health Guide',
        'public' => true,
        'icon'   => '<path d="M4 5.5A1.5 1.5 0 0 1 5.5 4H11v16H5.5A1.5 1.5 0 0 1 4 18.5z"/><path d="M20 5.5A1.5 1.5 0 0 0 18.5 4H13v16h5.5a1.5 1.5 0 0 0 1.5-1.5z"/>',
    ],
    'dashboard' => [
        'href'  => 'dashboard.php',
        'label' => 'Dashboard',
        'admin' => true,
        'icon'  => '<path d="M4 20V10"/><path d="M10 20V4"/><path d="M16 20v-7"/><path d="M21 20H3"/>',
    ],
    'records' => [
        'href'  => 'records.php',
        'label' => 'Records',
        'admin' => true,
        'icon'  => '<path d="M8 6h13"/><path d="M8 12h13"/><path d="M8 18h13"/><path d="M3.5 6h.01"/><path d="M3.5 12h.01"/><path d="M3.5 18h.01"/>',
    ],
    'export' => [
        'href'  => 'export.php',
        'label' => 'Export',
        'admin' => true,
        'icon'  => '<path d="M12 3v12"/><path d="m7 11 5 5 5-5"/><path d="M4 20h16"/>',
    ],
];

// Logged out: Home + Assessment only.  Logged in: Dashboard + Records + Export only.
$navItems = array_filter(
    $navItems,
    static fn(array $i): bool => $isAdmin ? !empty($i['admin']) : !empty($i['public'])
);

// The logo / mobile title goes to the dashboard for admins, to home for visitors.
$homeHref = $isAdmin ? 'dashboard.php' : 'home.php';

// Cache-busting so the browser loads the latest shared stylesheets.
$styleCssVer = is_file(__DIR__ . '/../assets/css/style.css') ? filemtime(__DIR__ . '/../assets/css/style.css') : 1;
$sidebarCssVer = is_file(__DIR__ . '/../assets/css/sidebar.css') ? filemtime(__DIR__ . '/../assets/css/sidebar.css') : 1;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($pageTitle) ?> | Swine Farmer Service Recommender System</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&family=Newsreader:opsz,wght@6..72,400;6..72,500;6..72,600&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/css/style.css?v=<?= (int) $styleCssVer ?>" rel="stylesheet">
    <link href="assets/css/sidebar.css?v=<?= (int) $sidebarCssVer ?>" rel="stylesheet">
    <script src="assets/js/sidebar.js" defer></script>
</head>
<body class="page-<?= e($activePage) ?>">
<script>
    /* Restore the saved sidebar state before the page paints (prevents a flash). */
    try { if (localStorage.getItem('sidebarCollapsed') === '1') document.body.classList.add('sidebar-collapsed'); } catch (e) {}
</script>

<header class="mobile-bar">
    <a class="mobile-brand" href="<?= e($homeHref) ?>">Swine Farmer Services</a>
    <button class="menu-toggle" type="button" id="menuToggle" aria-label="Toggle menu" aria-controls="sidebar" aria-expanded="false">
        <span class="menu-icon" aria-hidden="true"></span>
    </button>
</header>

<aside class="sidebar" id="sidebar" aria-label="Main navigation">
    <button class="sidebar-collapse" type="button" id="sidebarCollapse"
            aria-controls="sidebar" aria-expanded="true" aria-label="Collapse sidebar" title="Collapse sidebar">
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m15 6-6 6 6 6"/></svg>
    </button>

    <a class="brand" href="<?= e($homeHref) ?>" aria-label="PVET Swine Farmer Services - Home">
        <img class="brand-logo" src="assets/img/logo.png" alt="PVET logo" width="200" height="200">
    </a>

    <nav class="side-nav">
        <ul>
            <?php foreach ($navItems as $key => $item): ?>
                <li>
                    <a href="<?= e($item['href']) ?>"
                       class="<?= $activePage === $key ? 'is-active' : '' ?>"
                       data-label="<?= e($item['label']) ?>"
                       <?= $activePage === $key ? 'aria-current="page"' : '' ?>>
                        <span class="nav-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><?= $item['icon'] ?></svg></span>
                        <span class="nav-text"><?= e($item['label']) ?></span>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
    </nav>

    <!-- Simple login / logout link pinned to the bottom of the sidebar -->
    <div class="side-auth">
        <?php if ($isAdmin): ?>
            <div class="side-user">Signed in as <strong><?= e(admin_name()) ?></strong></div>
            <a class="auth-link" href="logout.php" title="Log out">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 4H5a1 1 0 0 0-1 1v14a1 1 0 0 0 1 1h4"/><path d="m16 8 4 4-4 4"/><path d="M20 12H9"/></svg>
                <span class="auth-text">Log out</span>
            </a>
        <?php else: ?>
            <a class="auth-link" href="login.php" title="Admin login">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M15 4h4a1 1 0 0 1 1 1v14a1 1 0 0 1-1 1h-4"/><path d="m10 8-4 4 4 4"/><path d="M6 12h11"/></svg>
                <span class="auth-text">Admin login</span>
            </a>
        <?php endif; ?>
    </div>

</aside>
<div class="nav-backdrop" id="navBackdrop"></div>

<main class="main">
