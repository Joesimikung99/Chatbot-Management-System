-- ============================================================
-- AI Chatbot System — Migration: Hybrid Search + Query Embedding Cache
--
-- 1) FULLTEXT ngram index บน knowledge_chunks.content
--    ใช้เป็น "keyword rescue" เมื่อ vector search หาไม่เจอ — embedding
--    มักพลาดคำเฉพาะ (EDUROAM, ALIST, Web-OPAC, ชื่อห้อง) ที่ FULLTEXT
--    จับได้ตรง ๆ (ngram parser ตัดคำแบบ 2-gram รองรับภาษาไทยที่ไม่มีเว้นวรรค)
--
-- 2) ตาราง query_embeddings — cache เวกเตอร์ของ "คำถามผู้ใช้" ข้าม request
--    เดิม memoize แค่ใน request เดียว คำถามยอดฮิตเสียค่า embedding API
--    (~300ms) ซ้ำทุกครั้งที่ answer cache ต้องเทียบ semantic
--
-- Idempotent: รันซ้ำได้
-- Run: mysql -u USER -p DATABASE < 011_hybrid_search_query_cache.sql
-- ============================================================

SET NAMES utf8mb4;

-- ── 1. FULLTEXT ngram index ────────────────────────────────────
SET @idx_exists = (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = 'knowledge_chunks'
    AND INDEX_NAME   = 'ft_content'
);
SET @sql = IF(@idx_exists = 0,
  'ALTER TABLE `knowledge_chunks` ADD FULLTEXT INDEX `ft_content` (`content`) WITH PARSER ngram',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ── 2. Query embedding cache ───────────────────────────────────
-- hash = md5(model + "\n" + query text) — ผูก model ไว้ในคีย์ เพื่อให้
-- ตอนเปลี่ยน embedding model แล้ว cache เก่าไม่ถูกหยิบมาใช้ผิดมิติ
CREATE TABLE IF NOT EXISTS `query_embeddings` (
  `hash`       CHAR(32) NOT NULL PRIMARY KEY,
  `model`      VARCHAR(191) NOT NULL,
  `embedding`  JSON NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Cache เวกเตอร์คำถามผู้ใช้ข้าม request (ล้างเป็นรอบตามอายุ)';
