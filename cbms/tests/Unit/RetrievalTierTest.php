<?php
/**
 * AIService retrieval tiers (WP-4)
 *
 * Tier 1 matches the question against the example questions of wiki pages;
 * tier 2 is the original chunk search. The contract that matters:
 *
 *   - a tier-1 hit serves every chunk of the matched page
 *   - a tier-1 miss must leave tier 2 behaving exactly as it did before the
 *     wiki layer existed (Drive documents with no wiki page are only reachable
 *     through it)
 *
 * Both AIService and the fake embedding service are built without their
 * constructors, so no DB or embedding API is touched.
 */

namespace Tests\Unit;

use Tests\TestCase;
use App\Services\AIService;
use App\Services\EmbeddingService;
use ReflectionClass;

class RetrievalTierTest extends TestCase
{
    private AIService $ai;
    private ReflectionClass $ref;
    private EmbeddingService $embedding;

    protected function setUp(): void
    {
        parent::setUp();

        $this->embedding = $this->makeFakeEmbedding();
        $this->ref       = new ReflectionClass(AIService::class);
        $this->ai        = $this->ref->newInstanceWithoutConstructor();

        $this->set('embedding', $this->embedding);
        $this->set('botId', 1);
        $this->set('similarityThreshold', 0.15);
        $this->set('topKChunks', 5);
        $this->set('wikiQuestionThreshold', 0.45);
    }

    /**
     * An EmbeddingService whose lookups return canned data — the real one
     * connects to MySQL and the embedding API in its constructor.
     */
    private function makeFakeEmbedding(): EmbeddingService
    {
        return new class extends EmbeddingService {
            /** @var array<int, array> */
            public array $pageHits = [];
            /** @var array<int, array> */
            public array $chunksBySource = [];
            /** @var array<int, array> */
            public array $similarChunks = [];
            /** @var array<int, array> */
            public array $keywordChunks = [];
            /** @var string[] */
            public array $questionQueries = [];
            /** @var string[] */
            public array $chunkQueries = [];
            /** @var string[] */
            public array $keywordQueries = [];

            // The parent constructor opens a DB connection and an HTTP client.
            public function __construct() {}

            public function findChunksByKeyword(
                string $queryText,
                int    $limit = 3,
                ?int   $botId = null
            ): array {
                $this->keywordQueries[] = $queryText;
                return array_slice($this->keywordChunks, 0, $limit);
            }

            public function findPagesByQuestion(
                string $queryText,
                int    $topK     = 3,
                float  $minScore = 0.45,
                ?int   $botId    = null
            ): array {
                $this->questionQueries[] = $queryText;

                $hits = array_values(array_filter($this->pageHits, fn($h) => $h['score'] >= $minScore));
                return array_slice($hits, 0, $topK);
            }

            public function getChunksBySource(array $sourceIds): array
            {
                return array_values(array_filter(
                    $this->chunksBySource,
                    fn($c) => in_array($c['source_id'], $sourceIds, true)
                ));
            }

            public function findSimilarChunks(
                string $queryText,
                int    $topK      = 5,
                float  $minScore  = 0.7,
                array  $sourceIds = [],
                ?int   $botId     = null
            ): array {
                $this->chunkQueries[] = $queryText;

                return array_values(array_filter($this->similarChunks, fn($c) => $c['score'] >= $minScore));
            }
        };
    }

    private function set(string $property, mixed $value): void
    {
        $this->ref->getProperty($property)->setValue($this->ai, $value);
    }

    /**
     * @return array{0: array, 1: int[], 2: bool}
     */
    private function retrieve(string $message, array $options = []): array
    {
        return $this->ref->getMethod('retrieveContext')->invokeArgs($this->ai, [$message, $options, null]);
    }

    // ────────────────────────────────────────────────────────────────
    // Tier 1: wiki question hit
    // ────────────────────────────────────────────────────────────────

    public function test_a_question_hit_serves_every_chunk_of_that_page(): void
    {
        $this->embedding->pageHits = [
            ['source_id' => 7, 'page_id' => 3, 'question' => 'ห้องสมุดเปิดกี่โมง', 'score' => 0.91],
        ];
        $this->embedding->chunksBySource = [
            ['id' => 71, 'content' => 'จันทร์-ศุกร์ 08:30-16:30', 'source_id' => 7],
            ['id' => 72, 'content' => 'เสาร์-อาทิตย์ปิด',        'source_id' => 7],
            ['id' => 90, 'content' => 'หน้าอื่นที่ไม่เกี่ยว',     'source_id' => 9],
        ];

        [$chunks, $chunkIds, $isConversational] = $this->retrieve('ห้องสมุดเปิดกี่โมงครับ');

        $this->assertFalse($isConversational);
        $this->assertSame([71, 72], $chunkIds, 'every chunk of the matched page, and nothing else');
        $this->assertSame(0.91, $chunks[0]['score'], 'the question score rides along for prompt attribution');
        $this->assertSame([], $this->embedding->chunkQueries, 'tier 2 must not run after a tier-1 hit');
    }

