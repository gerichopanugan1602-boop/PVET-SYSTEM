<?php
declare(strict_types=1);

/**
 * Admin session helpers.
 * Include this at the very top of a page (before any HTML is printed).
 *   - Public pages:  require_once __DIR__ . '/includes/auth.php';   (just starts the session)
 *   - Private pages: also call require_admin();
 */

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,          // ends when the browser closes
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

if (!function_exists('is_admin')) {
    function is_admin(): bool
    {
        return !empty($_SESSION['admin_id']);
    }
}

if (!function_exists('admin_name')) {
    function admin_name(): string
    {
        return (string) ($_SESSION['admin_name'] ?? '');
    }
}

/** Sends visitors who are not logged in to the login page. */
if (!function_exists('require_admin')) {
    function require_admin(): void
    {
        if (!is_admin()) {
            $page = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
            header('Location: login.php?next=' . urlencode($page));
            exit;
        }
    }
}

if (!function_exists('csrf_token')) {
    function csrf_token(): string
    {
        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
        }
        return (string) $_SESSION['csrf'];
    }
}

if (!function_exists('csrf_check')) {
    function csrf_check(mixed $token): bool
    {
        return is_string($token)
            && !empty($_SESSION['csrf'])
            && hash_equals((string) $_SESSION['csrf'], $token);
    }
}