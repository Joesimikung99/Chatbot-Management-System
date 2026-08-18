# SPEC.md — CBMS: AI Chatbot Management System

> **Session 11 / CP0 draft** · เขียน 2026-08-18 · สถานะ: ร่างก่อนเริ่ม build
> กฎของไฟล์นี้: ทุก acceptance criterion ต้องระบุ "อะไรเป็นคนตัดสิน" (คำสั่ง / test / query ที่รันได้)
> เกณฑ์ที่เครื่องเช็คไม่ได้ = ความปรารถนา ไม่ใช่เกณฑ์ → ห้ามอยู่ใน §7

---

## Business Context

*Problem Statement:*
เจ้าหน้าที่หน่วยงานในมหาวิทยาลัยพะเยาต้องตอบคำถามเดิมซ้ำ ๆ (เวลาทำการ, บริการ, สถานที่, สิทธิ์การใช้งาน, ระบบ IT) จาก 4 ช่องทางที่ไม่เชื่อมกัน — เคาน์เตอร์, โทรศัพท์, Facebook Page, LINE OA — โดยข้อมูลอ้างอิงกระจายอยู่ในเอกสาร PDF/Google Docs หลายฉบับบน Google Drive ผลคือ: ตอบได้เฉพาะเวลาราชการ (นิสิตถามตอน 22:00 ไม่มีคนตอบ), คำตอบไม่ตรงกันเมื่อคนตอบต่างคน และไม่มีข้อมูลเลยว่าคนถามอะไรบ่อยที่สุด

*Target Audience:*

| Persona | ใคร | ต้องการอะไร | ช่องทาง |
|---|---|---|---|
| ผู้ถาม | นิสิต/บุคลากร ~20,000 คน (ภาษาไทยเป็นหลัก, ส่วนใหญ่เข้าจากมือถือ) | คำตอบที่ถูกต้องภายในไม่กี่วินาที ตลอด 24 ชม. | Web widget, Facebook Messenger, LINE |
| เจ้าหน้าที่ผู้ดูแลเนื้อหา | เจ้าหน้าที่หน่วยงาน 1–3 คน/หน่วยงาน (ไม่ใช่โปรแกรมเมอร์) | เติม/แก้ Knowledge Base ด้วยการวางไฟล์ใน Google Drive · ดูคำถามที่บอทตอบไม่ได้ | Admin Dashboard |
| ผู้ดูแลระบบ | ทีม IT (super_admin 1–2 คน) | จัดการผู้ใช้/โมเดล AI, คุมค่าใช้จ่าย token, ตรวจ audit log | Admin Dashboard |

> หมายเหตุเรื่อง flow ที่คนละเรื่องกัน: **"ผู้ใช้ทั่วไปถามคำถาม"** (ไม่ต้อง login, ไม่เก็บ PII) ≠ **"แอดมิน login เข้าหลังบ้าน"** (Office 365 + ตรวจสิทธิ์ CAMS) ≠ **"แอดมินเพิ่มผู้ใช้แอดมินคนใหม่"** (ต้อง permission `manage_users`) — 3 flow นี้แยกกันเด็ดขาดทั้งใน spec และใน test

*Success Metrics:*

| ตัวชี้วัด | เป้า | วัดด้วย |
|---|---|---|
| คำถามที่บอทตอบได้เอง (ไม่ fallback) | ≥ 80% ของคำถามทั้งหมด | `SELECT 1 - SUM(is_fallback)/COUNT(*) FROM messages WHERE role='assistant'` |
| ความถูกต้องของคำตอบบนชุดคำถามทดสอบ 50 ข้อ | ≥ 80% ถูกต้อง | `tests/Feature/RagAccuracyTest.php` (fixture `tests/Fixtures/qa_set.json`) |
| เวลาตอบ p95 (คำถามใหม่) | < 8,000 ms | percentile จาก `messages.response_time_ms` |
| คำถามนอกเวลาราชการที่ได้รับคำตอบ | > 0 (จากปัจจุบัน = 0) | `SELECT COUNT(*) FROM messages WHERE HOUR(created_at) NOT BETWEEN 8 AND 16` |
| ค่าใช้จ่าย AI | ≤ $20/เดือน | `SELECT SUM(cost_usd) FROM token_usage_daily WHERE usage_date >= …` |
| ความพึงพอใจผู้ใช้ | ≥ 4.00/5.00 (n ≥ 30) | แบบสอบถามหลังใช้งาน 1 เดือน |

---

## Tech Stack

