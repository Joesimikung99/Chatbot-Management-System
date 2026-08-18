<?php
if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__, 2));
}
require_once BASE_PATH . '/vendor/autoload.php';
use Dotenv\Dotenv;
use App\Helpers\Auth;
use App\Helpers\Database;
$dotenv = Dotenv::createImmutable(BASE_PATH);
$dotenv->safeLoad();
Auth::startSession();
http_response_code(403);
$appName = Database::appName();
$adminUrl = rtrim($_ENV['APP_URL'] ?? 'https://appupili.up.ac.th/cbms', '/') . '/admin';
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>403 — ไม่มีสิทธิ์ | <?= htmlspecialchars($appName) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;600;700&display=swap" rel="stylesheet">
<script src="https://cdn.tailwindcss.com"></script>
<style>body{font-family:'Sarabun',sans-serif;background:linear-gradient(135deg,#1e1b4b,#312e81,#0f172a);}</style>
</head>
<body class="min-h-screen flex items-center justify-center text-white px-4">
<div class="text-center">
  <p class="text-8xl mb-6">🔒</p>
  <h1 class="text-5xl font-extrabold mb-3">403</h1>
  <p class="text-xl font-semibold text-indigo-300 mb-2">ไม่มีสิทธิ์เข้าถึง</p>
  <p class="text-indigo-400 mb-8">คุณไม่มีสิทธิ์เข้าถึงหน้านี้ กรุณาติดต่อผู้ดูแลระบบ</p>
  <div class="flex gap-3 justify-center">
    <a href="<?= $adminUrl ?>/index.php" class="px-6 py-3 rounded-2xl text-sm font-semibold" style="background:linear-gradient(135deg,#4f46e5,#7c3aed)">← กลับ Dashboard</a>
    <a href="<?= $adminUrl ?>/logout.php" class="px-6 py-3 rounded-2xl text-sm font-semibold border border-white/20 hover:bg-white/10 transition-colors">ออกจากระบบ</a>
  </div>
</div>
</body>
</html>
