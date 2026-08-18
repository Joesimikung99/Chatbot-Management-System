# แผนงาน: LLM Wiki Knowledge Layer สำหรับ CBMS Chatbot

> **สถานะ:** implement ครบทุก WP + ใช้งานจริงบน production แล้ว (2026-07-17) — ดูผลทดสอบจริงและข้อจำกัดที่เจอในหัวข้อ "9. สถานะการ implement" ท้ายเอกสาร
> **เป้าหมาย:** ยกระดับความแม่นยำของ RAG โดยเปลี่ยนหน่วยความรู้จาก "chunk ดิบที่ตัดจากเอกสาร" เป็น "หน้า wiki ที่ LLM เรียบเรียงต่อหัวข้อ" พร้อมคำถามตัวอย่างต่อหน้า และสารบัญความรู้ใน system prompt
> **วิธีใช้เอกสารนี้:** งานแบ่งเป็น Work Package (WP-0 ถึง WP-7) แต่ละ WP เขียนให้จบในตัว มี input/output/acceptance criteria ชัดเจน เพื่อให้ AI agent หลายตัวรับไปทำขนานกันได้ — **อ่านหัวข้อ "บริบทของระบบ" และ "การตัดสินใจเชิงออกแบบ" ก่อนเริ่มทุก WP**

---

## 1. ปัญหาที่ต้องการแก้ (Why)

ระบบปัจจุบันเป็น RAG แบบ chunk ดิบ ซึ่งมีปัญหาที่เห็นได้ในโค้ด:

1. **Embedding ภาษาไทยคะแนนต่ำ** — ต้องลด `similarity_threshold` เหลือ 0.15, มี floor hack ใน `AIService::retrieveContext()` และต้องเขียน regex synonym ไทยเอง (`AIService::expandQueryForSearch()`, `app/Services/AIService.php:749`)
2. **Chunk ตัดกลางเนื้อหา** — `EmbeddingService::chunkText()` ตัดทุก ~1,200 ตัวอักษร ข้อมูลเรื่องเดียวกันกระจายคนละ chunk ทำให้ตอบไม่ครบ
3. **บอทไม่รู้ขอบเขตความรู้ตัวเอง** — เวลา fallback บอกได้แค่ "ไม่มีข้อมูล" แนะนำหัวข้อใกล้เคียงไม่ได้
4. **ไม่มี loop ปรับปรุง** — คำถามที่ตอบไม่ได้ (`messages.is_fallback = 1`) ถูกเก็บไว้แต่ไม่ได้ใช้ประโยชน์

## 2. แนวทางแก้ (What)

| เทคนิค | แก้ปัญหาข้อ | Work Package |
|---|---|---|
| LLM เรียบเรียงเอกสารเป็น "หน้า wiki" ต่อหัวข้อ ครบจบในหน้า | 2 | WP-2, WP-3 |
| LLM สร้าง "คำถามตัวอย่าง" 5–10 ข้อต่อหน้า แล้ว embed คำถามชี้กลับมาที่หน้า (doc2query) | 1 | WP-3, WP-4 |
| สารบัญ wiki (index) ใส่ใน system prompt ทุกครั้ง | 3 | WP-5 |
| หน้า admin review ก่อน publish (กัน hallucination จากขั้นสรุป) | — | WP-6 |
| วิเคราะห์ fallback log → เสนอหัวข้อ wiki ที่ขาด | 4 | WP-7 |

---

## 3. บริบทของระบบ (อ่านก่อนเริ่มทุก WP)

**Stack:** PHP 8.4 (ไม่มี framework, autoload ผ่าน composer), MySQL 8, OpenRouter API, ไม่มี vector DB (cosine similarity คำนวณใน PHP)

**Pipeline ปัจจุบัน:**

```
Google Drive ──(sync.php --sync-drive)──▶ knowledge_sources (1 แถว/ไฟล์)
     │
     ├─ EmbeddingService::chunkText()   ตัด ~1,200 chars, overlap 200
     ├─ EmbeddingService::storeChunks() embed + insert ▶ knowledge_chunks
     │
User ──▶ AIService::chat()/chatStream()
     ├─ AnswerCacheService  (semantic cache — ข้าม LLM ถ้าคำถามซ้ำ)
     ├─ retrieveContext()   → EmbeddingService::findSimilarChunks() (cosine, top-K)
     ├─ buildMessages()     → อัด chunk เข้า system prompt
     └─ OpenRouterService::chat()/chatStream()
```

**ไฟล์หลักที่เกี่ยวข้อง:**

| ไฟล์ | หน้าที่ |
|---|---|
| `app/Services/AIService.php` | RAG orchestrator: retrieveContext, buildMessages, answer cache |
| `app/Services/EmbeddingService.php` | chunkText, storeChunks, findSimilarChunks, cosineSimilarity |
| `app/Services/OpenRouterService.php` | chat / chatStream / createEmbedding |
| `app/Services/AnswerCacheService.php` | semantic answer cache |
| `app/Helpers/Database.php` | PDO singleton: `fetch`, `fetchAll`, `insert`, `update`, `delete`, `query` |
| `sync.php` | CLI: `--sync-drive`, `--embed-all`, `--source=ID`, `--sync-models`, `--aggregate`, `--test-ai` |
| `public/admin/knowledge.php` | หน้า admin จัดการ knowledge sources |
| `database/migrations/` | ล่าสุดคือ `009_wiki_pages.sql` (ของแผนนี้เอง) → **migration ใหม่เริ่มที่ 010** |

