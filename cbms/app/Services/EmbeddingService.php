<?php
/**
 * Embedding Service
 * AI Chatbot System — CBMS
 *
 * Handles:
 * - Text chunking (split documents into overlapping chunks)
 * - Cosine similarity search against knowledge_chunks
 * - Vector comparison for RAG retrieval
 */

namespace App\Services;

use App\Helpers\Database;

class EmbeddingService
{
    private Database $db;
    private OpenRouterService $openRouter;

    // ~1200 Thai chars ≈ 600 tokens/chunk: finer retrieval granularity and a
    // much smaller prompt than the old 3200 (top-5 × 3200 ≈ 8k tokens of context).
    // Applies to newly (re-)synced sources only; existing chunks keep their size.
    private int $defaultChunkSize = 1200;
    private int $defaultChunkOverlap = 200;

    /** In-request memoization of query embeddings (md5(text) => vector). */
    private array $embedCache = [];

    /** Days a cached query embedding stays usable (query_embeddings table). */
    private const QUERY_CACHE_TTL_DAYS = 30;

    public function __construct()
    {
        $this->db         = Database::getInstance();
        $this->openRouter = new OpenRouterService();
    }

    /**
     * The embedding model currently in effect — part of every query-cache key
     * so a model switch can never serve vectors of the wrong dimension.
     */
    public function embeddingModel(): string
    {
        return $_ENV['EMBEDDING_MODEL']
            ?? $_ENV['OPENROUTER_EMBEDDING_MODEL']
            ?? 'openai/text-embedding-3-small';
    }

    /**
     * Embed a single query string. Three layers, cheapest first:
     *   1. in-request memo (multi-pass retrieval reuses the same vector)
     *   2. query_embeddings table — repeat questions across requests skip the
     *      ~300ms embedding API call entirely (migration 011; optional)
     *   3. embedding API
     */
    private function embedQuery(string $text): array
    {
        $key = md5($this->embeddingModel() . "\n" . $text);

        if (array_key_exists($key, $this->embedCache)) {
            return $this->embedCache[$key];
        }

        $vector = $this->fetchCachedQueryVector($key);

        if ($vector === null) {
            $vector = $this->openRouter->createEmbedding($text);
            if (!empty($vector)) {
                $this->storeCachedQueryVector($key, $vector);
            }
        }

        return $this->embedCache[$key] = $vector;
    }

    private function fetchCachedQueryVector(string $hash): ?array
    {
        try {
            $json = $this->db->fetchColumn(
                'SELECT embedding FROM query_embeddings
                 WHERE hash = ? AND created_at > DATE_SUB(NOW(), INTERVAL ' . self::QUERY_CACHE_TTL_DAYS . ' DAY)',
                [$hash]
            );
            if ($json === null) {
                return null;
            }
            $vector = json_decode((string)$json, true);
            return is_array($vector) && $vector !== [] ? $vector : null;
        } catch (\Throwable) {
            // query_embeddings table may not exist yet (migration 011)
            return null;
        }
    }

    private function storeCachedQueryVector(string $hash, array $vector): void
    {
        try {
            // created_at is set explicitly so a re-store after TTL expiry
            // refreshes the row (upsert would otherwise keep the stale date
            // and the entry would miss forever).
            $this->db->upsert('query_embeddings', [
                'hash'       => $hash,
                'model'      => mb_substr($this->embeddingModel(), 0, 191),
                'embedding'  => json_encode($vector),
                'created_at' => date('Y-m-d H:i:s'),
            ]);

            // Opportunistic cleanup of stale rows (~1% of stores)
            if (random_int(1, 100) === 1) {
                $this->db->query(
                    'DELETE FROM query_embeddings
                     WHERE created_at <= DATE_SUB(NOW(), INTERVAL ' . self::QUERY_CACHE_TTL_DAYS . ' DAY)'
                );
            }
        } catch (\Throwable) {
            // Cache is best-effort — never let it break retrieval
        }
    }

    /**
     * Public accessor for the memoized query embedding — lets callers
     * (AnswerCacheService, AIService) reuse the vector that retrieval
     * already fetched, without a second embedding API call.
     */
    public function getQueryEmbedding(string $text): array
    {
        return $this->embedQuery($text);
    }

    // ----------------------------------------------------------------
    // 1. Text Chunking
    // ----------------------------------------------------------------

