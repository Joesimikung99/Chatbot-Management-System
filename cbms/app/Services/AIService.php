<?php
/**
 * AI Service — RAG Engine
 * AI Chatbot System — CBMS
 *
 * Orchestrates the full Retrieval-Augmented Generation pipeline:
 * 1. Retrieve relevant knowledge chunks (via EmbeddingService)
 * 2. Build context-enriched prompt
 * 3. Call OpenRouter chat completion
 * 4. Log token usage (via LogService)
 * 5. Return structured response
 */

namespace App\Services;

use App\Helpers\Database;
use App\Helpers\Network;

class AIService
{
    private Database $db;
    private OpenRouterService  $openRouter;
    private EmbeddingService   $embedding;
    private AnswerCacheService $answerCache;
    private LogService         $logger;

    private ?int $botId = null;
    private array $_sourceLabels = [];

    /** Per-request cache of the wiki index block (null = not built yet). */
    private ?string $_wikiIndex = null;

    // Defaults — overridden by bot_settings (or system_settings for legacy)
    private float  $temperature        = 0.7;
    private int    $maxTokens          = 1000;
    private float  $similarityThreshold = 0.15;
    private int    $topKChunks         = 5;
    private int    $historyLength      = 10;
    private string $systemPrompt       = '';
    private string $fallbackMessage    = 'ขออภัยครับ ไม่พบข้อมูลที่เกี่ยวข้อง กรุณาติดต่อเจ้าหน้าที่โดยตรง';

    /**
     * Tier-1 retrieval cutoff: how close a user's question must be to a wiki
     * page's example question to serve that page. Question-vs-question scores
     * run much higher than question-vs-content, hence the higher default than
     * similarityThreshold.
     */
    private float $wikiQuestionThreshold = 0.45;

    /** Wiki pages to feed as context on a tier-1 hit (each is 1–2 chunks by design). */
    private const WIKI_TOP_PAGES = 2;

    /** Max wiki pages listed in the system prompt index, and its token budget. */
    private const WIKI_INDEX_MAX_PAGES  = 40;
    private const WIKI_INDEX_MAX_TOKENS = 1500;

    /**
     * Total character budget for conversation history in the prompt.
     * historyLength still caps the message count; this caps their combined
     * size so a few long answers can't crowd out the retrieved context.
     */
    private const HISTORY_CHAR_BUDGET = 3000;

    public function __construct(?int $botId = null)
    {
        $this->db          = Database::getInstance();
        $this->openRouter  = new OpenRouterService();
        $this->embedding   = new EmbeddingService();
        $this->answerCache = new AnswerCacheService($this->embedding);
        $this->logger      = new LogService();
        $this->botId       = $botId;

        $this->loadSettings();
    }

    // ----------------------------------------------------------------
    // Main Entry: Process a user message
    // ----------------------------------------------------------------

    /**
     * Process a user message through the RAG pipeline.
     *
     * @param  string $userMessage     The user's question
     * @param  string $sessionId       Conversation session ID
     * @param  string $platform        'web' | 'facebook' | 'line'
     * @param  array  $options         Override: model_id, temperature, etc.
     * @return array{
     *   reply:             string,
     *   is_fallback:       bool,
     *   tokens_prompt:     int,
     *   tokens_completion: int,
     *   tokens_total:      int,
     *   cost_usd:          float,
     *   response_time_ms:  int,
     *   chunks_used:       int[],
     *   model_id:          string
     * }
     */
    public function chat(
        string $userMessage,
        string $sessionId,
        string $platform = 'web',
        array  $options  = []
    ): array {
        $startTime = microtime(true);

        // 1. Get or create conversation
        $conversation = $this->getOrCreateConversation($sessionId, $platform);
        $convId       = $conversation['id'];

        // 2. Determine which model to use
        $model      = $this->resolveModel($options['model_id'] ?? null);
        $modelDbId  = $model['id'] ?? null;
        $modelApiId = $model['openrouter_model_id'] ?? ($_ENV['OPENROUTER_DEFAULT_MODEL'] ?? 'openai/gpt-4o-mini');

        // 3. Answer cache — repeated knowledge questions skip the LLM entirely
        $cached = $this->lookupCachedAnswer($userMessage);
        if ($cached !== null) {
            $this->saveMessage($convId, 'user', $userMessage, $modelDbId);
            return $this->finalizeCachedTurn(
                $cached, $conversation, $platform, $sessionId,
                $modelDbId, $userMessage, $startTime
            );
        }

        // 4. Retrieve relevant knowledge chunks (RAG)
        [$chunks, $chunkIds, $isConversational] = $this->retrieveContext($userMessage, $options, (int)$convId);

        // Fallback = no chunks found AND the question needed knowledge
        $isFallback = !$isConversational && empty($chunks);

        // 5. Build prompt messages BEFORE saving the user message, so the
        //    fetched history does not already contain the current question
        //    (it gets appended separately as the final user turn).
        $messages = $this->buildMessages($conversation, $userMessage, $chunks);

        $this->saveMessage($convId, 'user', $userMessage, $modelDbId);

        // 6. Call OpenRouter
        try {
            $temperature = (float)($options['temperature'] ?? $this->temperature);
            $maxTokens   = (int)($options['max_tokens']   ?? $this->maxTokens);

            // Slightly higher temperature for conversational messages (more natural)
            if ($isConversational) {
                $temperature = max($temperature, 0.6);
            }

            $aiResponse = $this->openRouter->chat($modelApiId, $messages, [
                'temperature' => $temperature,
                'max_tokens'  => $maxTokens,
            ]);

            $reply              = $aiResponse['content'];
            $usage              = $aiResponse['usage'];
            $responseTimeMs     = $aiResponse['response_time_ms'];
            $actualModelId      = $aiResponse['model'];

        } catch (\Throwable $e) {
            // Graceful fallback on API error
            $this->logError('AIService::chat failed: ' . $e->getMessage());

            $reply          = $this->fallbackMessage;
            $usage          = ['prompt_tokens' => 0, 'completion_tokens' => 0, 'total_tokens' => 0];
            $responseTimeMs = (int)((microtime(true) - $startTime) * 1000);
            $actualModelId  = $modelApiId;
            $isFallback     = true;
        }

        // Use fallback message only if API failed or reply is empty
        if ($isFallback && empty(trim($reply))) {
            $reply = $this->fallbackMessage;
        }

        // 7-10. Persist the assistant turn (cost, save, stats, logging) + return
        return $this->finalizeTurn([
            'conversation'   => $conversation,
            'convId'         => $convId,
            'platform'       => $platform,
            'sessionId'      => $sessionId,
            'modelDbId'      => $modelDbId,
            'actualModelId'  => $actualModelId,
            'userMessage'    => $userMessage,
            'reply'          => $reply,
            'usage'          => $usage,
            'responseTimeMs' => $responseTimeMs,
            'chunkIds'       => $chunkIds,
            'isFallback'     => $isFallback,
            'cacheable'      => !$isConversational && !$isFallback && !$this->isFollowUpMessage($userMessage),
        ]);
    }