**ตารางสำคัญ (ดู `database/migrations/001_create_all_tables.sql`):**

- `knowledge_sources` — `google_drive_file_id` เป็น `NOT NULL UNIQUE`, มี `sync_status`, `is_active`, `chunk_count`; migration 002 เพิ่ม `bot_id`
- `knowledge_chunks` — `source_id` FK (CASCADE), `content` TEXT, `embedding` JSON, `metadata` JSON
- `answer_cache` (migration 007) — ถูกเคลียร์อัตโนมัติเมื่อ `storeChunks()` ทำงาน (KB เปลี่ยน = cache stale)
- `messages` — มี `is_fallback`, `knowledge_chunks_used` ใช้ใน WP-7

**ข้อตกลงของ codebase (ต้องทำตาม):**

- Prepared statements ผ่าน `Database` helper เท่านั้น (ห้ามต่อ string SQL)
- Output ใน admin ต้อง `htmlspecialchars` เสมอ; ทุก POST form ต้องมี CSRF token (ดู `app/Helpers/Auth.php`)
- ภาษาไทยใช้ `mb_*` function พร้อม `'UTF-8'` เสมอ
- Log error ลง `storage/logs/` ตาม pattern ที่ `AIService::logError()` ทำ
- Migration เป็น SQL ล้วน รันด้วย `mysql < file.sql` (ไม่มี migration runner) — ต้องเป็น idempotent (`IF NOT EXISTS` / ตรวจ column ก่อน ADD)

---

## 4. การตัดสินใจเชิงออกแบบ (ตัดสินใจแล้ว — อย่า re-design ระหว่างทำ WP)

**D1 — หน้า wiki materialize เป็น `knowledge_sources` ปกติ**
เมื่อ publish หน้า wiki จะสร้าง/อัปเดตแถวใน `knowledge_sources` โดยใช้ `google_drive_file_id = 'wiki:<slug>'` (คอลัมน์นี้ NOT NULL UNIQUE อยู่แล้ว จึงใช้เป็น natural key ได้) และเพิ่มคอลัมน์ `source_type ENUM('drive','wiki') DEFAULT 'drive'` — ผลคือ **retrieval pipeline เดิม (`findSimilarChunks`) ใช้ได้ทันทีโดยไม่ต้องแก้** เพราะหน้า wiki กลายเป็น chunk ปกติ

**D2 — สถานะ authoring แยกไว้ที่ตาราง `wiki_pages`**
เนื้อหาร่าง, สถานะ review, ที่มา (source_ids), ลิงก์ระหว่างหน้า อยู่ใน `wiki_pages` — `knowledge_sources`/`knowledge_chunks` เก็บเฉพาะเวอร์ชันที่ publish แล้ว

**D3 — คำถามตัวอย่างอยู่ตาราง `wiki_questions` แยกจาก chunks**
ห้าม insert คำถามเป็น `knowledge_chunks` (เพราะ content ของ chunk จะถูกอัดเข้า prompt — คำถามไม่ใช่เนื้อหา) การค้นจะ scan `wiki_questions` เพิ่มอีกรอบ แล้ว map hit → chunks ของหน้านั้น

**D4 — ขนาดหน้า wiki ≤ 2,000 ตัวอักษรไทยต่อหน้า**
ให้หนึ่งหน้า = 1–2 chunks เพื่อไม่ให้ปัญหา "ตัดกลางเนื้อหา" กลับมา ถ้าหัวข้อใหญ่เกิน ให้ LLM แตกเป็นหลายหน้าแล้วลิงก์กัน

**D5 — หน้า wiki เริ่มต้นเป็น `draft` เสมอ ต้องมีคน approve ก่อนถึงจะ publish**
กัน hallucination จากขั้นสรุปเอกสาร ใช้ flow: `draft → published` (และ `archived`) ผ่านหน้า admin (WP-6) หรือ CLI flag `--publish` สำหรับ dev

**D6 — โมเดลที่ใช้สร้าง wiki กำหนดผ่าน env `WIKI_BUILDER_MODEL`** (default: โมเดล default ของระบบ) เรียกผ่าน `OpenRouterService::chat()` ที่มีอยู่

**D7 — retrieval ให้น้ำหนักหน้า wiki ก่อน chunk ดิบ**
ถ้า hit จาก `wiki_questions` หรือ chunk ที่ `source_type='wiki'` มีคะแนนเกิน threshold ให้ใช้ผลนั้น; chunk ดิบจากไฟล์ Drive เป็น fallback tier (เอกสารดิบยังอยู่ครบ ไม่ลบอะไรทิ้ง)

---

## 5. Work Packages

### กราฟ dependency

```
WP-0 (schema) ──┬──▶ WP-2 (WikiService: สร้างหน้า) ──▶ WP-3 (publish pipeline) ──▶ WP-4 (retrieval)
                │                                          ▲                          │
                ├──▶ WP-6 (admin UI) ──────────────────────┘ (ปุ่ม publish เรียก WP-3) │
                ├──▶ WP-7 (gap analysis)                                              │
                └──▶ WP-5 (wiki index ใน prompt) ◀────────────────────────────────────┘

WP-1 (prompt templates) — ไม่พึ่งใคร ทำขนานได้ตั้งแต่ต้น
WP-8 (tests) — ประกบทุก WP
```

**ลำดับแนะนำ:** WP-0 + WP-1 ก่อน → จากนั้น WP-2, WP-6, WP-7 ขนานกันได้ → WP-3 → WP-4 + WP-5 ขนานกัน

