-- ============================================================
-- AI Chatbot System — Migration: Per-bot unique keys
--
-- ปัญหา: uq_session_id (conversations) และ uq_drive_file_id
-- (knowledge_sources) เป็น UNIQUE ทั้งตาราง แต่โค้ดค้นแบบ per-bot
-- (WHERE session_id = ? AND bot_id = ?) แล้ว INSERT เมื่อไม่เจอ:
--
--   * ผู้ใช้ LINE คนเดียวกันทักบอท 2 ตัว → session 'line_Uxxxx' ชนกัน
--     → duplicate key → บอทตัวที่สองตอบ "ระบบมีปัญหา" ตลอด
--   * ไฟล์ Google Drive ไฟล์เดียวใช้ร่วม 2 บอทไม่ได้
--
-- แก้เป็น composite unique ต่อบอทแทน
--
-- Idempotent: รันซ้ำได้ (ตรวจ information_schema ก่อน DROP/ADD)
-- Requires: 002_add_multi_bot.sql (คอลัมน์ bot_id)
-- Run: mysql -u USER -p DATABASE < 010_multibot_unique_keys.sql
-- ============================================================

SET NAMES utf8mb4;

-- ── 1. conversations: (bot_id, session_id) ─────────────────────
-- เพิ่มคีย์ใหม่ก่อนแล้วค่อยถอนคีย์เก่า เพื่อไม่ให้มีจังหวะที่ session
-- ซ้ำแทรกเข้ามาได้ระหว่าง migration
SET @has_new = (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = 'conversations'
    AND INDEX_NAME   = 'uq_bot_session'
);
SET @sql = IF(@has_new = 0,
  'ALTER TABLE `conversations` ADD UNIQUE KEY `uq_bot_session` (`bot_id`, `session_id`)',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_old = (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = 'conversations'
    AND INDEX_NAME   = 'uq_session_id'
);
SET @sql = IF(@has_old > 0,
  'ALTER TABLE `conversations` DROP INDEX `uq_session_id`',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ── 2. knowledge_sources: (bot_id, google_drive_file_id) ───────
SET @has_new = (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = 'knowledge_sources'
    AND INDEX_NAME   = 'uq_bot_drive_file'
);
SET @sql = IF(@has_new = 0,
  'ALTER TABLE `knowledge_sources` ADD UNIQUE KEY `uq_bot_drive_file` (`bot_id`, `google_drive_file_id`)',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_old = (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = 'knowledge_sources'
    AND INDEX_NAME   = 'uq_drive_file_id'
);
SET @sql = IF(@has_old > 0,
  'ALTER TABLE `knowledge_sources` DROP INDEX `uq_drive_file_id`',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- หมายเหตุ: แถวที่ bot_id เป็น NULL (legacy ก่อน migration 002 backfill)
-- MySQL ไม่บังคับ unique เมื่อคอลัมน์ใดใน key เป็น NULL — production
-- backfill เป็น bot 1 หมดแล้ว จึงไม่มีผลจริง