    // ----------------------------------------------------------------
    // Main Entry: Streaming variant
    // ----------------------------------------------------------------

    /**
     * Streaming version of chat(): runs the same RAG pipeline but streams the
     * model's reply token-by-token via $onDelta, then persists the turn.
     *
     * @param  callable $onDelta  fn(string $textChunk): void — called per token
     * @return array  Same shape as chat()
     */
    public function chatStream(
        string   $userMessage,
        string   $sessionId,
        string   $platform,
        array    $options,
        callable $onDelta
    ): array {
        $startTime = microtime(true);

        $conversation = $this->getOrCreateConversation($sessionId, $platform);
        $convId       = $conversation['id'];

        $model      = $this->resolveModel($options['model_id'] ?? null);
        $modelDbId  = $model['id'] ?? null;
        $modelApiId = $model['openrouter_model_id'] ?? ($_ENV['OPENROUTER_DEFAULT_MODEL'] ?? 'openai/gpt-4o-mini');

        // Answer cache — a hit streams the stored reply instantly, no LLM call
        $cached = $this->lookupCachedAnswer($userMessage);
        if ($cached !== null) {
            $this->saveMessage($convId, 'user', $userMessage, $modelDbId);
            $onDelta($cached['reply']);
            return $this->finalizeCachedTurn(
                $cached, $conversation, $platform, $sessionId,
                $modelDbId, $userMessage, $startTime
            );
        }

        [$chunks, $chunkIds, $isConversational] = $this->retrieveContext($userMessage, $options, (int)$convId);
        $isFallback = !$isConversational && empty($chunks);

        // Build BEFORE saving the user message so history excludes the current turn.
        $messages = $this->buildMessages($conversation, $userMessage, $chunks);

        $this->saveMessage($convId, 'user', $userMessage, $modelDbId);

        try {
            $temperature = (float)($options['temperature'] ?? $this->temperature);
            $maxTokens   = (int)($options['max_tokens']   ?? $this->maxTokens);
            if ($isConversational) {
                $temperature = max($temperature, 0.6);
            }

            $aiResponse = $this->openRouter->chatStream($modelApiId, $messages, [
                'temperature' => $temperature,
                'max_tokens'  => $maxTokens,
            ], $onDelta);

            $reply          = $aiResponse['content'];
            $usage          = $aiResponse['usage'];
            $responseTimeMs = $aiResponse['response_time_ms'];
            $actualModelId  = $aiResponse['model'];

        } catch (\Throwable $e) {
            $this->logError('AIService::chatStream failed: ' . $e->getMessage());

            $reply          = $this->fallbackMessage;
            $usage          = ['prompt_tokens' => 0, 'completion_tokens' => 0, 'total_tokens' => 0];
            $responseTimeMs = (int)((microtime(true) - $startTime) * 1000);
            $actualModelId  = $modelApiId;
            $isFallback     = true;
            // Emit the fallback text so the client still receives a reply.
            $onDelta($reply);
        }

        if ($isFallback && empty(trim($reply))) {
            $reply = $this->fallbackMessage;
            $onDelta($reply);
        }

        return $this->finalizeTurn([
            'conversation'   => $conversation,
            'convId'         => $convId,
            'platform'       => $platform,
            'sessionId'      => $sessionId,
            'modelDbId'      => $modelDbId,
            'actualModelId'  => $actualModelId,
            'userMessage'    => $userMessage,
            'reply'          => $reply,
            'usage'          => $usage,
            'responseTimeMs' => $responseTimeMs,
            'chunkIds'       => $chunkIds,
            'isFallback'     => $isFallback,
            'cacheable'      => !$isConversational && !$isFallback && !$this->isFollowUpMessage($userMessage),
        ]);
    }

    // ----------------------------------------------------------------
    // Answer Cache (shared by chat + chatStream)
    // ----------------------------------------------------------------

