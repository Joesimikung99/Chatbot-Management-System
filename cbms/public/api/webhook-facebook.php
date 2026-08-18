<?php
/**
 * Facebook Messenger Webhook (Full)
 * AI Chatbot System — CBMS
 */

define('BASE_PATH', dirname(__DIR__, 2));
require_once BASE_PATH . '/vendor/autoload.php';

use Dotenv\Dotenv;
use App\Controllers\FacebookController;

$dotenv = Dotenv::createImmutable(BASE_PATH);
$dotenv->safeLoad();

date_default_timezone_set($_ENV['APP_TIMEZONE'] ?? 'Asia/Bangkok');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

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

// Helper: get setting from bot_settings first, then system_settings, then .env
function getBotSetting(string $key, ?int $botId, $db, ?string $envFallback = null): ?string {
    if ($botId) {
        $val = $db->fetchColumn("SELECT setting_value FROM bot_settings WHERE bot_id = ? AND setting_key = ?", [$botId, $key]);
        if ($val) return $val;
    }
    $val = $db->fetchColumn("SELECT setting_value FROM system_settings WHERE setting_key = ?", [$key]);
    return $val ?: $envFallback;
}

// ── Webhook Verification ────────────────────────────────────────────────
if ($method === 'GET') {
    $mode      = $_GET['hub_mode']         ?? '';
    $token     = $_GET['hub_verify_token'] ?? '';
    $challenge = $_GET['hub_challenge']    ?? '';

    $expected = getBotSetting('facebook_verify_token', $botId, $db, $_ENV['FACEBOOK_VERIFY_TOKEN'] ?? '');

    if ($mode === 'subscribe' && $expected && hash_equals($expected, $token)) {
        http_response_code(200);
        echo $challenge;
        exit;
    }

    http_response_code(403);
    echo 'Verification failed';
    exit;
}

// ── Incoming Events ─────────────────────────────────────────────────────
if ($method === 'POST') {
    $payload   = file_get_contents('php://input');
    $signature = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';

    $appSecret = getBotSetting('facebook_app_secret', $botId, $db, $_ENV['FACEBOOK_APP_SECRET'] ?? '');

    if ($appSecret) {
        if (empty($signature)) {
            http_response_code(403);
            echo 'Missing signature';
            exit;
        }
        $expected = 'sha256=' . hash_hmac('sha256', $payload, $appSecret);
        if (!hash_equals($expected, $signature)) {
            http_response_code(403);
            echo 'Invalid signature';
            exit;
        }
    }

    try {
        $controller = new FacebookController($botId);
        $controller->handleWebhook($payload, $signature);
    } catch (\Throwable $e) {
        error_log('[FacebookWebhook] ' . $e->getMessage());
    }

    http_response_code(200);
    echo 'EVENT_RECEIVED';
    exit;
}

http_response_code(405);
echo 'Method not allowed';
