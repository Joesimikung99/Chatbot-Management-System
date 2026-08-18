<?php
/**
 * Wiki Service — LLM Wiki Knowledge Layer
 * AI Chatbot System — CBMS
 *
 * เปลี่ยนหน่วยความรู้จาก "chunk ดิบที่ตัดจากเอกสาร" เป็น "หน้า wiki ที่ LLM
 * เรียบเรียงต่อหัวข้อ" พร้อมคำถามตัวอย่างต่อหน้า:
 *
 *   buildDrafts()      เอกสาร (knowledge_chunks ของ source) → ร่าง wiki_pages
 *   generateQuestions()หน้า wiki → wiki_questions (ยังไม่ embed)
 *   publishPage()      ร่างที่ approve แล้ว → knowledge_sources + chunks + embeddings
 *   unpublishPage()    ถอนหน้าออกจาก retrieval
 *   analyzeGaps()      คำถามที่ตอบไม่ได้ → หัวข้อ wiki ที่ยังขาด
 *
 * หน้าที่ publish แล้วจะกลายเป็น knowledge_sources แถวปกติ (source_type='wiki')
 * ทำให้ EmbeddingService::findSimilarChunks() ค้นเจอได้โดยไม่ต้องแก้อะไร
 */

namespace App\Services;

use App\Helpers\Database;

class WikiService
{
    /** เนื้อหาต่อหน้าเกินเท่านี้ถือว่า LLM ไม่ทำตามคำสั่ง → ตัดทิ้งท้าย (เผื่อ 25% จากเป้า 2,000) */
    private const MAX_CONTENT_CHARS = 2500;

    /** เอกสารที่ยาวกว่านี้จะถูกตัดก่อนส่งให้ LLM (กัน prompt บวม / ค่า API พุ่ง) */
    private const MAX_DOC_CHARS = 40000;

    /** overlap ระหว่าง chunk ติดกันจาก EmbeddingService คือ 200 — เผื่อไว้เท่าตัว */
    private const MAX_CHUNK_OVERLAP = 400;

    /** จำนวนคำถาม fallback สูงสุดที่ส่งให้ LLM จัดกลุ่มในรอบเดียว */
    private const MAX_GAP_QUESTIONS = 300;

    private Database $db;
    private OpenRouterService $openRouter;
    private EmbeddingService  $embedding;
    /**
     * Bot scope. An int restricts every read/write to that bot (how the admin
     * pages use it). NULL means "ไม่จำกัดบอท" — the CLI runs unscoped so a
     * single cron job covers every bot; pages built that way still get their
     * bot_id from the source document they came from.
     */
    private ?int $botId;

    /** Resolved once per instance — builderModel() hits the DB for the default model. */
    private ?string $builderModel = null;

    public function __construct(?int $botId = null)
    {
        $this->db         = Database::getInstance();
        $this->openRouter = new OpenRouterService();
        $this->embedding  = new EmbeddingService();
        $this->botId      = $botId;
    }

    // ----------------------------------------------------------------
    // 1. Build drafts from source documents
    // ----------------------------------------------------------------

    /**
     * อ่านเอกสารต้นทาง (source_type='drive', synced, is_active=1) ที่ยังไม่เคย
     * build เป็น wiki หรือที่แก้ไขหลังรอบ build ล่าสุด แล้วให้ LLM เรียบเรียง
     * เป็นร่างหน้า wiki
     *
     * รันซ้ำโดยเอกสารไม่เปลี่ยน = skip ทั้งหมด ไม่เสียค่า LLM
     * เอกสารฉบับใดพัง (JSON เพี้ยน / API error) จะถูกข้ามและ log ไว้ — batch ไม่ตาย
     *
     * @param  int|null $sourceId  ระบุเพื่อ build เฉพาะ source เดียว (ข้ามการเช็ค pending)
     * @return array{created: int, updated: int, skipped: int, failed: int, pages: int, questions: int}
     */
    public function buildDrafts(?int $sourceId = null): array
    {
        $stats   = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0, 'pages' => 0, 'questions' => 0];
        $sources = $sourceId !== null
            ? array_filter([$this->findSource($sourceId)])
            : $this->findPendingSources();

        foreach ($sources as $source) {
            $docText = $this->readSourceText((int)$source['id']);
            if ($docText === '') {
                $this->log("skip source {$source['id']} ({$source['file_name']}): no chunk content");
                $stats['skipped']++;
                continue;
            }

            $pages = $this->requestPages((string)$source['file_name'], $docText);
            if ($pages === null) {
                $stats['failed']++;
                continue;
            }
            if ($pages === []) {
                $this->log("source {$source['id']} ({$source['file_name']}): LLM returned no pages");
                $stats['skipped']++;
                continue;
            }

            foreach ($pages as $page) {
                $result = $this->upsertDraft($page, $source);
                if ($result === null) {
                    continue;
                }
                $stats[$result['action']]++;
                $stats['pages']++;

                // คำถามตัวอย่างผูกกับเนื้อหา — เนื้อหาไม่เปลี่ยนก็ไม่ต้องสร้างใหม่
                if ($result['changed']) {
                    $stats['questions'] += $this->generateQuestions($result['id']);
                }
            }
        }