    /**
     * Check the answer cache for a repeated knowledge question.
     * Conversational messages (greetings etc.) are never cached — they are
     * cheap anyway and a canned reply would feel robotic.
     */
    private function lookupCachedAnswer(string $userMessage): ?array
    {
        if ($this->isConversationalMessage($userMessage) || $this->isFollowUpMessage($userMessage)) {
            return null;
        }
        return $this->answerCache->lookup(
            $this->botId,
            $userMessage,
            fn (): array => $this->embedding->getQueryEmbedding($userMessage)
        );
    }

    /**
     * Context-dependent phrasing must never be cached or served from cache:
     * the same words ("แล้วเสาร์-อาทิตย์ล่ะ") mean different things after
     * different turns. Heuristic: very short messages or follow-up openers.
     */
    private function isFollowUpMessage(string $message): bool
    {
        $msg = trim($message);

        if (mb_strlen($msg, 'UTF-8') < 10) {
            return true;
        }
        if (preg_match('/^(แล้ว|งั้น|ถ้างั้น|ต่อจาก|อีกอย่าง|เพิ่มเติม|what about|how about|and\s)/iu', $msg)) {
            return true;
        }
        return (bool)preg_match('/(ล่ะ|หละ)\s*\??$/u', $msg);
    }

    /**
     * Persist a turn answered from the cache (zero token usage, no LLM call)
     * and return the standard response array.
     */
    private function finalizeCachedTurn(
        array  $cached,
        array  $conversation,
        string $platform,
        string $sessionId,
        ?int   $modelDbId,
        string $userMessage,
        float  $startTime
    ): array {
        return $this->finalizeTurn([
            'conversation'   => $conversation,
            'convId'         => $conversation['id'],
            'platform'       => $platform,
            'sessionId'      => $sessionId,
            'modelDbId'      => $modelDbId,
            'actualModelId'  => $cached['model_id'] ?: 'answer-cache',
            'userMessage'    => $userMessage,
            'reply'          => $cached['reply'],
            'usage'          => ['prompt_tokens' => 0, 'completion_tokens' => 0, 'total_tokens' => 0],
            'responseTimeMs' => (int)((microtime(true) - $startTime) * 1000),
            'chunkIds'       => json_decode($cached['chunk_ids'] ?? '[]', true) ?: [],
            'isFallback'     => false,
            'cacheable'      => false,
            'fromCache'      => true,
        ]);
    }

    // ----------------------------------------------------------------
    // Retrieval (shared by chat + chatStream)
    // ----------------------------------------------------------------

    /**
     * Run the RAG retrieval for a user message.
     *
     * Tier 1: the question is matched against the example questions of published
     * wiki pages. A hit there is a strong signal (question-vs-question), so the
     * whole page is served as context.
     *
     * Tier 2 (everything below): the original chunk search. Single-pass with a
     * local adaptive threshold: the query is embedded once (memoized in
     * EmbeddingService) and scored against the knowledge base once. Thai
     * embeddings score lower than English, so we fetch top-K candidates with
     * their scores (no hard cutoff in the query) and filter in PHP, with a floor
     * to catch genuinely-relevant low-score Thai matches. Drive documents that
     * have no wiki page yet are only reachable through this tier.
     *
     * @return array{0: array, 1: int[], 2: bool}  [chunks, chunkIds, isConversational]
     */
    private function retrieveContext(string $userMessage, array $options, ?int $convId = null): array
    {
        // Conversational messages (greetings, thanks, etc.) need no knowledge.
        if ($this->isConversationalMessage($userMessage)) {
            return [[], [], true];
        }

        $wikiChunks = $this->retrieveWikiPages($userMessage, $options);
        if (!empty($wikiChunks)) {
            return [$wikiChunks, array_column($wikiChunks, 'id'), false];
        }

        $threshold = (float)($options['similarity_threshold'] ?? $this->similarityThreshold);
        $topK      = (int)($options['top_k']                 ?? $this->topKChunks);
        $floor     = min($threshold, 0.22);

        $candidates = $this->embedding->findSimilarChunks($userMessage, $topK, 0.0, [], $this->botId);

        // Primary cut: candidates that clear the configured threshold.
        $chunks = array_values(array_filter($candidates, fn($c) => $c['score'] >= $threshold));

        // Local fallback: keep the best matches above a floor (reuses scored candidates).
        if (empty($chunks)) {
            $chunks = array_values(array_filter($candidates, fn($c) => $c['score'] >= $floor));
        }

        $chunks = $this->trimByScoreGap($chunks);

        // Follow-up questions ("แล้วเสาร์-อาทิตย์ล่ะ?") often omit the topic words,
        // so the embedding of the bare message matches nothing. Retry with the
        // previous user question prepended for context. (The current message is
        // not yet saved at this point, so the latest stored user row IS the
        // previous question.)
        if (empty($chunks) && $convId !== null) {
            $prevQuestion = $this->getPreviousUserQuestion($convId);
            if ($prevQuestion !== null && $prevQuestion !== $userMessage) {
                $contextual = $this->embedding->findSimilarChunks(
                    $prevQuestion . "\n" . $userMessage, $topK, 0.0, [], $this->botId
                );
                $chunks = $this->trimByScoreGap(
                    array_values(array_filter($contextual, fn($c) => $c['score'] >= $floor))
                );
            }
        }

        // Expand with Thai synonyms only if still empty (embedded at most once).
        if (empty($chunks)) {
            $expandedQuery = $this->expandQueryForSearch($userMessage);
            if ($expandedQuery !== $userMessage) {
                $expanded = $this->embedding->findSimilarChunks($expandedQuery, $topK, 0.0, [], $this->botId);
                $chunks   = $this->trimByScoreGap(
                    array_values(array_filter($expanded, fn($c) => $c['score'] >= $floor))
                );
            }
        }

        // Last resort: literal keyword match (FULLTEXT ngram). Embeddings miss
        // exact terms — "eduroam คืออะไร" scores near zero on cosine yet the
        // word sits verbatim in a chunk. Vector passes all failed by now, so a
        // literal hit beats answering "ไม่มีข้อมูล".
        if (empty($chunks)) {
            $chunks = $this->embedding->findChunksByKeyword($userMessage, 3, $this->botId);
        }

        return [$chunks, array_column($chunks, 'id'), false];
    }

