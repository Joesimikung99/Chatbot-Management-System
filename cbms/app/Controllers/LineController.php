<?php
/**
 * LINE Messaging API Controller
 * AI Chatbot System — CBMS
 *
 * Handles:
 * - Parsing incoming LINE webhook events
 * - Sending reply/push messages via LINE Messaging API
 * - Calling AIService for RAG response
 * - Logging conversation to DB
 */

namespace App\Controllers;

use App\Helpers\BotSettingsTrait;
use App\Helpers\Database;
use App\Services\AIService;
use App\Services\LogService;
use GuzzleHttp\Client;

class LineController
{
    use BotSettingsTrait;
    private Database   $db;
    private AIService  $ai;
    private LogService $logger;
    private Client     $http;

    private ?int   $botId;
    private string $channelToken;
    private string $channelSecret;
    private string $apiBase = 'https://api.line.me/v2/bot';

    public function __construct(?int $botId = null)
    {
        $this->db            = Database::getInstance();
        $this->botId         = $botId;
        $this->ai            = new AIService($botId);
        $this->logger        = new LogService();
        $this->http          = new Client(['timeout' => 20]);
        $this->channelToken  = $this->getDbSetting('line_channel_token')  ?? $_ENV['LINE_CHANNEL_TOKEN']  ?? '';
        $this->channelSecret = $this->getDbSetting('line_channel_secret') ?? $_ENV['LINE_CHANNEL_SECRET'] ?? '';
    }

    // ----------------------------------------------------------------
    // Entry Point
    // ----------------------------------------------------------------

    public function handleWebhook(string $payload, string $signature): void
    {
        if (!$this->verifySignature($payload, $signature)) {
            http_response_code(403);
            echo json_encode(['error' => 'Invalid signature']);
            exit;
        }

        $data   = json_decode($payload, true);
        $events = $data['events'] ?? [];

        foreach ($events as $event) {
            $this->routeEvent($event);
        }

        echo json_encode(['status' => 'ok']);
    }

    // ----------------------------------------------------------------
    // Route Event Type
    // ----------------------------------------------------------------

    private function routeEvent(array $event): void
    {
        $type = $event['type'] ?? '';

        match ($type) {
            'message'  => $this->handleMessageEvent($event),
            'follow'   => $this->handleFollow($event),
            'unfollow' => null, // Just log — no reply needed
            'postback' => $this->handlePostback($event),
            default    => null,
        };
    }

    // ----------------------------------------------------------------
    // Handle Message
    // ----------------------------------------------------------------

    private function handleMessageEvent(array $event): void
    {
        $messageType = $event['message']['type'] ?? '';

        if ($messageType !== 'text') {
            $this->replyMessage($event['replyToken'], [
                ['type' => 'text', 'text' => 'ขออภัยครับ ขณะนี้รองรับเฉพาะข้อความ กรุณาพิมพ์คำถามครับ 😊']
            ]);
            return;
        }

        $text      = $event['message']['text'] ?? '';
        $userId    = $event['source']['userId'] ?? 'unknown';
        $sessionId = 'line_' . $userId;

        // Same per-conversation quota as web chat — webhooks have no other
        // brake on LLM spend (every request arrives from LINE's servers).
        if (\App\Helpers\RateLimiter::isLimited($this->db, $this->botId, $sessionId)) {
            $this->replyMessage($event['replyToken'], [
                ['type' => 'text', 'text' => 'ส่งข้อความถี่เกินไป กรุณารอสักครู่แล้วลองใหม่ครับ 🙏']
            ]);
            return;
        }

        try {
            // Process through AI RAG pipeline
            $result = $this->ai->chat($text, $sessionId, 'line');

            // Send reply
            $this->replyMessage($event['replyToken'], [
                ['type' => 'text', 'text' => $result['reply']]
            ]);

            // Log
            $this->logger->logChat('line', [
                'user_id'  => $userId,
                'message'  => $text,
                'reply'    => $result['reply'],
                'tokens'   => $result['tokens_total'],
                'fallback' => $result['is_fallback'],
            ]);

        } catch (\Throwable $e) {
            $this->replyMessage($event['replyToken'], [
                ['type' => 'text', 'text' => 'ขออภัยครับ ระบบมีปัญหาชั่วคราว กรุณาลองใหม่อีกครั้ง 🙏']
            ]);
            $this->logger->logError('LineController', $e->getMessage(), ['user_id' => $userId]);
        }
    }

    // ----------------------------------------------------------------
    // Handle Follow (new user)
    // ----------------------------------------------------------------

