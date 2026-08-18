-- ============================================================
-- AI Chatbot System — Migration: New Features
-- 1. Conversation tags (auto-categorization)
-- 2. Handoff requests (human contact requests from web widget)
-- Run: mysql -u USER -p DATABASE < 004_add_features.sql
-- ============================================================

SET NAMES utf8mb4;

-- 1. Add tags column to conversations for auto-categorization
ALTER TABLE `conversations`
  ADD COLUMN `tags` JSON DEFAULT NULL AFTER `metadata`;

-- 2. Create handoff_requests table for "ขอคุยกับเจ้าหน้าที่" feature
CREATE TABLE IF NOT EXISTS `handoff_requests` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `bot_id` INT NOT NULL,
  `conversation_id` INT DEFAULT NULL,
  `session_id` VARCHAR(100) DEFAULT NULL,
  `contact_type` ENUM('facebook','line') NOT NULL,
  `contact_id` VARCHAR(255) NOT NULL,
  `user_message` TEXT DEFAULT NULL,
  `status` ENUM('pending','contacted','resolved') DEFAULT 'pending',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `resolved_at` TIMESTAMP NULL DEFAULT NULL,
  `resolved_by` INT DEFAULT NULL,
  FOREIGN KEY (`bot_id`) REFERENCES `bots`(`id`) ON DELETE CASCADE,
  INDEX `idx_handoff_bot_status` (`bot_id`, `status`),
  INDEX `idx_handoff_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
