<?php
/**
 * Log Service
 * AI Chatbot System — CBMS
 *
 * Handles:
 * - Writing to api_usage_logs table
 * - Writing to admin_activity_logs table
 * - Writing to storage/logs/ files
 */

namespace App\Services;

use App\Helpers\Database;

class LogService
{
    private Database $db;
    private string   $logDir;

    public function __construct()
    {
        $this->db     = Database::getInstance();
        $this->logDir = dirname(__DIR__, 2) . '/storage/logs';
    }

    // ----------------------------------------------------------------
    // 1. API Usage Log (per AI request)
    // ----------------------------------------------------------------

    /**
     * Log a single API request to api_usage_logs.
     *
     * @param array{
     *   conversation_id?: int,
     *   message_id?:      int,
     *   platform:         string,
     *   ai_model_id?:     int,
     *   api_provider:     string,
     *   tokens_prompt:    int,
     *   tokens_completion:int,
     *   tokens_total:     int,
     *   cost_usd:         float,
     *   response_time_ms: int,
     *   status:           string,
     *   error_message?:   string
     * } $data
     */
    public function logApiUsage(array $data): void
    {
        try {
            $this->db->insert('api_usage_logs', [
                'bot_id'            => $data['bot_id']            ?? null,
                'conversation_id'   => $data['conversation_id']   ?? null,
                'message_id'        => $data['message_id']        ?? null,
                'platform'          => $data['platform'],
                'ai_model_id'       => $data['ai_model_id']       ?? null,
                'api_provider'      => $data['api_provider']      ?? 'openrouter',
                'tokens_prompt'     => (int)($data['tokens_prompt']     ?? 0),
                'tokens_completion' => (int)($data['tokens_completion'] ?? 0),
                'tokens_total'      => (int)($data['tokens_total']      ?? 0),
                'cost_usd'          => (float)($data['cost_usd']        ?? 0),
                'response_time_ms'  => (int)($data['response_time_ms']  ?? 0),
                'status'            => $data['status']            ?? 'success',
                'error_message'     => $data['error_message']     ?? null,
            ]);
        } catch (\Throwable $e) {
            $this->writeToFile('db_errors.log', 'logApiUsage failed: ' . $e->getMessage());
        }
    }

    // ----------------------------------------------------------------
    // 2. Admin Activity Log (audit trail)
    // ----------------------------------------------------------------

    /**
     * Log an admin action to admin_activity_logs.
     *
     * @param int|null $userId     admin_users.id
     * @param string   $action     e.g. 'model.update', 'user.create', 'settings.save'
     * @param string   $targetType e.g. 'ai_model', 'admin_user', 'system_setting'
     * @param int|null $targetId
     * @param mixed    $oldValue   Previous state (will be JSON-encoded)
     * @param mixed    $newValue   New state (will be JSON-encoded)
     */
    public function logActivity(
        ?int   $userId,
        string $action,
        string $targetType = '',
        ?int   $targetId   = null,
        mixed  $oldValue   = null,
        mixed  $newValue   = null,
        ?int   $botId      = null
    ): void {
        try {
            $this->db->insert('admin_activity_logs', [
                'bot_id'      => $botId,
                'user_id'     => $userId,
                'action'      => $action,
                'target_type' => $targetType ?: null,
                'target_id'   => $targetId,
                'old_value'   => $oldValue !== null ? json_encode($oldValue, JSON_UNESCAPED_UNICODE) : null,
                'new_value'   => $newValue !== null ? json_encode($newValue, JSON_UNESCAPED_UNICODE) : null,
                'ip_address'  => $this->getClientIp(),
                'user_agent'  => $_SERVER['HTTP_USER_AGENT'] ?? null,
            ]);
        } catch (\Throwable $e) {
            $this->writeToFile('db_errors.log', 'logActivity failed: ' . $e->getMessage());
        }
    }

    // ----------------------------------------------------------------
    // 3. Chat / Webhook Log
    // ----------------------------------------------------------------

    /**
     * Log an incoming webhook or chat request to a daily file.
     *
     * @param string $platform  'web' | 'facebook' | 'line'
     * @param array  $data      Request data to log
     * @param string $level     'info' | 'error' | 'debug'
     */
    public function logChat(string $platform, array $data, string $level = 'info'): void
    {
        $filename = "chat_{$platform}_" . date('Y-m-d') . '.log';
        $line     = sprintf(
            "[%s][%s] %s\n",
            date('Y-m-d H:i:s'),
            strtoupper($level),
            json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
        $this->writeToFile($filename, $line, false);
    }

    // ----------------------------------------------------------------
    // 4. Application Error Log
    // ----------------------------------------------------------------

    public function logError(string $context, string $message, array $extra = []): void
    {
        $data = array_merge(['context' => $context, 'message' => $message], $extra);
        $line = sprintf(
            "[%s][ERROR] %s\n",
            date('Y-m-d H:i:s'),
            json_encode($data, JSON_UNESCAPED_UNICODE)
        );
        $this->writeToFile('errors_' . date('Y-m') . '.log', $line, false);
    }

    // ----------------------------------------------------------------
    // 5. Utility: Get Recent Logs for Admin UI
    // ----------------------------------------------------------------

    /**
     * Get recent entries from admin_activity_logs.
     *
     * @param int $limit
     * @return array<int, array<string, mixed>>
     */
    public function getRecentActivity(int $limit = 20): array
    {
        return $this->db->fetchAll(
            'SELECT al.*, au.display_name AS user_name, au.email AS user_email
             FROM admin_activity_logs al
             LEFT JOIN admin_users au ON au.id = al.user_id
             ORDER BY al.created_at DESC
             LIMIT ?',
            [$limit]
        );
    }

    /**
     * Get recent API usage logs.
     *
     * @param int $limit
     * @return array<int, array<string, mixed>>
     */
    public function getRecentApiUsage(int $limit = 50): array
    {
        return $this->db->fetchAll(
            'SELECT aul.*, am.display_name AS model_name
             FROM api_usage_logs aul
             LEFT JOIN ai_models am ON am.id = aul.ai_model_id
             ORDER BY aul.created_at DESC
             LIMIT ?',
            [$limit]
        );
    }

    // ----------------------------------------------------------------
    // Private Helpers
    // ----------------------------------------------------------------

    private function writeToFile(string $filename, string $content, bool $prefix = true): void
    {
        $path = $this->logDir . '/' . $filename;
        $line = $prefix
            ? sprintf("[%s] %s\n", date('Y-m-d H:i:s'), $content)
            : $content;

        @file_put_contents($path, $line, FILE_APPEND | LOCK_EX);
    }

    private function getClientIp(): string
    {
        return \App\Helpers\Network::getClientIp();
    }
}