- **Backend:** PHP 8.4 (custom framework — ไม่ใช้ Laravel/Symfony)
- **Database:** MySQL 8.0, charset `utf8mb4` (ต้องรองรับไทย + emoji)
- **Frontend (widget):** Vanilla JavaScript ES2020 — ไม่มี framework, ไม่มี build step
- **Frontend (admin):** TailwindCSS 3.x (CDN) + Alpine.js 3.x (CDN) + Chart.js 4.x + ฟอนต์ Sarabun + Heroicons (SVG inline)
- **AI Gateway:** OpenRouter API `https://openrouter.ai/api/v1`
  - chat model เริ่มต้น: `openai/gpt-4o-mini` (เปลี่ยนได้จาก Admin โดยไม่แก้โค้ด)
  - embedding model: `openai/text-embedding-3-small`
- **Auth:** PHP session-based + Microsoft OAuth2 (Azure AD) + ตรวจสิทธิ์กับ CAMS API (`program_code=CBMS`) + local login (เปิด/ปิดด้วย `ALLOW_LOCAL_LOGIN`)
- **Composer packages (ตรึงเวอร์ชัน):** `google/apiclient ^2.15` · `guzzlehttp/guzzle ^7.9` · `vlucas/phpdotenv ^5.6` · `firebase/php-jwt ^6.10` · `league/oauth2-client ^2.7` · `thenetworg/oauth2-azure ^2.2` · `ramsey/uuid ^4.7` · `smalot/pdfparser 2.0`
- **Test:** PHPUnit ^11.0 (`tests/Unit`, `tests/Feature`, DB แยกชื่อ `cbms_test`)
- **Deploy:** IIS + PHP 8.4 (FastCGI) บนเซิร์ฟเวอร์มหาวิทยาลัย · `APP_URL=https://appupili.up.ac.th/cbms` · งานตามเวลาใช้ Windows Task Scheduler เรียก `php sync.php`

*Rationale:* เซิร์ฟเวอร์ปลายทางเป็น IIS + PHP ที่ไม่มี node/build pipeline และทีมมีคนดูแล PHP อยู่แล้ว จึงเลือก PHP ล้วน + CSS/JS ผ่าน CDN และใช้ OpenRouter เป็นชั้นกลางเพื่อสลับโมเดล AI ได้จากหน้า Admin โดยไม่แตะโค้ด

**Design tokens (ให้ test เช็คได้ ไม่ต้องเถียงกันเรื่องสี):** primary `indigo-600 #4F46E5` · success `emerald-500` · warning `amber-500` · danger `red-500` · platform: web `indigo-500`, facebook `#1877F2`, line `#06C755`

**เครื่องมือที่เลือกใช้ใน Session 11 (Mini Workshop 11):**
- **Skill: `security-review`** — รันตรวจ diff ก่อน commit ทุก phase (เหตุผล: spec นี้มี auth, webhook signature, PII, API key ครบทุกโซนเสี่ยง)
- ⬜ ต้องรันทดสอบ 1 ครั้งให้เห็นผลจริงก่อนปิด CP0

---

## MoSCoW

| MUST (v1 ต้องมี ไม่มีไม่ผ่าน) | SHOULD (ทำถ้าเวลาเหลือใน v1) | COULD (v2) | WON'T (ไม่ทำ — กัน scope creep) |
|---|---|---|---|
| 1. Web chat widget ฝังเว็บอื่นได้ 1 บรรทัด | 1. Streaming ตอบทีละ token (SSE) | 1. Multi-bot: หลายหน่วยงานในระบบเดียว แยก Knowledge Base | 1. Train / fine-tune โมเดลเอง |
| 2. Facebook Messenger webhook | 2. Cache คำตอบคำถามซ้ำ | 2. Human handoff ส่งต่อเจ้าหน้าที่ | 2. Mobile app native |
| 3. LINE webhook | 3. Feedback 👍/👎 ต่อข้อความ | 3. เรียบเรียง Knowledge เป็นหน้า Wiki ด้วย LLM | 3. Realtime chat แบบหลายคนพร้อมกัน |
| 4. RAG จาก Google Drive (PDF/Docs) + fallback กัน hallucination | 4. Export report CSV | 4. รองรับภาษาอังกฤษ/จีน | 4. ธุรกรรมการเงินทุกชนิด |
| 5. Admin login: Office 365 → CAMS → session | 5. Gap analysis คำถามที่ตอบไม่ได้ | 5. Vector database จริง (pgvector/Qdrant) | 5. เก็บ PII ผู้ถาม (ชื่อ, รหัสนิสิต, เบอร์) |
| 6. RBAC: super_admin / admin / viewer + permission flags | | 6. Voice input | 6. เปลี่ยนไปใช้ framework สำเร็จรูป |
| 7. Dashboard + Token Usage Analytics (token/cost แยก platform & model) | | | 7. Deploy ขึ้น cloud นอกมหาวิทยาลัย |
| 8. AI Model Management: sync models จาก OpenRouter, ตั้ง default, ทดสอบ | | | |
| 9. User Management + Activity Log | | | |
| 10. `sync.php` CLI: sync Drive, sync models, aggregate สถิติรายวัน | | | |

