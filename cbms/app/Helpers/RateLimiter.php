<?php
/**
 * Rate Limiter — per-conversation message throttle
 * AI Chatbot System — CBMS
 *
 * The web chat path throttles in ChatController (per-session AND per-IP).
 * Facebook/LINE webhooks cannot use the IP half (every request comes from the
 * platform's servers), but the per-conversation half applies exactly the same
 * way — without it, one messenger user can spam the LLM at full API cost.
 *
 * Shares the thresholds and the rate_limit_enabled switch with the web path.
 */

namespace App\Helpers;

class RateLimiter
{
    /**
     * True if this conversation has already used up its minute or hour quota.
     * Fails open (false) on any DB error — a broken throttle must not take
     * the bot down.
     *
     * @param string $sessionId  Platform session id (e.g. "line_Uxxxx", "fb_123")
     */
    public static function isLimited(Database $db, ?int $botId, string $sessionId): bool
    {
        try {
            $enabled = $db->setting('rate_limit_enabled');
            if ($enabled === 'false' || $enabled === '0') {
                return false;
            }

            $perMinute = (int)($_ENV['RATE_LIMIT_PER_MINUTE'] ?? 20);
            $perHour   = (int)($_ENV['RATE_LIMIT_PER_HOUR']   ?? 100);

            $sql    = 'SELECT id FROM conversations WHERE session_id = ?';
            $params = [$sessionId];
            if ($botId !== null) {
                $sql     .= ' AND bot_id = ?';
                $params[] = $botId;
            }
            $conv = $db->fetch($sql, $params);
            if (!$conv) {
                return false; // first message of a new conversation
            }

            $counts = $db->fetch(
                "SELECT COUNT(*) AS hour_count,
                        COALESCE(SUM(created_at >= DATE_SUB(NOW(), INTERVAL 1 MINUTE)), 0) AS minute_count
                 FROM messages
                 WHERE conversation_id = ? AND role = 'user'
                   AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)",
                [$conv['id']]
            );

            return (int)($counts['minute_count'] ?? 0) >= $perMinute
                || (int)($counts['hour_count']   ?? 0) >= $perHour;

        } catch (\Throwable) {
            return false;
        }
    }
}
