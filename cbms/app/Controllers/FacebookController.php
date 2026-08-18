<?php
/**
 * Facebook Messenger Controller
 * AI Chatbot System — CBMS
 *
 * Handles:
 * - Parsing incoming Facebook webhook events
 * - Sending reply messages via Graph API
 * - Calling AIService for RAG response
 * - Logging conversation to DB
 */

namespace App\Controllers;

use App\Helpers\BotSettingsTrait;
use App\Helpers\Database;
use App\Services\AIService;
use App\Services\LogService;
use GuzzleHttp\Client;

class FacebookController
{
    use BotSettingsTrait;
    private Database  $db;
    private AIService $ai;
    private LogService $logger;
    private Client    $http;

    private ?int   $botId;
    private string $pageToken;
    private string $appSecret;
    private string $graphVersion;

    public function __construct(?int $botId = null)
    {
        $this->db           = Database::getInstance();
        $this->botId        = $botId;
        $this->ai           = new AIService($botId);
        $this->logger       = new LogService();
        $this->http         = new Client(['timeout' => 20]);
        $this->pageToken    = $this->getDbSetting('facebook_page_token')  ?? $_ENV['FACEBOOK_PAGE_TOKEN']  ?? '';
        $this->appSecret    = $this->getDbSetting('facebook_app_secret')  ?? $_ENV['FACEBOOK_APP_SECRET']  ?? '';
        $this->graphVersion = 'v19.0';
    }

    // ----------------------------------------------------------------
    // Entry Point — called by webhook-facebook.php
    // ----------------------------------------------------------------

    public function handleWebhook(string $payload, string $signature = ''): void
    {
        if (!$this->verifySignature($payload, $signature)) {
            http_response_code(403);
            echo json_encode(['error' => 'Invalid signature']);
            exit;
        }

        $data = json_decode($payload, true);

        if (($data['object'] ?? '') !== 'page') {
            return;
        }

        foreach ($data['entry'] ?? [] as $entry) {
            foreach ($entry['messaging'] ?? [] as $event) {
                $this->handleMessagingEvent($event);
            }
        }
    }

    /**
     * Verify Facebook X-Hub-Signature-256 header.
     */
    public function verifySignature(string $payload, string $signature): bool
    {
        if (empty($this->appSecret)) return false;
        if (empty($signature)) return false;

        $expected = 'sha256=' . hash_hmac('sha256', $payload, $this->appSecret);
        return hash_equals($expected, $signature);
    }

    // ----------------------------------------------------------------
    // Route event type
    // ----------------------------------------------------------------

    private function handleMessagingEvent(array $event): void
    {
        $senderId = $event['sender']['id']    ?? null;
        $pageId   = $event['recipient']['id'] ?? null;

        if (!$senderId) return;

        // Echoes of the page's OWN messages (sent when the app subscribes to
        // message_echoes) must never be processed as user input — the bot
        // would answer itself in a paid loop.
        if (!empty($event['message']['is_echo'])) {
            return;
        }

        // Text message
        if (!empty($event['message']['text'])) {
            $this->handleTextMessage($senderId, $event['message']['text'], $event);
            return;
        }

        // Postback (button click)
        if (!empty($event['postback']['payload'])) {
            $this->handlePostback($senderId, $event['postback']['payload'], $event);
            return;
        }

        // Read receipt — ignore
        if (isset($event['read'])) {
            return;
        }

        // Attachment (image, sticker, etc.)
        if (!empty($event['message']['attachments'])) {
            $this->sendTextMessage($senderId, 'ขออภัยครับ ขณะนี้ยังไม่รองรับการส่งรูปภาพหรือไฟล์ กรุณาพิมพ์ข้อความครับ 😊');
            return;
        }
    }

    // ----------------------------------------------------------------
    // Handle Text Message
    // ----------------------------------------------------------------

    private function handleTextMessage(string $senderId, string $text, array $event): void
    {
        // Generate session ID from Facebook sender ID
        $sessionId = 'fb_' . $senderId;

        // Same per-conversation quota as web chat — webhooks have no other
        // brake on LLM spend (every request arrives from Facebook's IPs).
        if (\App\Helpers\RateLimiter::isLimited($this->db, $this->botId, $sessionId)) {
            $this->sendTextMessage($senderId, 'ส่งข้อความถี่เกินไป กรุณารอสักครู่แล้วลองใหม่ครับ 🙏');
            return;
        }

        // Show typing indicator
        $this->sendAction($senderId, 'typing_on');

        try {

            // Process through AI RAG pipeline
            $result = $this->ai->chat($text, $sessionId, 'facebook');

            // Stop typing
            $this->sendAction($senderId, 'typing_off');

            // Send reply
            $this->sendTextMessage($senderId, $result['reply']);

            // Log to file
            $this->logger->logChat('facebook', [
                'sender_id' => $senderId,
                'message'   => $text,
                'reply'     => $result['reply'],
                'tokens'    => $result['tokens_total'],
                'fallback'  => $result['is_fallback'],
            ]);

        } catch (\Throwable $e) {
            $this->sendAction($senderId, 'typing_off');
            $this->sendTextMessage($senderId, 'ขออภัยครับ ระบบมีปัญหาชั่วคราว กรุณาลองใหม่อีกครั้ง 🙏');
            $this->logger->logError('FacebookController', $e->getMessage(), ['sender_id' => $senderId]);
        }
    }