    /**
     * Split a long text into overlapping chunks.
     *
     * Strategy: Split by paragraphs first, then merge into ~chunkSize
     * char-equivalent pieces with overlap.
     *
     * @param  string $text      Full document text
     * @param  int|null $chunkSize Approximate chars per chunk (default 3200)
     * @param  int|null $overlap   Overlap chars between chunks (default 400)
     * @return array<int, string>
     */
    public function chunkText(
        string $text,
        ?int   $chunkSize = null,
        ?int   $overlap   = null
    ): array {
        $chunkSize ??= $this->defaultChunkSize;
        $overlap   ??= $this->defaultChunkOverlap;
        $text = $this->cleanText($text);

        if (mb_strlen($text, 'UTF-8') <= $chunkSize) {
            return [$text];
        }

        // We split into paragraphs.
        $paragraphs = preg_split('/\n{2,}/', $text);
        $paragraphs = array_filter(array_map('trim', $paragraphs));

        $chunks  = [];
        $current = '';

        foreach ($paragraphs as $para) {
            $paraLen = mb_strlen($para, 'UTF-8');
            
            // If current + para fits in a chunk, append it.
            if (mb_strlen($current, 'UTF-8') + $paraLen + 2 <= $chunkSize) {
                $current .= ($current === '' ? '' : "\n\n") . $para;
                continue;
            }
            
            // It doesn't fit. 
            // 1. If $current is not empty, push it to chunks.
            if ($current !== '') {
                $chunks[] = $current;
                // Start a new $current with the overlap from the OLD $current, plus the new $para.
                $current = $this->getOverlap($current, $overlap) . "\n\n" . $para;
            } else {
                $current = $para;
            }
            
            // 2. Now $current might be LARGER than $chunkSize (because $para itself could be massive).
            // If it is, we MUST force split it right now.
            if (mb_strlen($current, 'UTF-8') > $chunkSize) {
                $forced  = $this->forceSplit($current, $chunkSize, $overlap);
                // Keep the last part as $current, and push the rest.
                if (count($forced) > 1) {
                    $chunks  = array_merge($chunks, array_slice($forced, 0, -1));
                    $current = end($forced) ?: '';
                }
            }
        }

        if (mb_strlen(trim($current), 'UTF-8') > 20) {
            $chunks[] = trim($current);
        }

        return array_values($chunks);
    }

    // ----------------------------------------------------------------
    // 2. Store Chunks with Embeddings
    // ----------------------------------------------------------------

    /**
     * Generate embeddings for an array of chunks and store them in DB.
     *
     * @param  int      $sourceId  knowledge_sources.id
     * @param  string[] $chunks    Array of text chunks
     * @param  bool     $withEmbedding  Whether to call embedding API (set false for testing)
     * @return int  Number of chunks stored
     */
    public function storeChunks(int $sourceId, array $chunks, bool $withEmbedding = true): int
    {
        $source   = $this->db->fetch(
            'SELECT file_name, bot_id FROM knowledge_sources WHERE id = ?',
            [$sourceId]
        );
        $docTitle = trim($source['file_name'] ?? '');

        // Delete old chunks for this source
        $this->db->delete('knowledge_chunks', ['source_id' => $sourceId]);

        $stored = 0;
        
        if (!$withEmbedding) {
            foreach ($chunks as $index => $content) {
                $content = trim($content);
                $this->db->insert('knowledge_chunks', [
                    'source_id'   => $sourceId,
                    'chunk_index' => $index,
                    'content'     => $content,
                    'embedding'   => null,
                    'token_count' => mb_strlen($content),
                    'metadata'    => json_encode(['index' => $index, 'length' => mb_strlen($content, 'UTF-8')]),
                ]);
                $stored++;
            }
        } else {
            // Batch process for embeddings (100 at a time to prevent payload too large)
            $batches = array_chunk($chunks, 100, true);
            foreach ($batches as $batch) {
                // Filter and prepare valid chunks
                $validChunks = [];
                $originalKeys = [];
                foreach ($batch as $index => $content) {
                    $content = trim($content);
                    if ($content !== '') {
                        $validChunks[] = $content;
                        $originalKeys[] = $index;
                    }
                }
                
                if (empty($validChunks)) continue;

                // Call API once for the entire batch. The document title is
                // prefixed into the EMBEDDING input only (contextual embedding:
                // topical queries match chunks of the right document better);
                // the stored content stays clean, so re-processing never
                // accumulates headers.
                $embedInputs = $docTitle === ''
                    ? $validChunks
                    : array_map(fn($c) => $docTitle . "\n" . $c, $validChunks);
                $vectors = $this->openRouter->createEmbedding($embedInputs);

                // Insert into DB
                foreach ($validChunks as $i => $content) {
                    $index = $originalKeys[$i];
                    $embedding = isset($vectors[$i]['embedding']) ? json_encode($vectors[$i]['embedding']) : null;
                    
                    $this->db->insert('knowledge_chunks', [
                        'source_id'   => $sourceId,
                        'chunk_index' => $index,
                        'content'     => $content,
                        'embedding'   => $embedding,
                        'token_count' => $this->estimateTokens($content),
                        'metadata'    => json_encode(['index' => $index, 'length' => mb_strlen($content, 'UTF-8')]),
                    ]);
                    $stored++;
                }
            }
        }

        // Update chunk_count on the source
        $this->db->update('knowledge_sources', ['chunk_count' => $stored], ['id' => $sourceId]);

        // KB changed → cached answers for this bot may now be stale
        try {
            $this->db->query('DELETE FROM answer_cache WHERE bot_id <=> ?', [$source['bot_id'] ?? null]);
        } catch (\Throwable) {
            // answer_cache table may not exist yet (migration 007)
        }

        return $stored;
    }

