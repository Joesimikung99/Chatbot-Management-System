<?php
/**
 * PHPUnit Bootstrap
 * Sets up autoloading and test environment.
 */

define('BASE_PATH', dirname(__DIR__));

require_once BASE_PATH . '/vendor/autoload.php';

// Load .env.testing if exists, otherwise .env
$dotenv = Dotenv\Dotenv::createImmutable(BASE_PATH, '.env.testing');
$dotenv->safeLoad();

// Fallback to regular .env
if (empty($_ENV['APP_ENV'])) {
    $dotenv = Dotenv\Dotenv::createImmutable(BASE_PATH);
    $dotenv->safeLoad();
}

date_default_timezone_set($_ENV['APP_TIMEZONE'] ?? 'Asia/Bangkok');