    /**
     * Drop tier-2 candidates whose score collapses relative to the best hit:
     * with top-K retrieval the 4th–5th chunk often scores far below the top
     * ones and adds ~600 tokens of noise each. A chunk scoring under 60% of
     * the leader is padding, not signal — at Thai-typical scores this keeps
     * every genuinely-related chunk (0.62 leader keeps ≥0.37).
     *
     * @param  array<int, array{score: float}> $chunks  sorted best-first
     */
    private function trimByScoreGap(array $chunks): array
    {
        if (count($chunks) < 2) {
            return $chunks;
        }

        $cutoff = max(array_column($chunks, 'score')) * 0.6;

        return array_values(array_filter($chunks, fn($c) => $c['score'] >= $cutoff));
    }

    /**
     * Retrieval tier 1 — match the question against wiki_questions and, on a
     * hit, return every chunk of the matched pages (a page is 1–2 chunks by
     * design, so this stays well inside the prompt budget).
     *
     * The query vector is memoized in EmbeddingService, so this adds no
     * embedding API call on top of the chunk search that may follow.
     *
     * @return array<int, array{id: int, content: string, source_id: int, score: float}>
     */
    private function retrieveWikiPages(string $userMessage, array $options): array
    {
        $threshold = (float)($options['wiki_question_threshold'] ?? $this->wikiQuestionThreshold);

        $hits = $this->embedding->findPagesByQuestion(
            $userMessage,
            self::WIKI_TOP_PAGES,
            $threshold,
            $this->botId
        );
        if (empty($hits)) {
            return [];
        }

        $scores = array_column($hits, 'score', 'source_id');
        $chunks = $this->embedding->getChunksBySource(array_keys($scores));

        // Carry the matching question's score onto each chunk so the prompt's
        // source attribution block renders the same way as for tier 2.
        return array_map(
            fn($c) => $c + ['score' => (float)($scores[$c['source_id']] ?? 0.0)],
            $chunks
        );
    }

    /**
     * Latest saved user message in a conversation (used as context for
     * follow-up retrieval).
     */
    private function getPreviousUserQuestion(int $convId): ?string
    {
        $row = $this->db->fetch(
            "SELECT content FROM messages
             WHERE conversation_id = ? AND role = 'user'
             ORDER BY id DESC
             LIMIT 1",
            [$convId]
        );
        return $row['content'] ?? null;
    }

    /**
     * Persist a completed assistant turn: cost, message row, conversation stats,
     * auto-tagging, and API usage logging. Returns the public response array.
     * Shared by chat() and chatStream().
     */
    private function finalizeTurn(array $c): array
    {
        $conversation = $c['conversation'];
        $usage        = $c['usage'];

        $costUsd = $this->openRouter->calculateCost(
            $c['actualModelId'],
            $usage['prompt_tokens'],
            $usage['completion_tokens']
        );

        $assistantMsgId = $this->saveMessage($c['convId'], 'assistant', $c['reply'], $c['modelDbId'], [
            'tokens_prompt'         => $usage['prompt_tokens'],
            'tokens_completion'     => $usage['completion_tokens'],
            'tokens_total'          => $usage['total_tokens'],
            'response_time_ms'      => $c['responseTimeMs'],
            'knowledge_chunks_used' => $c['chunkIds'],
            'is_fallback'           => $c['isFallback'] ? 1 : 0,
        ]);

        $tags = $this->classifyTags($c['userMessage']);
        $updateData = [
            'message_count'    => $conversation['message_count'] + 2,
            'total_tokens'     => $conversation['total_tokens']  + $usage['total_tokens'],
            'total_cost_usd'   => $conversation['total_cost_usd'] + $costUsd,
            'last_activity_at' => date('Y-m-d H:i:s'),
            'ai_model_id'      => $c['modelDbId'],
        ];
        if (!empty($tags)) {
            $existingTags = json_decode($conversation['tags'] ?? '[]', true) ?: [];
            $mergedTags   = array_values(array_unique(array_merge($existingTags, $tags)));
            $updateData['tags'] = json_encode($mergedTags, JSON_UNESCAPED_UNICODE);
        }
        $this->db->update('conversations', $updateData, ['id' => $c['convId']]);

        $this->logger->logApiUsage([
            'bot_id'            => $this->botId,
            'conversation_id'   => $c['convId'],
            'platform'          => $c['platform'],
            'ai_model_id'       => $c['modelDbId'],
            'api_provider'      => 'openrouter',
            'tokens_prompt'     => $usage['prompt_tokens'],
            'tokens_completion' => $usage['completion_tokens'],
            'tokens_total'      => $usage['total_tokens'],
            'cost_usd'          => $costUsd,
            'response_time_ms'  => $c['responseTimeMs'],
            'status'            => 'success',
        ]);

        // Cache grounded answers so repeats skip the LLM. getQueryEmbedding is
        // memoized — retrieval already embedded this text, so no extra API call.
        if (!empty($c['cacheable']) && trim($c['reply']) !== '') {
            $this->answerCache->store(
                $this->botId,
                $c['userMessage'],
                $this->embedding->getQueryEmbedding($c['userMessage']),
                $c['reply'],
                $c['actualModelId'],
                $c['chunkIds']
            );
        }

        return [
            'reply'             => $c['reply'],
            'message_id'        => $assistantMsgId,
            'is_fallback'       => $c['isFallback'],
            'cached'            => !empty($c['fromCache']),
            'tokens_prompt'     => $usage['prompt_tokens'],
            'tokens_completion' => $usage['completion_tokens'],
            'tokens_total'      => $usage['total_tokens'],
            'cost_usd'          => $costUsd,
            'response_time_ms'  => $c['responseTimeMs'],
            'chunks_used'       => $c['chunkIds'],
            'model_id'          => $c['actualModelId'],
            'session_id'        => $c['sessionId'],
        ];
    }

