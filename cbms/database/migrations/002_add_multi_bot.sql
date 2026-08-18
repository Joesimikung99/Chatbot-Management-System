-- ============================================================
-- AI Chatbot System — Migration: Multi-Bot Support
-- Database: MySQL 8.0
-- Encoding: utf8mb4_unicode_ci
-- Run: mysql -u USER -p DATABASE < 002_add_multi_bot.sql
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ============================================================
-- 1. bots — Bot entity
-- ============================================================
CREATE TABLE IF NOT EXISTS `bots` (
  `id`          INT AUTO_INCREMENT PRIMARY KEY,
  `slug`        VARCHAR(100) NOT NULL           COMMENT 'URL-safe identifier for widget/API',
  `name`        VARCHAR(255) NOT NULL,
  `description` TEXT DEFAULT NULL,
  `avatar_url`  VARCHAR(500) DEFAULT NULL,
  `is_active`   TINYINT(1) NOT NULL DEFAULT 1,
  `created_by`  INT DEFAULT NULL,
  `created_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_slug` (`slug`),
  INDEX `idx_active` (`is_active`),
  FOREIGN KEY (`created_by`) REFERENCES `admin_users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Individual bot instances with separate knowledge and settings';

-- ============================================================
-- 2. bot_users — Access control per bot
-- ============================================================
CREATE TABLE IF NOT EXISTS `bot_users` (
  `id`         INT AUTO_INCREMENT PRIMARY KEY,
  `bot_id`     INT NOT NULL,
  `user_id`    INT NOT NULL,
  `role`       ENUM('owner','editor','viewer') NOT NULL DEFAULT 'editor',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_bot_user` (`bot_id`, `user_id`),
  INDEX `idx_user` (`user_id`),
  FOREIGN KEY (`bot_id`)  REFERENCES `bots`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`user_id`) REFERENCES `admin_users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Maps admin users to bots they can access';

-- ============================================================
-- 3. bot_settings — Per-bot configuration
-- ============================================================
CREATE TABLE IF NOT EXISTS `bot_settings` (
  `id`            INT AUTO_INCREMENT PRIMARY KEY,
  `bot_id`        INT NOT NULL,
  `setting_key`   VARCHAR(100) NOT NULL,
  `setting_value` TEXT DEFAULT NULL,
  `updated_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_bot_setting` (`bot_id`, `setting_key`),
  FOREIGN KEY (`bot_id`) REFERENCES `bots`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Per-bot settings (system_prompt, temperature, platform tokens, etc.)';

-- ============================================================
-- 4. Add bot_id to existing tables
-- ============================================================
ALTER TABLE `conversations`
  ADD COLUMN `bot_id` INT DEFAULT NULL AFTER `id`,
  ADD INDEX `idx_bot` (`bot_id`);

ALTER TABLE `knowledge_sources`
  ADD COLUMN `bot_id` INT DEFAULT NULL AFTER `id`,
  ADD INDEX `idx_bot` (`bot_id`);

ALTER TABLE `token_usage_daily`
  ADD COLUMN `bot_id` INT DEFAULT NULL AFTER `id`,
  ADD INDEX `idx_bot` (`bot_id`);

ALTER TABLE `api_usage_logs`
  ADD COLUMN `bot_id` INT DEFAULT NULL AFTER `id`,
  ADD INDEX `idx_bot` (`bot_id`);

ALTER TABLE `admin_activity_logs`
  ADD COLUMN `bot_id` INT DEFAULT NULL AFTER `id`,
  ADD INDEX `idx_bot` (`bot_id`);

-- ============================================================
-- 5. Create default bot & backfill existing data
-- ============================================================
INSERT INTO `bots` (`id`, `slug`, `name`, `description`, `is_active`, `created_by`)
VALUES (1, 'default', 'Default Bot', 'Migrated from single-bot system', 1,
  (SELECT `id` FROM `admin_users` WHERE `role` = 'super_admin' ORDER BY `id` LIMIT 1));

UPDATE `conversations`      SET `bot_id` = 1 WHERE `bot_id` IS NULL;
UPDATE `knowledge_sources`  SET `bot_id` = 1 WHERE `bot_id` IS NULL;
UPDATE `token_usage_daily`  SET `bot_id` = 1 WHERE `bot_id` IS NULL;
UPDATE `api_usage_logs`     SET `bot_id` = 1 WHERE `bot_id` IS NULL;
UPDATE `admin_activity_logs`SET `bot_id` = 1 WHERE `bot_id` IS NULL;

-- ============================================================
-- 6. Copy per-bot settings from system_settings → bot_settings
-- ============================================================
INSERT INTO `bot_settings` (`bot_id`, `setting_key`, `setting_value`)
SELECT 1, `setting_key`, `setting_value`
FROM `system_settings`
WHERE `setting_key` IN (
  'system_prompt', 'temperature', 'max_tokens',
  'similarity_threshold', 'top_k_chunks', 'history_length',
  'fallback_message', 'bot_name', 'welcome_message',
  'primary_color', 'widget_position', 'allow_feedback',
  'facebook_page_token', 'facebook_app_secret', 'facebook_verify_token',
  'line_channel_token', 'line_channel_secret', 'line_channel_id'
);

-- Add google_drive_folder_id as per-bot setting (previously only in .env)
INSERT INTO `bot_settings` (`bot_id`, `setting_key`, `setting_value`)
VALUES (1, 'google_drive_folder_id', NULL);

-- ============================================================
-- 7. Grant all existing admins access to default bot
-- ============================================================
INSERT INTO `bot_users` (`bot_id`, `user_id`, `role`)
SELECT 1, `id`,
  CASE
    WHEN `role` = 'super_admin' THEN 'owner'
    WHEN `role` = 'admin'       THEN 'editor'
    ELSE 'viewer'
  END
FROM `admin_users`
WHERE `is_active` = 1;

-- ============================================================
-- 8. Add foreign keys (after backfill)
-- ============================================================
ALTER TABLE `conversations`
  ADD CONSTRAINT `fk_conversations_bot` FOREIGN KEY (`bot_id`) REFERENCES `bots`(`id`) ON DELETE SET NULL;

ALTER TABLE `knowledge_sources`
  ADD CONSTRAINT `fk_knowledge_sources_bot` FOREIGN KEY (`bot_id`) REFERENCES `bots`(`id`) ON DELETE CASCADE;

ALTER TABLE `token_usage_daily`
  ADD CONSTRAINT `fk_token_usage_daily_bot` FOREIGN KEY (`bot_id`) REFERENCES `bots`(`id`) ON DELETE SET NULL;

ALTER TABLE `api_usage_logs`
  ADD CONSTRAINT `fk_api_usage_logs_bot` FOREIGN KEY (`bot_id`) REFERENCES `bots`(`id`) ON DELETE SET NULL;

ALTER TABLE `admin_activity_logs`
  ADD CONSTRAINT `fk_admin_activity_logs_bot` FOREIGN KEY (`bot_id`) REFERENCES `bots`(`id`) ON DELETE SET NULL;

-- ============================================================
-- 9. Add manage_bots permission to existing super_admin & admin users
-- ============================================================
UPDATE `admin_users`
SET `permissions` = JSON_SET(COALESCE(`permissions`, '{}'), '$.manage_bots', TRUE)
WHERE `role` IN ('super_admin', 'admin') AND `is_active` = 1;

SET FOREIGN_KEY_CHECKS = 1;