    // ----------------------------------------------------------------
    // 3. Similarity Search
    // ----------------------------------------------------------------

    /**
     * Find the most relevant knowledge chunks for a query using cosine similarity.
     * Single query; rows are streamed off the statement one at a time so only
     * the running top-K (not the whole KB) is ever held in memory.
     *
     * @param  string $queryText    The user's question
     * @param  int    $topK         Number of top chunks to return
     * @param  float  $minScore     Minimum similarity score (0–1)
     * @param  int[]  $sourceIds    Optional: restrict to specific source IDs
     * @return array<int, array{id: int, content: string, score: float, source_id: int}>
     */
    public function findSimilarChunks(
        string $queryText,
        int    $topK     = 5,
        float  $minScore = 0.7,
        array  $sourceIds = [],
        ?int   $botId    = null
    ): array {
        $queryVector = $this->embedQuery($queryText);
        if (empty($queryVector)) {
            return [];
        }

        $sql = 'SELECT kc.id, kc.content, kc.embedding, kc.source_id
                FROM knowledge_chunks kc
                INNER JOIN knowledge_sources ks ON ks.id = kc.source_id
                WHERE ks.is_active = 1
                  AND kc.embedding IS NOT NULL';

        $params = [];

        if ($botId !== null) {
            $sql    .= ' AND ks.bot_id = ?';
            $params[] = $botId;
        }

        if (!empty($sourceIds)) {
            $placeholders = implode(',', array_fill(0, count($sourceIds), '?'));
            $sql    .= " AND kc.source_id IN ({$placeholders})";
            $params  = array_merge($params, $sourceIds);
        }

        $stmt      = $this->db->query($sql, $params);
        $topScored = [];

        while ($chunk = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $chunkVector = json_decode($chunk['embedding'], true);
            if (!is_array($chunkVector)) {
                continue;
            }

            $score = $this->cosineSimilarity($queryVector, $chunkVector);
            if ($score < $minScore) {
                continue;
            }

            $entry = [
                'id'        => (int)$chunk['id'],
                'content'   => $chunk['content'],
                'score'     => $score,
                'source_id' => (int)$chunk['source_id'],
            ];

            if (count($topScored) < $topK) {
                $topScored[] = $entry;
                usort($topScored, fn($a, $b) => $b['score'] <=> $a['score']);
            } elseif ($score > $topScored[$topK - 1]['score']) {
                $topScored[$topK - 1] = $entry;
                usort($topScored, fn($a, $b) => $b['score'] <=> $a['score']);
            }
        }

        return $topScored;
    }

    // ----------------------------------------------------------------
    // 3a. Keyword Search (hybrid rescue)
    // ----------------------------------------------------------------