**กฎ freeze:** ห้ามย้ายของจาก COULD/WON'T เข้า MUST ระหว่าง build ถ้าจำเป็นต้องเปลี่ยน = เปิด spec รอบใหม่ ไม่ใช่แก้กลางทาง

---

## Data Models

> ทุก `UNIQUE` / `NOT NULL` / `FOREIGN KEY` / `ENUM` ข้างล่างคือ check ที่ MySQL รันให้ฟรี ไม่ใช่ของตกแต่ง

### platforms
- id: INT AUTO_INCREMENT PRIMARY KEY
- name: ENUM('web','facebook','line') NOT NULL
- is_active: TINYINT DEFAULT 1
- config: JSON (nullable)
- created_at / updated_at: TIMESTAMP DEFAULT CURRENT_TIMESTAMP

### ai_models
- id: INT PRIMARY KEY
- openrouter_model_id: VARCHAR(255) NOT NULL UNIQUE   ← เช่น `openai/gpt-4o-mini`
- display_name: VARCHAR(255) NOT NULL
- provider_name: VARCHAR(100)
- context_length: INT
- pricing_prompt: DECIMAL(12,8) DEFAULT 0
- pricing_completion: DECIMAL(12,8) DEFAULT 0
- is_active: TINYINT DEFAULT 1
- is_default: TINYINT DEFAULT 0   ← ต้องมี `is_default=1` ได้ **แค่ 1 แถว** (บังคับใน service + test)
- sort_order: INT DEFAULT 0
- supports_vision / supports_tools: TINYINT DEFAULT 0
- max_tokens: INT DEFAULT 4096
- last_fetched_at: DATETIME
- INDEX idx_sort (sort_order, is_active)

### admin_users
- id: INT PRIMARY KEY
- username: VARCHAR(100) UNIQUE (nullable — ผู้ใช้ Microsoft ไม่มี username)
- email: VARCHAR(255) NOT NULL UNIQUE
- password: VARCHAR(255) (nullable, bcrypt เท่านั้น — ห้าม plain text)
- display_name: VARCHAR(255)
- avatar_url: VARCHAR(500)
- role: ENUM('super_admin','admin','viewer') DEFAULT 'viewer'
- auth_provider: ENUM('local','microsoft') DEFAULT 'local'
- microsoft_id / microsoft_tenant_id: VARCHAR(255)
- microsoft_token: JSON (nullable)
- cams_dept_code: VARCHAR(50) · cams_level_value: INT   ← จาก CAMS `/auth/verify`
- permissions: JSON   ← flags: view_dashboard, view_conversations, view_analytics, view_token_usage, manage_knowledge, manage_models, manage_users, manage_settings, export_data
- is_active: TINYINT DEFAULT 1
- last_login_at: DATETIME · last_login_ip: VARCHAR(45)
- INDEX idx_microsoft_id, idx_email

### conversations
- id: INT PRIMARY KEY
- session_id: VARCHAR(100) NOT NULL UNIQUE   ← UUID v4 ที่ client เก็บใน localStorage
- platform: ENUM('web','facebook','line') NOT NULL
- platform_user_id / platform_username: VARCHAR(255) (nullable)
- ai_model_id: INT FOREIGN KEY -> ai_models(id) ON DELETE SET NULL
- ip_address: VARCHAR(45)   ← ใช้ทำ rate limiting
- message_count: INT DEFAULT 0
- total_tokens: INT DEFAULT 0
- total_cost_usd: DECIMAL(10,6) DEFAULT 0
- started_at / last_activity_at: TIMESTAMP DEFAULT CURRENT_TIMESTAMP
- INDEX idx_session, idx_platform, idx_activity

### messages
- id: INT PRIMARY KEY
- conversation_id: INT NOT NULL FOREIGN KEY -> conversations(id) ON DELETE CASCADE
- role: ENUM('user','assistant','system') NOT NULL
- content: TEXT NOT NULL
- tokens_prompt / tokens_completion / tokens_total: INT DEFAULT 0
- response_time_ms: INT DEFAULT 0
- ai_model_id: INT FOREIGN KEY -> ai_models(id) ON DELETE SET NULL
- knowledge_chunks_used: JSON   ← chunk id ที่ใช้ตอบ (ต้องตรวจย้อนหลังได้ว่าคำตอบมาจากไหน)
- is_fallback: TINYINT DEFAULT 0
- feedback: ENUM('positive','negative') NULL
- created_at: TIMESTAMP DEFAULT CURRENT_TIMESTAMP
- INDEX idx_conversation, idx_created