---

### WP-0: Database Migration `009_wiki_pages.sql`

**Deliverable:** ไฟล์ `database/migrations/009_wiki_pages.sql`

**สิ่งที่ต้องสร้าง:**

```sql
-- 1) คอลัมน์ระบุประเภท source
ALTER TABLE knowledge_sources
  ADD COLUMN source_type ENUM('drive','wiki') NOT NULL DEFAULT 'drive';
  -- ต้องเขียนแบบ idempotent: ตรวจจาก information_schema ก่อน ADD

-- 2) ตารางหน้า wiki (authoring state)
CREATE TABLE IF NOT EXISTS wiki_pages (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  bot_id        INT NULL,                    -- NULL = legacy single-bot (ตาม pattern answer_cache)
  slug          VARCHAR(191) NOT NULL,       -- kebab-case ASCII เช่น 'library-hours'
  title         VARCHAR(500) NOT NULL,       -- ชื่อหัวข้อภาษาไทย
  content       MEDIUMTEXT NOT NULL,         -- เนื้อหา markdown ภาษาไทย ≤ ~2000 chars
  summary       VARCHAR(1000) NULL,          -- 1-2 ประโยค ใช้ทำสารบัญ (WP-5)
  status        ENUM('draft','published','archived') NOT NULL DEFAULT 'draft',
  source_ids    JSON NULL,                   -- [knowledge_sources.id] ที่มาของเนื้อหา (provenance)
  linked_slugs  JSON NULL,                   -- slug ของหน้าที่เกี่ยวข้อง (สำหรับอนาคต/WP-4 optional)
  content_hash  CHAR(32) NULL,               -- md5(content) เวอร์ชันที่ publish ล่าสุด (ไว้เช็คว่าแก้แล้วยังไม่ re-publish)
  published_source_id INT NULL,              -- FK → knowledge_sources.id แถวที่ materialize แล้ว
  generated_by  VARCHAR(191) NULL,           -- model id ที่สร้างร่าง
  reviewed_by   INT NULL,                    -- admin_users.id ที่ approve
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_bot_slug (bot_id, slug),
  KEY idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3) ตารางคำถามตัวอย่าง (doc2query)
CREATE TABLE IF NOT EXISTS wiki_questions (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  page_id    INT NOT NULL,
  source_id  INT NULL,          -- knowledge_sources.id ของหน้า (เติมตอน publish, ใช้ join ตอน retrieve)
  question   VARCHAR(1000) NOT NULL,
  embedding  JSON NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_page (page_id),
  KEY idx_source (source_id),
  FOREIGN KEY (page_id) REFERENCES wiki_pages(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

**Acceptance criteria:**
- รันซ้ำได้ไม่ error (idempotent — ดูตัวอย่าง style จาก migration 007)
- รันบน DB ที่มีข้อมูลเดิมได้ โดยแถว `knowledge_sources` เดิมทุกแถวได้ `source_type='drive'`
- มี comment ภาษาไทย/อังกฤษตาม style migration เดิม

---

### WP-1: Prompt Templates (ทำขนานได้ทันที ไม่พึ่ง WP อื่น)

**Deliverable:** ไฟล์ `app/Services/WikiPrompts.php` — class เก็บ static method คืน prompt string

**ต้องมี 3 prompts (ภาษาไทย):**

1. `buildPagesPrompt(string $docTitle, string $docText): array` — สั่งให้ LLM อ่านเอกสารแล้วแตกเป็นหน้า wiki:
   - หนึ่งหน้า = หนึ่งหัวข้อที่ผู้ใช้น่าจะถาม, ยาว ≤ 2,000 ตัวอักษร
   - **ห้ามแต่งข้อมูลที่ไม่มีในเอกสาร** (คำสั่งนี้ต้องเน้นหนัก — เป็น guard หลักกัน hallucination)
   - output เป็น JSON array: `[{"slug","title","summary","content","linked_slugs"}]`
   - slug เป็น kebab-case ASCII (translit/แปลหัวข้อไทยเป็นอังกฤษสั้นๆ)
2. `buildQuestionsPrompt(string $title, string $content): array` — สร้างคำถาม 5–10 ข้อที่ผู้ใช้จริงน่าจะพิมพ์ถาม (ภาษาพูด, มีทั้งสั้น/ยาว, สะกดแบบที่คนพิมพ์จริง) output เป็น JSON array of strings
3. `mergePagesPrompt(...)` — กรณีหลายเอกสารพูดเรื่องเดียวกัน: รับหน้าเดิม + เนื้อหาใหม่ ให้รวมเป็นหน้าเดียวโดยไม่ทิ้งข้อมูลเดิม

**หมายเหตุการ implement:** คืนเป็น messages array พร้อมใช้กับ `OpenRouterService::chat()` (`[['role'=>'system',...],['role'=>'user',...]]`) และสั่ง `response_format` เป็น JSON ถ้าโมเดลรองรับ; เขียน unit-testable (pure function ไม่แตะ DB)

**Acceptance criteria:** มี PHPDoc ตัวอย่าง output, มีเทสต์ smoke ว่า prompt มีคำสั่งห้ามแต่งข้อมูล และระบุ format JSON ชัด

---

### WP-2: WikiService — สร้างร่างหน้า wiki จากเอกสาร

**พึ่งพา:** WP-0, WP-1
**Deliverable:** `app/Services/WikiService.php` + คำสั่งใหม่ใน `sync.php`

**หน้าที่ของ class:**

```php
class WikiService
{
    // อ่าน knowledge_sources (source_type='drive', synced, is_active=1) ที่ยังไม่ถูก build
    // หรือที่ last_modified ใหม่กว่ารอบ build ล่าสุด แล้วเรียก LLM สร้าง/อัปเดตร่าง wiki_pages
    public function buildDrafts(?int $botId = null, ?int $sourceId = null): array; // สรุปผล: created/updated/skipped

