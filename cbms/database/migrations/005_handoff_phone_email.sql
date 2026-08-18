-- ============================================================
-- AI Chatbot System — Migration: Handoff phone + Email Notifications
-- 1. Add 'phone' to handoff_requests contact_type ENUM
-- 2. System settings for SMTP email notification
-- 3. Bot settings for handoff notification email
-- Run: mysql -u USER -p DATABASE < 005_handoff_phone_email.sql
-- ============================================================

SET NAMES utf8mb4;

-- 1. Add 'phone' to contact_type ENUM
ALTER TABLE `handoff_requests`
  MODIFY COLUMN `contact_type` ENUM('facebook','line','phone') NOT NULL;
