-- ============================================================
-- Seed Data: Demo / Production Initial Data
-- AI Chatbot System — CBMS
-- Run AFTER: 001_create_all_tables.sql and 001_seed_data.sql
-- ============================================================

SET NAMES utf8mb4;
SET time_zone = '+07:00';

-- ── System Settings ─────────────────────────────────────────────────────
INSERT INTO system_settings (setting_key, setting_value) VALUES
  ('app_name',              'AI Chatbot มหาวิทยาลัยพะเยา'),
  ('chat_welcome_message',  'สวัสดีครับ! 😊 ยินดีให้บริการข้อมูลมหาวิทยาลัยพะเยา\nมีคำถามอะไรให้ช่วยไหมครับ?'),
  ('system_prompt',
   'คุณคือผู้ช่วย AI ของมหาวิทยาลัยพะเยา (University of Phayao) ทำหน้าที่ตอบคำถามเกี่ยวกับมหาวิทยาลัยด้วยความสุภาพและเป็นมืออาชีพ\n\nแนวทางการตอบ:\n- ตอบเป็นภาษาไทย ยกเว้นผู้ใช้ถามภาษาอังกฤษ\n- ใช้ข้อมูลจาก Knowledge Base เป็นหลัก\n- หากไม่พบข้อมูล แจ้งให้ผู้ใช้ติดต่อเจ้าหน้าที่\n- ตอบกระชับ ชัดเจน ไม่เกิน 300 คำ\n- ใช้ Emoji อย่างเหมาะสม'),
  ('temperature',            '0.7'),
  ('max_tokens',             '1000'),
  ('similarity_threshold',   '0.70'),
  ('top_k_chunks',           '5'),
  ('history_length',         '10'),
  ('fallback_message',       'ขออภัยครับ ไม่พบข้อมูลที่เกี่ยวข้องกับคำถามนี้ กรุณาติดต่อเจ้าหน้าที่มหาวิทยาลัยโดยตรงครับ 🙏'),
  ('rate_limit_enabled',     'true'),
  ('rate_limit_per_minute',  '20'),
  ('rate_limit_per_hour',    '100'),
  ('allow_local_login',      'true'),
  ('facebook_verify_token',  'cbms_verify_2024'),
  ('facebook_page_token',    ''),
  ('facebook_app_secret',    ''),
  ('line_channel_id',        ''),
  ('line_channel_token',     ''),
  ('line_channel_secret',    '')
ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value);

-- ── Demo AI Models ──────────────────────────────────────────────────────
-- Note: Run `php sync.php --sync-models` to get real models from OpenRouter
-- These are example entries for offline testing
INSERT IGNORE INTO ai_models
  (openrouter_model_id, display_name, provider_name,
   context_length, pricing_prompt, pricing_completion,
   supports_vision, supports_tools, is_active, is_default, sort_order)
VALUES
  ('anthropic/claude-3.5-sonnet',        'Claude 3.5 Sonnet',       'Anthropic',  200000, 0.000003, 0.000015, 1, 1, 1, 1, 1),
  ('openai/gpt-4o-mini',                 'GPT-4o Mini',             'OpenAI',     128000, 0.00000015, 0.0000006, 1, 1, 1, 0, 2),
  ('google/gemini-flash-1.5',            'Gemini 1.5 Flash',        'Google',    1000000, 0.000000075, 0.0000003, 1, 0, 1, 0, 3),
  ('meta-llama/llama-3.1-8b-instruct',   'Llama 3.1 8B Instruct',   'Meta',      131072, 0.000000055, 0.000000055, 0, 0, 1, 0, 4),
  ('deepseek/deepseek-chat',             'DeepSeek Chat',           'DeepSeek',  128000, 0.00000014, 0.00000028, 0, 1, 1, 0, 5),
  ('anthropic/claude-3-haiku',           'Claude 3 Haiku',          'Anthropic',  200000, 0.00000025, 0.00000125, 1, 1, 1, 0, 6),
  ('openai/gpt-4o',                      'GPT-4o',                  'OpenAI',     128000, 0.000005,   0.000015, 1, 1, 1, 0, 7),
  ('mistralai/mistral-7b-instruct',      'Mistral 7B Instruct',     'Mistral',    32768, 0.00000007, 0.00000007, 0, 0, 0, 0, 8);