    // ----------------------------------------------------------------
    // Handle Postback (Get Started, Menu buttons)
    // ----------------------------------------------------------------

    private function handlePostback(string $senderId, string $payload, array $event): void
    {
        if ($payload === 'GET_STARTED') {
            $welcome = $this->getDbSetting('chat_welcome_message')
                       ?? 'สวัสดีครับ! ยินดีให้บริการข้อมูลมหาวิทยาลัยพะเยา 😊';
            $this->sendTextMessage($senderId, $welcome);
            return;
        }

        if ($payload === 'HELP') {
            $this->sendTextMessage($senderId, "คุณสามารถถามเกี่ยวกับ:\n• ข้อมูลมหาวิทยาลัย\n• คณะและสาขาวิชา\n• การสมัครเข้าศึกษา\n• บริการนักศึกษา\n\nพิมพ์คำถามได้เลยครับ!");
            return;
        }

        // Generic postback — treat as text
        $this->handleTextMessage($senderId, $payload, $event);
    }

    // ----------------------------------------------------------------
    // Facebook Graph API: Send Text Message
    // ----------------------------------------------------------------

    public function sendTextMessage(string $recipientId, string $text): bool
    {
        return $this->sendMessage($recipientId, ['text' => $text]);
    }

    // ----------------------------------------------------------------
    // Facebook Graph API: Send Message (generic)
    // ----------------------------------------------------------------

    public function sendMessage(string $recipientId, array $message): bool
    {
        if (empty($this->pageToken)) {
            $this->logger->logError('FacebookController', 'Page token not configured');
            return false;
        }

        try {
            $response = $this->http->post(
                "https://graph.facebook.com/{$this->graphVersion}/me/messages",
                [
                    'query' => ['access_token' => $this->pageToken],
                    'json'  => [
                        'recipient'      => ['id' => $recipientId],
                        'message'        => $message,
                        'messaging_type' => 'RESPONSE',
                    ],
                    'http_errors' => false,
                ]
            );

            $body   = json_decode($response->getBody()->getContents(), true);
            $status = $response->getStatusCode();

            if ($status !== 200 || isset($body['error'])) {
                $this->logger->logError('FacebookController', 'Send failed: ' . json_encode($body));
                return false;
            }

            return true;

        } catch (\Throwable $e) {
            $this->logger->logError('FacebookController', 'sendMessage exception: ' . $e->getMessage());
            return false;
        }
    }

    // ----------------------------------------------------------------
    // Facebook Graph API: Sender Action (typing, seen)
    // ----------------------------------------------------------------

    public function sendAction(string $recipientId, string $action): void
    {
        if (empty($this->pageToken)) return;

        try {
            $this->http->post(
                "https://graph.facebook.com/{$this->graphVersion}/me/messages",
                [
                    'query' => ['access_token' => $this->pageToken],
                    'json'  => [
                        'recipient'     => ['id' => $recipientId],
                        'sender_action' => $action,
                    ],
                    'http_errors' => false,
                ]
            );
        } catch (\Throwable) {
            // Non-fatal
        }
    }

    // ----------------------------------------------------------------
    // Facebook Graph API: Set Persistent Menu (call once on setup)
    // ----------------------------------------------------------------

    public function setupPersistentMenu(): bool
    {
        if (empty($this->pageToken)) return false;

        try {
            $this->http->post(
                "https://graph.facebook.com/{$this->graphVersion}/me/messenger_profile",
                [
                    'query' => ['access_token' => $this->pageToken],
                    'json'  => [
                        'get_started' => ['payload' => 'GET_STARTED'],
                        'persistent_menu' => [[
                            'locale'   => 'default',
                            'composer_input_disabled' => false,
                            'call_to_actions' => [
                                ['type' => 'postback', 'title' => '🏠 เริ่มต้น',     'payload' => 'GET_STARTED'],
                                ['type' => 'postback', 'title' => '❓ ช่วยเหลือ',    'payload' => 'HELP'],
                                ['type' => 'web_url',  'title' => '🌐 เว็บไซต์',     'url'     => $_ENV['APP_URL'] ?? 'https://appupili.up.ac.th/cbms'],
                            ],
                        ]],
                        'greeting' => [['locale'=>'default','text'=>'สวัสดีครับ! ยินดีให้บริการข้อมูลมหาวิทยาลัยพะเยา 😊']],
                    ],
                    'http_errors' => false,
                ]
            );
            return true;
        } catch (\Throwable $e) {
            $this->logger->logError('FacebookController', 'setupMenu: ' . $e->getMessage());
            return false;
        }
    }

    // ----------------------------------------------------------------
    // Trait Requirements
    // ----------------------------------------------------------------

    protected function getBotId(): ?int { return $this->botId; }
    protected function getDb(): Database { return $this->db; }
}
