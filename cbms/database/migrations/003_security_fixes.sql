-- ============================================================
-- AI Chatbot System — Migration: Security Fixes
-- Adds ip_address to conversations for IP-based rate limiting
-- Run: mysql -u USER -p DATABASE < 003_security_fixes.sql
-- ============================================================

SET NAMES utf8mb4;

-- Add ip_address column for IP-based rate limiting
ALTER TABLE `conversations`
  ADD COLUMN `ip_address` VARCHAR(45) DEFAULT NULL AFTER `platform_username`,
  ADD INDEX `idx_ip_address` (`ip_address`);
