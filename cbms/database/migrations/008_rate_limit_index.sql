-- ============================================================
-- AI Chatbot System — Migration: Rate-limit composite index
-- The per-message rate-limit checks filter messages by
-- conversation_id + created_at window; the composite index lets
-- MySQL resolve them without scanning a conversation's history.
-- Run: mysql -u USER -p DATABASE < 008_rate_limit_index.sql
-- ============================================================

SET NAMES utf8mb4;

ALTER TABLE `messages`
  ADD INDEX `idx_conv_created` (`conversation_id`, `created_at`);