### knowledge_sources
- id: INT PRIMARY KEY
- google_drive_file_id: VARCHAR(255) NOT NULL UNIQUE
- file_name: VARCHAR(500) NOT NULL
- file_type / mime_type: VARCHAR(50) / VARCHAR(100)
- last_modified / last_synced_at: DATETIME
- sync_status: ENUM('pending','processing','synced','error') DEFAULT 'pending'
- chunk_count: INT DEFAULT 0
- error_message: TEXT (nullable)
- is_active: TINYINT DEFAULT 1

### knowledge_chunks
- id: INT PRIMARY KEY
- source_id: INT NOT NULL FOREIGN KEY -> knowledge_sources(id) ON DELETE CASCADE
- chunk_index: INT NOT NULL
- content: TEXT NOT NULL
- embedding: JSON   ← float array ของ embedding model ที่ระบุใน Tech Stack
- token_count: INT DEFAULT 0
- INDEX idx_source
- UNIQUE (source_id, chunk_index)

### token_usage_daily  (pre-aggregated เพื่อให้ dashboard เร็ว)
- id: INT PRIMARY KEY
- usage_date: DATE NOT NULL
- platform: ENUM('web','facebook','line','all') NOT NULL
- ai_model_id: INT FOREIGN KEY -> ai_models(id) ON DELETE SET NULL
- total_conversations / total_messages: INT DEFAULT 0
- tokens_prompt / tokens_completion / tokens_total: BIGINT DEFAULT 0
- cost_usd: DECIMAL(12,6) DEFAULT 0
- avg_response_ms: INT DEFAULT 0 · fallback_count: INT DEFAULT 0
- UNIQUE KEY uq_date_platform_model (usage_date, platform, ai_model_id)   ← กัน aggregate ซ้ำ (idempotent)

### api_usage_logs
- id: INT PRIMARY KEY
- conversation_id: INT FK -> conversations(id) ON DELETE SET NULL · message_id: INT
- platform: ENUM('web','facebook','line') NOT NULL
- ai_model_id: INT FK -> ai_models(id) ON DELETE SET NULL
- api_provider: ENUM('openrouter','openai','gemini','google_drive') NOT NULL
- tokens_prompt / tokens_completion / tokens_total: INT DEFAULT 0
- cost_usd: DECIMAL(10,6) DEFAULT 0 · response_time_ms: INT DEFAULT 0
- status: ENUM('success','error') DEFAULT 'success' · error_message: TEXT
- INDEX idx_platform_date, idx_model_date, idx_created

### system_settings
- id: INT PRIMARY KEY
- setting_key: VARCHAR(100) NOT NULL UNIQUE
- setting_value: TEXT
- type: ENUM('string','json','boolean','integer','password') DEFAULT 'string'
- description: VARCHAR(500)
- updated_by: INT FK -> admin_users(id) ON DELETE SET NULL
- **seed ที่ต้องมี:** system_prompt, bot_name, welcome_message, temperature=0.7, max_tokens=1000, similarity_threshold=0.7, top_k_chunks=5, history_length=10, fallback_message, primary_color=#4F46E5

### admin_activity_logs
- id: INT PRIMARY KEY
- user_id: INT FK -> admin_users(id) ON DELETE SET NULL
- action: VARCHAR(100) NOT NULL · target_type: VARCHAR(50) · target_id: INT
- old_value / new_value: JSON
- ip_address: VARCHAR(45) · user_agent: VARCHAR(500)
- created_at: TIMESTAMP DEFAULT CURRENT_TIMESTAMP
- INDEX idx_user, idx_created

---

## API Endpoints

