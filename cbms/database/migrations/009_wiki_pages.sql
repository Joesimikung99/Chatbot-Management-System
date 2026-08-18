-- ============================================================
-- AI Chatbot System — Migration: LLM Wiki Knowledge Layer
-- เพิ่มชั้นความรู้แบบ "หน้า wiki" ที่ LLM เรียบเรียงจากเอกสารต้นทาง
-- พร้อมคำถามตัวอย่างต่อหน้า (doc2query) สำหรับ retrieval tier 1
--
--   knowledge_sources.source_type — แยกหน้า wiki ที่ materialize แล้ว
--                                   ออกจากไฟล์ดิบของ Google Drive
--   wiki_pages                    — สถานะ authoring (draft/published/archived)
--   wiki_questions                — คำถามตัวอย่าง + embedding ชี้กลับมาที่หน้า
--
-- Idempotent: รันซ้ำได้ (ตรวจ information_schema ก่อน ALTER)
-- Run: mysql -u USER -p DATABASE < 009_wiki_pages.sql
-- ============================================================

SET NAMES utf8mb4;

-- ============================================================
-- 1. knowledge_sources.source_type
--    หน้า wiki ที่ publish แล้วจะถูก materialize เป็นแถวใน
--    knowledge_sources (google_drive_file_id = 'wiki:<slug>') เพื่อให้
--    retrieval pipeline เดิมใช้ได้ทันที — คอลัมน์นี้ไว้แยกสองชนิดออกจากกัน
--    แถวเดิมทุกแถวได้ค่า default 'drive'
-- ============================================================
SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = 'knowledge_sources'
    AND COLUMN_NAME  = 'source_type'
);
SET @sql = IF(@col_exists = 0,
  "ALTER TABLE `knowledge_sources`
     ADD COLUMN `source_type` ENUM('drive','wiki') NOT NULL DEFAULT 'drive'
     COMMENT 'drive = ไฟล์ดิบจาก Google Drive, wiki = หน้า wiki ที่ publish แล้ว'
     AFTER `bot_id`",
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_exists = (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = 'knowledge_sources'
    AND INDEX_NAME   = 'idx_source_type'
);
SET @sql = IF(@idx_exists = 0,
  'ALTER TABLE `knowledge_sources` ADD INDEX `idx_source_type` (`source_type`)',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ============================================================
-- 2. wiki_pages — สถานะ authoring ของหน้า wiki
--    เนื้อหาร่าง / รอ review อยู่ที่นี่เท่านั้น; เฉพาะเวอร์ชันที่ publish
--    แล้วเท่านั้นที่ไปโผล่ใน knowledge_sources + knowledge_chunks
-- ============================================================
CREATE TABLE IF NOT EXISTS `wiki_pages` (
  `id`                  INT AUTO_INCREMENT PRIMARY KEY,
  `bot_id`              INT NULL                COMMENT 'bots.id (NULL = legacy single-bot mode)',
  `slug`                VARCHAR(191) NOT NULL   COMMENT 'kebab-case ASCII เช่น library-hours',
  `title`               VARCHAR(500) NOT NULL   COMMENT 'ชื่อหัวข้อภาษาไทย',
  `content`             MEDIUMTEXT NOT NULL     COMMENT 'เนื้อหา markdown ภาษาไทย (เป้า ≤ 2,000 ตัวอักษร)',
  `summary`             VARCHAR(1000) NULL      COMMENT '1-2 ประโยค ใช้ทำสารบัญใน system prompt',
  `status`              ENUM('draft','published','archived') NOT NULL DEFAULT 'draft',
  `source_ids`          JSON NULL               COMMENT 'knowledge_sources.id ที่เป็นที่มาของเนื้อหา (provenance)',
  `linked_slugs`        JSON NULL               COMMENT 'slug ของหน้าที่เกี่ยวข้อง',
  `content_hash`        CHAR(32) NULL           COMMENT 'md5(content) ของเวอร์ชันที่ publish ล่าสุด',
  `published_source_id` INT NULL                COMMENT 'knowledge_sources.id แถวที่ materialize แล้ว',
  `generated_by`        VARCHAR(191) NULL       COMMENT 'openrouter model id ที่สร้างร่าง',
  `reviewed_by`         INT NULL                COMMENT 'admin_users.id ที่กด publish',
  `created_at`          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_bot_slug` (`bot_id`, `slug`),
  KEY `idx_status` (`status`),
  KEY `idx_bot_status` (`bot_id`, `status`),
  CONSTRAINT `fk_wiki_pages_bot`
    FOREIGN KEY (`bot_id`) REFERENCES `bots`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_wiki_pages_source`
    FOREIGN KEY (`published_source_id`) REFERENCES `knowledge_sources`(`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_wiki_pages_reviewer`
    FOREIGN KEY (`reviewed_by`) REFERENCES `admin_users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='หน้า wiki ที่ LLM เรียบเรียงจากเอกสาร (authoring state)';

-- ============================================================
-- 3. wiki_questions — คำถามตัวอย่างต่อหน้า (doc2query)
--    เก็บแยกจาก knowledge_chunks โดยตั้งใจ: content ของ chunk จะถูกอัดเข้า
--    prompt — คำถามไม่ใช่เนื้อหาที่ใช้ตอบ ใช้เป็น "ป้ายชี้ทาง" ตอนค้นเท่านั้น
-- ============================================================
CREATE TABLE IF NOT EXISTS `wiki_questions` (
  `id`         INT AUTO_INCREMENT PRIMARY KEY,
  `page_id`    INT NOT NULL,
  `source_id`  INT NULL                COMMENT 'knowledge_sources.id ของหน้า (เติมตอน publish)',
  `question`   VARCHAR(1000) NOT NULL,
  `embedding`  JSON NULL               COMMENT 'Vector embedding (รูปแบบเดียวกับ knowledge_chunks.embedding)',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_page` (`page_id`),
  KEY `idx_source` (`source_id`),
  CONSTRAINT `fk_wiki_questions_page`
    FOREIGN KEY (`page_id`) REFERENCES `wiki_pages`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_wiki_questions_source`
    FOREIGN KEY (`source_id`) REFERENCES `knowledge_sources`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='คำถามตัวอย่างต่อหน้า wiki สำหรับ retrieval tier 1';

-- ============================================================
-- 4. Settings — threshold ของ retrieval tier 1
--    คำถาม-vs-คำถาม (ภาษาเดียวกัน) ได้คะแนน cosine สูงกว่า
--    คำถาม-vs-เนื้อหา มาก จึงตั้ง threshold สูงกว่า similarity_threshold ได้
-- ============================================================
INSERT IGNORE INTO `system_settings` (`setting_key`, `setting_value`, `type`, `description`)
VALUES ('wiki_question_threshold', '0.45', 'string',
        'คะแนน cosine ขั้นต่ำที่ถือว่าคำถามตรงกับคำถามตัวอย่างของหน้า wiki');

INSERT IGNORE INTO `bot_settings` (`bot_id`, `setting_key`, `setting_value`)
SELECT `id`, 'wiki_question_threshold', '0.45' FROM `bots`;