    // ----------------------------------------------------------------
    // Conversation Management
    // ----------------------------------------------------------------

    public function getOrCreateConversation(string $sessionId, string $platform): array
    {
        $query  = 'SELECT * FROM conversations WHERE session_id = ?';
        $params = [$sessionId];
        if ($this->botId) {
            $query   .= ' AND bot_id = ?';
            $params[] = $this->botId;
        }
        $conv = $this->db->fetch($query, $params);

        if ($conv) {
            return $conv;
        }

        $id = $this->db->insert('conversations', [
            'bot_id'           => $this->botId,
            'session_id'       => $sessionId,
            'platform'         => $platform,
            'ip_address'       => Network::getClientIp(),
            'started_at'       => date('Y-m-d H:i:s'),
            'last_activity_at' => date('Y-m-d H:i:s'),
            'message_count'    => 0,
            'total_tokens'     => 0,
            'total_cost_usd'   => 0,
        ]);

        return $this->db->fetch('SELECT * FROM conversations WHERE id = ?', [$id]);
    }

    public function getConversationHistory(int $convId, int $limit = 10): array
    {
        // Order by id, not created_at: the timestamp has 1-second resolution,
        // so a user/assistant pair saved in the same second could come back
        // swapped and scramble the prompt history.
        return $this->db->fetchAll(
            'SELECT role, content FROM messages
             WHERE conversation_id = ?
             ORDER BY id DESC
             LIMIT ?',
            [$convId, $limit]
        );
    }

    // ----------------------------------------------------------------
    // Message Saving
    // ----------------------------------------------------------------

    private function saveMessage(
        int    $convId,
        string $role,
        string $content,
        ?int   $modelDbId = null,
        array  $extra     = []
    ): int {
        $data = array_merge([
            'conversation_id'      => $convId,
            'role'                 => $role,
            'content'              => $content,
            'ai_model_id'          => $modelDbId,
            'tokens_prompt'        => 0,
            'tokens_completion'    => 0,
            'tokens_total'         => 0,
            'response_time_ms'     => 0,
            'knowledge_chunks_used'=> null,
            'is_fallback'          => 0,
        ], $extra);

        if (isset($data['knowledge_chunks_used']) && is_array($data['knowledge_chunks_used'])) {
            $data['knowledge_chunks_used'] = json_encode($data['knowledge_chunks_used']);
        }

        return (int)$this->db->insert('messages', $data);
    }

    // ----------------------------------------------------------------
    // Prompt Building (RAG)
    // ----------------------------------------------------------------