```
POST /api/chat.php
- Body: {message (required, 1-2000 chars), session_id? (uuid), model_id?}
- 200 + {success:true, data:{reply, session_id, is_fallback, tokens_total, response_time_ms, model_used}}
- 400 message ว่าง / เกิน 2000 ตัวอักษร / JSON ผิดรูป  → {success:false, error:{field, message}}
- 429 เกิน rate limit (20/นาที, 100/ชม. ต่อ IP) + header Retry-After
- 503 OpenRouter ล่ม / API key ผิด (ต้องไม่คืน stack trace)

GET /api/chat.php?action=history&session_id=<uuid>
- 200 + {success:true, data:[{role, content, created_at}]}   (ว่างได้ → data: [])
- 400 ไม่ส่ง session_id | 404 session_id ไม่มีในระบบ

POST /api/chat.php?action=feedback
- Body: {message_id:int, feedback:'positive'|'negative'}
- 200 + {success:true} | 400 feedback ไม่ใช่ 2 ค่านี้ | 404 message_id ไม่มี

GET /api/webhook-facebook.php
- Query: hub.mode, hub.verify_token, hub.challenge
- 200 + hub.challenge (เมื่อ token ตรง FACEBOOK_VERIFY_TOKEN) | 403 token ไม่ตรง

POST /api/webhook-facebook.php
- Header: X-Hub-Signature-256 = 'sha256=' + hash_hmac('sha256', rawBody, FACEBOOK_APP_SECRET)
- 200 (ตอบผู้ใช้ผ่าน Graph API v19.0) | 403 signature ไม่ตรง (ตรวจด้วย hash_equals)
- ข้าม event ที่ message.is_echo = true (ห้ามตอบข้อความของตัวเอง)

POST /api/webhook-line.php
- Header: X-Line-Signature = base64(hash_hmac('sha256', rawBody, LINE_CHANNEL_SECRET, true))
- 200 (ตอบผ่าน Reply Message API v2) | 403 signature ไม่ตรง
- Event: message | follow | unfollow | postback | join

GET  /admin/login.php                - 200 (ปุ่ม Office 365 + local form ถ้า ALLOW_LOCAL_LOGIN=true)
POST /admin/login.php  (local)       - 302 → /admin/index.php | 401 user/pass ผิด | 403 is_active=0 | 429 ผิดเกิน 5 ครั้ง/15 นาที
GET  /admin/auth-callback.php        - Query: code, state
                                     - 302 → dashboard เมื่อ state ตรง + domain ผ่าน + CAMS authorized
                                     - 403 state ไม่ตรง | 403 domain ไม่อยู่ใน MICROSOFT_ALLOWED_DOMAINS
                                     - 403 CAMS ตอบ authorized=false (แสดงหน้า Access Denied ไม่ใช่ 500)
POST /admin/api/models-sync.php      - 200 + {added, updated, deactivated} | 403 ไม่มี permission manage_models
POST /admin/api/models-test.php      - 200 + {success, response_time_ms} | 403 | 502 model ตอบไม่ได้
POST /admin/api/model-default.php    - 200 (ตั้ง is_default ได้ทีละ 1) | 403
POST /admin/api/users.php            - 201 สร้าง user | 400 email ผิดรูป | 409 email ซ้ำ | 403 ไม่มี manage_users
GET  /admin/api/token-usage.php      - Query: from, to, platform?, model_id?
                                     - 200 + {summary, by_platform, by_model, daily[]} | 400 ช่วงวันที่ผิด | 403
GET  /admin/api/export.php           - 200 + text/csv (ต้องมี CSRF token) | 403 ไม่มี export_data

CORS: Access-Control-Allow-Origin = APP_URL หรือค่าใน CORS_ALLOWED_ORIGINS เท่านั้น — ห้าม `*`
ทุก response ต้องเป็น envelope เดียวกัน: {success:boolean, data?:…, error?:{field?, message}}
```

**CLI**
```
php sync.php                       - sync Google Drive → chunk → embed → บันทึก DB   (exit 0 = สำเร็จ)
php sync.php --sync-models         - sync รายการ model จาก OpenRouter → ai_models
php sync.php --aggregate           - รวม api_usage_logs → token_usage_daily (รันทุกวัน 23:59, ต้อง idempotent)
php sync.php --test-ai             - ยิงข้อความสั้นทดสอบ model default (exit 0/1)
```

---

## Edge Cases

แต่ละข้อจะกลายเป็น test 1 ตัวใน §Acceptance Criteria — agent ไม่คิดเคสพวกนี้เองแน่นอน ต้องเขียนไว้