    public function test_only_the_top_pages_are_served(): void
    {
        $this->embedding->pageHits = [
            ['source_id' => 1, 'page_id' => 1, 'question' => 'a', 'score' => 0.9],
            ['source_id' => 2, 'page_id' => 2, 'question' => 'b', 'score' => 0.8],
            ['source_id' => 3, 'page_id' => 3, 'question' => 'c', 'score' => 0.7],
        ];
        $this->embedding->chunksBySource = [
            ['id' => 11, 'content' => 'one',   'source_id' => 1],
            ['id' => 22, 'content' => 'two',   'source_id' => 2],
            ['id' => 33, 'content' => 'three', 'source_id' => 3],
        ];

        [, $chunkIds] = $this->retrieve('คำถามที่ตรงกับหลายหน้า');

        $this->assertSame([11, 22], $chunkIds, 'capped at WIKI_TOP_PAGES');
    }

    // ────────────────────────────────────────────────────────────────
    // Tier 1 miss → tier 2 unchanged
    // ────────────────────────────────────────────────────────────────

    public function test_a_weak_question_match_falls_through_to_chunk_search(): void
    {
        // Below the 0.45 tier-1 cutoff: close-ish wording, different topic.
        $this->embedding->pageHits = [
            ['source_id' => 7, 'page_id' => 3, 'question' => 'ห้องสมุดเปิดกี่โมง', 'score' => 0.31],
        ];
        $this->embedding->similarChunks = [
            ['id' => 55, 'content' => 'ค่าปรับวันละ 5 บาท', 'score' => 0.28, 'source_id' => 4],
        ];

        [$chunks, $chunkIds] = $this->retrieve('ค่าปรับคืนหนังสือช้าเท่าไหร่');

        $this->assertSame([55], $chunkIds);
        $this->assertCount(1, $this->embedding->chunkQueries, 'tier 2 ran');
        $this->assertSame(0.28, $chunks[0]['score'], 'tier-2 chunks keep their own score');
    }

    public function test_no_wiki_pages_at_all_leaves_chunk_search_untouched(): void
    {
        $this->embedding->similarChunks = [
            ['id' => 55, 'content' => 'ค่าปรับวันละ 5 บาท', 'score' => 0.62, 'source_id' => 4],
            ['id' => 56, 'content' => 'ยืมได้ 5 เล่ม',      'score' => 0.45, 'source_id' => 4],
        ];

        [, $chunkIds] = $this->retrieve('ค่าปรับคืนหนังสือช้าเท่าไหร่');

        $this->assertSame([55, 56], $chunkIds, 'both clear the threshold and the score-gap cut');
    }

    /**
     * Score-gap trim: a chunk scoring far below the leader (under 60%) is
     * padding, not signal — it costs ~600 prompt tokens and adds noise.
     */
    public function test_chunks_scoring_far_below_the_leader_are_dropped(): void
    {
        $this->embedding->similarChunks = [
            ['id' => 55, 'content' => 'ค่าปรับวันละ 5 บาท', 'score' => 0.62, 'source_id' => 4],
            ['id' => 56, 'content' => 'เวลาทำการ',          'score' => 0.19, 'source_id' => 4],
        ];

        [, $chunkIds] = $this->retrieve('ค่าปรับคืนหนังสือช้าเท่าไหร่');

        $this->assertSame([55], $chunkIds, '0.19 is under 60% of 0.62 — dropped');
    }

    // ────────────────────────────────────────────────────────────────
    // Keyword rescue (hybrid search)
    // ────────────────────────────────────────────────────────────────

    /**
     * "eduroam คืออะไร" scores near zero on cosine although the word sits
     * verbatim in a chunk — the FULLTEXT rescue pass must catch it.
     */
    public function test_keyword_rescue_fires_when_every_vector_pass_misses(): void
    {
        $this->embedding->similarChunks = [];
        $this->embedding->keywordChunks = [
            ['id' => 99, 'content' => 'EDUROAM คือเครือข่าย WiFi ...', 'source_id' => 4],
        ];

        [$chunks, $chunkIds, $isConversational] = $this->retrieve('eduroam คืออะไร');

        $this->assertSame([99], $chunkIds);
        $this->assertFalse($isConversational);
        $this->assertArrayNotHasKey('score', $chunks[0], 'keyword hits carry no cosine score');
    }

    public function test_keyword_rescue_stays_out_of_the_way_when_vectors_hit(): void
    {
        $this->embedding->similarChunks = [
            ['id' => 55, 'content' => 'ค่าปรับวันละ 5 บาท', 'score' => 0.62, 'source_id' => 4],
        ];
        $this->embedding->keywordChunks = [
            ['id' => 99, 'content' => 'ไม่ควรโผล่มา', 'source_id' => 4],
        ];

        [, $chunkIds] = $this->retrieve('ค่าปรับคืนหนังสือช้าเท่าไหร่');

        $this->assertSame([55], $chunkIds);
        $this->assertSame([], $this->embedding->keywordQueries, 'rescue must not even run');
    }