    /**
     * Build the messages array for OpenRouter chat API.
     * Injects relevant knowledge chunks into the system prompt.
     */
    private function buildMessages(array $conversation, string $userMessage, array $chunks): array
    {
        $messages = [];

        // ── 1. System prompt (user-defined personality) ───────────────
        $systemContent = $this->systemPrompt;

        // ── 1b. Wiki index — tells the bot what it does and doesn't know ──
        $wikiIndex = $this->getWikiIndex();
        if ($wikiIndex !== '') {
            $systemContent .= <<<INDEX


## หัวข้อที่มีข้อมูลในระบบ

{$wikiIndex}
INDEX;
        }

        // ── 2. RAG context injection with source attribution ─────────
        if (!empty($chunks)) {
            $contextParts = [];
            foreach ($chunks as $i => $c) {
                $sourceLabel = '';
                if (!empty($c['source_id'])) {
                    $sourceLabel = $this->getSourceLabel($c['source_id']);
                }
                $num = $i + 1;
                $score = isset($c['score']) ? ' (ความเกี่ยวข้อง: ' . round($c['score'] * 100) . '%)' : '';
                $header = $sourceLabel
                    ? "[แหล่งข้อมูล #{$num}: {$sourceLabel}{$score}]"
                    : "[ข้อมูลอ้างอิง #{$num}{$score}]";
                $contextParts[] = "{$header}\n{$c['content']}";
            }
            $contextText = implode("\n\n---\n\n", $contextParts);

            $systemContent .= <<<CONTEXT


## ข้อมูลอ้างอิงจาก Knowledge Base

{$contextText}

## คำแนะนำในการตอบ

- ตอบจากข้อมูลอ้างอิงข้างต้นเป็นหลัก ถ้าข้อมูลมีหลายแหล่ง ให้สรุปรวมอย่างเป็นธรรมชาติ
- ใช้ภาษาที่เข้าใจง่าย เป็นกันเอง เหมือนพี่คุยกับน้อง
- ถ้าข้อมูลอ้างอิงตอบได้แค่บางส่วน ให้ตอบส่วนที่ทำได้แล้วบอกชัดว่าส่วนไหนไม่มีข้อมูล
- ห้ามแต่งข้อมูลเอง ถ้าไม่มีในข้อมูลอ้างอิงให้บอกตรงๆ
- จัดรูปแบบให้อ่านง่าย ใช้ bullet point หรือลำดับเลขเมื่อเหมาะสม
CONTEXT;
        } else {
            // No knowledge found — guide the AI to handle gracefully
            $systemContent .= <<<NO_CONTEXT


## สถานะ: ไม่พบข้อมูลอ้างอิงที่ตรงกับคำถาม

- ถ้าเป็นคำทักทาย (สวัสดี, ขอบคุณ, ลาก่อน) → ตอบกลับอย่างเป็นมิตรตามปกติ
- ถ้าเป็นคำถามทั่วไปที่ไม่ต้องการข้อมูลเฉพาะ → ตอบได้ตามความรู้ทั่วไป
- ถ้าเป็นคำถามเฉพาะที่ต้องใช้ข้อมูลจาก Knowledge Base → บอกสุภาพว่าไม่มีข้อมูลในระบบ และแนะนำให้ติดต่อเจ้าหน้าที่
NO_CONTEXT;

            // With an index available the bot can do better than "ไม่มีข้อมูล":
            // it can point the user at a topic it actually knows about.
            if ($wikiIndex !== '') {
                $systemContent .= "\n- ถ้าคำถามใกล้เคียงหัวข้อในรายการ \"หัวข้อที่มีข้อมูลในระบบ\" ข้างต้น ให้ชวนผู้ใช้ถามหัวข้อนั้นแทน (ระบุชื่อหัวข้อให้ชัด)";
            }
        }

        // ── 2b. Today's date — "วันนี้เปิดมั้ย" is a top question and the
        //        answer depends on the weekday. Appended LAST so the long
        //        static prefix above stays byte-identical across requests
        //        (Gemini's implicit prompt caching discounts repeated prefixes).
        $systemContent .= "\n\nวันนี้คือ" . $this->currentThaiDate()
            . ' (ใช้อ้างอิงเมื่อผู้ใช้ถามถึง "วันนี้/พรุ่งนี้" เทียบกับตารางเวลาทำการ)';

        $messages[] = ['role' => 'system', 'content' => $systemContent];

        // ── 3. Conversation history (oldest first) ───────────────────
        // Capped by total characters, not just message count: FAQ answers run
        // 500+ Thai chars each, so 10 full turns can outweigh the retrieved
        // context itself. Newest turns survive; oldest are dropped first.
        $history = array_reverse(
            $this->trimHistoryToBudget(
                $this->getConversationHistory($conversation['id'], $this->historyLength),
                self::HISTORY_CHAR_BUDGET
            )
        );
        foreach ($history as $msg) {
            if (in_array($msg['role'], ['user', 'assistant'], true)) {
                $messages[] = ['role' => $msg['role'], 'content' => $msg['content']];
            }
        }

        // ── 4. Current user message ──────────────────────────────────
        $messages[] = ['role' => 'user', 'content' => $userMessage];

        return $messages;
    }

    /**
     * A bullet list of every published wiki page (title — summary), injected
     * into the system prompt so the bot knows the shape of its own knowledge:
     * it can steer a near-miss question toward a topic it can actually answer
     * instead of just saying "ไม่มีข้อมูล".
     *
     * Returns '' when there are no published pages, in which case the prompt is
     * left exactly as it was before this feature existed.
     */
    private function getWikiIndex(): string
    {
        if ($this->_wikiIndex !== null) {
            return $this->_wikiIndex;
        }

        try {
            $pages = $this->db->fetchAll(
                "SELECT title, summary FROM wiki_pages
                 WHERE bot_id <=> ? AND status = 'published'
                 ORDER BY title ASC
                 LIMIT " . self::WIKI_INDEX_MAX_PAGES,
                [$this->botId]
            );
        } catch (\Throwable) {
            // wiki_pages may not exist yet (migration 009)
            return $this->_wikiIndex = '';
        }

        if (empty($pages)) {
            return $this->_wikiIndex = '';
        }

        $withSummary = [];
        $titlesOnly  = [];
        foreach ($pages as $p) {
            $title   = trim((string)$p['title']);
            $summary = trim((string)($p['summary'] ?? ''));
            $titlesOnly[]  = "- {$title}";
            $withSummary[] = $summary === '' ? "- {$title}" : "- {$title} — {$summary}";
        }

        $index = implode("\n", $withSummary);

        // Over budget → drop the summaries rather than the topics: knowing that
        // a topic exists at all is what makes the fallback useful.
        if ($this->embedding->estimateTokens($index) > self::WIKI_INDEX_MAX_TOKENS) {
            $index = implode("\n", $titlesOnly);
        }

        return $this->_wikiIndex = $index;
    }

    /**
     * Keep the newest history rows whose combined content fits the budget.
     * Rows arrive NEWEST-FIRST (getConversationHistory orders by id DESC);
     * the first two rows (current Q/A pair context) are always kept so a
     * single oversized reply can't wipe the whole history.
     *
     * @param  array<int, array{role: string, content: string}> $rows newest first
     * @return array<int, array{role: string, content: string}> newest first
     */
    private function trimHistoryToBudget(array $rows, int $budget): array
    {
        $kept  = [];
        $total = 0;

        foreach ($rows as $i => $row) {
            $len = mb_strlen($row['content'] ?? '', 'UTF-8');
            if ($i >= 2 && $total + $len > $budget) {
                break;
            }
            $kept[] = $row;
            $total += $len;
        }

        return $kept;
    }

