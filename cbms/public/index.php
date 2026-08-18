<?php
define('BASE_PATH', dirname(__DIR__));
require_once BASE_PATH . '/vendor/autoload.php';
use Dotenv\Dotenv;
$dotenv = Dotenv::createImmutable(BASE_PATH);
$dotenv->safeLoad();
$base = rtrim($_ENV['APP_URL'] ?? 'https://appupili.up.ac.th/cbms', '/');
header("Location: {$base}/admin/login.php");
exit;
