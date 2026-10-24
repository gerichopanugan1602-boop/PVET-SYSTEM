<?php
/**
 * Database configuration (XAMPP defaults).
 *
 * On first use, the database and the `assessments` table are created
 * automatically from the questionnaire definition in includes/functions.php.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/functions.php';

const DB_HOST    = '127.0.0.1';
const DB_PORT    = 3306;
const DB_NAME    = 'swine_farmer_services';
const DB_USER    = 'root';
const DB_PASS    = '';
const DB_CHARSET = 'utf8mb4';

/**
 * Returns a shared PDO connection. Throws PDOException on failure.
 */
function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];

    $dsn = sprintf('mysql:host=%s;port=%d;charset=%s', DB_HOST, DB_PORT, DB_CHARSET);
    $conn = new PDO($dsn, DB_USER, DB_PASS, $options);

    $conn->exec('CREATE DATABASE IF NOT EXISTS `' . DB_NAME . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $conn->exec('USE `' . DB_NAME . '`');
    $conn->exec(assessment_table_sql());

    return $pdo = $conn;
}

/**
 * Connection helper for pages that should still render without a database.
 */
function try_db(): ?PDO
{
    try {
        return db();
    } catch (Throwable $e) {
        error_log('[Swine Farmer Services] Database error: ' . $e->getMessage());
        return null;
    }
}