    /**
     * Today's date in Thai civil format, e.g. "วันศุกร์ที่ 18 กรกฎาคม พ.ศ. 2569".
     * Locale-independent on purpose — setlocale() is unreliable on Windows/IIS.
     */
    private function currentThaiDate(): string
    {
        $days = ['Sunday' => 'อาทิตย์', 'Monday' => 'จันทร์', 'Tuesday' => 'อังคาร',
                 'Wednesday' => 'พุธ', 'Thursday' => 'พฤหัสบดี', 'Friday' => 'ศุกร์',
                 'Saturday' => 'เสาร์'];
        $months = [1 => 'มกราคม', 'กุมภาพันธ์', 'มีนาคม', 'เมษายน', 'พฤษภาคม', 'มิถุนายน',
                   'กรกฎาคม', 'สิงหาคม', 'กันยายน', 'ตุลาคม', 'พฤศจิกายน', 'ธันวาคม'];

        return 'วัน' . $days[date('l')] . 'ที่ ' . (int)date('j')
            . ' ' . $months[(int)date('n')] . ' พ.ศ. ' . ((int)date('Y') + 543);
    }

    /**
     * Get a human-readable label for a knowledge source (cached per-request).
     */
    private function getSourceLabel(int $sourceId): string
    {
        if (!isset($this->_sourceLabels[$sourceId])) {
            $source = $this->db->fetch(
                'SELECT file_name, file_type FROM knowledge_sources WHERE id = ?',
                [$sourceId]
            );
            $this->_sourceLabels[$sourceId] = $source['file_name'] ?? '';
        }
        return $this->_sourceLabels[$sourceId];
    }

    // ----------------------------------------------------------------
    // Conversational Detection
    // ----------------------------------------------------------------

    /**
     * Detect if a message is conversational (greeting, thanks, farewell)
     * and doesn't require knowledge base lookup.
     */
    private function isConversationalMessage(string $message): bool
    {
        $msg = mb_strtolower(trim($message), 'UTF-8');
        $len = mb_strlen($msg, 'UTF-8');

        // Very short messages (< 8 chars) that are likely greetings
        if ($len <= 8 && preg_match('/^(สวัสดี|หวัดดี|ดี|hi|hello|hey|yo|hola)/', $msg)) {
            return true;
        }

        // Common conversational patterns (Thai + English)
        $patterns = [
            // Greetings
            '/^(สวัสดี|หวัดดี|ดีครับ|ดีค่ะ|ดีจ้า|hello|hi there|good\s*(morning|afternoon|evening))/',
            // Thanks
            '/^(ขอบคุณ|ขอบใจ|thank|thx|ขอบพระคุณ|ธ[ัง]ค)/',
            // Farewell
            '/^(ลาก่อน|บ๊ายบาย|bye|ไว้เจอกัน|ลาแล้ว|see you)/',
            // Acknowledgment
            '/^(ได้เลย|โอเค|ok|ตกลง|เข้าใจแล้ว|รับทราบ|เข้าใจ|ครับ|ค่ะ|จ้า|จ้ะ|อ่อ)$/',
            // How are you / pleasantries
            '/^(เป็นไง|สบายดี|เป็นอย่างไร|how are you|what\'?s up)/',
            // Who are you
            '/^(คุณคือใคร|เธอคือใคร|แนะนำตัว|who are you|what are you)/',
        ];

        foreach ($patterns as $p) {
            if (preg_match($p, $msg)) {
                return true;
            }
        }

        return false;
    }

    // ----------------------------------------------------------------
    // Auto-Tagging (Keyword-based Thai Classification)
    // ----------------------------------------------------------------

    private function classifyTags(string $message): array
    {
        $msg = mb_strtolower(trim($message), 'UTF-8');
        $tags = [];

        $tagRules = [
            'สอบถามบริการ'   => '/ยืม|คืน|สมัคร|สมาชิก|บัตร|ต่ออายุ|จอง|บริการ|ค่าปรับ/',
            'เวลาทำการ'      => '/เปิด|ปิด|กี่โมง|เวลา|วันหยุด|ทำการ/',
            'สถานที่'        => '/อยู่ที่ไหน|ที่ตั้ง|แผนที่|อาคาร|ชั้น|ตรงไหน/',
            'ติดต่อ'         => '/ติดต่อ|โทร|เบอร์|อีเมล|email|line/',
            'IT/เทคโนโลยี'   => '/wifi|ไวไฟ|อินเทอร์เน็ต|ระบบ|เว็บ|แอป|login|password/',
            'ฐานข้อมูล/วิจัย' => '/ฐานข้อมูล|database|วิจัย|journal|วารสาร|บทความ|thesis/',
            'หนังสือ/สื่อ'    => '/หนังสือ|e-?book|ebook|นิตยสาร|สื่อ|dvd|cd/',
            'ทักทาย'         => '/^(สวัสดี|หวัดดี|ดี|hello|hi|hey|ขอบคุณ|ลาก่อน|bye)/',
            'ร้องเรียน'      => '/ร้องเรียน|ปัญหา|แจ้ง|เสีย|ไม่ทำงาน|bug|error/',
        ];

        foreach ($tagRules as $tag => $pattern) {
            if (preg_match($pattern . 'iu', $msg)) {
                $tags[] = $tag;
            }
        }

        return $tags;
    }

    // ----------------------------------------------------------------
    // Query Expansion for Thai Language
    // ----------------------------------------------------------------