    private function handleFollow(array $event): void
    {
        $welcome = $this->getDbSetting('chat_welcome_message')
                   ?? 'สวัสดีครับ! ยินดีให้บริการข้อมูลมหาวิทยาลัยพะเยา 😊';

        $this->replyMessage($event['replyToken'], [
            ['type' => 'text', 'text' => $welcome],
        ]);
    }

    // ----------------------------------------------------------------
    // Handle Postback (Rich Menu buttons)
    // ----------------------------------------------------------------

    private function handlePostback(array $event): void
    {
        $data      = $event['postback']['data'] ?? '';
        $userId    = $event['source']['userId'] ?? 'unknown';
        $sessionId = 'line_' . $userId;

        match ($data) {
            'action=help' => $this->replyMessage($event['replyToken'], [
                ['type' => 'text', 'text' => "คุณสามารถถามเกี่ยวกับ:\n• ข้อมูลมหาวิทยาลัย\n• คณะและสาขาวิชา\n• การสมัครเข้าศึกษา\n• บริการนักศึกษา\n\nพิมพ์คำถามได้เลยครับ!"]
            ]),
            'action=start' => $this->handleFollow($event),
            default => $this->handleTextMessage($event, $data, $sessionId),
        };
    }

    private function handleTextMessage(array $event, string $text, string $sessionId): void
    {
        if (\App\Helpers\RateLimiter::isLimited($this->db, $this->botId, $sessionId)) {
            $this->replyMessage($event['replyToken'], [
                ['type' => 'text', 'text' => 'ส่งข้อความถี่เกินไป กรุณารอสักครู่แล้วลองใหม่ครับ 🙏']
            ]);
            return;
        }

        try {
            $result = $this->ai->chat($text, $sessionId, 'line');
            $this->replyMessage($event['replyToken'], [
                ['type' => 'text', 'text' => $result['reply']]
            ]);
        } catch (\Throwable $e) {
            $this->logger->logError('LineController', 'postback handler: ' . $e->getMessage());
        }
    }

    // ----------------------------------------------------------------
    // LINE API: Reply Message (uses replyToken — free, <30s)
    // ----------------------------------------------------------------

    public function replyMessage(string $replyToken, array $messages): bool
    {
        if (empty($this->channelToken)) {
            $this->logger->logError('LineController', 'Channel token not configured');
            return false;
        }

        try {
            $res = $this->http->post("{$this->apiBase}/message/reply", [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->channelToken,
                    'Content-Type'  => 'application/json',
                ],
                'json' => [
                    'replyToken' => $replyToken,
                    'messages'   => $messages,
                ],
                'http_errors' => false,
            ]);

            if ($res->getStatusCode() !== 200) {
                $body = $res->getBody()->getContents();
                $this->logger->logError('LineController', 'replyMessage failed: ' . $body);
                return false;
            }

            return true;

        } catch (\Throwable $e) {
            $this->logger->logError('LineController', 'replyMessage exception: ' . $e->getMessage());
            return false;
        }
    }

    // ----------------------------------------------------------------
    // LINE API: Push Message (proactive — requires push permission)
    // ----------------------------------------------------------------

    public function pushMessage(string $userId, array $messages): bool
    {
        if (empty($this->channelToken)) return false;

        try {
            $res = $this->http->post("{$this->apiBase}/message/push", [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->channelToken,
                    'Content-Type'  => 'application/json',
                ],
                'json' => ['to' => $userId, 'messages' => $messages],
                'http_errors' => false,
            ]);
            return $res->getStatusCode() === 200;
        } catch (\Throwable) {
            return false;
        }
    }

    // ----------------------------------------------------------------
    // LINE API: Get User Profile
    // ----------------------------------------------------------------

    public function getUserProfile(string $userId): ?array
    {
        if (empty($this->channelToken)) return null;
        try {
            $res  = $this->http->get("{$this->apiBase}/profile/{$userId}", [
                'headers' => ['Authorization' => 'Bearer ' . $this->channelToken],
                'http_errors' => false,
            ]);
            if ($res->getStatusCode() === 200) {
                return json_decode($res->getBody()->getContents(), true);
            }
        } catch (\Throwable) {}
        return null;
    }

    // ----------------------------------------------------------------
    // Signature Verification
    // ----------------------------------------------------------------

    public function verifySignature(string $body, string $signature): bool
    {
        if (empty($this->channelSecret) || empty($signature)) return false;
        $expected = base64_encode(hash_hmac('sha256', $body, $this->channelSecret, true));
        return hash_equals($expected, $signature);
    }

    // ----------------------------------------------------------------
    // Trait Requirements
    // ----------------------------------------------------------------

    protected function getBotId(): ?int { return $this->botId; }
    protected function getDb(): Database { return $this->db; }
}
