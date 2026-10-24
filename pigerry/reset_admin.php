<?php
declare(strict_types=1);

/**
 * ONE-TIME script: creates the admin, or resets its password if the username already exists.
 * 1) Change $username and $password below, save.
 * 2) Open this file once in the browser.
 * 3) DELETE this file right after.
 */
require_once __DIR__ . '/config/database.php';

$username = 'admin';            // <-- the username you will type at login
$password = 'ChangeMe123!';     // <-- the password you will type at login

$db = try_db();
if (!$db) {
    exit('The database is not available. Check that MySQL is running in XAMPP.');
}

try {
    $hash = password_hash($password, PASSWORD_DEFAULT);

    $find = $db->prepare('SELECT `id` FROM `admins` WHERE `username` = :u LIMIT 1');
    $find->execute([':u' => $username]);
    $id = $find->fetchColumn();

    if ($id !== false) {
        $up = $db->prepare('UPDATE `admins` SET `password_hash` = :p WHERE `id` = :id');
        $up->execute([':p' => $hash, ':id' => $id]);
        echo 'Password UPDATED for "' . htmlspecialchars($username) . '".';
    } else {
        $in = $db->prepare('INSERT INTO `admins` (`username`, `password_hash`) VALUES (:u, :p)');
        $in->execute([':u' => $username, ':p' => $hash]);
        echo 'Admin "' . htmlspecialchars($username) . '" CREATED.';
    }

    // Quick self-check: reads it back and verifies the password.
    $chk = $db->prepare('SELECT `password_hash` FROM `admins` WHERE `username` = :u');
    $chk->execute([':u' => $username]);
    echo password_verify($password, (string) $chk->fetchColumn())
        ? ' Verified OK. Now DELETE this file and log in.'
        : ' WARNING: verification failed.';
} catch (Throwable $ex) {
    echo 'Failed: ' . htmlspecialchars($ex->getMessage());
}