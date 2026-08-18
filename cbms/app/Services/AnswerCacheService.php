<?php
/**
 * Answer Cache Service
 * AI Chatbot System — CBMS
 *
 * Semantic FAQ cache: stores grounded (knowledge-based) answers keyed by the
 * question text and its embedding. Repeated questions — exact or paraphrased —
 * are served straight from MySQL without touching the LLM, cutting response
 * time from seconds to milliseconds and API cost to zero.
 *
 * Lookup strategy:
 *   1. Exact match on md5(normalized question) — no embedding call needed.
 *   2. Semantic match: cosine similarity of the query embedding vs cached
 *      question embeddings (>= ANSWER_CACHE_MIN_SIMILARITY, default 0.95).
 *
 * Entries expire after ANSWER_CACHE_TTL_HOURS (default 72) and are wiped for
 * a bot whenever its knowledge base is re-synced (EmbeddingService::storeChunks).
 *
 * Requires migration 007_answer_cache.sql. Degrades gracefully (no-ops)
 * if the table does not exist yet.
 */

namespace App\Services;

use App\Helpers\Database;

class AnswerCacheService
{
    private Database $db;
    private EmbeddingService $embedding;

    private bool  $enabled;
    private int   $ttlHours;
    private float $minSimilarity;

    /** Max cached rows scanned per semantic lookup (most-hit first). */
    private const SEMANTIC_SCAN_LIMIT = 300;

    public function __construct(EmbeddingService $embedding)
    {
        $this->db        = Database::getInstance();
        $this->embedding = $embedding;

        $this->enabled       = filter_var($_ENV['ANSWER_CACHE_ENABLED'] ?? 'true', FILTER_VALIDATE_BOOLEAN);
        $this->ttlHours      = max(1, (int)($_ENV['ANSWER_CACHE_TTL_HOURS'] ?? 72));
        $this->minSimilarity = (float)($_ENV['ANSWER_CACHE_MIN_SIMILARITY'] ?? 0.95);
    }

    // ----------------------------------------------------------------
    // Lookup
    // ----------------------------------------------------------------

    /**
     * Look up a cached answer for a question.
     *
     * @param  callable():array $vectorProvider  Lazily supplies the query embedding;
     *                                           only invoked when the exact-hash pass misses.
     * @return array|null  answer_cache row, or null on miss / disabled / missing table
     */
    public function lookup(?int $botId, string $question, callable $vectorProvider): ?array
    {
        if (!$this->enabled) {
            return null;
        }

        try {
            $row = $this->db->fetch(
                'SELECT * FROM answer_cache
                 WHERE bot_id <=> ? AND question_hash = ? AND expires_at > NOW()
                 LIMIT 1',
                [$botId, $this->hashQuestion($question)]
            );

            if (!$row) {
                $vector = $vectorProvider();
                if (!empty($vector)) {
                    $row = $this->findBySimilarity($botId, $vector);
                }
            }

            if (!$row) {
                return null;
            }

            $this->db->query(
                'UPDATE answer_cache SET hit_count = hit_count + 1 WHERE id = ?',
                [$row['id']]
            );

            return $row;

        } catch (\Throwable $e) {
            $this->logError('lookup failed: ' . $e->getMessage());
            return null;
        }
    }

    // ----------------------------------------------------------------
    // Store
    // ----------------------------------------------------------------

    /**
     * Store a grounded answer. Upserts on (bot_id, question_hash).
     */
    public function store(
        ?int    $botId,
        string  $question,
        array   $vector,
        string  $reply,
        ?string $modelId,
        array   $chunkIds = []
    ): void {
        if (!$this->enabled) {
            return;
        }

        try {
            $this->db->upsert('answer_cache', [
                'bot_id'             => $botId,
                'question_hash'      => $this->hashQuestion($question),
                'question_text'      => mb_substr($question, 0, 1000, 'UTF-8'),
                'question_embedding' => empty($vector) ? null : json_encode($vector),
                'reply'              => $reply,
                'model_id'           => $modelId,
                'chunk_ids'          => json_encode(array_values($chunkIds)),
                'expires_at'         => date('Y-m-d H:i:s', time() + $this->ttlHours * 3600),
            ]);

            // Opportunistic cleanup of expired rows (~2% of stores)
            if (random_int(1, 50) === 1) {
                $this->db->query('DELETE FROM answer_cache WHERE expires_at <= NOW()');
            }
        } catch (\Throwable $e) {
            $this->logError('store failed: ' . $e->getMessage());
        }
    }

    /**
     * Drop all cached answers for a bot (called when its KB changes).
     */
    public function invalidateBot(?int $botId): void
    {
        try {
            $this->db->query('DELETE FROM answer_cache WHERE bot_id <=> ?', [$botId]);
        } catch (\Throwable $e) {
            $this->logError('invalidateBot failed: ' . $e->getMessage());
        }
    }

    // ----------------------------------------------------------------
    // Private Helpers
    // ----------------------------------------------------------------

    private function findBySimilarity(?int $botId, array $vector): ?array
    {
        $rows = $this->db->fetchAll(
            'SELECT * FROM answer_cache
             WHERE bot_id <=> ? AND expires_at > NOW() AND question_embedding IS NOT NULL
             ORDER BY hit_count DESC
             LIMIT ' . self::SEMANTIC_SCAN_LIMIT,
            [$botId]
        );

        $best      = null;
        $bestScore = 0.0;

        foreach ($rows as $row) {
            $cachedVector = json_decode($row['question_embedding'], true);
            if (!is_array($cachedVector)) {
                continue;
            }
            $score = $this->embedding->cosineSimilarity($vector, $cachedVector);
            if ($score > $bestScore) {
                $bestScore = $score;
                $best      = $row;
            }
        }

        return ($best !== null && $bestScore >= $this->minSimilarity) ? $best : null;
    }

    /**
     * Normalize + hash a question so trivial variations (case, extra
     * whitespace) share one cache entry.
     */
    private function hashQuestion(string $question): string
    {
        $normalized = mb_strtolower(trim($question), 'UTF-8');
        $normalized = preg_replace('/\s+/u', ' ', $normalized) ?? $normalized;
        return md5($normalized);
    }

    private function logError(string $message): void
    {
        $logPath = dirname(__DIR__, 2) . '/storage/logs/answer_cache.log';
        @file_put_contents($logPath,
            sprintf("[%s] %s\n", date('Y-m-d H:i:s'), $message),
            FILE_APPEND | LOCK_EX
        );
    }
}