        return $stats;
    }

    /**
     * เอกสารที่ยังไม่มีหน้า wiki ที่ build หลัง last_modified ล่าสุดของมัน
     * (= ยังไม่เคย build หรือเอกสารถูกแก้หลัง build)
     *
     * @return array<int, array<string, mixed>>
     */
    private function findPendingSources(): array
    {
        $sql = "SELECT ks.*
                FROM knowledge_sources ks
                WHERE ks.source_type = 'drive'
                  AND ks.sync_status = 'synced'
                  AND ks.is_active   = 1
                  AND ks.chunk_count > 0
                  AND NOT EXISTS (
                        SELECT 1 FROM wiki_pages wp
                        WHERE JSON_CONTAINS(wp.source_ids, CAST(ks.id AS JSON))
                          AND wp.updated_at >= COALESCE(ks.last_modified, ks.created_at)
                  )";
        $params = [];

        if ($this->botId !== null) {
            $sql     .= ' AND ks.bot_id = ?';
            $params[] = $this->botId;
        }

        return $this->db->fetchAll($sql . ' ORDER BY ks.id ASC', $params);
    }

    private function findSource(int $sourceId): ?array
    {
        $sql    = 'SELECT * FROM knowledge_sources WHERE id = ?';
        $params = [$sourceId];
        if ($this->botId !== null) {
            $sql     .= ' AND bot_id = ?';
            $params[] = $this->botId;
        }
        return $this->db->fetch($sql, $params);
    }

    /**
     * ต่อ chunk ของ source กลับเป็นเนื้อเอกสารเต็ม — ไม่ต้องดึงไฟล์จาก Drive ใหม่
     * chunk ติดกันมี overlap ~200 ตัวอักษร (EmbeddingService::chunkText) ต้องตัดออก
     * ไม่งั้น LLM จะเห็นเนื้อหาซ้ำและอาจเรียบเรียงซ้ำตาม
     */
    private function readSourceText(int $sourceId): string
    {
        $rows = $this->db->fetchAll(
            'SELECT content FROM knowledge_chunks WHERE source_id = ? ORDER BY chunk_index ASC',
            [$sourceId]
        );

        $text = '';
        foreach ($rows as $row) {
            $chunk = trim((string)$row['content']);
            if ($chunk === '') {
                continue;
            }
            if ($text === '') {
                $text = $chunk;
                continue;
            }
            $overlap = $this->overlapLength($text, $chunk);
            $text   .= "\n\n" . mb_substr($chunk, $overlap, null, 'UTF-8');
        }

        if (mb_strlen($text, 'UTF-8') > self::MAX_DOC_CHARS) {
            $this->log("source {$sourceId}: document truncated to " . self::MAX_DOC_CHARS . ' chars for the wiki prompt');
            $text = mb_substr($text, 0, self::MAX_DOC_CHARS, 'UTF-8');
        }

        return trim($text);
    }

    /**
     * ความยาวของส่วนท้าย $prev ที่ตรงกับส่วนหัวของ $next (longest common suffix/prefix)
     * ใช้หา overlap ที่ chunkText ใส่ไว้ระหว่าง chunk
     */
    private function overlapLength(string $prev, string $next): int
    {
        $max = min(
            self::MAX_CHUNK_OVERLAP,
            mb_strlen($prev, 'UTF-8'),
            mb_strlen($next, 'UTF-8')
        );

        // สั้นกว่า 20 ตัวอักษรที่ตรงกันถือเป็นความบังเอิญ ไม่ใช่ overlap
        for ($len = $max; $len >= 20; $len--) {
            if (mb_substr($prev, -$len, null, 'UTF-8') === mb_substr($next, 0, $len, 'UTF-8')) {
                return $len;
            }
        }

        return 0;
    }

    /**
     * เรียก LLM แตกเอกสารเป็นหน้า wiki
     *
     * @return array<int, array<string, mixed>>|null  null = พัง (log แล้ว), [] = ไม่มีหน้า
     */
    private function requestPages(string $docTitle, string $docText): ?array
    {
        $raw = $this->askModel(
            WikiPrompts::buildPagesPrompt($docTitle, $docText),
            "buildPages({$docTitle})",
            8000
        );
        if ($raw === null) {
            return null;
        }

        $data = $this->decodeJson($raw);
        if ($data === null) {
            $this->log("buildPages({$docTitle}): could not parse LLM JSON");
            return null;
        }

        $pages = $this->extractList($data, 'pages');
        $valid = [];

        foreach ($pages as $page) {
            if (!is_array($page)) {
                continue;
            }
            $slug    = $this->normalizeSlug((string)($page['slug'] ?? ''), (string)($page['title'] ?? ''));
            $title   = trim((string)($page['title']   ?? ''));
            $content = trim((string)($page['content'] ?? ''));

            if ($slug === '' || $title === '' || $content === '') {
                $this->log("buildPages({$docTitle}): dropped a page with missing slug/title/content");
                continue;
            }

            $valid[] = [
                'slug'         => $slug,
                'title'        => mb_substr($title, 0, 500, 'UTF-8'),
                'summary'      => mb_substr(trim((string)($page['summary'] ?? '')), 0, 1000, 'UTF-8'),
                'content'      => $this->clampContent($content, $slug),
                'linked_slugs' => $this->normalizeLinkedSlugs($page['linked_slugs'] ?? []),
            ];
        }

        return $valid;
    }

    /**
     * สร้างหน้าใหม่ หรือรวมเข้ากับหน้าเดิมที่ slug ชนกัน
     *
     * หน้าได้ bot_id จากเอกสารต้นทาง ไม่ใช่จาก scope ของ instance — CLI ที่รัน
     * แบบไม่ระบุบอทจึงยังแยกหน้าของแต่ละบอทได้ถูกต้อง
     *
     * @param  array<string, mixed> $source  แถว knowledge_sources ของเอกสารต้นทาง
     * @return array{action: string, id: int, changed: bool}|null  null = พัง (log แล้ว)
     */
    private function upsertDraft(array $page, array $source): ?array
    {
        $sourceId = (int)$source['id'];
        $docTitle = (string)$source['file_name'];
        $botId    = $source['bot_id'] === null ? null : (int)$source['bot_id'];

        $existing = $this->db->fetch(
            'SELECT * FROM wiki_pages WHERE bot_id <=> ? AND slug = ?',
            [$botId, $page['slug']]
        );

        if ($existing === null) {
            $id = (int)$this->db->insert('wiki_pages', [
                'bot_id'       => $botId,
                'slug'         => $page['slug'],
                'title'        => $page['title'],
                'content'      => $page['content'],
                'summary'      => $page['summary'] ?: null,
                'status'       => 'draft',
                'source_ids'   => json_encode([$sourceId]),
                'linked_slugs' => json_encode($page['linked_slugs'], JSON_UNESCAPED_UNICODE),
                'generated_by' => $this->builderModel(),
            ]);
            return ['action' => 'created', 'id' => $id, 'changed' => true];
        }

        // หน้าเดิมมาจากเอกสารฉบับเดิมและเนื้อหาเหมือนเดิม → ไม่ต้องเสียค่า LLM รวมซ้ำ
        $sourceIds = $this->decodeIdList($existing['source_ids'] ?? null);
        if ($existing['content'] === $page['content'] && in_array($sourceId, $sourceIds, true)) {
            $this->touchPage((int)$existing['id']);
            return ['action' => 'updated', 'id' => (int)$existing['id'], 'changed' => false];
        }

        $merged = $existing['status'] === 'archived'
            // หน้าที่ archived แล้วถือว่าเลิกใช้ — เขียนทับด้วยเนื้อหาใหม่ ไม่ต้องรวม
            ? $page
            : $this->requestMerge($existing, $page, $docTitle);

        if ($merged === null) {
            return null;
        }

        $this->db->update('wiki_pages', [
            'title'        => $merged['title'],
            'content'      => $merged['content'],
            'summary'      => $merged['summary'] ?: null,
            // เนื้อหาเปลี่ยน = ต้องให้คน review ใหม่ (D5)
            'status'       => 'draft',
            'source_ids'   => json_encode(array_values(array_unique([...$sourceIds, $sourceId]))),
            'linked_slugs' => json_encode($merged['linked_slugs'], JSON_UNESCAPED_UNICODE),
            'generated_by' => $this->builderModel(),
        ], ['id' => $existing['id']]);

        return ['action' => 'updated', 'id' => (int)$existing['id'], 'changed' => true];
    }

    /**
     * รวมหน้าเดิมกับเนื้อหาใหม่ผ่าน LLM (mergePagesPrompt)
     *
     * @return array<string, mixed>|null
     */
    private function requestMerge(array $existing, array $page, string $docTitle): ?array
    {
        $linked = array_values(array_unique([
            ...$this->decodeStringList($existing['linked_slugs'] ?? null),
            ...$page['linked_slugs'],
        ]));

        $raw = $this->askModel(
            WikiPrompts::mergePagesPrompt(
                $page['slug'],
                (string)$existing['title'],
                (string)$existing['content'],
                $page['title'],
                $page['content'],
                $linked
            ),
            "mergePages({$page['slug']} ← {$docTitle})",
            4000
        );
        if ($raw === null) {
            return null;
        }

        $data = $this->decodeJson($raw);
        $merged = is_array($data['page'] ?? null) ? $data['page'] : $data;

        $title   = trim((string)($merged['title']   ?? ''));
        $content = trim((string)($merged['content'] ?? ''));
        if ($title === '' || $content === '') {
            $this->log("mergePages({$page['slug']}): unusable LLM response — keeping the existing page");
            return null;
        }

        return [
            'title'        => mb_substr($title, 0, 500, 'UTF-8'),
            'summary'      => mb_substr(trim((string)($merged['summary'] ?? $page['summary'])), 0, 1000, 'UTF-8'),
            'content'      => $this->clampContent($content, $page['slug']),
            'linked_slugs' => $this->normalizeLinkedSlugs($merged['linked_slugs'] ?? $linked),
        ];
    }

    /**
     * บอกว่ารอบ build นี้ได้ตรวจ source นี้แล้ว (แม้เนื้อหาไม่เปลี่ยน) เพื่อให้
     * findPendingSources() ไม่หยิบมันมา build ซ้ำรอบหน้า
     */
    private function touchPage(int $pageId): void
    {
        $this->db->query('UPDATE wiki_pages SET updated_at = NOW() WHERE id = ?', [$pageId]);
    }

    // ----------------------------------------------------------------
    // 2. Example questions (doc2query)
    // ----------------------------------------------------------------

    /**
     * สร้างคำถามตัวอย่างของหน้า wiki ลง wiki_questions (แทนที่ชุดเดิมทั้งหมด)
     * ยังไม่ embed ที่นี่ — publishPage() จะ embed ให้ตอนหน้าถูก approve
     *
     * @return int  จำนวนคำถามที่บันทึก (0 = LLM พัง, log ไว้แล้ว)
     */
    public function generateQuestions(int $pageId): int
    {
        $page = $this->findPage($pageId);
        if ($page === null) {
            return 0;
        }

        $raw = $this->askModel(
            WikiPrompts::buildQuestionsPrompt((string)$page['title'], (string)$page['content']),
            "buildQuestions(page {$pageId})",
            1500
        );
        if ($raw === null) {
            return 0;
        }

        $data = $this->decodeJson($raw);
        if ($data === null) {
            $this->log("buildQuestions(page {$pageId}): could not parse LLM JSON");
            return 0;
        }

        $questions = [];
        foreach ($this->extractList($data, 'questions') as $q) {
            if (!is_string($q)) {
                continue;
            }
            $q = trim($q);
            if ($q !== '') {
                $questions[] = mb_substr($q, 0, 1000, 'UTF-8');
            }
        }
        $questions = array_slice(array_values(array_unique($questions)), 0, WikiPrompts::MAX_QUESTIONS);

        if ($questions === []) {
            $this->log("buildQuestions(page {$pageId}): LLM returned no usable questions");
            return 0;
        }

        $this->db->delete('wiki_questions', ['page_id' => $pageId]);
        foreach ($questions as $q) {
            $this->db->insert('wiki_questions', [
                'page_id'   => $pageId,
                'source_id' => $page['published_source_id'],
                'question'  => $q,
            ]);
        }

        return count($questions);
    }

    /**
     * Embed คำถามของหน้าที่ยังไม่มี vector แล้วผูกกับ source ที่ materialize ไว้
     * เรียก API ครั้งเดียวต่อหน้า (createEmbedding รับ array อยู่แล้ว)
     *
     * @return int  จำนวนคำถามที่ embed สำเร็จ
     */
    private function embedQuestions(int $pageId, int $sourceId): int
    {
        // source_id ผูกใหม่ทุกครั้ง เผื่อหน้าเคย publish แล้ว source เปลี่ยน
        $this->db->update('wiki_questions', ['source_id' => $sourceId], ['page_id' => $pageId]);

        $rows = $this->db->fetchAll(
            'SELECT id, question FROM wiki_questions WHERE page_id = ? AND embedding IS NULL',
            [$pageId]
        );
        if ($rows === []) {
            return 0;
        }

        $vectors = $this->openRouter->createEmbedding(array_column($rows, 'question'));
        $stored  = 0;

        foreach ($rows as $i => $row) {
            $vector = $vectors[$i]['embedding'] ?? null;
            if (!is_array($vector) || $vector === []) {
                continue;
            }
            $this->db->update('wiki_questions', ['embedding' => json_encode($vector)], ['id' => $row['id']]);
            $stored++;
        }

        if ($stored < count($rows)) {
            $this->log("embedQuestions(page {$pageId}): embedded {$stored}/" . count($rows) . ' questions');
        }

        return $stored;
    }

    // ----------------------------------------------------------------
    // 3. Publish pipeline
    // ----------------------------------------------------------------

    /**
     * Publish หน้า wiki: materialize เป็น knowledge_sources + chunks + embeddings
     * ทำให้ retrieval pipeline เดิมค้นเจอทันที
     *
     * publish ซ้ำหน้าเดิม = แทนที่ chunk ชุดเก่า (storeChunks ลบของเก่าให้อยู่แล้ว)
     * และเคลียร์ answer_cache ของบอทนั้นให้ฟรีในตัว
     *
     * @param  int|null $reviewedBy  admin_users.id ที่กด publish (null = publish จาก CLI)
     */
    public function publishPage(int $pageId, ?int $reviewedBy = null): bool
    {
        $page = $this->findPage($pageId);
        if ($page === null) {
            $this->log("publishPage({$pageId}): page not found");
            return false;
        }

        $content = trim((string)$page['content']);
        if ($content === '') {
            $this->log("publishPage({$pageId}): refusing to publish an empty page");
            return false;
        }

        $sourceId = $this->upsertWikiSource($page);
        $chunks   = $this->embedding->chunkText($content);
        $this->embedding->storeChunks($sourceId, $chunks);
        $this->embedQuestions($pageId, $sourceId);

        $this->db->update('wiki_pages', [
            'status'              => 'published',
            'published_source_id' => $sourceId,
            'content_hash'        => md5($content),
            'reviewed_by'         => $reviewedBy,
        ], ['id' => $pageId]);

        return true;
    }

    /**
     * ถอนหน้าออกจาก retrieval: ปิด knowledge_source, archive หน้า, ล้าง embedding
     * ของคำถาม (chunk ยังอยู่แต่ค้นไม่เจอเพราะ findSimilarChunks กรอง is_active=1)
     */
    public function unpublishPage(int $pageId): bool
    {
        $page = $this->findPage($pageId);
        if ($page === null) {
            return false;
        }

        if (!empty($page['published_source_id'])) {
            $this->db->update(
                'knowledge_sources',
                ['is_active' => 0],
                ['id' => $page['published_source_id']]
            );
        }

        $this->db->update('wiki_questions', ['embedding' => null, 'source_id' => null], ['page_id' => $pageId]);
        $this->db->update('wiki_pages', ['status' => 'archived'], ['id' => $pageId]);

        // KB เปลี่ยน → คำตอบที่แคชไว้อาจอ้างข้อมูลที่ถอนไปแล้ว
        $this->clearAnswerCache($page['bot_id'] ?? null);

        return true;
    }

    /**
     * สร้าง/อัปเดตแถว knowledge_sources ของหน้า wiki
     *
     * natural key = 'wiki:<bot_id>:<slug>' — google_drive_file_id เป็น UNIQUE
     * ระดับตาราง (ไม่ใช่ต่อบอท) ส่วน slug ของ wiki unique ต่อบอท จึงต้องมี
     * bot_id ในคีย์ ไม่งั้นสองบอทที่มี slug เดียวกันจะชนกัน
     */
    private function upsertWikiSource(array $page): int
    {
        $driveId = 'wiki:' . ($page['bot_id'] ?? 0) . ':' . $page['slug'];
        $now     = date('Y-m-d H:i:s');

        $data = [
            'bot_id'         => $page['bot_id'],
            'file_name'      => $page['title'],
            'file_type'      => 'wiki',
            'mime_type'      => 'text/markdown',
            'source_type'    => 'wiki',
            'sync_status'    => 'synced',
            'last_modified'  => $now,
            'last_synced_at' => $now,
            'error_message'  => null,
            'is_active'      => 1,
        ];

        $existing = $this->db->fetch(
            'SELECT id FROM knowledge_sources WHERE google_drive_file_id = ? AND bot_id <=> ?',
            [$driveId, $page['bot_id']]
        );

        if ($existing !== null) {
            $this->db->update('knowledge_sources', $data, ['id' => $existing['id']]);
            return (int)$existing['id'];
        }

        return (int)$this->db->insert(
            'knowledge_sources',
            array_merge($data, ['google_drive_file_id' => $driveId])
        );
    }

    // ----------------------------------------------------------------
    // 4. Gap analysis — เรียนรู้จากคำถามที่ตอบไม่ได้
    // ----------------------------------------------------------------

    /**
     * ดึงคำถามที่บอทตอบไม่ได้ (คำตอบถัดไป is_fallback=1) ในช่วง N วัน
     * แล้วให้ LLM จัดกลุ่มเป็นหัวข้อ พร้อมกรองหัวข้อที่มีหน้า wiki อยู่แล้วออก
     *
     * LLM ทำหน้าที่ "จัดกลุ่มคำถาม" เท่านั้น ไม่แต่งเนื้อหา — fallback log
     * ไม่มีแหล่งข้อมูลรองรับ เนื้อหาต้องให้คนเขียนเอง
     *
     * @return array<int, array{topic: string, frequency: int, sample_questions: string[], suggested_slug: string}>
     */
    public function analyzeGaps(int $days = 30): array
    {
        $questions = $this->findFallbackQuestions($days);
        if ($questions === []) {
            return [];
        }

        [$botSql, $botParams] = $this->botScope();
        $existing = $this->db->fetchAll(
            "SELECT title, slug FROM wiki_pages WHERE status <> 'archived'{$botSql}",
            $botParams
        );

        $raw = $this->askModel(
            WikiPrompts::gapAnalysisPrompt($questions, array_column($existing, 'title')),
            "analyzeGaps({$days}d)",
            3000
        );
        if ($raw === null) {
            return [];
        }

        $data = $this->decodeJson($raw);
        if ($data === null) {
            $this->log("analyzeGaps({$days}d): could not parse LLM JSON");
            return [];
        }

        $existingSlugs = array_column($existing, 'slug');
        $topics        = [];

        foreach ($this->extractList($data, 'topics') as $t) {
            if (!is_array($t) || trim((string)($t['topic'] ?? '')) === '') {
                continue;
            }
            $slug = $this->normalizeSlug((string)($t['suggested_slug'] ?? ''), (string)$t['topic']);
            if ($slug === '' || in_array($slug, $existingSlugs, true)) {
                continue;
            }

            $samples = array_values(array_filter(
                is_array($t['sample_questions'] ?? null) ? $t['sample_questions'] : [],
                'is_string'
            ));

            $topics[] = [
                'topic'            => mb_substr(trim((string)$t['topic']), 0, 500, 'UTF-8'),
                'frequency'        => max(1, (int)($t['frequency'] ?? 1)),
                'sample_questions' => array_slice($samples, 0, 3),
                'suggested_slug'   => $slug,
            ];
        }

        usort($topics, fn($a, $b) => $b['frequency'] <=> $a['frequency']);

        return $topics;
    }

    /**
     * คำถามของผู้ใช้ที่ "ระบบตอบไม่ได้จริง" — คำตอบถัดไปเป็น fallback
     * หรือถูกผู้ใช้กด 👎 (ตอบแล้วแต่ตอบผิด/ไม่ตรง ก็คือช่องว่างความรู้เช่นกัน)
     *
     * @return string[]
     */
    private function findFallbackQuestions(int $days): array
    {
        // INTERVAL ไม่รับ placeholder ที่ตำแหน่งนี้ใน MySQL — cast เป็น int
        // แล้วแทรกตรงๆ ตาม pattern เดียวกับ Auth::isLoginThrottled()
        $days  = max(1, min(365, $days));
        $limit = self::MAX_GAP_QUESTIONS;
        [$botSql, $botParams] = $this->botScope('c');

        $rows = $this->db->fetchAll(
            "SELECT m.content
             FROM messages m
             JOIN conversations c ON c.id = m.conversation_id
             WHERE m.role = 'user'{$botSql}
               AND m.created_at >= DATE_SUB(NOW(), INTERVAL {$days} DAY)
               AND (
                     SELECT (a.is_fallback = 1 OR a.feedback = 'negative')
                     FROM messages a
                     WHERE a.conversation_id = m.conversation_id
                       AND a.id > m.id
                       AND a.role = 'assistant'
                     ORDER BY a.id ASC LIMIT 1
                   ) = 1
             ORDER BY m.id DESC
             LIMIT {$limit}",
            $botParams
        );

        $questions = [];
        foreach ($rows as $row) {
            $q = trim((string)$row['content']);
            if ($q !== '') {
                $questions[] = mb_substr($q, 0, 500, 'UTF-8');
            }
        }

        return array_values(array_unique($questions));
    }

    /**
     * สร้างหน้าร่างเปล่าจากหัวข้อที่ analyzeGaps() เสนอ ให้ admin เติมเนื้อหาเอง
     * — จงใจไม่ให้ LLM แต่งเนื้อหา เพราะไม่มีเอกสารต้นทางรองรับ
     *
     * @return int  wiki_pages.id ที่สร้าง (0 = slug ซ้ำกับหน้าที่มีอยู่)
     */
    public function createEmptyDraft(string $slug, string $title, string $summary = ''): int
    {
        $slug = $this->normalizeSlug($slug, $title);
        if ($slug === '' || trim($title) === '') {
            return 0;
        }

        $existing = $this->db->fetch(
            'SELECT id FROM wiki_pages WHERE bot_id <=> ? AND slug = ?',
            [$this->botId, $slug]
        );
        if ($existing !== null) {
            return 0;
        }

        return (int)$this->db->insert('wiki_pages', [
            'bot_id'  => $this->botId,
            'slug'    => $slug,
            'title'   => mb_substr(trim($title), 0, 500, 'UTF-8'),
            'content' => '',
            'summary' => $summary !== '' ? mb_substr($summary, 0, 1000, 'UTF-8') : null,
            'status'  => 'draft',
        ]);
    }

    // ----------------------------------------------------------------
    // 5. Read helpers (ใช้โดยหน้า admin)
    // ----------------------------------------------------------------

    /**
     * รายการหน้า wiki ของบอทนี้ พร้อมจำนวนคำถามและชื่อผู้ review
     *
     * @param  string $status  '' = ทุกสถานะ
     * @return array<int, array<string, mixed>>
     */
    public function listPages(string $status = ''): array
    {
        [$botSql, $params] = $this->botScope('wp');

        $sql = "SELECT wp.*,
                       (SELECT COUNT(*) FROM wiki_questions q WHERE q.page_id = wp.id) AS question_count,
                       (SELECT COUNT(*) FROM wiki_questions q WHERE q.page_id = wp.id AND q.embedding IS NULL) AS unembedded_count,
                       au.display_name AS reviewer_name
                FROM wiki_pages wp
                LEFT JOIN admin_users au ON au.id = wp.reviewed_by
                WHERE 1 = 1{$botSql}";

        if ($status !== '') {
            $sql     .= ' AND wp.status = ?';
            $params[] = $status;
        }

        return $this->db->fetchAll($sql . ' ORDER BY wp.updated_at DESC', $params);
    }

    /**
     * หน้า wiki หนึ่งหน้า (จำกัดเฉพาะบอทปัจจุบัน)
     */
    public function findPage(int $pageId): ?array
    {
        [$botSql, $botParams] = $this->botScope();

        return $this->db->fetch(
            "SELECT * FROM wiki_pages WHERE id = ?{$botSql}",
            [$pageId, ...$botParams]
        );
    }

    /**
     * SQL fragment + params ที่จำกัด query ให้อยู่ในบอทของ instance นี้
     * botId = null (CLI) → ไม่จำกัด
     *
     * @param  string $alias  table alias ของตารางที่มีคอลัมน์ bot_id
     * @return array{0: string, 1: array<int, int>}
     */
    private function botScope(string $alias = ''): array
    {
        if ($this->botId === null) {
            return ['', []];
        }

        $column = ($alias !== '' ? $alias . '.' : '') . 'bot_id';
        return [" AND {$column} = ?", [$this->botId]];
    }

    /**
     * คำถามตัวอย่างของหน้า
     *
     * @return array<int, array<string, mixed>>
     */
    public function pageQuestions(int $pageId): array
    {
        return $this->db->fetchAll(
            'SELECT id, question, source_id, embedding IS NOT NULL AS is_embedded
             FROM wiki_questions WHERE page_id = ? ORDER BY id ASC',
            [$pageId]
        );
    }

    /**
     * ชื่อไฟล์ต้นทางของหน้า (provenance) สำหรับแสดงในหน้า admin
     *
     * @return string[]
     */
    public function sourceNames(?string $sourceIdsJson): array
    {
        $ids = $this->decodeIdList($sourceIdsJson);
        if ($ids === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $rows = $this->db->fetchAll(
            "SELECT file_name FROM knowledge_sources WHERE id IN ({$placeholders})",
            $ids
        );

        return array_column($rows, 'file_name');
    }

    /**
     * อัปเดตเนื้อหาที่ admin แก้ด้วยมือ (หน้ากลับเป็น draft ถ้าเคย publish แล้ว
     * จะเห็น badge "แก้ไขแล้ว ยังไม่ re-publish" จาก content_hash ที่ไม่ตรง)
     */
    public function savePage(int $pageId, string $title, string $summary, string $content): bool
    {
        $page = $this->findPage($pageId);
        if ($page === null || trim($title) === '') {
            return false;
        }

        $this->db->update('wiki_pages', [
            'title'   => mb_substr(trim($title), 0, 500, 'UTF-8'),
            'summary' => trim($summary) !== '' ? mb_substr(trim($summary), 0, 1000, 'UTF-8') : null,
            'content' => trim($content),
        ], ['id' => $pageId]);

        return true;
    }

    /**
     * แทนที่ชุดคำถามของหน้าด้วยรายการที่ admin แก้มา
     *
     * คำถามข้อที่ข้อความไม่เปลี่ยนจะถูกเก็บแถวเดิมไว้ทั้ง embedding — แก้คำถาม
     * ข้อเดียวจึงไม่ทำให้ทั้งหน้าต้อง embed ใหม่
     *
     * @param  string[] $questions
     * @return int  จำนวนคำถามหลังบันทึก
     */
    public function saveQuestions(int $pageId, array $questions): int
    {
        $page = $this->findPage($pageId);
        if ($page === null) {
            return 0;
        }

        $clean = [];
        foreach ($questions as $q) {
            if (!is_string($q)) {
                continue;
            }
            $q = trim($q);
            if ($q !== '') {
                $clean[] = mb_substr($q, 0, 1000, 'UTF-8');
            }
        }
        $clean = array_values(array_unique($clean));

        $existing = $this->db->fetchAll('SELECT id, question FROM wiki_questions WHERE page_id = ?', [$pageId]);
        $kept     = [];

        foreach ($existing as $row) {
            if (in_array($row['question'], $clean, true)) {
                $kept[] = $row['question'];
            } else {
                $this->db->delete('wiki_questions', ['id' => $row['id']]);
            }
        }

        foreach ($clean as $q) {
            if (in_array($q, $kept, true)) {
                continue;
            }
            $this->db->insert('wiki_questions', [
                'page_id'   => $pageId,
                'source_id' => $page['published_source_id'],
                'question'  => $q,
            ]);
        }

        return count($clean);
    }

    /**
     * ลบหน้า wiki ทิ้งถาวร พร้อม source ที่ materialize ไว้ (chunks + questions
     * หายตาม FK CASCADE)
     */
    public function deletePage(int $pageId): bool
    {
        $page = $this->findPage($pageId);
        if ($page === null) {
            return false;
        }

        if (!empty($page['published_source_id'])) {
            $this->db->delete('knowledge_sources', ['id' => $page['published_source_id']]);
        }
        $this->db->delete('wiki_pages', ['id' => $pageId]);
        $this->clearAnswerCache($page['bot_id'] ?? null);

        return true;
    }

    // ----------------------------------------------------------------
    // Private Helpers — LLM plumbing
    // ----------------------------------------------------------------

    /**
     * โมเดลที่ใช้สร้าง wiki: env WIKI_BUILDER_MODEL → default model ของระบบ
     */
    public function builderModel(): string
    {
        if ($this->builderModel !== null) {
            return $this->builderModel;
        }

        $configured = trim((string)($_ENV['WIKI_BUILDER_MODEL'] ?? ''));
        if ($configured !== '') {
            return $this->builderModel = $configured;
        }

        $model = $this->openRouter->getDefaultModel();
        return $this->builderModel = $model['openrouter_model_id']
            ?? ($_ENV['OPENROUTER_DEFAULT_MODEL'] ?? 'openai/gpt-4o-mini');
    }

    /**
     * เรียก LLM หนึ่งครั้ง คืน content ดิบ หรือ null ถ้าพัง (log ไว้แล้ว)
     * อุณหภูมิต่ำ: งานนี้คือเรียบเรียงข้อเท็จจริง ไม่ใช่งานสร้างสรรค์
     */
    private function askModel(array $messages, string $context, int $maxTokens): ?string
    {
        try {
            $response = $this->openRouter->chat(
                $this->builderModel(),
                $messages,
                array_merge(['temperature' => 0.2, 'max_tokens' => $maxTokens], WikiPrompts::jsonOptions())
            );
        } catch (\Throwable $e) {
            $this->log("{$context}: LLM call failed — " . $e->getMessage());
            return null;
        }

        $content = trim((string)($response['content'] ?? ''));
        if ($content === '') {
            $this->log("{$context}: LLM returned an empty response");
            return null;
        }

        return $content;
    }

    /**
     * Parse JSON ที่ LLM ตอบกลับแบบทนพัง: strip code fence, ถ้ายังไม่ได้
     * ลองคว้าก้อน {...} / [...] ก้อนนอกสุด
     *
     * @return array<mixed>|null
     */
    private function decodeJson(string $raw): ?array
    {
        $text = trim($raw);

        if (str_starts_with($text, '```')) {
            $text = preg_replace('/^```[a-zA-Z]*\s*/', '', $text);
            $text = preg_replace('/```\s*$/', '', (string)$text);
            $text = trim((string)$text);
        }

        $data = json_decode($text, true);
        if (is_array($data)) {
            return $data;
        }

        if (preg_match('/[\{\[].*[\}\]]/s', $text, $m)) {
            $data = json_decode($m[0], true);
            if (is_array($data)) {
                return $data;
            }
        }

        return null;
    }

    /**
     * ดึง list ออกจาก response — รองรับทั้ง {"pages":[...]} และ [...] เปล่าๆ
     * (โมเดลที่ไม่รองรับ response_format มักตอบเป็น array ตรงๆ)
     *
     * @return array<mixed>
     */
    private function extractList(array $data, string $key): array
    {
        if (isset($data[$key]) && is_array($data[$key])) {
            return $data[$key];
        }
        return array_is_list($data) ? $data : [];
    }

    // ----------------------------------------------------------------
    // Private Helpers — normalization
    // ----------------------------------------------------------------

    /**
     * บังคับ slug ให้เป็น kebab-case ASCII ถ้า LLM ให้ slug ที่ใช้ไม่ได้
     * (เช่น ภาษาไทย) จะ fallback เป็น hash ของ title เพื่อไม่ให้หน้าหาย
     */
    private function normalizeSlug(string $slug, string $title = ''): string
    {
        $slug = strtolower(trim($slug));
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
        $slug = trim($slug, '-');

        if ($slug === '' && trim($title) !== '') {
            $slug = 'page-' . substr(md5(trim($title)), 0, 10);
        }

        return substr($slug, 0, 191);
    }

    /**
     * @param  mixed $value
     * @return string[]
     */
    private function normalizeLinkedSlugs(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $slugs = [];
        foreach ($value as $slug) {
            if (!is_string($slug)) {
                continue;
            }
            $normalized = $this->normalizeSlug($slug);
            if ($normalized !== '') {
                $slugs[] = $normalized;
            }
        }

        return array_values(array_unique($slugs));
    }

    /**
     * เนื้อหาที่ยาวเกินเพดานแปลว่า LLM ไม่ทำตามคำสั่งแตกหน้า — ตัดที่ขอบย่อหน้า
     * ใกล้ที่สุด (ตัดกลางประโยคแย่กว่า) แล้ว log ไว้ให้คนเห็นตอน review
     */
    private function clampContent(string $content, string $slug): string
    {
        if (mb_strlen($content, 'UTF-8') <= self::MAX_CONTENT_CHARS) {
            return $content;
        }

        $this->log("page '{$slug}': content is " . mb_strlen($content, 'UTF-8')
            . ' chars — truncated to ' . self::MAX_CONTENT_CHARS);

        $cut   = mb_substr($content, 0, self::MAX_CONTENT_CHARS, 'UTF-8');
        $break = mb_strrpos($cut, "\n", 0, 'UTF-8');

        // ตัดที่ขึ้นบรรทัดใหม่เฉพาะเมื่อไม่ทำให้เนื้อหาหายเกินครึ่ง
        if ($break !== false && $break > self::MAX_CONTENT_CHARS / 2) {
            $cut = mb_substr($cut, 0, $break, 'UTF-8');
        }

        return trim($cut);
    }

    /**
     * @return int[]
     */
    private function decodeIdList(?string $json): array
    {
        $decoded = json_decode((string)$json, true);
        if (!is_array($decoded)) {
            return [];
        }

        return array_values(array_unique(array_map('intval', array_filter($decoded, 'is_numeric'))));
    }

    /**
     * @return string[]
     */
    private function decodeStringList(?string $json): array
    {
        $decoded = json_decode((string)$json, true);
        if (!is_array($decoded)) {
            return [];
        }

        return array_values(array_filter($decoded, 'is_string'));
    }

    private function clearAnswerCache(?int $botId): void
    {
        try {
            $this->db->query('DELETE FROM answer_cache WHERE bot_id <=> ?', [$botId]);
        } catch (\Throwable) {
            // answer_cache table may not exist yet (migration 007)
        }
    }

    private function log(string $message): void
    {
        $logPath = dirname(__DIR__, 2) . '/storage/logs/wiki_service.log';
        @file_put_contents($logPath,
            sprintf("[%s] %s\n", date('Y-m-d H:i:s'), $message),
            FILE_APPEND | LOCK_EX
        );
    }
}