    /**
     * Expand short Thai queries with synonyms/keywords for better embedding matches.
     * Thai embeddings with text-embedding-3-small often score very low because the model
     * was primarily trained on English. Expanding the query with related terms helps.
     */
    private function expandQueryForSearch(string $query): string
    {
        $q = mb_strtolower(trim($query), 'UTF-8');

        // Common Thai synonym/keyword expansions
        $expansions = [
            // Library hours
            'เปิด.*ปิด|กี่โมง|เวลาทำการ|เวลาเปิด|เวลาปิด' =>
                'เวลาเปิดให้บริการ เวลาปิดทำการ ชั่วโมงทำการ วันจันทร์ วันศุกร์ วันเสาร์ วันอาทิตย์',
            // Location
            'อยู่ที่ไหน|อยู่ตรงไหน|สถานที่|ที่ตั้ง|แผนที่' =>
                'ที่ตั้ง สถานที่ตั้ง อาคาร ชั้น ที่อยู่ แผนที่',
            // Contact
            'ติดต่อ|โทร|เบอร์|อีเมล|email' =>
                'ช่องทางการติดต่อ เบอร์โทรศัพท์ อีเมล ที่อยู่ เว็บไซต์',
            // Borrowing
            'ยืม|คืน|ต่ออายุ|ยืมหนังสือ' =>
                'สิทธิ์การยืม การคืน ต่ออายุ จำนวนเล่ม กี่วัน ค่าปรับ',
            // Membership
            'สมัครสมาชิก|สมาชิก|บัตร' =>
                'สมัครสมาชิก บัตรสมาชิก สิทธิ์การใช้บริการ',
            // Database
            'ฐานข้อมูล|database|วิจัย|journal' =>
                'ฐานข้อมูลวิชาการ ฐานข้อมูลออนไลน์ งานวิจัย วารสาร E-Book',
            // E-Book
            'อีบุ๊ค|e-book|ebook|หนังสืออิเล็กทรอนิกส์' =>
                'หนังสืออิเล็กทรอนิกส์ E-Book อ่านออนไลน์ Web-OPAC',
            // Room booking
            'จองห้อง|ห้องประชุม|ห้องกลุ่ม|study room' =>
                'จองห้องประชุม ห้องศึกษากลุ่ม ห้องกลุ่มย่อย ขั้นตอนการจอง',
            // WiFi/IT
            'wifi|ไวไฟ|อินเทอร์เน็ต|eduroam' =>
                'EDUROAM WiFi อินเทอร์เน็ต ระบบสารสนเทศ',
            // Library synonym
            'ห้องสมุด|หอสมุด' =>
                'ศูนย์บรรณสารและการเรียนรู้ ห้องสมุด หอสมุด บรรณสาร',
        ];

        foreach ($expansions as $pattern => $keywords) {
            if (preg_match("/{$pattern}/iu", $q)) {
                return $query . ' ' . $keywords;
            }
        }

        return $query;
    }

    // ----------------------------------------------------------------
    // Model Resolution
    // ----------------------------------------------------------------

    private function resolveModel(?string $modelId): array
    {
        if ($modelId) {
            $model = $this->db->fetch(
                'SELECT * FROM ai_models WHERE openrouter_model_id = ? AND is_active = 1',
                [$modelId]
            );
            if ($model) {
                return $model;
            }
        }
        return $this->openRouter->getDefaultModel() ?? [];
    }

    // ----------------------------------------------------------------
    // Settings Loader
    // ----------------------------------------------------------------

    private function loadSettings(): void
    {
        $keys = [
            'system_prompt', 'temperature', 'max_tokens',
            'similarity_threshold', 'top_k_chunks', 'history_length',
            'fallback_message', 'wiki_question_threshold',
        ];
        $placeholders = implode(',', array_fill(0, count($keys), '?'));

        $settings = [];

        if ($this->botId) {
            $settings = $this->db->fetchAll(
                "SELECT setting_key, setting_value FROM bot_settings
                 WHERE bot_id = ? AND setting_key IN ({$placeholders})",
                array_merge([$this->botId], $keys)
            );
        } else {
            $settings = $this->db->fetchAll(
                "SELECT setting_key, setting_value FROM system_settings
                 WHERE setting_key IN ({$placeholders})",
                $keys
            );
        }

        foreach ($settings as $s) {
            match ($s['setting_key']) {
                'system_prompt'        => $this->systemPrompt        = $s['setting_value'],
                'temperature'          => $this->temperature          = (float)$s['setting_value'],
                'max_tokens'           => $this->maxTokens            = (int)$s['setting_value'],
                'similarity_threshold' => $this->similarityThreshold  = (float)$s['setting_value'],
                'top_k_chunks'         => $this->topKChunks           = (int)$s['setting_value'],
                'history_length'       => $this->historyLength        = (int)$s['setting_value'],
                'fallback_message'     => $this->fallbackMessage      = $s['setting_value'],
                // A blank/garbage value would cast to 0.0 and make tier 1 match
                // every question — keep the default unless it is a real number.
                'wiki_question_threshold' => $this->wikiQuestionThreshold = is_numeric($s['setting_value'])
                    ? (float)$s['setting_value']
                    : $this->wikiQuestionThreshold,
                default                => null,
            };
        }
    }

    private function logError(string $message): void
    {
        $logPath = dirname(__DIR__, 2) . '/storage/logs/ai_service.log';
        @file_put_contents($logPath,
            sprintf("[%s] %s\n", date('Y-m-d H:i:s'), $message),
            FILE_APPEND | LOCK_EX
        );
    }
}
