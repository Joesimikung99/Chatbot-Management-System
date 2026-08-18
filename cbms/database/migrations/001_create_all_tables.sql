-- ============================================================
-- AI Chatbot System — Database Migration V2
-- Database: MySQL 8.0
-- Encoding: utf8mb4_unicode_ci
-- Run: mysql -u USER -p DATABASE < 001_create_all_tables.sql
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ============================================================
-- 1. platforms
-- ============================================================
CREATE TABLE IF NOT EXISTS `platforms` (
  `id`         INT AUTO_INCREMENT PRIMARY KEY,
  `name`       ENUM('web','facebook','line') NOT NULL,
  `is_active`  TINYINT(1) NOT NULL DEFAULT 1,
  `config`     JSON,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_platform_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Platform configurations (web, facebook, line)';

-- ============================================================
-- 2. ai_models
-- ============================================================
CREATE TABLE IF NOT EXISTS `ai_models` (
  `id`                  INT AUTO_INCREMENT PRIMARY KEY,
  `openrouter_model_id` VARCHAR(255) NOT NULL,
  `display_name`        VARCHAR(255) NOT NULL,
  `provider_name`       VARCHAR(100),
  `description`         TEXT,
  `context_length`      INT DEFAULT NULL,
  `pricing_prompt`      DECIMAL(12,8) NOT NULL DEFAULT 0.00000000 COMMENT 'USD per 1 token (input)',
  `pricing_completion`  DECIMAL(12,8) NOT NULL DEFAULT 0.00000000 COMMENT 'USD per 1 token (output)',
  `is_active`           TINYINT(1) NOT NULL DEFAULT 1,
  `is_default`          TINYINT(1) NOT NULL DEFAULT 0,
  `sort_order`          INT NOT NULL DEFAULT 0,
  `supports_vision`     TINYINT(1) NOT NULL DEFAULT 0,
  `supports_tools`      TINYINT(1) NOT NULL DEFAULT 0,
  `max_tokens`          INT NOT NULL DEFAULT 4096,
  `last_fetched_at`     DATETIME DEFAULT NULL,
  `custom_notes`        TEXT,
  `created_at`          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_openrouter_model_id` (`openrouter_model_id`),
  INDEX `idx_sort` (`sort_order`, `is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='AI Models synced from OpenRouter';

-- ============================================================
-- 3. admin_users (รองรับ Local + Microsoft O365 + CAMS)
-- ============================================================
CREATE TABLE IF NOT EXISTS `admin_users` (
  `id`                   INT AUTO_INCREMENT PRIMARY KEY,
  `username`             VARCHAR(100) DEFAULT NULL,
  `email`                VARCHAR(255) NOT NULL,
  `password`             VARCHAR(255) DEFAULT NULL        COMMENT 'Bcrypt hash — NULL for Microsoft users',
  `display_name`         VARCHAR(255) DEFAULT NULL,
  `avatar_url`           VARCHAR(500) DEFAULT NULL,
  `role`                 ENUM('super_admin','admin','viewer') NOT NULL DEFAULT 'viewer',
  `auth_provider`        ENUM('local','microsoft') NOT NULL DEFAULT 'local',
  `microsoft_id`         VARCHAR(255) DEFAULT NULL,
  `microsoft_tenant_id`  VARCHAR(255) DEFAULT NULL,
  `microsoft_token`      JSON DEFAULT NULL               COMMENT 'Encrypted token data',
  -- CAMS fields
  `cams_user_id`         INT DEFAULT NULL,
  `cams_dept_code`       VARCHAR(50) DEFAULT NULL,
  `cams_dept_name`       VARCHAR(255) DEFAULT NULL,
  `cams_level_code`      VARCHAR(50) DEFAULT NULL,
  `cams_level_value`     INT DEFAULT NULL,
  --
  `last_login_at`        DATETIME DEFAULT NULL,
  `last_login_ip`        VARCHAR(45) DEFAULT NULL,
  `is_active`            TINYINT(1) NOT NULL DEFAULT 1,
  `permissions`          JSON DEFAULT NULL               COMMENT 'Granular permission flags',
  `created_at`           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_email` (`email`),
  UNIQUE KEY `uq_username` (`username`),
  INDEX `idx_microsoft_id` (`microsoft_id`),
  INDEX `idx_role_active` (`role`, `is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Admin users — supports local, Microsoft O365, and CAMS';

-- ============================================================
-- 4. conversations
-- ============================================================
CREATE TABLE IF NOT EXISTS `conversations` (
  `id`                INT AUTO_INCREMENT PRIMARY KEY,
  `session_id`        VARCHAR(100) NOT NULL,
  `platform`          ENUM('web','facebook','line') NOT NULL,
  `platform_user_id`  VARCHAR(255) DEFAULT NULL,
  `platform_username` VARCHAR(255) DEFAULT NULL,
  `ai_model_id`       INT DEFAULT NULL,
  `metadata`          JSON DEFAULT NULL,
  `started_at`        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_activity_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `message_count`     INT NOT NULL DEFAULT 0,
  `total_tokens`      INT NOT NULL DEFAULT 0,
  `total_cost_usd`    DECIMAL(10,6) NOT NULL DEFAULT 0.000000,
  `is_resolved`       TINYINT(1) NOT NULL DEFAULT 0,
  UNIQUE KEY `uq_session_id` (`session_id`),
  INDEX `idx_platform` (`platform`),
  INDEX `idx_activity` (`last_activity_at`),
  INDEX `idx_platform_date` (`platform`, `started_at`),
  FOREIGN KEY (`ai_model_id`) REFERENCES `ai_models`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Chat sessions per platform';

-- ============================================================
-- 5. messages
-- ============================================================
CREATE TABLE IF NOT EXISTS `messages` (
  `id`                   INT AUTO_INCREMENT PRIMARY KEY,
  `conversation_id`      INT NOT NULL,
  `role`                 ENUM('user','assistant','system') NOT NULL,
  `content`              TEXT NOT NULL,
  `tokens_prompt`        INT NOT NULL DEFAULT 0,
  `tokens_completion`    INT NOT NULL DEFAULT 0,
  `tokens_total`         INT NOT NULL DEFAULT 0,
  `response_time_ms`     INT NOT NULL DEFAULT 0,
  `ai_model_id`          INT DEFAULT NULL,
  `knowledge_chunks_used`JSON DEFAULT NULL               COMMENT 'IDs of chunks used for RAG',
  `is_fallback`          TINYINT(1) NOT NULL DEFAULT 0,
  `feedback`             ENUM('positive','negative') DEFAULT NULL,
  `created_at`           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_conversation` (`conversation_id`),
  INDEX `idx_created` (`created_at`),
  FOREIGN KEY (`conversation_id`) REFERENCES `conversations`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`ai_model_id`)     REFERENCES `ai_models`(`id`)     ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Individual chat messages with token tracking';

-- ============================================================
-- 6. knowledge_sources (Google Drive files)
-- ============================================================
CREATE TABLE IF NOT EXISTS `knowledge_sources` (
  `id`                   INT AUTO_INCREMENT PRIMARY KEY,
  `google_drive_file_id` VARCHAR(255) NOT NULL,
  `file_name`            VARCHAR(500) NOT NULL,
  `file_type`            VARCHAR(50) DEFAULT NULL,
  `mime_type`            VARCHAR(100) DEFAULT NULL,
  `google_drive_url`     VARCHAR(500) DEFAULT NULL,
  `last_modified`        DATETIME DEFAULT NULL,
  `last_synced_at`       DATETIME DEFAULT NULL,
  `sync_status`          ENUM('pending','processing','synced','error') NOT NULL DEFAULT 'pending',
  `chunk_count`          INT NOT NULL DEFAULT 0,
  `error_message`        TEXT DEFAULT NULL,
  `is_active`            TINYINT(1) NOT NULL DEFAULT 1,
  `created_at`           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_drive_file_id` (`google_drive_file_id`),
  INDEX `idx_sync_status` (`sync_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Knowledge base files from Google Drive';

-- ============================================================
-- 7. knowledge_chunks (ข้อความตัดย่อย + embedding)
-- ============================================================
CREATE TABLE IF NOT EXISTS `knowledge_chunks` (
  `id`          INT AUTO_INCREMENT PRIMARY KEY,
  `source_id`   INT NOT NULL,
  `chunk_index` INT NOT NULL,
  `content`     TEXT NOT NULL,
  `embedding`   JSON DEFAULT NULL               COMMENT 'Vector embedding array',
  `token_count` INT NOT NULL DEFAULT 0,
  `metadata`    JSON DEFAULT NULL,
  `created_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_source` (`source_id`),
  INDEX `idx_source_chunk` (`source_id`, `chunk_index`),
  FOREIGN KEY (`source_id`) REFERENCES `knowledge_sources`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Text chunks with vector embeddings for RAG';

-- ============================================================
-- 8. token_usage_daily (pre-aggregated สำหรับ performance)
-- ============================================================
CREATE TABLE IF NOT EXISTS `token_usage_daily` (
  `id`                  INT AUTO_INCREMENT PRIMARY KEY,
  `usage_date`          DATE NOT NULL,
  `platform`            ENUM('web','facebook','line','all') NOT NULL,
  `ai_model_id`         INT DEFAULT NULL,
  `total_conversations` INT NOT NULL DEFAULT 0,
  `total_messages`      INT NOT NULL DEFAULT 0,
  `tokens_prompt`       BIGINT NOT NULL DEFAULT 0,
  `tokens_completion`   BIGINT NOT NULL DEFAULT 0,
  `tokens_total`        BIGINT NOT NULL DEFAULT 0,
  `cost_usd`            DECIMAL(12,6) NOT NULL DEFAULT 0.000000,
  `avg_response_ms`     INT NOT NULL DEFAULT 0,
  `fallback_count`      INT NOT NULL DEFAULT 0,
  `created_at`          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_date_platform_model` (`usage_date`, `platform`, `ai_model_id`),
  INDEX `idx_date` (`usage_date`),
  INDEX `idx_platform_date` (`platform`, `usage_date`),
  FOREIGN KEY (`ai_model_id`) REFERENCES `ai_models`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Daily pre-aggregated token usage by platform and model';

-- ============================================================
-- 9. api_usage_logs (per-request log)
-- ============================================================
CREATE TABLE IF NOT EXISTS `api_usage_logs` (
  `id`                INT AUTO_INCREMENT PRIMARY KEY,
  `conversation_id`   INT DEFAULT NULL,
  `message_id`        INT DEFAULT NULL,
  `platform`          ENUM('web','facebook','line') NOT NULL,
  `ai_model_id`       INT DEFAULT NULL,
  `api_provider`      ENUM('openrouter','openai','gemini','google_drive') NOT NULL,
  `tokens_prompt`     INT NOT NULL DEFAULT 0,
  `tokens_completion` INT NOT NULL DEFAULT 0,
  `tokens_total`      INT NOT NULL DEFAULT 0,
  `cost_usd`          DECIMAL(10,6) NOT NULL DEFAULT 0.000000,
  `response_time_ms`  INT NOT NULL DEFAULT 0,
  `status`            ENUM('success','error') NOT NULL DEFAULT 'success',
  `error_message`     TEXT DEFAULT NULL,
  `created_at`        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_platform_date` (`platform`, `created_at`),
  INDEX `idx_model_date` (`ai_model_id`, `created_at`),
  INDEX `idx_created` (`created_at`),
  FOREIGN KEY (`conversation_id`) REFERENCES `conversations`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`ai_model_id`)     REFERENCES `ai_models`(`id`)     ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Per-request API usage and cost logs';

-- ============================================================
-- 10. system_settings (key-value store)
-- ============================================================
CREATE TABLE IF NOT EXISTS `system_settings` (
  `id`            INT AUTO_INCREMENT PRIMARY KEY,
  `setting_key`   VARCHAR(100) NOT NULL,
  `setting_value` TEXT DEFAULT NULL,
  `type`          ENUM('string','json','boolean','integer','password') NOT NULL DEFAULT 'string',
  `description`   VARCHAR(500) DEFAULT NULL,
  `updated_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `updated_by`    INT DEFAULT NULL,
  UNIQUE KEY `uq_setting_key` (`setting_key`),
  FOREIGN KEY (`updated_by`) REFERENCES `admin_users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='System-wide key-value settings store';

-- ============================================================
-- 11. admin_activity_logs (audit trail)
-- ============================================================
CREATE TABLE IF NOT EXISTS `admin_activity_logs` (
  `id`          INT AUTO_INCREMENT PRIMARY KEY,
  `user_id`     INT DEFAULT NULL,
  `action`      VARCHAR(100) NOT NULL,
  `target_type` VARCHAR(50) DEFAULT NULL,
  `target_id`   INT DEFAULT NULL,
  `old_value`   JSON DEFAULT NULL,
  `new_value`   JSON DEFAULT NULL,
  `ip_address`  VARCHAR(45) DEFAULT NULL,
  `user_agent`  VARCHAR(500) DEFAULT NULL,
  `created_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_user` (`user_id`),
  INDEX `idx_created` (`created_at`),
  INDEX `idx_action` (`action`),
  FOREIGN KEY (`user_id`) REFERENCES `admin_users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Admin audit trail for all important actions';

SET FOREIGN_KEY_CHECKS = 1;
