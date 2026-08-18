-- ============================================================
-- AI Chatbot System — Migration: Local-login brute-force throttle
-- Adds a table to track failed admin local-login attempts so the
-- login page can lock out repeated guesses per IP / identifier.
-- Run: mysql -u USER -p DATABASE < 006_login_throttle.sql
-- ============================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `admin_login_attempts` (
  `id`           BIGINT AUTO_INCREMENT PRIMARY KEY,
  `identifier`   VARCHAR(255) NOT NULL          COMMENT 'Username or email that was tried',
  `ip_address`   VARCHAR(45)  NOT NULL,
  `attempted_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_ip_time`         (`ip_address`, `attempted_at`),
  INDEX `idx_identifier_time` (`identifier`, `attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Failed local-login attempts for brute-force throttling';