1. **Empty state — ยังไม่มีบทสนทนา:** history คืน `data: []` และหน้า Conversations ต้อง render `data-state-empty` พร้อมข้อความ "ยังไม่มีบทสนทนา" — ไม่ใช่ตารางเปล่า ๆ
2. **Empty state — Knowledge Base ว่าง:** ถามอะไรก็ต้องได้ fallback message + `is_fallback=1` ห้ามให้โมเดลเดาคำตอบเอง
3. **Validation failure:** `message` ว่าง / เกิน 2,000 ตัวอักษร / body ไม่ใช่ JSON → 400 + `error.field='message'` (ไม่ใช่ 500)
4. **ไม่พบข้อมูลที่เกี่ยวข้อง:** ทุก chunk มี similarity < `similarity_threshold` → fallback message, บันทึก `is_fallback=1`, ไม่เรียก AI ซ้ำ
5. **Race condition — ผู้ใช้กดส่งรัว ๆ:** widget ต้องมี `data-state-loading` และ disable ปุ่มส่ง; ฝั่ง server ห้ามเกิด message ซ้ำใน conversation เดียวกัน
6. **Rate limit:** ยิงเกิน 20 ครั้ง/นาที จาก IP เดียว → 429 + `Retry-After` และต้องบังคับกับทั้ง web, Facebook และ LINE
7. **Concurrent edit — แอดมิน 2 คนแก้ settings พร้อมกัน:** คนที่ save ทีหลังชนะ (last-write-wins) แต่ทั้ง 2 ครั้งต้องมีแถวใน `admin_activity_logs` พร้อม old_value/new_value
8. **Concurrent — ตั้ง default model พร้อมกัน 2 คน:** จบแล้วต้องมี `is_default=1` เพียงแถวเดียว
9. **`--aggregate` รันซ้ำวันเดิม:** ต้องได้ตัวเลขเดิม ไม่เกิดแถวซ้ำ (บังคับด้วย unique key)
10. **ข้อความภาษาไทยยาว:** ต้องตัด/นับด้วยฟังก์ชัน multibyte (`mb_strlen`, `mb_substr`) — ห้ามใช้ `strlen` (ตัดกลางตัวอักษรไทยเป็นขยะ)
11. **Facebook ส่ง echo ข้อความของบอทเอง:** ต้องข้าม ไม่วนตอบตัวเอง
12. **Webhook signature ผิด/ไม่มี header:** 403 ทันที ก่อนแตะ DB หรือเรียก AI (เทียบด้วย `hash_equals` กัน timing attack)
13. **OpenRouter ล่ม / timeout / API key ผิด:** ตอบ 503 + ข้อความสุภาพให้ผู้ใช้, บันทึก `api_usage_logs.status='error'`, ไม่คืน stack trace
14. **ไฟล์ Google Drive เสีย / PDF อ่านไม่ออก:** `sync_status='error'` + `error_message` และ **ต้องไม่หยุด** การ sync ไฟล์ที่เหลือ
15. **ไฟล์ถูกลบจาก Drive:** ตั้ง `is_active=0` — ห้ามลบ chunk ทิ้งถาวร (กันข้อมูลหายเพราะกดผิด)
16. **Login: email อยู่นอก `MICROSOFT_ALLOWED_DOMAINS`:** 403 "Domain ของ Email ไม่ได้รับอนุญาต" ไม่สร้าง user ใน DB
17. **Login: CAMS ตอบ `authorized=false` หรือ CAMS ล่ม:** แสดงหน้า Access Denied / ข้อความ "ระบบสิทธิ์ไม่พร้อมใช้งาน" — ห้าม fallback เป็นให้สิทธิ์เข้าใช้
18. **viewer พยายามยิง POST ตรงไปที่ admin API:** 403 ทุกครั้ง (permission check ที่ server ไม่ใช่แค่ซ่อนปุ่มใน UI)
19. **CSRF:** POST ที่ไม่มี/ผิด token → 403
20. **Export CSV ที่ค่าขึ้นต้นด้วย `=`, `+`, `-`, `@`:** ต้อง escape กัน formula injection ใน Excel
21. **Session หมดอายุ (> `ADMIN_SESSION_LIFETIME`):** redirect ไป login พร้อมข้อความ "Session หมดอายุ" ไม่ใช่หน้า error เปล่า

---

## Acceptance Criteria

เขียนไว้เพื่อให้ **เครื่อง** เป็นผู้ตัดสินว่าผ่านหรือไม่ ไม่ใช่คนดูแล้วรู้สึกว่าโอเค · ทุก test ตั้งชื่อตาม criterion ของมัน