    /**
     * The Thai-embedding floor: nothing clears the configured threshold, so the
     * best low-scoring candidates are kept rather than falling back to
     * "ไม่มีข้อมูล". This predates the wiki layer and must survive it.
     */
    public function test_the_low_score_thai_floor_still_applies(): void
    {
        $this->set('similarityThreshold', 0.40);
        $this->embedding->similarChunks = [
            ['id' => 55, 'content' => 'ค่าปรับวันละ 5 บาท', 'score' => 0.25, 'source_id' => 4],
            ['id' => 56, 'content' => 'ไม่เกี่ยวเลย',        'score' => 0.05, 'source_id' => 4],
        ];

        [, $chunkIds] = $this->retrieve('ค่าปรับคืนหนังสือช้าเท่าไหร่');

        $this->assertSame([55], $chunkIds, 'kept by the floor; the 0.05 chunk is not');
    }

    public function test_nothing_relevant_means_fallback(): void
    {
        $this->embedding->similarChunks = [
            ['id' => 56, 'content' => 'ไม่เกี่ยวเลย', 'score' => 0.05, 'source_id' => 4],
        ];

        [$chunks, $chunkIds, $isConversational] = $this->retrieve('คำถามที่ไม่มีข้อมูลในระบบเลย');

        $this->assertSame([], $chunks);
        $this->assertSame([], $chunkIds);
        $this->assertFalse($isConversational, 'a real question, just unanswerable');
    }

    // ────────────────────────────────────────────────────────────────
    // Greetings skip retrieval entirely
    // ────────────────────────────────────────────────────────────────

    public function test_a_greeting_skips_both_tiers(): void
    {
        [$chunks, $chunkIds, $isConversational] = $this->retrieve('สวัสดีครับ');

        $this->assertTrue($isConversational);
        $this->assertSame([], $chunks);
        $this->assertSame([], $chunkIds);
        $this->assertSame([], $this->embedding->questionQueries, 'no embedding call for a greeting');
        $this->assertSame([], $this->embedding->chunkQueries);
    }

    // ────────────────────────────────────────────────────────────────
    // Threshold is configurable (settings key wiki_question_threshold)
    // ────────────────────────────────────────────────────────────────

    public function test_the_tier_1_threshold_is_configurable_per_request(): void
    {
        $this->embedding->pageHits = [
            ['source_id' => 7, 'page_id' => 3, 'question' => 'ห้องสมุดเปิดกี่โมง', 'score' => 0.31],
        ];
        $this->embedding->chunksBySource = [
            ['id' => 71, 'content' => 'จันทร์-ศุกร์ 08:30-16:30', 'source_id' => 7],
        ];

        [, $chunkIds] = $this->retrieve('ห้องสมุดเปิดกี่โมงครับ', ['wiki_question_threshold' => 0.30]);

        $this->assertSame([71], $chunkIds, 'a lower cutoff lets the 0.31 match through');
    }

    // ────────────────────────────────────────────────────────────────
    // History character budget
    // ────────────────────────────────────────────────────────────────

    /**
     * @param array<int, array{role: string, content: string}> $rows newest first
     */
    private function trimHistory(array $rows, int $budget): array
    {
        return $this->ref->getMethod('trimHistoryToBudget')->invokeArgs($this->ai, [$rows, $budget]);
    }

    public function test_history_within_budget_is_untouched(): void
    {
        $rows = [
            ['role' => 'assistant', 'content' => str_repeat('ก', 100)],
            ['role' => 'user',      'content' => str_repeat('ข', 100)],
        ];

        $this->assertSame($rows, $this->trimHistory($rows, 3000));
    }

    public function test_oldest_history_is_dropped_when_over_budget(): void
    {
        // Newest first, as getConversationHistory returns them
        $rows = [
            ['role' => 'assistant', 'content' => str_repeat('ก', 900)],
            ['role' => 'user',      'content' => str_repeat('ข', 900)],
            ['role' => 'assistant', 'content' => str_repeat('ค', 900)],
            ['role' => 'user',      'content' => str_repeat('ง', 900)],
        ];

        $kept = $this->trimHistory($rows, 2000);

        $this->assertCount(2, $kept, 'the two oldest rows no longer fit');
        $this->assertStringContainsString('ก', $kept[0]['content'], 'newest rows survive');
    }

    /**
     * One oversized reply must not wipe the whole history — the newest Q/A
     * pair is always kept so follow-up questions retain their context.
     */
    public function test_the_newest_pair_survives_even_a_blown_budget(): void
    {
        $rows = [
            ['role' => 'assistant', 'content' => str_repeat('ก', 5000)],
            ['role' => 'user',      'content' => str_repeat('ข', 5000)],
            ['role' => 'assistant', 'content' => str_repeat('ค', 5000)],
        ];

        $kept = $this->trimHistory($rows, 3000);

        $this->assertCount(2, $kept, 'first two rows are unconditional, the third is over budget');
    }
}
