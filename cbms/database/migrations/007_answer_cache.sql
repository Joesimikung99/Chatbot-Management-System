-- ============================================================
-- AI Chatbot System — Migration: Semantic answer cache
-- Stores grounded (KB-based) answers keyed by question hash +
-- question embedding, so repeated questions — exact or
-- paraphrased — are served from MySQL without calling the LLM.
-- Run: mysql -u USER -p DATABASE < 007_answer_cache.sql
-- ============================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `answer_cache` (
  `id`                 BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `bot_id`             INT NULL                COMMENT 'bots.id (NULL = legacy single-bot mode)',
  `question_hash`      CHAR(32) NOT NULL       COMMENT 'md5 of the normalized question text',
  `question_text`      TEXT NOT NULL,
  `question_embedding` MEDIUMTEXT NULL         COMMENT 'JSON float array (same format as knowledge_chunks.embedding)',
  `reply`              MEDIUMTEXT NOT NULL,
  `model_id`           VARCHAR(191) NULL       COMMENT 'openrouter model that produced the reply',
  `chunk_ids`          VARCHAR(500) NULL       COMMENT 'JSON array of knowledge_chunks.id used',
  `hit_count`          INT UNSIGNED NOT NULL DEFAULT 0,
  `created_at`         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `expires_at`         DATETIME NOT NULL,
  UNIQUE KEY `uq_bot_question` (`bot_id`, `question_hash`),
  KEY `idx_bot_expires` (`bot_id`, `expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Semantic FAQ answer cache';