### Chat & RAG
| ID | Criterion | Check |
|---|---|---|
| AC-1 | ส่ง `message` ปกติ → 200 และ `data.reply` ยาว > 0, `session_id` เป็น UUID v4 | `ChatApiTest::test_valid_message_returns_200_with_reply` |
| AC-2 | `message` ว่าง → 400 + `error.field='message'`; เกิน 2000 ตัวอักษร → 400 | `ChatApiTest::test_invalid_message_returns_400_with_field_error` |
| AC-3 | KB ว่าง / similarity ต่ำกว่า threshold → `is_fallback=true` และ reply = fallback_message ตรงตัว | `RagFallbackTest::test_no_match_returns_fallback_and_flags_row` |
| AC-4 | ทุกคำตอบที่ไม่ fallback ต้องมี `knowledge_chunks_used` ไม่ว่าง (ตรวจที่มาของคำตอบได้) | `RagFallbackTest::test_answer_records_source_chunks` |
| AC-5 | Accuracy บนชุดคำถาม 50 ข้อ ≥ 80% (assert คำสำคัญที่ต้องมีในคำตอบ) | `RagAccuracyTest::test_qa_set_accuracy_at_least_80_percent` |
| AC-6 | ยิง 21 requests/นาที จาก IP เดียว → ครั้งที่ 21 ได้ 429 + `Retry-After` | `RateLimitTest::test_21st_request_in_a_minute_returns_429` |
| AC-7 | OpenRouter ล่ม (mock timeout) → 503, ไม่มี stack trace, `api_usage_logs.status='error'` | `ChatApiTest::test_ai_provider_failure_returns_503_and_logs_error` |
| AC-8 | ข้อความไทย 3,000 ตัวอักษรถูกนับ/ตัดด้วย mb_* ไม่มีตัวอักษรพัง | `ThaiTextTest::test_long_thai_text_uses_multibyte_functions` |

### Webhooks
| ID | Criterion | Check |
|---|---|---|
| AC-9 | FB verify: token ตรง → 200 + challenge; token ผิด → 403 | `FacebookWebhookTest::test_verify_token_mismatch_returns_403` |
| AC-10 | FB POST: signature ถูก → 200; ผิด/ไม่มี header → 403 และไม่มีแถวใหม่ใน `messages` | `FacebookWebhookTest::test_invalid_signature_returns_403_without_db_write` |
| AC-11 | FB event `is_echo=true` → ไม่สร้าง message ใหม่ | `FacebookWebhookTest::test_echo_event_is_skipped` |
| AC-12 | LINE: signature ถูก → 200; ผิด → 403 | `LineWebhookTest::test_invalid_signature_returns_403` |

### Auth & Authorization
| ID | Criterion | Check |
|---|---|---|
| AC-13 | ไม่ login เข้า `/admin/index.php` → 302 ไป login; login แล้ว → 200 | `AdminAuthTest::test_dashboard_requires_login` |
| AC-14 | OAuth callback `state` ไม่ตรง → 403 และไม่สร้าง session | `OAuthCallbackTest::test_state_mismatch_returns_403` |
| AC-15 | email นอก allowed domain → 403 และไม่มีแถวใหม่ใน `admin_users` | `OAuthCallbackTest::test_disallowed_domain_creates_no_user` |
| AC-16 | CAMS ตอบ `authorized=false` → 403 Access Denied (ไม่ให้ผ่านเข้าระบบ) | `CamsAuthorizationTest::test_unauthorized_user_is_denied` |
| AC-17 | CAMS ตอบ `level_value` → map เป็น role: 2 = super_admin, 1 = admin, อื่น ๆ = viewer | `CamsAuthorizationTest::test_level_value_maps_to_role` |
| AC-18 | viewer ยิง POST admin API ทุกตัว → 403 ทุกครั้ง | `PermissionTest::test_viewer_cannot_post_to_admin_apis` |
| AC-19 | POST ที่ไม่มี CSRF token → 403 | `CsrfTest::test_post_without_token_returns_403` |
| AC-20 | Login ผิด 6 ครั้งใน 15 นาที → 429 | `LoginThrottleTest::test_sixth_failed_login_returns_429` |
| AC-21 | Session cookie มี HttpOnly + Secure + SameSite และ session id เปลี่ยนหลัง login | `SessionSecurityTest::test_cookie_flags_and_regeneration` |
| AC-22 | รหัสผ่านใน DB เป็น bcrypt (`$2y$`) เท่านั้น | `PasswordHashTest::test_password_is_bcrypt_hash` |

### Admin features
| ID | Criterion | Check |
|---|---|---|
| AC-23 | Sync models → คืน `{added, updated, deactivated}` และ model ที่หายไปจาก OpenRouter ถูกตั้ง `is_active=0` (ไม่ลบ) | `ModelSyncTest::test_missing_model_is_deactivated_not_deleted` |
| AC-24 | ตั้ง default model 2 ครั้งพร้อมกัน → เหลือ `is_default=1` แถวเดียว | `ModelDefaultTest::test_only_one_default_model_remains` |
| AC-25 | Cost = tokens × pricing จาก `ai_models` (คลาดเคลื่อนไม่เกิน 0.000001) | `CostCalculationTest::test_cost_matches_model_pricing` |
| AC-26 | `--aggregate` วันเดิม 2 ครั้ง → จำนวนแถวและตัวเลขเท่าเดิม | `AggregationTest::test_aggregate_is_idempotent` |
| AC-27 | Token usage API แยกยอดตาม platform และ model ตรงกับผลรวมใน `api_usage_logs` | `TokenAnalyticsTest::test_breakdown_matches_raw_logs` |
| AC-28 | สร้าง user ด้วย email ที่มีอยู่แล้ว → 409 | `UserManagementTest::test_duplicate_email_returns_409` |
| AC-29 | ทุกการเปลี่ยน settings/role/model มีแถวใน `admin_activity_logs` พร้อม old_value + new_value | `ActivityLogTest::test_changes_are_audited` |
| AC-30 | Export CSV: ค่า `=cmd()` ออกมาเป็น `'=cmd()` | `CsvExportTest::test_formula_injection_is_escaped` |
| AC-31 | หน้า Conversations/Knowledge ที่ไม่มีข้อมูล render `data-state-empty` | `EmptyStateTest::test_empty_list_renders_state_hook` (assert state hook ไม่ใช่ styling) |