-- ── Demo Super Admin (local login: admin / <ตั้งเอง> ──────────────────
INSERT IGNORE INTO admin_users
  (email, username, display_name, password, role, auth_provider, is_active, permissions)
VALUES
  ('admin@up.ac.th', 'admin', 'System Administrator',
   'CHANGE_ME_BCRYPT_HASH',
   'super_admin', 'local', 1,
   '{"view_dashboard":true,"view_conversations":true,"view_analytics":true,"view_token_usage":true,"manage_knowledge":true,"manage_models":true,"manage_users":true,"manage_settings":true,"export_data":true}');

-- ── Demo Knowledge Sources (Google Drive) ──────────────────────────────
INSERT IGNORE INTO knowledge_sources
  (google_drive_file_id, google_drive_url, file_name, file_type, sync_status, is_active, chunk_count)
VALUES
  ('1abc123', 'https://docs.google.com/document/d/1abc123',
   'แนะนำมหาวิทยาลัยพะเยา.gdoc', 'google_doc', 'pending', 1, 0),
  ('2def456', 'https://docs.google.com/document/d/2def456',
   'คณะและสาขาวิชา 2567.gdoc', 'google_doc', 'pending', 1, 0),
  ('3ghi789', 'https://drive.google.com/file/d/3ghi789',
   'ระเบียบการรับสมัคร TCAS68.pdf', 'pdf', 'pending', 1, 0),
  ('4jkl012', 'https://drive.google.com/file/d/4jkl012',
   'อัตราค่าเล่าเรียน 2567.pdf', 'pdf', 'pending', 1, 0),
  ('5mno345', 'https://drive.google.com/file/d/5mno345',
   'บริการและสวัสดิการนักศึกษา.docx', 'docx', 'pending', 1, 0);

-- ── Demo Conversations ──────────────────────────────────────────────────
-- Web conversation
INSERT IGNORE INTO conversations
  (session_id, platform, started_at, last_activity_at, message_count, total_tokens, total_cost_usd)
VALUES
  ('w-demo001', 'web',      DATE_SUB(NOW(), INTERVAL 2 HOUR), DATE_SUB(NOW(), INTERVAL 2 HOUR), 4, 1240, 0.0037),
  ('w-demo002', 'web',      DATE_SUB(NOW(), INTERVAL 5 HOUR), DATE_SUB(NOW(), INTERVAL 5 HOUR), 6, 1890, 0.0057),
  ('fb-demo01', 'facebook', DATE_SUB(NOW(), INTERVAL 1 DAY),  DATE_SUB(NOW(), INTERVAL 1 DAY),  2,  620, 0.0019),
  ('ln-demo01', 'line',     DATE_SUB(NOW(), INTERVAL 2 DAY),  DATE_SUB(NOW(), INTERVAL 2 DAY),  3,  870, 0.0026);

-- ── Demo Messages ──────────────────────────────────────────────────────
SET @cid = (SELECT id FROM conversations WHERE session_id='w-demo001' LIMIT 1);
INSERT IGNORE INTO messages
  (conversation_id, role, content, tokens_total, is_fallback, response_time_ms, created_at)
VALUES
  (@cid, 'user',      'มหาวิทยาลัยพะเยาอยู่ที่ไหนครับ', 0, 0, 0, DATE_SUB(NOW(), INTERVAL 120 MINUTE)),
  (@cid, 'assistant', 'มหาวิทยาลัยพะเยาตั้งอยู่ที่ตำบลแม่กา อำเภอเมือง จังหวัดพะเยา 56000 ครับ 😊 ห่างจากตัวเมืองพะเยาประมาณ 10 กิโลเมตร', 120, 0, 850, DATE_SUB(NOW(), INTERVAL 119 MINUTE)),
  (@cid, 'user',      'มีคณะอะไรบ้างครับ',                0, 0, 0, DATE_SUB(NOW(), INTERVAL 118 MINUTE)),
  (@cid, 'assistant', 'มหาวิทยาลัยพะเยามีคณะและวิทยาลัยกว่า 19 คณะ เช่น\n• คณะแพทยศาสตร์\n• คณะวิศวกรรมศาสตร์\n• คณะวิทยาศาสตร์\n• คณะพยาบาลศาสตร์\n• คณะบริหารธุรกิจ\nและอีกหลายคณะครับ', 280, 0, 920, DATE_SUB(NOW(), INTERVAL 117 MINUTE));

-- ── Demo Token Analytics ────────────────────────────────────────────────
SET @model_id = (SELECT id FROM ai_models WHERE is_default=1 LIMIT 1);
INSERT IGNORE INTO token_analytics
  (date, platform, ai_model_id, conversations, messages,
   tokens_prompt, tokens_completion, tokens_total, cost_usd)
VALUES
  (CURDATE(),                'web',      @model_id, 12, 48, 14400, 9600,  24000, 0.072),
  (CURDATE(),                'facebook', @model_id,  5, 14,  4200, 2800,   7000, 0.021),
  (CURDATE(),                'line',     @model_id,  3,  9,  2700, 1800,   4500, 0.0135),
  (DATE_SUB(CURDATE(),INTERVAL 1 DAY), 'web',      @model_id, 18, 72, 21600, 14400, 36000, 0.108),
  (DATE_SUB(CURDATE(),INTERVAL 1 DAY), 'facebook', @model_id,  8, 22,  6600,  4400, 11000, 0.033),
  (DATE_SUB(CURDATE(),INTERVAL 2 DAY), 'web',      @model_id, 15, 60, 18000, 12000, 30000, 0.090),
  (DATE_SUB(CURDATE(),INTERVAL 3 DAY), 'web',      @model_id, 10, 40, 12000,  8000, 20000, 0.060),
  (DATE_SUB(CURDATE(),INTERVAL 4 DAY), 'web',      @model_id, 22, 88, 26400, 17600, 44000, 0.132),
  (DATE_SUB(CURDATE(),INTERVAL 5 DAY), 'web',      @model_id, 19, 76, 22800, 15200, 38000, 0.114),
  (DATE_SUB(CURDATE(),INTERVAL 6 DAY), 'web',      @model_id, 14, 56, 16800, 11200, 28000, 0.084),
  (DATE_SUB(CURDATE(),INTERVAL 7 DAY), 'web',      @model_id, 25, 100,30000, 20000, 50000, 0.150),
  (DATE_SUB(CURDATE(),INTERVAL 7 DAY), 'facebook', @model_id, 10, 30,  9000,  6000, 15000, 0.045),
  (DATE_SUB(CURDATE(),INTERVAL 7 DAY), 'line',     @model_id,  6, 18,  5400,  3600,  9000, 0.027)
ON DUPLICATE KEY UPDATE
  conversations=VALUES(conversations), messages=VALUES(messages),
  tokens_total=VALUES(tokens_total), cost_usd=VALUES(cost_usd);

-- ── API Usage Log (Demo) ────────────────────────────────────────────────
INSERT IGNORE INTO api_usage_logs
  (platform, session_id, ai_model_id, tokens_prompt, tokens_completion, tokens_total, cost_usd, response_time_ms, is_fallback, created_at)
SELECT 'web', CONCAT('demo-', seq), @model_id,
       FLOOR(RAND()*800+200), FLOOR(RAND()*600+100),
       FLOOR(RAND()*1400+300), ROUND(RAND()*0.01+0.001,6),
       FLOOR(RAND()*1500+500), IF(RAND()<0.05,1,0),
       DATE_SUB(NOW(), INTERVAL seq HOUR)
FROM (
  SELECT 0 AS seq UNION SELECT 1 UNION SELECT 2 UNION SELECT 3 UNION SELECT 4
  UNION SELECT 5 UNION SELECT 6 UNION SELECT 7 UNION SELECT 8 UNION SELECT 9
) t;

-- ============================================================
-- Done! Default Login:
--   URL:      https://appupili.up.ac.th/cbms/admin/login.php
--   Username: admin
-- ตั้งรหัสผ่านเองก่อนใช้: php -r "echo password_hash('YOUR_PASSWORD', PASSWORD_DEFAULT);"
-- (Change immediately after first login)
-- ============================================================
