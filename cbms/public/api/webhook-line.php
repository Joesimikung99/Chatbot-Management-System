<?php
/**
 * LINE Messaging API Webhook (Full)
 * AI Chatbot System — CBMS
 */

define('BASE_PATH', dirname(__DIR__, 2));
require_once BASE_PATH . '/vendor/autoload.php';

use Dotenv\Dotenv;
use App\Controllers\LineController;

$dotenv = Dotenv::createImmutable(BASE_PATH);
$dotenv->safeLoad();

date_default_timezone_set($_ENV['APP_TIMEZONE'] ?? 'Asia/Bangkok');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// ── Resolve Bot ─────────────────────────────────────────────────────────
$botSlug = $_GET['bot'] ?? null;
$botId   = null;
$db      = \App\Helpers\Database::getInstance();

if ($botSlug) {
    $bot = $db->fetch('SELECT id FROM bots WHERE slug = ? AND is_active = 1', [$botSlug]);
    if ($bot) $botId = (int)$bot['id'];
}
if (!$botId) {
    $defBot = $db->fetch("SELECT id FROM bots WHERE slug = 'default' LIMIT 1");
    $botId  = $defBot ? (int)$defBot['id'] : null;
}

$payload   = file_get_contents('php://input');
$signature = $_SERVER['HTTP_X_LINE_SIGNATURE'] ?? '';

header('Content-Type: application/json');

try {
    $controller = new LineController($botId);
    $controller->handleWebhook($payload, $signature);
} catch (\Throwable $e) {
    error_log('[LINEWebhook] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Internal error']);
}