    /**
     * FULLTEXT keyword search over knowledge_chunks — the rescue pass when
     * every vector attempt came up empty. Embeddings routinely miss exact
     * terms (EDUROAM, ALIST, Web-OPAC, room names); the ngram index catches
     * them literally.
     *
     * Returns [] silently when the ft_content index does not exist yet
     * (migration 011), so callers need no feature check.
     *
     * @return array<int, array{id: int, content: string, source_id: int}>
     */
    public function findChunksByKeyword(
        string $queryText,
        int    $limit = 3,
        ?int   $botId = null
    ): array {
        try {
            $sql = 'SELECT kc.id, kc.content, kc.source_id,
                           MATCH(kc.content) AGAINST(? IN NATURAL LANGUAGE MODE) AS kw_score
                    FROM knowledge_chunks kc
                    INNER JOIN knowledge_sources ks ON ks.id = kc.source_id
                    WHERE ks.is_active = 1
                      AND MATCH(kc.content) AGAINST(? IN NATURAL LANGUAGE MODE)';

            $params = [$queryText, $queryText];
            if ($botId !== null) {
                $sql    .= ' AND ks.bot_id = ?';
                $params[] = $botId;
            }

            $rows = $this->db->fetchAll(
                $sql . ' ORDER BY kw_score DESC LIMIT ' . max(1, $limit),
                $params
            );

            // ngram matching is loose for Thai — require a meaningful lead:
            // keep only rows scoring at least half of the best hit.
            if (empty($rows)) {
                return [];
            }
            $top = (float)$rows[0]['kw_score'];
            if ($top <= 0) {
                return [];
            }

            $chunks = [];
            foreach ($rows as $r) {
                if ((float)$r['kw_score'] < $top * 0.5) {
                    break;
                }
                // No 'score' key on purpose: kw_score is not a 0–1 cosine and
                // must not be rendered as "ความเกี่ยวข้อง: N%" in the prompt.
                $chunks[] = [
                    'id'        => (int)$r['id'],
                    'content'   => $r['content'],
                    'source_id' => (int)$r['source_id'],
                ];
            }
            return $chunks;

        } catch (\Throwable) {
            // ft_content index missing, or DB unavailable — rescue is optional
            return [];
        }
    }

    // ----------------------------------------------------------------
    // 3b. Wiki Question Search (retrieval tier 1)
    // ----------------------------------------------------------------

    /**
     * Match a user's question against the example questions of published wiki
     * pages (doc2query). Question-vs-question scores far higher than
     * question-vs-content — especially in Thai — so a hit here is a much
     * stronger signal than a raw chunk match, and can use a higher threshold.
     *
     * Returns at most one entry per page (the best-scoring question of that
     * page); the caller pulls the page's chunks as context.
     *
     * Uses the same memoized query vector as findSimilarChunks(), so calling
     * both for one request costs a single embedding API call.
     *
     * @param  string $queryText  The user's question
     * @param  int    $topK       Max number of pages to return
     * @param  float  $minScore   Minimum similarity score (0–1)
     * @return array<int, array{source_id: int, page_id: int, question: string, score: float}>
     */
    public function findPagesByQuestion(
        string $queryText,
        int    $topK     = 3,
        float  $minScore = 0.45,
        ?int   $botId    = null
    ): array {
        $queryVector = $this->embedQuery($queryText);
        if (empty($queryVector)) {
            return [];
        }

        $sql = 'SELECT wq.page_id, wq.source_id, wq.question, wq.embedding
                FROM wiki_questions wq
                INNER JOIN knowledge_sources ks ON ks.id = wq.source_id
                WHERE ks.is_active = 1
                  AND wq.embedding IS NOT NULL';

        $params = [];
        if ($botId !== null) {
            $sql    .= ' AND ks.bot_id = ?';
            $params[] = $botId;
        }

        try {
            $stmt = $this->db->query($sql, $params);
        } catch (\Throwable) {
            // wiki_questions may not exist yet (migration 009) — the caller
            // falls through to plain chunk retrieval.
            return [];
        }

        $best = [];

        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $vector = json_decode($row['embedding'], true);
            if (!is_array($vector)) {
                continue;
            }

            $score = $this->cosineSimilarity($queryVector, $vector);
            if ($score < $minScore) {
                continue;
            }

            $sourceId = (int)$row['source_id'];
            if (isset($best[$sourceId]) && $best[$sourceId]['score'] >= $score) {
                continue;
            }

            $best[$sourceId] = [
                'source_id' => $sourceId,
                'page_id'   => (int)$row['page_id'],
                'question'  => $row['question'],
                'score'     => $score,
            ];
        }

        $pages = array_values($best);
        usort($pages, fn($a, $b) => $b['score'] <=> $a['score']);

