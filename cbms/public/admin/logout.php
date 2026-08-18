<?php
define('BASE_PATH', dirname(__DIR__, 2));
require_once BASE_PATH . '/vendor/autoload.php';
use Dotenv\Dotenv;
use App\Helpers\Auth;
use App\Services\LogService;
$dotenv = Dotenv::createImmutable(BASE_PATH);
$dotenv->safeLoad();
Auth::startSession();
if (Auth::check()) {
    $user = Auth::user();
    try { (new LogService())->logActivity($user['id'], 'auth.logout', 'admin_user', $user['id']); } catch (\Throwable) {}
}
Auth::logout();
$base = rtrim($_ENV['APP_URL'] ?? 'https://appupili.up.ac.th/cbms', '/');
header("Location: {$base}/admin/login.php");
exit;