    // สร้างคำถามตัวอย่างลง wiki_questions (ยังไม่ embed — embed ตอน publish ใน WP-3)
    public function generateQuestions(int $pageId): int;
}
```

**พฤติกรรมสำคัญ:**
- ดึงเนื้อหาเอกสารจาก `knowledge_chunks` ของ source นั้น (ต่อ chunk_index กลับเป็นเนื้อเต็ม — ระวัง overlap 200 ตัวอักษรซ้ำระหว่าง chunk ติดกัน ตัดออกด้วยการหา longest common suffix/prefix) — ไม่ต้องดึงไฟล์จาก Drive ใหม่
- ถ้า slug ที่ LLM เสนอชนกับหน้า `status != 'archived'` เดิม → ใช้ `mergePagesPrompt` รวมเนื้อหา แล้ว mark หน้าเป็น `draft` ใหม่ (ต้อง review ซ้ำ)
- ต่อท้าย `source_ids` (JSON) ทุกครั้ง เพื่อ provenance
- Parse JSON จาก LLM แบบ defensive: strip code fence, ลอง `json_decode` ถ้าพังให้ log แล้วข้าม source นั้น (อย่าให้ทั้ง batch ตาย)
- Log ลง `storage/logs/wiki_service.log` ตาม pattern `AIService::logError()`

**คำสั่ง CLI ใหม่ใน `sync.php`:**
```
php sync.php --build-wiki              # build ร่างจากทุก source ที่ pending
php sync.php --build-wiki --source=5   # build จาก source เดียว
```
(เพิ่มใน `getopt` list และ help text ตาม style เดิมของไฟล์)

**Acceptance criteria:**
- รัน `--build-wiki` ซ้ำสองรอบโดยเอกสารไม่เปลี่ยน → รอบสอง skip ทั้งหมด (idempotent)
- หน้า wiki ที่ได้ทุกหน้า `status='draft'`, `generated_by` = model id ที่ใช้
- เอกสารเดียวแตกได้หลายหน้า, ไม่มีหน้าไหน content เกิน ~2,500 chars (เผื่อ 25% จากเป้า 2,000)

---

### WP-3: Publish Pipeline — draft → knowledge_chunks + embeddings

**พึ่งพา:** WP-0, WP-2 (โครงสร้างข้อมูล) — โค้ดเขียนขนานกับ WP-2 ได้ถ้าตกลง schema ตาม WP-0
**Deliverable:** เพิ่ม method ใน `WikiService` + คำสั่ง CLI

```php
public function publishPage(int $pageId, ?int $reviewedBy = null): bool;
public function unpublishPage(int $pageId): bool; // archive: ปิด knowledge_source + ลบ questions embedding
```

**ขั้นตอนใน `publishPage`:**
1. Upsert แถว `knowledge_sources`: `google_drive_file_id = 'wiki:'.$slug`, `file_name = $title`, `source_type='wiki'`, `sync_status='synced'`, `is_active=1`, `bot_id` ตามหน้า
2. เรียก `EmbeddingService::chunkText()` + `storeChunks()` ด้วย content ของหน้า (ได้ answer_cache invalidation ฟรีจากโค้ดเดิมใน `storeChunks`)
3. Embed คำถามใน `wiki_questions` ของหน้านั้น (batch เดียวผ่าน `OpenRouterService::createEmbedding(array)` — รองรับ array input อยู่แล้ว ดูการใช้ใน `storeChunks`) แล้วเติม `source_id`
4. อัปเดต `wiki_pages`: `status='published'`, `published_source_id`, `content_hash=md5(content)`, `reviewed_by`

**คำสั่ง CLI:**
```
php sync.php --publish-wiki=ID     # publish หน้าเดียว (สำหรับ dev/test)
php sync.php --publish-wiki=all    # publish ทุกหน้าที่ status='draft' (ใช้เฉพาะ initial seed — production ให้ approve ผ่าน admin)
```

**Acceptance criteria:**
- publish ซ้ำหน้าเดิม (เนื้อหาแก้แล้ว) → chunk เก่าถูกแทนที่ ไม่งอกซ้ำ (`storeChunks` ลบของเก่าให้อยู่แล้ว), answer_cache ของ bot นั้นถูกเคลียร์
- `unpublishPage` → `knowledge_sources.is_active=0`, หน้าเป็น `archived`, chunk ไม่โผล่ใน retrieval อีก
- คำถามทุกข้อของหน้าที่ publish มี embedding ไม่เป็น NULL

---

### WP-4: Retrieval Upgrade — ค้นจาก wiki_questions ก่อน

**พึ่งพา:** WP-0 (schema); ทดสอบจริงต้องมีข้อมูลจาก WP-3
**Deliverable:** แก้ `app/Services/EmbeddingService.php` + `app/Services/AIService.php`

**สิ่งที่ต้องแก้:**

1. **`EmbeddingService` เพิ่ม method:**
   ```php
   public function findPagesByQuestion(string $queryText, int $topK, float $minScore, ?int $botId): array
   // scan wiki_questions (JOIN knowledge_sources ผ่าน source_id เช็ค is_active=1)
   // คืน [['source_id'=>..,'score'=>..,'question'=>..]] เรียงคะแนน
   // ใช้ embedQuery() เดิม → memoized ไม่เปลือง API call เพิ่ม
   ```
2. **`AIService::retrieveContext()` เพิ่ม tier แรก:**
   - Tier 1: `findPagesByQuestion()` — threshold แนะนำ **0.45** (คำถาม-vs-คำถาม ภาษาเดียวกัน คะแนนสูงกว่า คำถาม-vs-เนื้อหา มาก จึงตั้ง threshold สูงได้) ถ้า hit → ดึง chunks ทั้งหมดของ source นั้น (หน้า wiki สั้น 1–2 chunks) มาเป็น context
   - Tier 2: `findSimilarChunks()` เดิมทุกอย่าง (รวม floor hack, follow-up retry, query expansion) — **อย่าลบของเดิม** เอกสาร Drive ที่ยังไม่ได้ทำเป็น wiki ต้องยังค้นเจอ
   - threshold ของ tier 1 อ่านจาก settings key ใหม่ `wiki_question_threshold` (default 0.45) ตาม pattern `loadSettings()`
3. คงรูปแบบ return `[$chunks, $chunkIds, $isConversational]` เดิมเป๊ะ — `buildMessages`/`finalizeTurn` ไม่ต้องแก้

**Acceptance criteria:**
- คำถามที่ตรงกับ `wiki_questions` → ได้ chunks ของหน้านั้นครบทุก chunk, `chunks_used` ใน response มี id ถูกต้อง
- คำถามที่ไม่มีใน wiki → พฤติกรรมเหมือนเดิม 100% (regression: รัน `php sync.php --test-ai "..."` และ `bash test-api.sh` ผ่าน)
- ไม่มี embedding API call เพิ่มต่อ request (reuse query vector เดิมผ่าน memoization)

---

### WP-5: Wiki Index ใน System Prompt

**พึ่งพา:** WP-0; เห็นผลจริงเมื่อมีหน้า published จาก WP-3
**Deliverable:** แก้ `app/Services/AIService.php::buildMessages()`

**สิ่งที่ต้องทำ:**
1. Method ใหม่ `getWikiIndex(): string` — ดึง `title` + `summary` ของทุกหน้า `status='published'` ของ bot นั้น (เรียงตาม title) เป็น bullet list สั้นๆ; **cache ต่อ request** (property) และจำกัดไม่เกิน ~40 หน้า / ~1,500 tokens — ถ้าเกินให้ตัดเหลือ title อย่างเดียว
2. ใน `buildMessages()` แทรก block ใหม่หลัง system prompt:
   ```
   ## หัวข้อที่มีข้อมูลในระบบ
   - เวลาทำการ — เวลาเปิด-ปิดวันธรรมดา/เสาร์-อาทิตย์/ปิดเทอม
   - การยืม-คืนหนังสือ — สิทธิ์ จำนวนเล่ม ค่าปรับ
   ...
   ```
3. ปรับ NO_CONTEXT block เดิม: เพิ่มคำแนะนำว่า "ถ้าคำถามใกล้เคียงหัวข้อในรายการข้างต้น ให้ชวนผู้ใช้ถามหัวข้อนั้น"
4. ถ้าไม่มีหน้า published เลย → ไม่แทรกอะไร (พฤติกรรมเดิมเป๊ะ)

**Acceptance criteria:** ไม่มีหน้า published = prompt เหมือนเดิม byte-ต่อ-byte; มีหน้า published = index โผล่ทั้ง path มี chunks และ path fallback; token ของ index ไม่เกิน budget ที่ตั้ง

---

### WP-6: Admin UI — Review & Publish

**พึ่งพา:** WP-0 (ปุ่ม publish เรียกโค้ด WP-3 — ทำ UI ก่อนแล้ว stub ได้)
**Deliverable:** `public/admin/wiki.php` + เมนูใน `public/admin/layouts/header.php`

**Features (เรียงความสำคัญ):**
1. ตารางรายการหน้า wiki: title, slug, status (badge สี), แหล่งที่มา (file_name จาก source_ids), updated_at — filter ตาม status
2. หน้าแก้ไข: form แก้ title/summary/content (textarea, markdown), แสดงรายการคำถามตัวอย่าง (แก้/ลบ/เพิ่มได้)
3. ปุ่ม **Publish** (เรียก `WikiService::publishPage($id, $adminId)`), **Unpublish/Archive**
4. แสดง diff-hint: ถ้า `md5(content) != content_hash` และ status='published' → badge "แก้ไขแล้ว ยังไม่ re-publish"
5. ปุ่ม "Generate คำถามใหม่" (เรียก `WikiService::generateQuestions`)

**ข้อบังคับ:** ตาม convention เดิมของ `public/admin/knowledge.php` — ใช้ layouts/header+footer, CSRF token ทุก POST, `htmlspecialchars` ทุก output, ตรวจ role ผ่าน `Auth` helper (แก้ไข/publish = admin ขึ้นไป, viewer อ่านอย่างเดียว), TailwindCSS + Alpine.js ตาม style หน้าอื่น

**Acceptance criteria:** ทุก action ผ่าน CSRF + role check; publish จากหน้านี้แล้วบอทตอบด้วยข้อมูลใหม่ได้ (ยิง `public/api/chat.php` ทดสอบ); viewer กด publish ไม่ได้ (403)

---

### WP-7: Gap Analysis — เรียนรู้จากคำถามที่ตอบไม่ได้

**พึ่งพา:** WP-0 (ตาราง wiki_pages ไว้เทียบ), ขนานกับ WP อื่นได้
**Deliverable:** method ใน `WikiService` + CLI + widget ใน admin

1. `analyzeGaps(int $days = 30): array` — ดึง `messages` ที่ `role='user'` และคำตอบถัดไป `is_fallback=1` ในช่วง N วัน → ส่งให้ LLM จัดกลุ่มเป็นหัวข้อ พร้อมนับความถี่ + เทียบว่าหัวข้อไหนยังไม่มีใน `wiki_pages` → คืน `[{"topic","frequency","sample_questions","suggested_slug"}]`
2. CLI: `php sync.php --wiki-gaps` พิมพ์ตารางสรุป
3. (optional ถ้ามีเวลา) แสดงผลบน `public/admin/wiki.php` เป็น section "หัวข้อที่ผู้ใช้ถามแต่ยังไม่มีข้อมูล" พร้อมปุ่ม "สร้างร่างหน้า" (สร้าง `wiki_pages` เปล่าสถานะ draft ให้ admin เติมเนื้อหา — **ห้ามให้ LLM แต่งเนื้อหาเองจาก fallback log** เพราะไม่มี source)

**Acceptance criteria:** ไม่มี fallback ใน N วัน → รายงานว่างไม่ error; คำถามส่วนตัว/PII ไม่หลุดไป log ไหนนอกจากที่มีอยู่แล้ว

---

### WP-8: Tests (ประกบทุก WP)

**Deliverable:** ไฟล์เทสต์ใน `tests/` (PHPUnit มีอยู่แล้ว — ดู `phpunit.xml` และ style เทสต์เดิมในโฟลเดอร์ `tests/`)

ขั้นต่ำที่ต้องมี:
- `WikiPromptsTest` — prompt มี guard ห้ามแต่งข้อมูล, format ถูก (WP-1, pure ไม่ต้อง mock)
- `WikiServiceTest` — parse JSON response ของ LLM (mock OpenRouterService): case ปกติ, มี code fence, JSON พัง, slug ชน
- `RetrievalTierTest` — mock embeddings: tier 1 hit → ได้หน้า wiki; tier 1 miss → fallthrough เหมือนเดิม (WP-4)
- Regression: `bash test-api.sh` ยังผ่านหลังทุก WP ที่แตะ `AIService`

---

## 6. การมอบหมายให้ AI หลายตัว (คำแนะนำสำหรับผู้ควบคุม)

| ลำดับ | มอบหมายพร้อมกันได้ | หมายเหตุ |
|---|---|---|
| รอบ 1 | WP-0, WP-1 | เล็ก จบเร็ว เป็นฐานของทุกอย่าง — review schema ให้นิ่งก่อนรอบ 2 |
| รอบ 2 | WP-2, WP-6, WP-7 | คนละไฟล์กัน ไม่ชนกัน (WP-6 stub ปุ่ม publish ไว้ก่อน) |
| รอบ 3 | WP-3 | ต่อจาก WP-2 (อยู่ไฟล์ WikiService เดียวกัน — อย่าทำขนานกับ WP-2) |
| รอบ 4 | WP-4, WP-5 | แตะ `AIService.php` ทั้งคู่ — ถ้าให้คนละ agent ทำ ให้ทำทีละอัน หรือระวัง merge conflict |
| ตลอด | WP-8 | ประกบทุกรอบ |

**กติกากลางสำหรับทุก agent:**
1. อ่าน section 3 (บริบท) และ section 4 (การตัดสินใจ) ก่อนเริ่ม — อย่าเปลี่ยน design decision โดยไม่อัปเดตเอกสารนี้
2. อย่าแตะไฟล์นอก scope ของ WP ตัวเอง ยกเว้น `sync.php` (หลาย WP เพิ่ม CLI flag — เพิ่มเฉพาะ block ของตัวเอง)
3. งานเสร็จแล้วให้ติ๊ก checklist ด้านล่างพร้อมระบุไฟล์ที่แก้
4. ทุก WP ที่แตะ `AIService`/`EmbeddingService` ต้องรัน regression: `php sync.php --test-ai "ห้องสมุดเปิดกี่โมง"` และ `bash test-api.sh`

## 7. Checklist ติดตามงาน

- [x] WP-0 Migration 009 — ไฟล์: `database/migrations/009_wiki_pages.sql`
- [x] WP-1 Prompt templates — ไฟล์: `app/Services/WikiPrompts.php`
- [x] WP-2 WikiService สร้างร่าง — ไฟล์: `app/Services/WikiService.php`, `sync.php`
- [x] WP-3 Publish pipeline — ไฟล์: `app/Services/WikiService.php`, `sync.php`
- [x] WP-4 Retrieval tier — ไฟล์: `app/Services/EmbeddingService.php`, `app/Services/AIService.php`
- [x] WP-5 Wiki index ใน prompt — ไฟล์: `app/Services/AIService.php`
- [x] WP-6 Admin UI — ไฟล์: `public/admin/wiki.php`, `public/admin/layouts/header.php`, `public/admin/knowledge.php` (icon)
- [x] WP-7 Gap analysis — ไฟล์: `app/Services/WikiService.php`, `sync.php`, `public/admin/wiki.php`
- [x] WP-8 Tests — ไฟล์: `tests/Unit/WikiPromptsTest.php`, `tests/Unit/WikiServiceParsingTest.php`, `tests/Unit/RetrievalTierTest.php`

## 8. ความเสี่ยงและ mitigation

| ความเสี่ยง | Mitigation |
|---|---|
| LLM แต่งข้อมูลตอนสรุปหน้า wiki | D5: ทุกหน้าเป็น draft ต้องมีคน approve (WP-6) + prompt เน้นห้ามแต่ง (WP-1) + provenance ผ่าน source_ids |
| ค่า LLM ตอน build wiki | รันเฉพาะ source ที่เปลี่ยน (idempotent ใน WP-2); build เป็นงาน cron รายสัปดาห์เหมือน --sync-drive เดิม |
| threshold 0.45 ของ tier 1 ไม่เหมาะกับข้อมูลจริง | เป็น setting ปรับได้ (`wiki_question_threshold`) ไม่ hardcode |
| ข้อมูลใน wiki ตกรุ่นเมื่อเอกสารต้นทางเปลี่ยน | WP-2 ตรวจ `last_modified` ของ source → mark หน้าเป็น draft ใหม่ให้ review |
| scan ตาราง embedding ใน PHP ช้าเมื่อข้อมูลโต | wiki_questions มีจำนวนน้อย (สิบ-ร้อยแถว) ไม่กระทบ; ปัญหา scale ของ knowledge_chunks เป็นของเดิม อยู่นอก scope แผนนี้ |

---

## 9. สถานะการ implement (2026-07-17)

**ใช้งานจริงบน production แล้ว** — รัน migration 009, build wiki จาก source 8, publish หน้า `library-hours` และยืนยันว่าบอทตัวจริง (lib bot) ตอบคำถามที่เคยตอบไม่ได้ได้แล้ว

### ผลการทดสอบจริง (lib bot, 2026-07-17)

| WP | สถานะ | หลักฐาน |
|---|---|---|
| WP-0 | ✅ ยืนยันแล้ว | migration รันผ่าน, query `wiki_pages`/`wiki_questions` ทำงาน |
| WP-1 | ✅ ยืนยันแล้ว | `WikiPromptsTest` ผ่าน + prompt จริงให้ผลถูกต้องกับ Gemini Flash Lite |
| WP-2 | ✅ ยืนยันแล้ว | `--build-wiki --source=8` → 5 หน้า, 49 คำถาม, 0 failed (~30 วินาที) |
| WP-3 | ✅ ยืนยันแล้ว | publish → materialize เป็น `knowledge_sources` (type=wiki) **1 chunk** ตามเป้า D4 |
| WP-4 | ✅ ยืนยันแล้ว | คำถามที่มีหน้า wiki = 1,037 tokens (tier 1, หน้าเดียว) / ไม่มีหน้า wiki = 2,843 tokens (tier 2, chunk ดิบ 5 ก้อน) — **ประหยัด token ~63%** และ tier 2 ยังทำงานเหมือนเดิม |
| WP-5 | ⚠️ ยังพิสูจน์ไม่ได้ | ดูหัวข้อ "ข้อจำกัดที่เจอ" ด้านล่าง |
| WP-6 | ✅ ยืนยันแล้ว | review + แก้เนื้อหา + publish ผ่านหน้า admin ได้ครบ flow |
| WP-7 | ✅ ยืนยันแล้ว | วิเคราะห์ 90 วัน → 7 หัวข้อ เรียงตามความถี่ (อันดับ 1: เวลาเปิดทำการ 8 ครั้ง) |
| WP-8 | ✅ ผ่าน | 116 tests / 1 failure ที่**ไม่เกี่ยวกับงานนี้** (`BugRegressionTest::test_bug04` — footer.php เลิกใช้ accordion แล้วแต่เทสยังไม่แก้ตาม), 30 skipped (Feature tests ต้องมี DB `cbms_test`) |

**คุณภาพเนื้อหาที่ LLM ร่าง:** ตรวจหน้า `library-hours` เทียบ Google Doc ต้นทางทีละบรรทัด — ข้อมูลทุกตัว (เวลาเปิด-ปิด, รถรับ-ส่ง 23.30 น., ห้อง 24 ชม.) มีในเอกสารจริง **ไม่มี hallucination** แต่เจอการ**ตัดข้อมูลจนอาจทำให้เข้าใจผิด** 1 จุด: ตัดชื่ออาคารของห้องวิทย์สุขภาพทิ้งเหลือแค่ "ชั้น 4" (ตึก CE มี 3 ชั้น) → แก้มือก่อน publish = flow review (D5) ทำงานตามที่ออกแบบไว้

### ข้อจำกัดที่เจอจากการทดสอบจริง

**WP-5 พิสูจน์ไม่ได้จากภายนอก และบางส่วนน่าจะเป็น dead code**

`similarity_threshold` ของ lib bot = 0.15 ซึ่งต่ำมาก ผลคือ retrieval **หา chunk เจอเกือบทุกคำถาม** แม้แต่ "ขอสูตรต้มยำกุ้ง" ก็ยังได้ chunk มา 5 ก้อน (2,901 tokens) และ `is_fallback = false` แปลว่า:

1. บล็อก `NO_CONTEXT` แทบไม่เคยทำงาน → บรรทัด "ถ้าคำถามใกล้เคียงหัวข้อในรายการ ให้ชวนผู้ใช้ถามหัวข้อนั้น" ที่ WP-5 เพิ่มเข้าไป **แทบไม่มีโอกาสถูกใช้**
2. ตัวสารบัญเองยังถูกแทรกทุกครั้ง (โค้ดวางไว้ก่อน if/else) แต่ไม่มีคำสั่งบอกโมเดลว่าให้ใช้มันตอนที่ยังหา chunk เจอ
3. `is_fallback` แทบไม่เป็น 1 → **WP-7 จะเก็บ gap ได้น้อยลงเรื่อยๆ** เพราะบอทตอบ "ไม่มีข้อมูล" โดยไม่ถูกนับเป็น fallback

ยังไม่ได้แก้ — ต้องตัดสินใจก่อนว่าจะทำ tier แยกจริงจัง (เช่น ถ้าคะแนนต่ำกว่า X ให้ถือเป็น "ไม่มีข้อมูล") ซึ่งกระทบพฤติกรรมเดิมของบอท

### ที่ยังไม่ได้ทำ

- 4 หน้าที่เหลือของ source 8 (`borrowing-rules`, `online-services`, `study-rooms-and-equipment`, `general-information`) ยังเป็น draft ยังไม่ได้ review/publish
- source 9 (E-Book) ยังไม่ได้ `--build-wiki`
- ยังไม่ได้ตั้ง cron ของ `--build-wiki`
- เบอร์ติดต่อใน system prompt ของ lib bot (0 5446 6666 ต่อ 3530) ไม่ตรงกับเอกสารต้นทาง (0 5446 6705) — คนละเรื่องกับแผนนี้ แต่ควรเช็ค

### จุดที่ต่างจากแผน (ตัดสินใจระหว่าง implement)

| หัวข้อ | แผนเดิม | ที่ทำจริง + เหตุผล |
|---|---|---|
| **D1** natural key ของ source | `google_drive_file_id = 'wiki:<slug>'` | `'wiki:<bot_id>:<slug>'` — คอลัมน์นี้ UNIQUE ทั้งตาราง (ไม่ใช่ต่อบอท) ส่วน slug ของ wiki unique ต่อบอท ถ้าไม่มี bot_id ในคีย์ สองบอทที่มี slug เดียวกันจะชนกัน |
| **WP-2** signature | `buildDrafts(?int $botId, ?int $sourceId)` | `__construct(?int $botId)` + `buildDrafts(?int $sourceId)` ตาม pattern `AIService`; `$botId = null` แปลว่า **ไม่จำกัดบอท** (สำหรับ CLI) และหน้าที่สร้างได้ `bot_id` จาก source ที่เป็นต้นทาง ทำให้ cron ตัวเดียวครอบทุกบอทได้ |
| **WP-2** คำถามตัวอย่าง | `generateQuestions()` เป็น method แยก เรียกทีหลัง | `buildDrafts()` เรียกให้อัตโนมัติเฉพาะหน้าที่เนื้อหาเปลี่ยน (หน้าที่เนื้อหาเดิมไม่ต้องเสียค่า LLM ซ้ำ) — admin กด "Generate ใหม่" เองได้ |
| **WP-1** จำนวน prompt | 3 prompts | 4 — เพิ่ม `gapAnalysisPrompt()` ที่ WP-7 ต้องใช้ (สั่งห้าม LLM แต่งเนื้อหา จัดกลุ่มคำถามอย่างเดียว) |
| **WP-2/3** วิธีเช็ค pending | ไม่ระบุ | `NOT EXISTS (wiki_pages ที่ JSON_CONTAINS(source_ids, ks.id) AND updated_at >= ks.last_modified)` — ไม่ต้องเพิ่มคอลัมน์ใหม่ใน `knowledge_sources` |
| **WP-7** ปุ่ม "สร้างร่างหน้า" | optional | ทำแล้ว (`WikiService::createEmptyDraft()` — สร้างหน้าเปล่า ให้คนเขียนเนื้อหาเอง) |
| CLI | `--build-wiki`, `--publish-wiki`, `--wiki-gaps` | เพิ่ม `--wiki-days=N` (ช่วงเวลาของ gap analysis) และ `--bot=ID` (จำกัดบอท, default = ทุกบอท) |
| settings | `wiki_question_threshold` default 0.45 | migration 009 seed ค่าลงทั้ง `system_settings` และ `bot_settings` ของทุกบอท; `loadSettings()` เมินค่าที่ไม่ใช่ตัวเลข (ค่าว่างจะ cast เป็น 0.0 = tier 1 แมตช์ทุกคำถาม) |

### สิ่งที่ยังไม่ได้ทำ (นอก scope รอบนี้)

- `analyzeGaps()` นับ `frequency` จากที่ LLM จัดกลุ่มให้ ไม่ได้นับจาก DB — ถ้าอยากได้เลขที่เชื่อถือได้ต้องนับเองหลัง LLM จัดกลุ่ม
- เอกสารที่ LLM คืน `pages: []` (ไม่มีเนื้อหาเป็นประโยชน์) จะถูกลองใหม่ทุกครั้งที่รัน `--build-wiki` เพราะไม่มีที่บันทึกว่า "เคยลองแล้ว" — ถ้าเจอบ่อยค่อยเพิ่มคอลัมน์ `wiki_built_at` ใน `knowledge_sources`
- ยังไม่ได้ตั้ง cron ของ `--build-wiki` (แผนเสนอรายสัปดาห์เหมือน `--sync-drive`)
