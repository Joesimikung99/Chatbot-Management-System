<?php
/**
 * Token Analytics Service
 * AI Chatbot System — CBMS
 *
 * Provides aggregated token usage statistics for the admin dashboard
 * and the Token Usage Analytics page.
 */

namespace App\Services;

use App\Helpers\Database;

class TokenAnalyticsService
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    // ----------------------------------------------------------------
    // 1. Usage by Platform
    // ----------------------------------------------------------------

    /**
     * Get token usage totals broken down by platform for a date range.
     *
     * @param  string $dateFrom  Y-m-d
     * @param  string $dateTo    Y-m-d
     * @return array<int, array{
     *   platform: string,
     *   conversations: int,
     *   messages: int,
     *   tokens_prompt: int,
     *   tokens_completion: int,
     *   tokens_total: int,
     *   cost_usd: float,
     *   avg_tokens_per_msg: float
     * }>
     */
    public function getUsageByPlatform(string $dateFrom, string $dateTo, ?int $botId = null): array
    {
        $botFilter = $botId ? 'AND c.bot_id = ?' : '';
        $params = [$dateFrom, $dateTo];
        if ($botId) $params[] = $botId;

        return $this->db->fetchAll(
            "SELECT
               c.platform,
               COUNT(DISTINCT c.id)              AS conversations,
               COUNT(m.id)                        AS messages,
               COALESCE(SUM(m.tokens_prompt), 0)      AS tokens_prompt,
               COALESCE(SUM(m.tokens_completion), 0)  AS tokens_completion,
               COALESCE(SUM(m.tokens_total), 0)       AS tokens_total,
               COALESCE(SUM(
                 CASE WHEN am.id IS NOT NULL
                 THEN (m.tokens_prompt     * am.pricing_prompt)
                    + (m.tokens_completion * GREATEST(am.pricing_completion, 0))
                 ELSE 0 END
               ), 0)                               AS cost_usd,
               CASE WHEN COUNT(m.id) > 0
                 THEN ROUND(SUM(m.tokens_total) / COUNT(m.id), 0)
                 ELSE 0
               END                                AS avg_tokens_per_msg
             FROM conversations c
             LEFT JOIN messages m ON m.conversation_id = c.id
                                  AND m.role = 'assistant'
             LEFT JOIN ai_models am ON am.id = m.ai_model_id
             WHERE DATE(c.started_at) BETWEEN ? AND ?
               {$botFilter}
             GROUP BY c.platform
             ORDER BY tokens_total DESC",
            $params
        );
    }

    // ----------------------------------------------------------------
    // 2. Usage by AI Model
    // ----------------------------------------------------------------

    /**
     * Get token usage broken down by AI model.
     *
     * @return array<int, array{
     *   model_name: string,
     *   provider: string,
     *   conversations: int,
     *   messages: int,
     *   tokens_total: int,
     *   cost_usd: float,
     *   avg_response_ms: float
     * }>
     */
    public function getUsageByModel(string $dateFrom, string $dateTo, ?int $botId = null): array
    {
        $botFilter = $botId ? 'AND c.bot_id = ?' : '';
        $params = [$dateFrom, $dateTo];
        if ($botId) $params[] = $botId;

        return $this->db->fetchAll(
            "SELECT
               am.display_name              AS model_name,
               am.provider_name             AS provider,
               am.openrouter_model_id       AS model_id,
               COUNT(DISTINCT c.id)         AS conversations,
               COUNT(m.id)                  AS messages,
               COALESCE(SUM(m.tokens_total), 0)    AS tokens_total,
               COALESCE(SUM(m.tokens_prompt), 0)   AS tokens_prompt,
               COALESCE(SUM(m.tokens_completion),0) AS tokens_completion,
               COALESCE(SUM(
                 (m.tokens_prompt * GREATEST(am.pricing_prompt, 0))
               + (m.tokens_completion * GREATEST(am.pricing_completion, 0))
               ), 0)                        AS cost_usd,
               ROUND(AVG(m.response_time_ms), 0) AS avg_response_ms
             FROM messages m
             INNER JOIN ai_models am ON am.id = m.ai_model_id
             INNER JOIN conversations c ON c.id = m.conversation_id
             WHERE m.role = 'assistant'
               AND DATE(m.created_at) BETWEEN ? AND ?
               {$botFilter}
             GROUP BY am.id
             ORDER BY cost_usd DESC",
            $params
        );
    }

    // ----------------------------------------------------------------
    // 3. Daily Trend
    // ----------------------------------------------------------------

    /**
     * Get daily token usage trend for charting.
     *
     * @param  string $dateFrom
     * @param  string $dateTo
     * @param  string $platform 'all' | 'web' | 'facebook' | 'line'
     * @return array<int, array{date: string, tokens_total: int, cost_usd: float, conversations: int}>
     */
    public function getDailyTrend(string $dateFrom, string $dateTo, string $platform = 'all', ?int $botId = null): array
    {
        $platformFilter = $platform !== 'all' ? 'AND c.platform = ?' : '';
        $botFilter = $botId ? 'AND c.bot_id = ?' : '';
        $params = [$dateFrom, $dateTo];
        if ($platform !== 'all') $params[] = $platform;
        if ($botId) $params[] = $botId;

        return $this->db->fetchAll(
            "SELECT
               DATE(m.created_at)            AS `date`,
               COALESCE(SUM(m.tokens_total), 0)    AS tokens_total,
               COALESCE(SUM(m.tokens_prompt), 0)   AS tokens_prompt,
               COALESCE(SUM(m.tokens_completion),0) AS tokens_completion,
               COALESCE(SUM(
                 (m.tokens_prompt * GREATEST(am.pricing_prompt, 0))
               + (m.tokens_completion * GREATEST(am.pricing_completion, 0))
               ), 0)                          AS cost_usd,
               COUNT(DISTINCT c.id)           AS conversations
             FROM messages m
             INNER JOIN conversations c ON c.id = m.conversation_id
             LEFT JOIN ai_models am ON am.id = m.ai_model_id
             WHERE m.role = 'assistant'
               AND DATE(m.created_at) BETWEEN ? AND ?
               {$platformFilter}
               {$botFilter}
             GROUP BY DATE(m.created_at)
             ORDER BY `date` ASC",
            $params
        );
    }

    // ----------------------------------------------------------------
    // 4. Aggregate Daily Stats (Cron Job)
    // ----------------------------------------------------------------

    /**
     * Aggregate stats from api_usage_logs into token_usage_daily.
     * Should be called via cron at 23:59 daily.
     *
     * @param  string $date  Y-m-d (defaults to yesterday)
     */
    public function aggregateDailyStats(?string $date = null): void
    {
        $date ??= date('Y-m-d', strtotime('-1 day'));

        // Aggregate by platform
        $platforms = ['web', 'facebook', 'line'];
        foreach ($platforms as $platform) {
            $this->aggregateForPlatform($date, $platform);
        }

        // Aggregate 'all' combined
        $this->aggregateAll($date);
    }

    // ----------------------------------------------------------------
    // 5. Top Cost Models
    // ----------------------------------------------------------------

    /**
     * Get top models by cost (all time or recent 30 days).
     *
     * @param  int $limit
     * @return array<int, array{model_name: string, provider: string, cost_usd: float, tokens_total: int}>
     */
    public function getTopCostModels(int $limit = 10, ?int $botId = null): array
    {
        $botFilter = $botId ? 'AND tud.bot_id = ?' : '';
        $params = $botId ? [$botId, $limit] : [$limit];
        return $this->db->fetchAll(
            "SELECT
               am.display_name  AS model_name,
               am.provider_name AS provider,
               SUM(tud.cost_usd)     AS cost_usd,
               SUM(tud.tokens_total) AS tokens_total,
               SUM(tud.total_messages) AS total_messages
             FROM token_usage_daily tud
             INNER JOIN ai_models am ON am.id = tud.ai_model_id
             WHERE tud.usage_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) {$botFilter}
             GROUP BY am.id
             ORDER BY cost_usd DESC
             LIMIT ?",
            $params
        );
    }

    // ----------------------------------------------------------------
    // 6. Cost Estimate (current month projection)
    // ----------------------------------------------------------------

    /**
     * Estimate cost for the current month and compare with last month.
     *
     * @return array{
     *   month_to_date: float,
     *   projected_end_of_month: float,
     *   last_month_total: float,
     *   change_percent: float,
     *   daily_average: float
     * }
     */
    public function getCostEstimate(?int $botId = null): array
    {
        $today      = date('Y-m-d');
        $monthStart = date('Y-m-01');
        $dayOfMonth = (int)date('j');
        $daysInMonth = (int)date('t');

        $botFilter = $botId ? 'AND bot_id = ?' : '';

        // Current month so far
        $mtdParams = [$monthStart, $today];
        if ($botId) $mtdParams[] = $botId;
        $mtd = (float)($this->db->fetchColumn(
            "SELECT COALESCE(SUM(cost_usd), 0)
             FROM token_usage_daily
             WHERE usage_date BETWEEN ? AND ? {$botFilter}",
            $mtdParams
        ) ?? 0);

        // Last month total
        $lastMonthStart = date('Y-m-01', strtotime('first day of last month'));
        $lastMonthEnd   = date('Y-m-t', strtotime('last day of last month'));
        $lmParams = [$lastMonthStart, $lastMonthEnd];
        if ($botId) $lmParams[] = $botId;
        $lastMonth = (float)($this->db->fetchColumn(
            "SELECT COALESCE(SUM(cost_usd), 0)
             FROM token_usage_daily
             WHERE usage_date BETWEEN ? AND ? {$botFilter}",
            $lmParams
        ) ?? 0);

        $dailyAvg  = $dayOfMonth > 0 ? $mtd / $dayOfMonth : 0;
        $projected = $dailyAvg * $daysInMonth;
        $change    = $lastMonth > 0 ? (($mtd - $lastMonth) / $lastMonth) * 100 : 0;

        return [
            'month_to_date'          => round($mtd, 4),
            'projected_end_of_month' => round($projected, 4),
            'last_month_total'       => round($lastMonth, 4),
            'change_percent'         => round($change, 1),
            'daily_average'          => round($dailyAvg, 4),
        ];
    }

    // ----------------------------------------------------------------
    // 7. Summary Cards (for Dashboard)
    // ----------------------------------------------------------------

    /**
     * Get today's summary stats.
     *
     * @return array{
     *   today_conversations: int,
     *   today_messages: int,
     *   today_tokens: int,
     *   today_cost: float,
     *   yesterday_conversations: int,
     *   yesterday_messages: int,
     *   yesterday_tokens: int,
     *   yesterday_cost: float
     * }
     */
    public function getDailySummary(?int $botId = null): array
    {
        $today     = date('Y-m-d');
        $yesterday = date('Y-m-d', strtotime('-1 day'));

        $botFilter = $botId ? 'AND c.bot_id = ?' : '';
        $query = "SELECT
            COUNT(DISTINCT c.id)         AS conversations,
            COUNT(m.id)                  AS messages,
            COALESCE(SUM(m.tokens_total),0)   AS tokens,
            COALESCE(SUM(
              (m.tokens_prompt * GREATEST(am.pricing_prompt, 0))
            + (m.tokens_completion * GREATEST(am.pricing_completion, 0))
            ),0)                         AS cost_usd
          FROM messages m
          INNER JOIN conversations c ON c.id = m.conversation_id
          LEFT JOIN ai_models am ON am.id = m.ai_model_id
          WHERE m.role = 'assistant'
            AND DATE(m.created_at) = ?
            {$botFilter}";

        $todayParams     = [$today];
        $yesterdayParams  = [$yesterday];
        if ($botId) { $todayParams[] = $botId; $yesterdayParams[] = $botId; }

        $todayData     = $this->db->fetch($query, $todayParams)     ?? [];
        $yesterdayData = $this->db->fetch($query, $yesterdayParams) ?? [];

        return [
            'today_conversations'     => (int)($todayData['conversations']     ?? 0),
            'today_messages'          => (int)($todayData['messages']          ?? 0),
            'today_tokens'            => (int)($todayData['tokens']            ?? 0),
            'today_cost'              => (float)($todayData['cost_usd']        ?? 0),
            'yesterday_conversations' => (int)($yesterdayData['conversations'] ?? 0),
            'yesterday_messages'      => (int)($yesterdayData['messages']      ?? 0),
            'yesterday_tokens'        => (int)($yesterdayData['tokens']        ?? 0),
            'yesterday_cost'          => (float)($yesterdayData['cost_usd']    ?? 0),
        ];
    }

    // ----------------------------------------------------------------
    // 8. Platform Daily Breakdown (for Dashboard charts)
    // ----------------------------------------------------------------

    /**
     * Get 30-day daily conversation counts per platform.
     * Returns data suitable for Chart.js line chart.
     */
    public function getPlatformDailyChart(int $days = 30, ?int $botId = null): array
    {
        $from = date('Y-m-d', strtotime("-{$days} days"));
        $to   = date('Y-m-d');

        $botFilter = $botId ? 'AND bot_id = ?' : '';
        $params = [$from, $to];
        if ($botId) $params[] = $botId;

        $rows = $this->db->fetchAll(
            "SELECT
               DATE(started_at) AS `date`,
               platform,
               COUNT(*)         AS count
             FROM conversations
             WHERE DATE(started_at) BETWEEN ? AND ?
               {$botFilter}
             GROUP BY DATE(started_at), platform
             ORDER BY `date` ASC",
            $params
        );

        // Fill in date gaps and organize by platform
        $dates    = [];
        $d        = strtotime($from);
        $end      = strtotime($to);
        while ($d <= $end) {
            $dates[] = date('Y-m-d', $d);
            $d = strtotime('+1 day', $d);
        }

        $byPlatform = ['web' => [], 'facebook' => [], 'line' => []];
        $indexed    = [];
        foreach ($rows as $row) {
            $indexed[$row['date']][$row['platform']] = (int)$row['count'];
        }

        foreach ($dates as $date) {
            foreach (['web', 'facebook', 'line'] as $p) {
                $byPlatform[$p][] = $indexed[$date][$p] ?? 0;
            }
        }

        return [
            'labels'   => $dates,
            'web'      => $byPlatform['web'],
            'facebook' => $byPlatform['facebook'],
            'line'     => $byPlatform['line'],
        ];
    }

    // ----------------------------------------------------------------
    // Private Helpers
    // ----------------------------------------------------------------

    private function aggregateForPlatform(string $date, string $platform): void
    {
        $data = $this->db->fetchAll(
            "SELECT
               m.ai_model_id,
               COUNT(DISTINCT c.id)          AS total_conversations,
               COUNT(m.id)                   AS total_messages,
               SUM(m.tokens_prompt)          AS tokens_prompt,
               SUM(m.tokens_completion)      AS tokens_completion,
               SUM(m.tokens_total)           AS tokens_total,
               SUM(
                 (m.tokens_prompt * GREATEST(COALESCE(am.pricing_prompt,0), 0))
               + (m.tokens_completion * GREATEST(COALESCE(am.pricing_completion,0), 0))
               )                             AS cost_usd,
               ROUND(AVG(m.response_time_ms),0) AS avg_response_ms,
               SUM(m.is_fallback)            AS fallback_count
             FROM messages m
             INNER JOIN conversations c ON c.id = m.conversation_id
             LEFT JOIN ai_models am ON am.id = m.ai_model_id
             WHERE c.platform = ?
               AND DATE(m.created_at) = ?
               AND m.role = 'assistant'
             GROUP BY m.ai_model_id",
            [$platform, $date]
        );

        foreach ($data as $row) {
            $this->db->upsert('token_usage_daily', [
                'usage_date'          => $date,
                'platform'            => $platform,
                'ai_model_id'         => $row['ai_model_id'],
                'total_conversations' => $row['total_conversations'],
                'total_messages'      => $row['total_messages'],
                'tokens_prompt'       => $row['tokens_prompt']    ?? 0,
                'tokens_completion'   => $row['tokens_completion'] ?? 0,
                'tokens_total'        => $row['tokens_total']     ?? 0,
                'cost_usd'            => $row['cost_usd']         ?? 0,
                'avg_response_ms'     => $row['avg_response_ms']  ?? 0,
                'fallback_count'      => $row['fallback_count']   ?? 0,
            ]);
        }
    }

    private function aggregateAll(string $date): void
    {
        // Combined totals across all platforms
        $row = $this->db->fetch(
            "SELECT
               NULL                              AS ai_model_id,
               COUNT(DISTINCT c.id)              AS total_conversations,
               COUNT(m.id)                       AS total_messages,
               SUM(m.tokens_prompt)              AS tokens_prompt,
               SUM(m.tokens_completion)          AS tokens_completion,
               SUM(m.tokens_total)               AS tokens_total,
               SUM(
                 (m.tokens_prompt * GREATEST(COALESCE(am.pricing_prompt,0), 0))
               + (m.tokens_completion * GREATEST(COALESCE(am.pricing_completion,0), 0))
               )                                 AS cost_usd,
               ROUND(AVG(m.response_time_ms),0)  AS avg_response_ms,
               SUM(m.is_fallback)                AS fallback_count
             FROM messages m
             INNER JOIN conversations c ON c.id = m.conversation_id
             LEFT JOIN ai_models am ON am.id = m.ai_model_id
             WHERE DATE(m.created_at) = ?
               AND m.role = 'assistant'",
            [$date]
        );

        if ($row) {
            $this->db->upsert('token_usage_daily', array_merge($row, [
                'usage_date' => $date,
                'platform'   => 'all',
                'ai_model_id'=> null,
            ]));
        }
    }
}
