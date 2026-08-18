<?php
/**
 * Chat Controller
 * AI Chatbot System — CBMS
 *
 * Handles Web Chat API requests:
 * - Validate input
 * - Rate limiting
 * - Session management
 * - Delegate to AIService
 * - Return JSON response
 */

namespace App\Controllers;

use App\Helpers\Database;
use App\Helpers\Network;
use App\Helpers\Response;
use App\Services\AIService;
use Ramsey\Uuid\Uuid;

class ChatController
{
    private Database  $db;
    private AIService $ai;
    private ?int $botId;

    public function __construct(?int $botId = null)
    {
        $this->db    = Database::getInstance();
        $this->botId = $botId;
        $this->ai    = new AIService($botId);
    }

    // ----------------------------------------------------------------
    // POST /api/chat.php
    // ----------------------------------------------------------------

    /**
     * Handle a web chat message.
     *
     * Request body (JSON):
     * {
     *   "message":    "string (required)",
     *   "session_id": "string (optional — auto-generated if missing)",
     *   "model_id":   "string (optional — overrides default model)"
     * }
     *
     * Response:
     * {
     *   "success":    true,
     *   "data": {
     *     "reply":             "string",
     *     "session_id":        "string",
     *     "is_fallback":       bool,
     *     "tokens_total":      int,
     *     "response_time_ms":  int,
     *     "model_id":          "string"
     *   }
     * }
     */
    public function handleWebChat(): never
    {
        // 1. CORS + OPTIONS preflight
        Response::setCorsHeaders();
        Response::handlePreflight();

        // 2. Enforce POST
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            Response::error('Method not allowed', 405);
        }

        // 3. Parse JSON body
        $body = Response::getJsonBody();

        // 4. Validate required fields
        $missing = Response::validateRequired($body, ['message']);
        if (!empty($missing)) {
            Response::error(
                'Missing required fields: ' . implode(', ', $missing),
                422,
                array_fill_keys($missing, 'Required')
            );
        }

        $message   = trim($body['message']);
        $modelId   = $body['model_id']   ?? null;
        $sessionId = $body['session_id'] ?? null;

        // 5. Validate message length — mb_strlen: with byte-based strlen a
        //    Thai message (3 bytes/char) was capped at ~1,333 chars, not the
        //    4,000 the error message promises.
        if ($message === '') {
            Response::error('Message cannot be empty', 422);
        }

        if (mb_strlen($message, 'UTF-8') > 4000) {
            Response::error('Message too long (max 4000 characters)', 422);
        }

        // 6. Generate / validate session ID
        $sessionId = $this->resolveSessionId($sessionId);

        // 7. Rate limiting
        $this->checkRateLimit($sessionId);

        // 8. Process through AI (RAG pipeline)
        try {
            $result = $this->ai->chat($message, $sessionId, 'web', [
                'model_id' => $modelId,
            ]);
        } catch (\Throwable $e) {
            $this->logError('ChatController::handleWebChat: ' . $e->getMessage());
            Response::error('AI service temporarily unavailable. Please try again.', 503);
        }

