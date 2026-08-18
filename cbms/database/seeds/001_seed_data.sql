-- ============================================================
-- AI Chatbot System — Seed Data V2
-- Run AFTER migration: mysql -u USER -p DATABASE < 001_seed_data.sql
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ============================================================
-- Platforms
-- ============================================================
INSERT IGNORE INTO `platforms` (`name`, `is_active`, `config`) VALUES
('web',      1, '{"widget_color":"#4F46E5","welcome_message":"สวัสดี มีอะไรให้ช่วยไหมครับ?","bot_name":"AI Assistant"}'),
('facebook', 1, '{"verify_token":"","page_access_token":""}'),
('line',     1, '{"channel_access_token":"","channel_secret":""}');

-- ============================================================
-- Default AI Models (จะ sync จาก OpenRouter จริงใน Step 5)
-- ============================================================
INSERT IGNORE INTO `ai_models`
  (`openrouter_model_id`, `display_name`, `provider_name`, `description`,
   `context_length`, `pricing_prompt`, `pricing_completion`,
   `is_active`, `is_default`, `sort_order`, `supports_vision`, `supports_tools`,
   `max_tokens`, `last_fetched_at`, `custom_notes`)
VALUES
  ('openai/gpt-4o-mini', 'GPT-4o Mini', 'OpenAI',
   'Fast and affordable — suitable for most tasks',
   128000, 0.00000015, 0.00000060,
   1, 1, 1, 0, 1,
   16384, NOW(), 'Default model — ราคาถูก ความเร็วสูง'),
  ('anthropic/claude-3-haiku', 'Claude 3 Haiku', 'Anthropic',
   'Fast and compact — good for real-time applications',
   200000, 0.00000025, 0.00000125,
   1, 0, 2, 0, 0,
   4096, NOW(), NULL),
  ('google/gemini-flash-1.5', 'Gemini Flash 1.5', 'Google',
   'Fast multimodal model from Google',
   1000000, 0.00000008, 0.00000030,
   1, 0, 3, 1, 1,
   8192, NOW(), NULL),
  ('meta-llama/llama-3.1-70b-instruct', 'Llama 3.1 70B', 'Meta',
   'Open-source large language model',
   128000, 0.00000040, 0.00000040,
   1, 0, 4, 0, 0,
   4096, NOW(), NULL);

-- ============================================================
-- Default Super Admin (Local Login)
-- ตั้งรหัสผ่านเองก่อนใช้: php -r "echo password_hash('YOUR_PASSWORD', PASSWORD_DEFAULT);"
-- Hash: bcrypt cost 12
-- ============================================================
INSERT IGNORE INTO `admin_users`
  (`username`, `email`, `password`, `display_name`, `role`, `auth_provider`, `is_active`,
   `permissions`)
VALUES
  ('superadmin', 'admin@up.ac.th',
   'CHANGE_ME_BCRYPT_HASH',
   'Super Administrator', 'super_admin', 'local', 1,
   '{"view_dashboard":true,"view_conversations":true,"view_analytics":true,"view_token_usage":true,"manage_knowledge":true,"manage_models":true,"manage_users":true,"manage_settings":true,"export_data":true}');

-- Note: รหัสผ่านด้านบนคือ "password" (bcrypt hash จาก Laravel)
-- ให้เปลี่ยนก่อน deploy จริงด้วย: password_hash('YourPassword', PASSWORD_BCRYPT, ['cost'=>12])

-- ============================================================
-- Default System Settings
-- ============================================================
INSERT IGNORE INTO `system_settings` (`setting_key`, `setting_value`, `type`, `description`) VALUES
('system_prompt',
 'คุณเป็น AI Assistant ที่ช่วยตอบคำถามเกี่ยวกับข้อมูลขององค์กร กรุณาตอบอย่างสุภาพ ชัดเจน และตรงประเด็น หากไม่มีข้อมูลที่เกี่ยวข้อง ให้แจ้งว่าไม่พบข้อมูล',
 'string', 'System Prompt สำหรับ AI'),
('bot_name',              'AI Assistant',                     'string',  'ชื่อ Bot ที่แสดงใน Widget'),
('welcome_message',       'สวัสดี! มีอะไรให้ช่วยไหมครับ? 😊', 'string',  'ข้อความต้อนรับ'),
('temperature',           '0.7',                              'string',  'AI Temperature (0.0 - 2.0)'),
('max_tokens',            '1000',                             'integer', 'Max Response Tokens'),
('similarity_threshold',  '0.7',                              'string',  'Cosine Similarity Threshold (0.0 - 1.0)'),
('top_k_chunks',          '5',                                'integer', 'Top K Knowledge Chunks for RAG'),
('fallback_message',      'ขออภัยครับ ไม่พบข้อมูลที่เกี่ยวข้องกับคำถามของคุณ กรุณาติดต่อเจ้าหน้าที่โดยตรง', 'string', 'ข้อความเมื่อ AI ตอบไม่ได้'),
('history_length',        '10',                               'integer', 'จำนวน Messages ใน Conversation History'),
('primary_color',         '#4F46E5',                          'string',  'Widget Primary Color (Hex)'),
('widget_position',       'bottom-right',                     'string',  'ตำแหน่ง Widget (bottom-right / bottom-left)'),
('allow_feedback',        'true',                             'boolean', 'อนุญาตให้ User กด Like/Dislike'),
('rate_limit_enabled',    'true',                             'boolean', 'เปิด Rate Limiting สำหรับ API');

SET FOREIGN_KEY_CHECKS = 1;