### Knowledge pipeline
| ID | Criterion | Check |
|---|---|---|
| AC-32 | Sync PDF 1 ไฟล์ → `sync_status='synced'`, `chunk_count > 0`, ทุก chunk มี `embedding` ยาวเท่ากัน | `SyncPipelineTest::test_pdf_produces_chunks_with_embeddings` |
| AC-33 | ไฟล์เสีย 1 ไฟล์ → `sync_status='error'` + error_message และไฟล์อื่นยัง sync ต่อ | `SyncPipelineTest::test_broken_file_does_not_abort_run` |
| AC-34 | ไฟล์ถูกลบจาก Drive → `is_active=0`, chunk ยังอยู่ใน DB | `SyncPipelineTest::test_deleted_drive_file_is_deactivated` |
| AC-35 | Cosine similarity: เวกเตอร์เดียวกัน = 1.0, ตั้งฉาก = 0.0, มิติไม่เท่ากัน = 0.0 | `EmbeddingServiceTest::test_cosine_similarity_edge_values` |

### Deploy & security gate
| ID | Criterion | Check |
|---|---|---|
| AC-36 | `php vendor/bin/phpunit` ผ่านทั้ง Unit + Feature, exit 0 | คำสั่งเดียว จบ |
| AC-37 | live URL ตอบ 200 จากเครื่องสะอาด | `curl -s -o /dev/null -w "%{http_code}" https://appupili.up.ac.th/cbms/` = 200 |
| AC-38 | ไม่มีไฟล์ diagnostic/ทดสอบใน webroot | `test -f public/diag.php -o -n "$(ls public/test-*.php 2>/dev/null)" && exit 1` |
| AC-39 | ไม่มี secret ใน repo (API key, client secret, service account) | `git grep -nE "sk-or-v1-|BEGIN PRIVATE KEY|CLIENT_SECRET=[^\"]" -- . ':!*.example'` ต้องไม่เจอ |
| AC-40 | ไม่มี SQL ที่ต่อ string (ต้องเป็น prepared statement ทั้งหมด) | `git grep -nE "query\(.*\\\$" app/` ต้องไม่เจอ |
| AC-41 | Security checklist 15 ข้อ (§Tech Stack / promt.env) ผ่านครบ | รัน Skill `security-review` บน diff → ไม่มี finding ระดับ high |

---

## Definition of Done

- [ ] **Verification report เขียว:** ทุก criterion AC-1 … AC-41 มี check และผ่าน (`php vendor/bin/phpunit` exit 0)
- [ ] ทุก edge case ใน §Edge Cases มี test ที่ระบุชื่อกำกับไว้แล้ว (ไม่มีข้อไหนเหลือเป็น "จะดูตอน demo")
- [ ] ซ้อม demo sequence 3 core features แล้ว: (1) ถามผ่าน widget บนเว็บภายนอกได้คำตอบจาก KB (2) ถามผ่าน LINE ได้คำตอบเดียวกัน (3) แอดมิน login ด้วย Office 365 แล้วเห็น token/cost แยก platform
- [ ] Migration รันได้ทั้ง fresh install และรันซ้ำบน DB ที่มีข้อมูล (idempotent)
- [ ] Seed data พร้อม: system_settings 10 ค่า + super_admin 1 คน + ai_models เริ่มต้น
- [ ] `curl` production 200 และไม่มีไฟล์ diagnostic ใน webroot
- [ ] Code commit แล้ว (atomic commit), tests ผ่านทั้งหมด, manual testing เสร็จตาม checklist
- [ ] `.env.example` ครบทุกตัวแปร และ `.env` จริงไม่อยู่ใน repo

---

**Freeze:** 2026-08-18 — หลังจากนี้ห้ามเพิ่ม scope ใหม่ระหว่าง build แก้ได้เฉพาะผ่านการเปิด spec รอบใหม่
