<?php
/**
 * Switch Active Bot — AJAX Endpoint
 * POST /admin/api/switch-bot.php
 * Body: { "bot_id": int }
 */

define('BASE_PATH', dirname(__DIR__, 3));
require_once BASE_PATH . '/vendor/autoload.php';

use Dotenv\Dotenv;
use App\Helpers\Auth;

$dotenv = Dotenv::createImmutable(BASE_PATH);
$dotenv->safeLoad();

header('Content-Type: application/json; charset=utf-8');

Auth::requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// BUG-07 fix: validate CSRF token (reads from X-CSRF-TOKEN header)
if (!Auth::validateCsrf()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'CSRF validation failed']);
    exit;
}

$body  = json_decode(file_get_contents('php://input'), true) ?? [];
$botId = (int)($body['bot_id'] ?? 0);

if ($botId <= 0) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'bot_id is required']);
    exit;
}

if (!Auth::setActiveBot($botId)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied to this bot']);
    exit;
}

echo json_encode([
    'success' => true,
    'message' => 'Bot switched',
    'bot_id'  => $botId,
]);