        // 9. Return success response
        Response::success([
            'reply'            => $result['reply'],
            'message_id'       => $result['message_id'],
            'session_id'       => $sessionId,
            'is_fallback'      => $result['is_fallback'],
            'cached'           => $result['cached'] ?? false,
            'tokens_total'     => $result['tokens_total'],
            'response_time_ms' => $result['response_time_ms'],
            'model_id'         => $result['model_id'],
        ]);
    }

    // ----------------------------------------------------------------
    // POST /api/chat.php?action=stream
    // ----------------------------------------------------------------

    /**
     * Streaming variant of handleWebChat using Server-Sent Events (SSE).
     *
     * Emits one `data: {"delta":"..."}` event per token as the model generates,
     * then a final `data: {"done":true, ...meta}` event. All validation happens
     * BEFORE any SSE output so errors can still use the normal JSON path.
     */
    public function handleWebChatStream(): never
    {
        Response::setCorsHeaders();
        Response::handlePreflight();

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            Response::error('Method not allowed', 405);
        }

        $body = Response::getJsonBody();

        $missing = Response::validateRequired($body, ['message']);
        if (!empty($missing)) {
            Response::error(
                'Missing required fields: ' . implode(', ', $missing),
                422,
                array_fill_keys($missing, 'Required')
            );
        }

        $message   = trim($body['message']);
        $modelId   = $body['model_id']   ?? null;
        $sessionId = $this->resolveSessionId($body['session_id'] ?? null);

        if ($message === '') {
            Response::error('Message cannot be empty', 422);
        }
        if (mb_strlen($message, 'UTF-8') > 4000) {
            Response::error('Message too long (max 4000 characters)', 422);
        }

        $this->checkRateLimit($sessionId);

        // ── Switch to SSE mode ───────────────────────────────────────
        header('Content-Type: text/event-stream; charset=utf-8');
        header('Cache-Control: no-cache, no-transform');
        header('X-Accel-Buffering: no');   // disable nginx proxy buffering
        header('Connection: keep-alive');

        @ini_set('zlib.output_compression', '0');
        @ini_set('output_buffering', '0');
        @ini_set('implicit_flush', '1');
        while (ob_get_level() > 0) {
            @ob_end_flush();
        }
        ob_implicit_flush(true);

        $send = function (array $payload): void {
            echo 'data: ' . json_encode($payload, JSON_UNESCAPED_UNICODE) . "\n\n";
            @flush();
        };

        // Let the client know the resolved session id immediately.
        $send(['session_id' => $sessionId]);

        try {
            $result = $this->ai->chatStream($message, $sessionId, 'web', [
                'model_id' => $modelId,
            ], function (string $delta) use ($send): void {
                $send(['delta' => $delta]);
            });

            $send([
                'done'             => true,
                'message_id'       => $result['message_id'],
                'session_id'       => $sessionId,
                'is_fallback'      => $result['is_fallback'],
                'cached'           => $result['cached'] ?? false,
                'tokens_total'     => $result['tokens_total'],
                'response_time_ms' => $result['response_time_ms'],
                'model_id'         => $result['model_id'],
            ]);
        } catch (\Throwable $e) {
            $this->logError('ChatController::handleWebChatStream: ' . $e->getMessage());
            $send(['error' => 'AI service temporarily unavailable. Please try again.']);
        }

        echo "event: end\ndata: {}\n\n";
        @flush();
        exit;
    }

    // ----------------------------------------------------------------
    // GET /api/chat.php?action=history&session_id=xxx
    // ----------------------------------------------------------------

    /**
     * Return conversation history for a given session.
     */
    public function getHistory(): never
    {
        Response::setCorsHeaders();
        Response::handlePreflight();

        $sessionId = $_GET['session_id'] ?? '';
        if (empty($sessionId)) {
            Response::error('session_id is required', 422);
        }

        // BUG-08 fix: scope by bot_id to prevent cross-bot data leak
        $query  = 'SELECT * FROM conversations WHERE session_id = ?';
        $params = [$sessionId];
        if ($this->botId) {
            $query   .= ' AND bot_id = ?';
            $params[] = $this->botId;
        }
        $conversation = $this->db->fetch($query, $params);

        if (!$conversation) {
            Response::success(['messages' => [], 'session_id' => $sessionId]);
        }

        $messages = $this->db->fetchAll(
            "SELECT id, role, content, created_at, is_fallback, feedback
             FROM messages
             WHERE conversation_id = ?
             ORDER BY created_at ASC",
            [$conversation['id']]
        );

        Response::success([
            'session_id' => $sessionId,
            'messages'   => $messages,
            'meta'       => [
                'message_count'  => $conversation['message_count'],
                'total_tokens'   => $conversation['total_tokens'],
                'started_at'     => $conversation['started_at'],
            ],
        ]);
    }

    // ----------------------------------------------------------------
    // POST /api/chat.php?action=feedback
    // ----------------------------------------------------------------

    /**
     * Record thumbs up/down feedback on a message.
     *
     * Body: { "message_id": int, "feedback": "positive"|"negative" }
     */
    public function recordFeedback(): never
    {
        Response::setCorsHeaders();
        Response::handlePreflight();

        $body = Response::getJsonBody();
        $missing = Response::validateRequired($body, ['message_id', 'feedback']);
        if (!empty($missing)) {
            Response::error('Missing: ' . implode(', ', $missing), 422);
        }

        $messageId = (int)$body['message_id'];
        $feedback  = $body['feedback'];

        if (!in_array($feedback, ['positive', 'negative'], true)) {
            Response::error('feedback must be "positive" or "negative"', 422);
        }

        // BUG-09 fix: verify message belongs to current bot before updating
        if ($this->botId) {
            $msg = $this->db->fetch(
                "SELECT m.id FROM messages m
                 JOIN conversations c ON c.id = m.conversation_id
                 WHERE m.id = ? AND c.bot_id = ?",
                [$messageId, $this->botId]
            );
            if (!$msg) {
                Response::error('Message not found', 404);
            }
        }

        $affected = $this->db->update('messages', ['feedback' => $feedback], ['id' => $messageId]);

        if ($affected === 0) {
            Response::error('Message not found', 404);
        }

        // A downvoted answer must stop being served from the answer cache —
        // otherwise every user asking the same thing keeps getting the reply
        // one of them just flagged as wrong, for up to the full cache TTL.
        // Matching on the reply text kills every cache entry serving it,
        // regardless of how the cached question was phrased.
        if ($feedback === 'negative') {
            try {
                $msg = $this->db->fetch(
                    "SELECT m.content, c.bot_id FROM messages m
                     JOIN conversations c ON c.id = m.conversation_id
                     WHERE m.id = ? AND m.role = 'assistant'",
                    [$messageId]
                );
                if ($msg) {
                    $this->db->query(
                        'DELETE FROM answer_cache WHERE bot_id <=> ? AND reply = ?',
                        [$msg['bot_id'], $msg['content']]
                    );
                }
            } catch (\Throwable) {
                // answer_cache may not exist (migration 007) — non-fatal
            }
        }

        Response::success(null, 'Feedback recorded');
    }

    // ----------------------------------------------------------------
    // GET /api/chat.php?action=config&bot=xxx
    // ----------------------------------------------------------------

    /**
     * Return bot configuration for widget bootstrap.
     */
    public function getBotConfig(): never
    {
        Response::setCorsHeaders();
        Response::handlePreflight();

        if (!$this->botId) {
            Response::error('Bot not found', 404);
        }

        $bot = $this->db->fetch('SELECT name, avatar_url FROM bots WHERE id = ?', [$this->botId]);

        $settings = $this->db->fetchAll(
            "SELECT setting_key, setting_value FROM bot_settings
             WHERE bot_id = ? AND setting_key IN ('bot_name','chat_welcome_message','primary_color','allow_feedback','widget_icon_url','widget_logo_url','widget_icon_size','widget_draggable','quick_replies','handoff_enabled')",
            [$this->botId]
        );

        $config = [];
        foreach ($settings as $s) {
            $config[$s['setting_key']] = $s['setting_value'];
        }

        // Widget bootstrap is fetched on every page load; let browsers/proxies
        // reuse it for 5 minutes (config edits take up to that long to appear).
        header('Cache-Control: public, max-age=300');

        Response::success([
            'bot_name'        => $config['bot_name'] ?? $bot['name'] ?? 'AI Assistant',
            'welcome_message' => $config['chat_welcome_message'] ?? 'สวัสดี! มีอะไรให้ช่วยไหมครับ?',
            'primary_color'   => $config['primary_color'] ?? '#4F46E5',
            'allow_feedback'  => ($config['allow_feedback'] ?? 'true') === 'true',
            'icon_url'        => $config['widget_icon_url'] ?? null,
            'logo_url'        => $config['widget_logo_url'] ?? null,
            'icon_size'       => (int)($config['widget_icon_size'] ?: 60),
            'draggable'       => ($config['widget_draggable'] ?? 'false') === 'true',
            'avatar_url'      => $bot['avatar_url'] ?? null,
            'quick_replies'   => json_decode($config['quick_replies'] ?? '[]', true) ?: [],
            'handoff_enabled' => ($config['handoff_enabled'] ?? 'false') === 'true',
        ]);
    }

    // ----------------------------------------------------------------
    // POST /api/chat.php?action=handoff
    // ----------------------------------------------------------------

    public function handleHandoff(): never
    {
        Response::setCorsHeaders();
        Response::handlePreflight();

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            Response::error('Method not allowed', 405);
        }

        if (!$this->botId) {
            Response::error('Bot not found', 404);
        }

        $body = Response::getJsonBody();
        $missing = Response::validateRequired($body, ['contact_type', 'contact_id']);
        if (!empty($missing)) {
            Response::error('Missing: ' . implode(', ', $missing), 422);
        }

        $contactType = $body['contact_type'];
        if (!in_array($contactType, ['facebook', 'line', 'phone'], true)) {
            Response::error('contact_type must be "facebook", "line" or "phone"', 422);
        }

        $contactId = trim($body['contact_id']);
        if (strlen($contactId) > 255) {
            Response::error('contact_id too long', 422);
        }

        $sessionId = $body['session_id'] ?? null;
        $userMessage = trim($body['message'] ?? '');

        $conversationId = null;
        if ($sessionId) {
            $conv = $this->db->fetch(
                'SELECT id FROM conversations WHERE session_id = ? AND bot_id = ?',
                [$sessionId, $this->botId]
            );
            if ($conv) $conversationId = (int)$conv['id'];
        }

        $this->db->insert('handoff_requests', [
            'bot_id'          => $this->botId,
            'conversation_id' => $conversationId,
            'session_id'      => $sessionId,
            'contact_type'    => $contactType,
            'contact_id'      => $contactId,
            'user_message'    => $userMessage ?: null,
            'status'          => 'pending',
        ]);

        try {
            $emailService = new \App\Services\EmailService();
            $emailService->sendHandoffNotification($this->botId, [
                'contact_type' => $contactType,
                'contact_id'   => $contactId,
                'user_message' => $userMessage,
            ]);
        } catch (\Throwable $e) {
            // Email failure should not block the handoff request
        }

        Response::success(null, 'Handoff request submitted');
    }

    // ----------------------------------------------------------------
    // Private Helpers
    // ----------------------------------------------------------------

    /**
     * Resolve or generate a session ID.
     *
     * Honor any client-supplied session id that is a safe, reasonably-sized
     * token. The web widget persists its own id in localStorage in the form
     * "<bot>-<rand>-<timestamp>" (e.g. "default-asc0ypibi-1718..."), which is
     * NOT hex — the previous UUID-only check rejected it and minted a brand
     * new UUID on every request, so each message started a fresh conversation
     * and the bot lost all prior context. Accepting the widget's stable id
     * keeps the conversation (and its history) intact from the very first turn.
     *
     * Only generate a new UUID when no usable id was provided.
     */
    private function resolveSessionId(?string $sessionId): string
    {
        if ($sessionId !== null && preg_match('/^[A-Za-z0-9_\-]{8,128}$/', $sessionId)) {
            return $sessionId;
        }
        return Uuid::uuid4()->toString();
    }

    /**
     * Rate limiting using both session ID and client IP.
     * IP-based limits prevent bypass by creating new sessions.
     */
    private function checkRateLimit(string $sessionId): void
    {
        $limitEnabled = $this->db->setting('rate_limit_enabled');
        if ($limitEnabled === 'false' || $limitEnabled === '0') {
            return;
        }

        $perMinute = (int)($_ENV['RATE_LIMIT_PER_MINUTE'] ?? 20);
        $perHour   = (int)($_ENV['RATE_LIMIT_PER_HOUR']   ?? 100);

        // --- IP-based rate limit (prevents session_id rotation bypass) ---
        // Minute + hour windows resolved in ONE query via conditional aggregation.
        $clientIp = Network::getClientIp();
        $botFilter = $this->botId ? ' AND c.bot_id = ?' : '';
        $ipParams  = [$clientIp];
        if ($this->botId) $ipParams[] = $this->botId;

        $ipCounts = $this->db->fetch(
            "SELECT COUNT(*) AS hour_count,
                    COALESCE(SUM(m.created_at >= DATE_SUB(NOW(), INTERVAL 1 MINUTE)), 0) AS minute_count
             FROM messages m
             INNER JOIN conversations c ON c.id = m.conversation_id
             WHERE c.ip_address = ? AND m.role = 'user'
               AND m.created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
               {$botFilter}",
            $ipParams
        );

        if ((int)($ipCounts['minute_count'] ?? 0) >= $perMinute) {
            Response::error("Too many messages. Please wait a moment before sending again.", 429);
        }
        if ((int)($ipCounts['hour_count'] ?? 0) >= $perHour) {
            Response::error("Hourly message limit reached. Please try again later.", 429);
        }

        // --- Session-based rate limit (original behavior) ---
        $rlQuery  = 'SELECT id FROM conversations WHERE session_id = ?';
        $rlParams = [$sessionId];
        if ($this->botId) {
            $rlQuery   .= ' AND bot_id = ?';
            $rlParams[] = $this->botId;
        }
        $conv = $this->db->fetch($rlQuery, $rlParams);

        if (!$conv) {
            return;
        }

        $sessCounts = $this->db->fetch(
            "SELECT COUNT(*) AS hour_count,
                    COALESCE(SUM(created_at >= DATE_SUB(NOW(), INTERVAL 1 MINUTE)), 0) AS minute_count
             FROM messages
             WHERE conversation_id = ? AND role = 'user'
               AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)",
            [$conv['id']]
        );

        if ((int)($sessCounts['minute_count'] ?? 0) >= $perMinute) {
            Response::error("Too many messages. Please wait a moment before sending again.", 429);
        }
        if ((int)($sessCounts['hour_count'] ?? 0) >= $perHour) {
            Response::error("Hourly message limit reached. Please try again later.", 429);
        }
    }

    private function logError(string $message): void
    {
        $logPath = dirname(__DIR__, 2) . '/storage/logs/chat.log';
        @file_put_contents($logPath,
            sprintf("[%s][ERROR] %s\n", date('Y-m-d H:i:s'), $message),
            FILE_APPEND | LOCK_EX
        );
    }
}
