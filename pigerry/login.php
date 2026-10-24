<?php
declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';

$pageTitle  = 'Admin login';
$activePage = 'login';

/* Only these pages may be opened after login (prevents redirect tricks). */
$allowedNext = ['dashboard.php', 'records.php', 'export.php'];
$next = $_GET['next'] ?? $_POST['next'] ?? '';
$next = in_array($next, $allowedNext, true) ? $next : 'dashboard.php';

if (is_admin()) {
    header('Location: ' . $next);
    exit;
}

const LOGIN_MAX_FAILS = 5;
const LOGIN_LOCK_SECS = 300;

$error    = '';
$username = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $lockedUntil = (int) ($_SESSION['login_lock_until'] ?? 0);

    if (!csrf_check($_POST['csrf'] ?? null)) {
        $error = 'Your session expired. Please try again.';
    } elseif ($lockedUntil > time()) {
        $mins  = (int) ceil(($lockedUntil - time()) / 60);
        $error = 'Too many failed attempts. Try again in ' . $mins . ' minute' . ($mins === 1 ? '' : 's') . '.';
    } else {
        $db   = try_db();
        $user = null;
        if ($db) {
            $stmt = $db->prepare('SELECT `id`, `username`, `password_hash` FROM `admins` WHERE `username` = :u LIMIT 1');
            $stmt->execute([':u' => $username]);
            $user = $stmt->fetch() ?: null;
        } else {
            $error = 'The database is not available. Check that MySQL is running in XAMPP.';
        }

        if ($error === '') {
            // Always run password_verify so a wrong username and a wrong password take the same time.
            $hash = $user['password_hash'] ?? '$2y$10$usesomesillystringforsaltuyAbCdEfGhIjKlMnOpQrStUvWxYz012';
            $ok   = password_verify($password, $hash) && $user !== null;

            if ($ok) {
                session_regenerate_id(true);
                unset($_SESSION['login_fails'], $_SESSION['login_lock_until']);
                $_SESSION['admin_id']   = (int) $user['id'];
                $_SESSION['admin_name'] = (string) $user['username'];

                if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
                    $up = $db->prepare('UPDATE `admins` SET `password_hash` = :h WHERE `id` = :id');
                    $up->execute([':h' => password_hash($password, PASSWORD_DEFAULT), ':id' => $user['id']]);
                }
                header('Location: ' . $next);
                exit;
            }

            $_SESSION['login_fails'] = (int) ($_SESSION['login_fails'] ?? 0) + 1;
            if ($_SESSION['login_fails'] >= LOGIN_MAX_FAILS) {
                $_SESSION['login_lock_until'] = time() + LOGIN_LOCK_SECS;
                $_SESSION['login_fails']      = 0;
            }
            $error = 'Incorrect username or password.';
        }
    }
}

$cssVer = is_file(__DIR__ . '/assets/css/login.css') ? filemtime(__DIR__ . '/assets/css/login.css') : 1;

require __DIR__ . '/includes/sidebar.php';
?>
<link rel="stylesheet" href="assets/css/login.css?v=<?= (int) $cssVer ?>">

<section class="page-head">
    <div class="page">
        <h1 class="page-title">Admin login</h1>
        <p class="page-sub">Sign in to view records, the dashboard and export data.</p>
    </div>
</section>

<section class="section section--tight">
    <div class="page">
        <form class="l-panel" method="post" action="login.php" autocomplete="off">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="next" value="<?= e($next) ?>">

            <?php if ($error !== ''): ?>
                <div class="notice notice--error" role="alert"><?= e($error) ?></div>
            <?php endif; ?>

            <div class="l-field">
                <label for="lUser">Username</label>
                <input type="text" class="form-control" id="lUser" name="username"
                       value="<?= e($username) ?>" required autofocus autocomplete="username">
            </div>

            <div class="l-field">
                <label for="lPass">Password</label>
                <input type="password" class="form-control" id="lPass" name="password"
                       required autocomplete="current-password">
            </div>

            <button type="submit" class="btn btn-pvet l-submit">Log in</button>
            <p class="l-back"><a href="home.php" class="text-link">Back to home</a></p>
        </form>
    </div>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>