        return array_slice($pages, 0, $topK);
    }

    /**
     * All chunks belonging to the given sources, in document order.
     * Wiki pages are short (1–2 chunks by design), so a page that matched on a
     * question is fed to the model whole rather than chunk-by-chunk by score.
     *
     * @param  int[] $sourceIds
     * @return array<int, array{id: int, content: string, source_id: int}>
     */
    public function getChunksBySource(array $sourceIds): array
    {
        if (empty($sourceIds)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($sourceIds), '?'));
        $rows = $this->db->fetchAll(
            "SELECT id, content, source_id
             FROM knowledge_chunks
             WHERE source_id IN ({$placeholders})
             ORDER BY source_id ASC, chunk_index ASC",
            array_map('intval', $sourceIds)
        );

        return array_map(fn($r) => [
            'id'        => (int)$r['id'],
            'content'   => $r['content'],
            'source_id' => (int)$r['source_id'],
        ], $rows);
    }

    // ----------------------------------------------------------------
    // 4. Cosine Similarity
    // ----------------------------------------------------------------

    /**
     * Compute cosine similarity between two vectors.
     * Returns a value in [0, 1] (1 = identical direction).
     *
     * @param  float[] $vecA
     * @param  float[] $vecB
     * @return float
     */
    public function cosineSimilarity(array $vecA, array $vecB): float
    {
        $len = count($vecA);

        // Different lengths = vectors from different embedding models
        // (e.g. mid-switch before --re-embed-all finishes). Comparing a
        // truncated prefix produces confidently-wrong scores — treat such
        // pairs as unrelated instead.
        if ($len === 0 || $len !== count($vecB)) {
            return 0.0;
        }

        $dotProduct = 0.0;
        $normA      = 0.0;
        $normB      = 0.0;

        for ($i = 0; $i < $len; $i++) {
            $dotProduct += $vecA[$i] * $vecB[$i];
            $normA      += $vecA[$i] * $vecA[$i];
            $normB      += $vecB[$i] * $vecB[$i];
        }

        $denom = sqrt($normA) * sqrt($normB);
        if ($denom == 0) {
            return 0.0;
        }

        return (float)($dotProduct / $denom);
    }

    // ----------------------------------------------------------------
    // 5. Token Estimation
    // ----------------------------------------------------------------

    /**
     * Rough token count estimate (1 token ≈ 4 chars for English,
     * ~2 chars for Thai/CJK). Used for chunk sizing.
     */
    public function estimateTokens(string $text): int
    {
        // Count Thai/CJK characters (approx 1 token per 2 chars)
        $thaiCount = preg_match_all('/[\x{0E00}-\x{0E7F}]/u', $text);
        $cjkCount  = preg_match_all('/[\x{4E00}-\x{9FFF}]/u', $text);
        $otherLen  = mb_strlen($text, 'UTF-8') - $thaiCount - $cjkCount;

        return (int)(($thaiCount * 0.5) + ($cjkCount * 0.5) + ($otherLen / 4));
    }

    // ----------------------------------------------------------------
    // Private Helpers
    // ----------------------------------------------------------------

    private function cleanText(string $text): string
    {
        // Remove excessive whitespace
        $text = preg_replace('/[ \t]+/', ' ', $text);
        // Normalize newlines
        $text = preg_replace('/\r\n?/', "\n", $text);
        // Remove more than 3 consecutive newlines
        $text = preg_replace('/\n{4,}/', "\n\n\n", $text);
        return trim($text);
    }

    private function getOverlap(string $text, int $overlapChars): string
    {
        if (mb_strlen($text, 'UTF-8') <= $overlapChars) {
            return $text;
        }
        return mb_substr($text, -$overlapChars, null, 'UTF-8');
    }

    private function forceSplit(string $text, int $chunkSize, int $overlap): array
    {
        $chunks = [];
        $start  = 0;
        $len    = mb_strlen($text, 'UTF-8');

        while ($start < $len) {
            $end      = min($start + $chunkSize, $len);
            $chunks[] = mb_substr($text, $start, $end - $start, 'UTF-8');
            
            if ($end >= $len) {
                break;
            }
            
            $start = $end - $overlap;
            
            // Failsafe to prevent negative progress or infinite loop
            if ($start <= $end - $chunkSize) {
                $start = $end; 
            }
        }

        return $chunks;
    }
}
