<?php
/**
 * Web Chat API Endpoint
 * AI Chatbot System — CBMS
 *
 * Base path: /api/chat.php
 *
 * Routes:
 *   POST /api/chat.php                     → Send message, get AI reply
 *   GET  /api/chat.php?action=history&session_id=xxx → Conversation history
 *   POST /api/chat.php?action=feedback     → Record message feedback
 */

// ── Bootstrap ──────────────────────────────────────────────────────────
define('BASE_PATH', dirname(__DIR__, 2));

require_once BASE_PATH . '/vendor/autoload.php';

use Dotenv\Dotenv;
use App\Helpers\Response;
use App\Controllers\ChatController;

// Load environment
$dotenv = Dotenv::createImmutable(BASE_PATH);
$dotenv->safeLoad();

// Set timezone
date_default_timezone_set($_ENV['APP_TIMEZONE'] ?? 'Asia/Bangkok');

// ── Security Headers ───────────────────────────────────────────────────
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');

// ── CORS Preflight ─────────────────────────────────────────────────────
Response::setCorsHeaders();
Response::handlePreflight();

// ── Resolve Bot ───────────────────────────────────────────────────────
use App\Helpers\Database;

$botSlug = $_GET['bot'] ?? null;
$botId   = null;

if ($botSlug) {
    $db  = Database::getInstance();
    $bot = $db->fetch(
        'SELECT id FROM bots WHERE slug = ? AND is_active = 1',
        [$botSlug]
    );
    if ($bot) {
        $botId = (int)$bot['id'];
    }
}

// Fallback: default bot
if (!$botId) {
    $db    = $db ?? Database::getInstance();
    $defBot = $db->fetch("SELECT id FROM bots WHERE slug = 'default' LIMIT 1");
    $botId  = $defBot ? (int)$defBot['id'] : null;
}

// ── Route ──────────────────────────────────────────────────────────────
$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    $controller = new ChatController($botId);

    match (true) {
        // GET config (widget bootstrap)
        $method === 'GET' && $action === 'config'
            => $controller->getBotConfig(),

        // GET history
        $method === 'GET' && $action === 'history'
            => $controller->getHistory(),

        // POST feedback
        $method === 'POST' && $action === 'feedback'
            => $controller->recordFeedback(),

        // POST handoff request
        $method === 'POST' && $action === 'handoff'
            => $controller->handleHandoff(),

        // POST chat with SSE streaming
        $method === 'POST' && $action === 'stream'
            => $controller->handleWebChatStream(),

        // POST chat (default)
        $method === 'POST'
            => $controller->handleWebChat(),

        // Unsupported
        default
            => Response::error('Invalid request', 400),
    };

} catch (\Throwable $e) {
    // Global catch — never expose internal errors to public
    $debug = filter_var($_ENV['APP_DEBUG'] ?? false, FILTER_VALIDATE_BOOLEAN);

    if ($debug) {
        Response::error($e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine(), 500);
    }

    Response::error('Internal server error', 500);
}
