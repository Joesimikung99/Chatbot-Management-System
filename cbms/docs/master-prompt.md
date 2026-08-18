Master Prompt V2 - AI Chatbot System (Updated)
# AI Chatbot System - Complete Project Specification V2
# Updated: เพิ่ม OpenRouter AI, Token Analytics, Office 365 Login, User Management

## Project Overview
สร้างระบบ AI Chatbot แบบ Multi-Platform ที่ใช้ Knowledge Base จาก Google Drive 
รองรับ 3 Platform คือ Web Chat Widget, Facebook Messenger, LINE Official Account
มีระบบ Admin Dashboard สำหรับดู Log, สถิติ, จัดการ AI Models ผ่าน OpenRouter
และ Login ด้วย Microsoft Office 365

## Tech Stack (ห้ามเปลี่ยน)
- Backend: PHP 8.4
- Database: MySQL 8.0
- Admin UI: TailwindCSS + Alpine.js
- Charts: Chart.js + ApexCharts
- PHP Packages (via Composer):
  - google/apiclient               ← Google Drive API
  - guzzlehttp/guzzle              ← HTTP Client (OpenRouter, LINE, FB)
  - vlucas/phpdotenv               ← Environment Variables
  - firebase/php-jwt               ← JWT Token
  - league/oauth2-client           ← OAuth2 Base
  - thenetworg/oauth2-azure        ← Microsoft Azure AD / Office 365
  - ramsey/uuid                    ← UUID Generator
- Frontend Widget: Vanilla JavaScript (ไม่ใช้ Framework)
- Admin JS: Alpine.js + Chart.js + ApexCharts

## Project Structure
/chatbot-system
├── /public                          ← Web Root
│   ├── index.php                    ← Landing / Demo Chat
│   ├── .htaccess
│   ├── /admin
│   │   ├── index.php                ← Dashboard
│   │   ├── login.php                ← Login Page (Office 365 + Local)
│   │   ├── auth-callback.php        ← OAuth2 Callback
│   │   ├── conversations.php        ← Conversation Logs
│   │   ├── analytics.php            ← Analytics & Stats
│   │   ├── token-usage.php          ← Token Usage by Platform ← NEW
│   │   ├── knowledge.php            ← Knowledge Base Manager
│   │   ├── models.php               ← AI Model Management ← NEW
│   │   ├── users.php                ← User Management ← NEW
│   │   └── settings.php             ← System Settings
│   ├── /api
│   │   ├── chat.php                 ← Web Chat API
│   │   ├── webhook-facebook.php     ← Facebook Webhook
│   │   └── webhook-line.php         ← LINE Webhook
│   └── /assets
│       ├── /widget                  ← Chat Widget
│       ├── /admin                   ← Admin Assets
│       └── /images
├── /app
│   ├── /Controllers
│   │   ├── ChatController.php
│   │   ├── FacebookController.php
│   │   ├── LineController.php
│   │   ├── AdminController.php
│   │   ├── KnowledgeController.php
│   │   ├── ModelController.php      ← NEW
│   │   └── UserController.php       ← NEW
│   ├── /Services
│   │   ├── AIService.php            ← RAG Engine
│   │   ├── OpenRouterService.php    ← OpenRouter API ← NEW
│   │   ├── GoogleDriveService.php
│   │   ├── FacebookService.php
│   │   ├── LineService.php
│   │   ├── EmbeddingService.php
│   │   ├── LogService.php
│   │   ├── TokenAnalyticsService.php ← NEW
│   │   └── MicrosoftAuthService.php  ← NEW
│   ├── /Models
│   │   ├── Conversation.php
│   │   ├── Message.php
│   │   ├── KnowledgeChunk.php
│   │   ├── AIModel.php              ← NEW
│   │   └── AdminUser.php            ← NEW
│   └── /Helpers
│       ├── Database.php
│       ├── Auth.php
│       └── Response.php
├── /config
│   ├── app.php
│   ├── database.php
│   └── services.php
├── /storage
│   ├── /logs
│   └── /cache
├── .env
├── .env.example
├── composer.json
└── README.md

---

## DATABASE SCHEMA (MySQL 8.0) - FULL SCHEMA V2

### Table: platforms
```sql
CREATE TABLE platforms (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  name        ENUM('web','facebook','line') NOT NULL,
  is_active   TINYINT DEFAULT 1,
  config      JSON,
  created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);



Table: ai_models ← NEW
sql
CREATE TABLE ai_models (
  id                  INT AUTO_INCREMENT PRIMARY KEY,
  openrouter_model_id VARCHAR(255) NOT NULL UNIQUE,
  display_name        VARCHAR(255) NOT NULL,
  provider_name       VARCHAR(100),
  description         TEXT,
  context_length      INT,
  pricing_prompt      DECIMAL(12,8) DEFAULT 0,
  pricing_completion  DECIMAL(12,8) DEFAULT 0,
  is_active           TINYINT DEFAULT 1,
  is_default          TINYINT DEFAULT 0,
  sort_order          INT DEFAULT 0,
  supports_vision     TINYINT DEFAULT 0,
  supports_tools      TINYINT DEFAULT 0,
  max_tokens          INT DEFAULT 4096,
  last_fetched_at     DATETIME,
  custom_notes        TEXT,
  created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_sort (sort_order, is_active)
);



Table: admin_users ← UPDATED (เพิ่ม Office 365)
sql
CREATE TABLE admin_users (
  id                    INT AUTO_INCREMENT PRIMARY KEY,
  username              VARCHAR(100) UNIQUE,
  email                 VARCHAR(255) NOT NULL UNIQUE,
  password              VARCHAR(255),
  display_name          VARCHAR(255),
  avatar_url            VARCHAR(500),
  role                  ENUM('super_admin','admin','viewer') DEFAULT 'viewer',
  auth_provider         ENUM('local','microsoft') DEFAULT 'local',
  microsoft_id          VARCHAR(255),
  microsoft_tenant_id   VARCHAR(255),
  microsoft_token       JSON,
  last_login_at         DATETIME,
  last_login_ip         VARCHAR(45),
  is_active             TINYINT DEFAULT 1,
  permissions           JSON,
  created_at            TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at            TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_microsoft_id (microsoft_id),
  INDEX idx_email (email)
);



Table: conversations
sql
CREATE TABLE conversations (
  id                  INT AUTO_INCREMENT PRIMARY KEY,
  session_id          VARCHAR(100) NOT NULL UNIQUE,
  platform            ENUM('web','facebook','line') NOT NULL,
  platform_user_id    VARCHAR(255),
  platform_username   VARCHAR(255),
  ai_model_id         INT,
  metadata            JSON,
  started_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  last_activity_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  message_count       INT DEFAULT 0,
  total_tokens        INT DEFAULT 0,
  total_cost_usd      DECIMAL(10,6) DEFAULT 0,
  is_resolved         TINYINT DEFAULT 0,
  INDEX idx_session (session_id),
  INDEX idx_platform (platform),
  INDEX idx_activity (last_activity_at),
  FOREIGN KEY (ai_model_id) REFERENCES ai_models(id) ON DELETE SET NULL
);



Table: messages
sql
CREATE TABLE messages (
  id                    INT AUTO_INCREMENT PRIMARY KEY,
  conversation_id       INT NOT NULL,
  role                  ENUM('user','assistant','system') NOT NULL,
  content               TEXT NOT NULL,
  tokens_prompt         INT DEFAULT 0,
  tokens_completion     INT DEFAULT 0,
  tokens_total          INT DEFAULT 0,
  response_time_ms      INT DEFAULT 0,
  ai_model_id           INT,
  knowledge_chunks_used JSON,
  is_fallback           TINYINT DEFAULT 0,
  feedback              ENUM('positive','negative') NULL,
  created_at            TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE,
  FOREIGN KEY (ai_model_id) REFERENCES ai_models(id) ON DELETE SET NULL,
  INDEX idx_conversation (conversation_id),
  INDEX idx_created (created_at)
);



Table: knowledge_sources
sql
CREATE TABLE knowledge_sources (
  id                    INT AUTO_INCREMENT PRIMARY KEY,
  google_drive_file_id  VARCHAR(255) UNIQUE NOT NULL,
  file_name             VARCHAR(500) NOT NULL,
  file_type             VARCHAR(50),
  mime_type             VARCHAR(100),
  google_drive_url      VARCHAR(500),
  last_modified         DATETIME,
  last_synced_at        DATETIME,
  sync_status           ENUM('pending','processing','synced','error') DEFAULT 'pending',
  chunk_count           INT DEFAULT 0,
  error_message         TEXT,
  is_active             TINYINT DEFAULT 1,
  created_at            TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at            TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);



Table: knowledge_chunks
sql
CREATE TABLE knowledge_chunks (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  source_id     INT NOT NULL,
  chunk_index   INT NOT NULL,
  content       TEXT NOT NULL,
  embedding     JSON,
  token_count   INT DEFAULT 0,
  metadata      JSON,
  created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (source_id) REFERENCES knowledge_sources(id) ON DELETE CASCADE,
  INDEX idx_source (source_id)
);



Table: token_usage_daily ← NEW (pre-aggregated สำหรับ performance)
sql
CREATE TABLE token_usage_daily (
  id                  INT AUTO_INCREMENT PRIMARY KEY,
  usage_date          DATE NOT NULL,
  platform            ENUM('web','facebook','line','all') NOT NULL,
  ai_model_id         INT,
  total_conversations INT DEFAULT 0,
  total_messages      INT DEFAULT 0,
  tokens_prompt       BIGINT DEFAULT 0,
  tokens_completion   BIGINT DEFAULT 0,
  tokens_total        BIGINT DEFAULT 0,
  cost_usd            DECIMAL(12,6) DEFAULT 0,
  avg_response_ms     INT DEFAULT 0,
  fallback_count      INT DEFAULT 0,
  created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_date_platform_model (usage_date, platform, ai_model_id),
  INDEX idx_date (usage_date),
  FOREIGN KEY (ai_model_id) REFERENCES ai_models(id) ON DELETE SET NULL
);



Table: api_usage_logs
sql
CREATE TABLE api_usage_logs (
  id                INT AUTO_INCREMENT PRIMARY KEY,
  conversation_id   INT,
  message_id        INT,
  platform          ENUM('web','facebook','line') NOT NULL,
  ai_model_id       INT,
  api_provider      ENUM('openrouter','openai','gemini','google_drive') NOT NULL,
  tokens_prompt     INT DEFAULT 0,
  tokens_completion INT DEFAULT 0,
  tokens_total      INT DEFAULT 0,
  cost_usd          DECIMAL(10,6) DEFAULT 0,
  response_time_ms  INT DEFAULT 0,
  status            ENUM('success','error') DEFAULT 'success',
  error_message     TEXT,
  created_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE SET NULL,
  FOREIGN KEY (ai_model_id) REFERENCES ai_models(id) ON DELETE SET NULL,
  INDEX idx_platform_date (platform, created_at),
  INDEX idx_model_date (ai_model_id, created_at),
  INDEX idx_created (created_at)
);



Table: system_settings
sql
CREATE TABLE system_settings (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  setting_key   VARCHAR(100) NOT NULL UNIQUE,
  setting_value TEXT,
  type          ENUM('string','json','boolean','integer','password') DEFAULT 'string',
  description   VARCHAR(500),
  updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  updated_by    INT,
  FOREIGN KEY (updated_by) REFERENCES admin_users(id) ON DELETE SET NULL
);



Table: admin_activity_logs ← NEW
sql
CREATE TABLE admin_activity_logs (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  user_id     INT,
  action      VARCHAR(100) NOT NULL,
  target_type VARCHAR(50),
  target_id   INT,
  old_value   JSON,
  new_value   JSON,
  ip_address  VARCHAR(45),
  user_agent  VARCHAR(500),
  created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES admin_users(id) ON DELETE SET NULL,
  INDEX idx_user (user_id),
  INDEX idx_created (created_at)
);



ENVIRONMENT VARIABLES (.env) - V2
ini
# App
APP_NAME="AI Chatbot System"
APP_URL="https://yourdomain.com"
APP_ENV="production"
APP_DEBUG=false
APP_SECRET_KEY="random-64-char-string"
APP_TIMEZONE="Asia/Bangkok"

# Database
DB_HOST="localhost"
DB_PORT="3306"
DB_DATABASE="chatbot_db"
DB_USERNAME="chatbot_user"
DB_PASSWORD="your_password"
DB_CHARSET="utf8mb4"

# ===== OpenRouter AI ← NEW =====
OPENROUTER_API_KEY="sk-or-v1-..."
OPENROUTER_BASE_URL="https://openrouter.ai/api/v1"
OPENROUTER_SITE_URL="https://yourdomain.com"
OPENROUTER_SITE_NAME="AI Chatbot System"
OPENROUTER_DEFAULT_MODEL="openai/gpt-4o-mini"
OPENROUTER_EMBEDDING_MODEL="openai/text-embedding-3-small"

# ===== Microsoft Office 365 ← NEW =====
MICROSOFT_CLIENT_ID="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx"
MICROSOFT_CLIENT_SECRET="your-client-secret"
MICROSOFT_TENANT_ID="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx"
MICROSOFT_REDIRECT_URI="https://yourdomain.com/admin/auth-callback.php"
MICROSOFT_ALLOWED_DOMAINS="company.com,subsidiary.com"

# Google Drive
GOOGLE_APPLICATION_CREDENTIALS="/path/to/service-account.json"
GOOGLE_DRIVE_FOLDER_ID="your-folder-id"

# Facebook
FACEBOOK_APP_ID=""
FACEBOOK_APP_SECRET=""
FACEBOOK_PAGE_ACCESS_TOKEN=""
FACEBOOK_VERIFY_TOKEN="random-verify-token"

# LINE
LINE_CHANNEL_ACCESS_TOKEN=""
LINE_CHANNEL_SECRET=""

# Admin
ADMIN_SESSION_NAME="chatbot_admin"
ADMIN_SESSION_LIFETIME=86400
ALLOW_LOCAL_LOGIN=true

# Rate Limiting
RATE_LIMIT_PER_MINUTE=20
RATE_LIMIT_PER_HOUR=100



NEW SERVICES SPECIFICATION
OpenRouterService.php ← NEW
Methods ที่ต้องมี:

1. fetchAvailableModels(): array
   - เรียก GET https://openrouter.ai/api/v1/models
   - Return array ของ models พร้อม pricing
   - Cache ผลลัพธ์ใน DB (table: ai_models)
   - อัพเดทข้อมูลเฉพาะที่เปลี่ยนแปลง

2. syncModelsToDatabase(): array
   - เรียก fetchAvailableModels()
   - เปรียบเทียบกับ ai_models ใน DB
   - Insert models ใหม่
   - Update pricing/info ที่เปลี่ยน
   - ไม่ลบ models เก่า (set is_active=0 ถ้าหาย)
   - Return summary {added, updated, deactivated}

3. chat($model, $messages, $options=[]): array
   - POST https://openrouter.ai/api/v1/chat/completions
   - Headers:
     Authorization: Bearer {OPENROUTER_API_KEY}
     HTTP-Referer: {OPENROUTER_SITE_URL}
     X-Title: {OPENROUTER_SITE_NAME}
   - Return {content, usage{prompt_tokens, completion_tokens, total_tokens}}

4. createEmbedding($text): array
   - ใช้ OpenRouter embedding endpoint
   - Return vector array

5. calculateCost($modelId, $promptTokens, $completionTokens): float
   - ดึง pricing จาก ai_models
   - คำนวณ cost_usd

6. getActiveModels(): array
   - ดึง models ที่ is_active=1 เรียงตาม sort_order

7. getDefaultModel(): ?array
   - ดึง model ที่ is_default=1

8. testModel($modelId): array
   - ทดสอบ model ด้วยข้อความสั้น
   - Return {success, response_time_ms, error?}



MicrosoftAuthService.php ← NEW
Methods ที่ต้องมี:

1. getAuthorizationUrl(): string
   - สร้าง URL สำหรับ Redirect ไป Microsoft Login
   - Scopes: openid, email, profile, offline_access
   - เก็บ state ใน Session เพื่อป้องกัน CSRF

2. handleCallback($code, $state): array
   - ตรวจสอบ state
   - แลก code เป็น Access Token
   - ดึงข้อมูล User จาก Microsoft Graph API
     GET https://graph.microsoft.com/v1.0/me
     Fields: id, displayName, mail, userPrincipalName, jobTitle, photo
   - Return {microsoft_id, email, display_name, avatar_url, tenant_id}

3. validateAllowedDomain($email): bool
   - เช็คว่า email domain อยู่ใน MICROSOFT_ALLOWED_DOMAINS
   - ถ้า MICROSOFT_ALLOWED_DOMAINS ว่าง = ทุก domain ผ่าน

4. findOrCreateUser($microsoftData): AdminUser
   - ค้นหา User จาก microsoft_id หรือ email
   - ถ้าไม่มี → สร้างใหม่ (role: viewer by default)
   - อัพเดท token และ last_login

5. refreshTokenIfNeeded($user): bool
   - ตรวจสอบ token หมดอายุหรือไม่
   - Refresh ถ้าจำเป็น

Flow การ Login:
1. User คลิก "Login with Microsoft"
2. Redirect ไป Microsoft Login Page
3. Microsoft Callback มาที่ /admin/auth-callback.php
4. ตรวจสอบ Domain
5. Find or Create User ใน DB
6. สร้าง PHP Session
7. Redirect ไป Dashboard



TokenAnalyticsService.php ← NEW
Methods ที่ต้องมี:

1. getUsageByPlatform($dateFrom, $dateTo): array
   - ดึงข้อมูล token usage แยกตาม platform
   - Return: [{platform, conversations, messages, 
               tokens_prompt, tokens_completion, tokens_total, cost_usd}]

2. getUsageByModel($dateFrom, $dateTo): array
   - ดึงข้อมูล token usage แยกตาม AI Model
   - Return: [{model_name, provider, conversations,
               tokens_total, cost_usd, avg_response_ms}]

3. getDailyTrend($dateFrom, $dateTo, $platform='all'): array
   - ดึง daily usage สำหรับ chart
   - Return: [{date, tokens_total, cost_usd, conversations}]

4. aggregateDailyStats($date): void
   - รวมข้อมูลจาก api_usage_logs → token_usage_daily
   - เรียกทุกวัน via Cron Job (23:59)
   - แยก platform และ model

5. getTopCostModels($limit=10): array
   - Top models ที่ใช้เงินมากสุด

6. getCostEstimate($currentMonth=true): array
   - ประมาณการค่าใช้จ่ายเดือนนี้
   - เทียบกับเดือนที่แล้ว



ADMIN PAGES SPECIFICATION - V2
Login Page (/public/admin/login.php)
Design:
- หน้ากลาง screen, Card กลาง
- Logo / ชื่อระบบ
- แบ่งเป็น 2 ส่วนชัดเจน

Section 1 - Microsoft Office 365 Login:
- ปุ่มใหญ่ "เข้าสู่ระบบด้วย Microsoft Office 365"
- Microsoft Logo สีฟ้า
- ข้อความ "แนะนำสำหรับผู้ใช้ภายในองค์กร"

Section 2 - Local Login (ถ้า ALLOW_LOCAL_LOGIN=true):
- Divider "หรือ"
- Form: Username/Email + Password
- ปุ่ม Login
- Link "ลืมรหัสผ่าน" (optional)

Error States:
- "Domain ของ Email ไม่ได้รับอนุญาต"
- "บัญชีถูกระงับการใช้งาน"
- "Username หรือ Password ไม่ถูกต้อง"
- "Session หมดอายุ กรุณา Login ใหม่"



AI Model Management (/public/admin/models.php) ← NEW
ส่วนที่ 1 - Header Actions:
- ปุ่ม "Sync Models จาก OpenRouter" 
  → เรียก OpenRouterService::syncModelsToDatabase()
  → แสดง loading + สรุปผล {เพิ่ม X, อัพเดท Y}
- ปุ่ม "ตั้งค่า OpenRouter API Key" → Modal
- Last synced: วันที่ sync ล่าสุด

ส่วนที่ 2 - Model Table:
Columns:
| # | ชื่อ Model | Provider | Context | ราคา Input | ราคา Output | สถานะ | Default | ทดสอบ | จัดการ |

- ลาก-วางเรียงลำดับ (drag & drop sort_order) ด้วย SortableJS
- ราคาแสดงเป็น $/1M tokens
- Toggle Active/Inactive (Switch)
- Radio Default (เลือกได้แค่ 1)
- ปุ่ม Test Model → Popup แสดง response + response time
- ปุ่ม Edit → Modal แก้ไข:
  * display_name (ชื่อที่แสดง)
  * max_tokens
  * custom_notes
  * is_active toggle
  * sort_order

ส่วนที่ 3 - Filter & Search:
- Search ชื่อ Model
- Filter by Provider (OpenAI, Anthropic, Google, Meta, ฯลฯ)
- Filter: Active Only / All
- Filter: Supports Vision / Supports Tools

ส่วนที่ 4 - Pricing Summary Card:
- แสดง Model ที่ถูกสุด / แพงสุด
- แสดง Default Model ปัจจุบัน

Modal - Edit Model:
- ชื่อ Model (openrouter_model_id) → Read Only
- Display Name → แก้ไขได้
- Max Tokens
- Custom Notes (หมายเหตุสำหรับ Admin)
- Active Toggle
- ปุ่ม Save



User Management (/public/admin/users.php) ← NEW
ส่วนที่ 1 - Stats Row:
- Total Users | Active | Microsoft Login | Local Login

ส่วนที่ 2 - User Table:
Columns:
| Avatar | ชื่อ | Email | Role | Login Type | Last Login | สถานะ | จัดการ |

- Avatar แสดง Microsoft Profile Photo (ถ้ามี) หรือ Initials
- Login Type: Microsoft Icon หรือ Local Icon
- Role Badge: Super Admin (แดง), Admin (ส้ม), Viewer (เทา)
- Last Login: relative time เช่น "2 ชั่วโมงที่แล้ว"
- สถานะ: Active/Inactive Toggle

Actions per user:
- Edit Role
- Reset Password (Local login เท่านั้น)
- View Activity Log
- Deactivate / Activate
- Delete (เฉพาะ Super Admin)

ส่วนที่ 3 - Add User Modal:
- ถ้า Microsoft: ค้นหาจาก Email (เพิ่มล่วงหน้า)
- ถ้า Local: Username + Email + Password + Role
- กำหนด Role
- กำหนด Permissions (JSON checkboxes)

Permissions System:
- view_dashboard
- view_conversations
- view_analytics
- view_token_usage
- manage_knowledge
- manage_models
- manage_users
- manage_settings
- export_data

ส่วนที่ 4 - Activity Log Tab:
- Log การเข้าสู่ระบบทั้งหมด (IP, Browser, เวลา)
- Log การเปลี่ยนแปลง Settings
- Log การ Sync Knowledge Base



Token Usage Analytics (/public/admin/token-usage.php) ← NEW
ส่วนที่ 1 - Date Range & Filter:
- Date Range Picker (Today, 7D, 30D, This Month, Custom)
- Filter by Platform (All / Web / Facebook / LINE)
- Filter by Model

ส่วนที่ 2 - Summary Cards (แถวบน):
┌─────────────┐ ┌─────────────┐ ┌─────────────┐ ┌─────────────┐
│ Total Tokens│ │  ค่าใช้จ่าย │ │ Input Tokens│ │Output Tokens│
│  1,234,567  │ │   $12.34    │ │   456,789   │ │   777,778   │
│ ↑ 15% vs   │ │ ↑ 12% vs   │ │             │ │             │
│ ช่วงก่อน   │ │ ช่วงก่อน   │ │             │ │             │
└─────────────┘ └─────────────┘ └─────────────┘ └─────────────┘

ส่วนที่ 3 - Platform Breakdown Table:
┌──────────┬──────────┬──────────┬──────────┬──────────┬──────────┐
│ Platform │ Sessions │ Messages │  Tokens  │ ค่าใช้จ่าย│  เฉลี่ย  │
│          │          │          │  (Total) │  (USD)   │Token/Msg │
├──────────┼──────────┼──────────┼──────────┼──────────┼──────────┤
│ 🌐 Web   │    245   │  1,203   │  456,789 │  $4.56   │   380    │
│ 📘 FB    │    123   │    567   │  234,567 │  $2.34   │   413    │
│ 💚 LINE  │    456   │  2,102   │  543,211 │  $5.43   │   258    │
├──────────┼──────────┼──────────┼──────────┼──────────┼──────────┤
│ รวม      │    824   │  3,872   │1,234,567 │ $12.33   │   318    │
└──────────┴──────────┴──────────┴──────────┴──────────┴──────────┘

ส่วนที่ 4 - Charts:
Chart 1 (Line Chart): Daily Token Usage แยก Platform 3 เส้น
  - X-axis: วันที่
  - Y-axis: จำนวน Tokens
  - 3 Lines: Web (น้ำเงิน), Facebook (น้ำเงินเข้ม), LINE (เขียว)

Chart 2 (Donut Chart): สัดส่วน Platform
  - Tokens by Platform
  - แสดง % และจำนวน

Chart 3 (Bar Chart): Token Cost by Model
  - X-axis: ชื่อ Model
  - Y-axis: ค่าใช้จ่าย (USD)
  - เรียงจากแพงสุด

Chart 4 (Area Chart): Daily Cost Trend
  - X-axis: วันที่
  - Y-axis: USD

ส่วนที่ 5 - Model Usage Table:
┌──────────────────┬──────────┬──────────┬──────────┬──────────┐
│ Model            │ Provider │  Tokens  │ Cost USD │ Req Count│
├──────────────────┼──────────┼──────────┼──────────┼──────────┤
│ gpt-4o-mini      │ OpenAI   │  890,123 │  $0.89   │  2,345   │
│ claude-3-haiku   │Anthropic │  234,567 │  $0.28   │    567   │
│ llama-3.1-70b    │ Meta     │  109,877 │  $0.05   │    345   │
└──────────────────┴──────────┴──────────┴──────────┴──────────┘

ส่วนที่ 6 - Cost Projection Card:
- ใช้ไปแล้วเดือนนี้: $X.XX
- ประมาณการสิ้นเดือน: $X.XX (อ้างอิงจาก daily avg)
- เทียบเดือนที่แล้ว: +/- X%
- Export Report ปุ่ม (CSV/Excel)



Dashboard (/public/admin/index.php) - UPDATED
ส่วนที่ 1 - Summary Cards Row 1:
┌──────────────┐ ┌──────────────┐ ┌──────────────┐ ┌──────────────┐
│Conversations │ │   Messages   │ │ Token วันนี้ │ │ Cost วันนี้  │
│  วันนี้: 45  │ │  วันนี้: 234 │ │   123,456    │ │    $0.12     │
│ เมื่อวาน: 38 │ │ เมื่อวาน:180 │ │ เมื่อวาน:98k │ │  เมื่อวาน:$0.09│
└──────────────┘ └──────────────┘ └──────────────┘ └──────────────┘

ส่วนที่ 2 - Platform Cards Row 2:
┌──────────────┐ ┌──────────────┐ ┌──────────────┐
│  🌐 Web Chat │ │📘 Facebook   │ │💚 LINE       │
│  23 sessions │ │  12 sessions │ │  10 sessions │
│  45 messages │ │  20 messages │ │  15 messages │
└──────────────┘ └──────────────┘ └──────────────┘

ส่วนที่ 3 - Charts Row:
- Left (60%): Line Chart - Conversations 30 วัน แยก Platform
- Right (40%): Donut - Platform Distribution

ส่วนที่ 4 - Bottom Row:
- Left (50%): Recent Conversations Table (10 รายการล่าสุด)
- Right (50%): 
  * Default AI Model ปัจจุบัน
  * Token Usage Today (mini bar chart)
  * Knowledge Base Status
  * Recent Admin Activity



Settings Page (/public/admin/settings.php) - UPDATED
Tabs:
1. AI & OpenRouter ← UPDATED
2. Platforms (Facebook / LINE)
3. Chat Appearance
4. Microsoft SSO ← NEW
5. System

Tab 1 - AI & OpenRouter:
Section A - OpenRouter:
- API Key (password field + Show/Hide + Test Connection)
- Site URL (for OpenRouter attribution)
- Site Name

Section B - Model Selection:
- Default Model: Dropdown (เลือกจาก ai_models ที่ is_active=1)
- Embedding Model: Dropdown
- ปุ่ม "จัดการ Models" → Link ไป /admin/models.php

Section C - AI Behavior:
- System Prompt (Textarea ใหญ่ พร้อม Token Counter)
- Temperature (Slider 0.0 - 2.0)
- Max Response Tokens
- Similarity Threshold (Slider 0.0 - 1.0)
- Top K Chunks (1-10)
- Conversation History Length (1-20 messages)
- Fallback Message (เมื่อตอบไม่ได้)

Tab 4 - Microsoft SSO ← NEW:
- Client ID
- Client Secret (password field)
- Tenant ID
- Redirect URI (Read Only - แสดง URL)
- Allowed Email Domains (comma separated)
- Allow Local Login Toggle
- ปุ่ม Test Microsoft Connection
- ปุ่ม View Setup Guide (Modal อธิบายวิธีตั้งค่า Azure AD)

Azure AD Setup Guide Modal:
1. ไปที่ portal.azure.com
2. App registrations → New registration
3. Redirect URI: {APP_URL}/admin/auth-callback.php
4. API Permissions: openid, email, profile
5.  Client ID, Tenant ID
6. Create Client Secret



OPENROUTER INTEGRATION DETAILS
Base URL: https://openrouter.ai/api/v1

Endpoints ที่ใช้:
1. GET  /models                    ← ดึงรายการ models ทั้งหมด
2. POST /chat/completions          ← ส่งคำถาม-รับคำตอบ
3. POST /embeddings                ← สร้าง embeddings (บาง models)

Required Headers:
Authorization: Bearer {OPENROUTER_API_KEY}
HTTP-Referer: {OPENROUTER_SITE_URL}
X-Title: {OPENROUTER_SITE_NAME}
Content-Type: application/json

Model ID Format ใน OpenRouter:
- openai/gpt-4o
- openai/gpt-4o-mini
- anthropic/claude-3-5-sonnet
- anthropic/claude-3-haiku
- google/gemini-flash-1.5
- meta-llama/llama-3.1-70b-instruct
- mistralai/mistral-7b-instruct

Pricing Format จาก API:
{
  "id": "openai/gpt-4o-mini",
  "name": "GPT-4o Mini",
  "pricing": {
    "prompt": "0.00000015",     ← ราคาต่อ 1 token (USD)
    "completion": "0.0000006"
  },
  "context_length": 128000,
  "top_provider": {
    "max_completion_tokens": 16384
  }
}

Chat Request Format:
{
  "model": "openai/gpt-4o-mini",
  "messages": [...],
  "max_tokens": 1000,
  "temperature": 0.7,
  "stream": false
}

Chat Response ที่ต้องอ่าน:
response.choices[0].message.content   ← คำตอบ
response.usage.prompt_tokens          ← tokens ที่ใช้
response.usage.completion_tokens
response.usage.total_tokens
response.model                        ← model ที่ใช้จริง



MICROSOFT OFFICE 365 AUTH FLOW
1. Setup Azure AD App:
   - Login: portal.azure.com
   - App registrations → New registration
   - Redirect URI: https://yourdomain.com/admin/auth-callback.php
   - API Permissions: Microsoft Graph
     * User.Read (Delegated)
     * email (Delegated)
     * openid (Delegated)
     * profile (Delegated)

2. OAuth2 Flow:
   
   /admin/login.php
   └─ คลิก "Login with Microsoft"
      └─ MicrosoftAuthService::getAuthorizationUrl()
         └─ Redirect → login.microsoftonline.com/{tenant_id}/oauth2/v2.0/authorize
            ?client_id={CLIENT_ID}
            &response_type=code
            &redirect_uri={REDIRECT_URI}
            &scope=openid email profile offline_access User.Read
            &state={random_state}
            
   /admin/auth-callback.php
   └─ รับ ?code=xxx&state=xxx
      └─ ตรวจสอบ state
         └─ POST login.microsoftonline.com/{tenant_id}/oauth2/v2.0/token
            └─ ได้ access_token, refresh_token
               └─ GET graph.microsoft.com/v1.0/me
                  └─ ได้ข้อมูล User
                     └─ validateAllowedDomain()
                        └─ findOrCreateUser()
                           └─ สร้าง PHP Session
                              └─ Redirect → /admin/index.php

3. User ที่ Login ผ่าน Microsoft ครั้งแรก:
   - สร้าง record ใหม่ใน admin_users
   - Role เริ่มต้น: 'viewer'
   - Super Admin ต้องเปลี่ยน Role ให้ทีหลัง
   - ถ้า email ตรงกับ Local user → merge account

4. Session Management:
   $_SESSION['admin_user'] = [
     'id'           => int,
     'email'        => string,
     'display_name' => string,
     'role'         => string,
     'permissions'  => array,
     'auth_provider'=> 'microsoft' | 'local',
     'avatar_url'   => string,
     'login_at'     => timestamp,
   ]



UI/UX DESIGN SPECIFICATIONS
Admin Layout
┌─────────────────────────────────────────────────────────┐
│ TOPBAR: Logo | Breadcrumb          | Avatar | Logout     │
├───────────┬─────────────────────────────────────────────┤
│           │                                             │
│  SIDEBAR  │            MAIN CONTENT                     │
│  (240px)  │                                             │
│           │                                             │
│ Dashboard │                                             │
│ Conversations                                           │
│ Analytics │                                             │
│ Token Usage                                             │
│ Knowledge │                                             │
│ ─────── │                                             │
│ Models    │                                             │
│ Users     │                                             │
│ Settings  │                                             │
│           │                                             │
└───────────┴─────────────────────────────────────────────┘

Sidebar Active State: Indigo background
Font: Sarabun (Google Fonts) - รองรับภาษาไทย
Icon Library: Heroicons (SVG inline)



Color System (TailwindCSS)
Primary:    indigo-600  (#4F46E5)
Secondary:  slate-600   (#475569)
Success:    emerald-500 (#10B981)
Warning:    amber-500   (#F59E0B)
Danger:     red-500     (#EF4444)
Info:       sky-500     (#0EA5E9)

Platform Colors:
Web:        indigo-500
Facebook:   blue-700    (#1877F2)
LINE:       emerald-500 (#06C755)



Component Standards
Cards:
- bg-white rounded-xl shadow-sm border border-slate-200
- Hover: shadow-md transition

Buttons:
- Primary: bg-indigo-600 hover:bg-indigo-700 text-white
- Secondary: bg-white border border-slate-300 hover:bg-slate-50
- Danger: bg-red-600 hover:bg-red-700 text-white
- Size: px-4 py-2 text-sm rounded-lg

Tables:
- Header: bg-slate-50 text-slate-600 text-xs uppercase
- Row Hover: hover:bg-slate-50
- Border: divide-y divide-slate-200

Badges:
- Super Admin: bg-red-100 text-red-700
- Admin: bg-amber-100 text-amber-700
- Viewer: bg-slate-100 text-slate-600
- Active: bg-emerald-100 text-emerald-700
- Inactive: bg-slate-100 text-slate-500

Forms:
- Input: border border-slate-300 rounded-lg px-3 py-2
        focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500
- Label: text-sm font-medium text-slate-700
- Error: text-red-600 text-sm mt-1



SECURITY REQUIREMENTS - V2
Authentication:
1. PHP Session ใช้ secure, httponly, samesite=Strict
2. Session Regeneration หลัง Login
3. CSRF Token ทุก Form (double submit cookie pattern)
4. Microsoft State Parameter ป้องกัน CSRF

Authorization:
5. Permission check ทุก Admin Action
6. Role-based access (Super Admin > Admin > Viewer)
7. Viewer ดูได้อย่างเดียว ห้ามแก้ไข

API Security:
8. OpenRouter API Key เก็บใน .env เท่านั้น ไม่แสดงใน UI เต็ม
9. Microsoft Client Secret เก็บใน .env
10. Facebook/LINE Signature Verification

General:
11. SQL Injection: PDO Prepared Statements ทุกที่
12. XSS: htmlspecialchars() ทุก output
13. Rate Limiting: API endpoints
14. HTTPS Only (Strict-Transport-Security header)
15. Admin Activity Log ทุกการกระทำสำคัญ



BUILD ORDER - V2
Step 1:  composer.json + packages install
Step 2:  .env + config files
Step 3:  Database migration (ทุก tables)
Step 4:  Database Helper + Response Helper
Step 5:  OpenRouterService (fetchModels, chat, embedding)
Step 6:  EmbeddingService (cosine similarity, chunk text)
Step 7:  AIService (RAG engine ใช้ OpenRouter)
Step 8:  LogService + TokenAnalyticsService
Step 9:  GoogleDriveService
Step 10: CLI sync script (php sync.php)
Step 11: ChatController + /api/chat.php
Step 12: ทดสอบ chat API ด้วย curl
Step 13: MicrosoftAuthService
Step 14: Admin Auth (login.php, auth-callback.php, Auth Helper)
Step 15: Admin Layout Template (sidebar, topbar)
Step 16: Dashboard Page
Step 17: AI Model Management Page
Step 18: User Management Page
Step 19: Token Usage Analytics Page
Step 20: Conversations Page
Step 21: Analytics Page
Step 22: Knowledge Base Page
Step 23: Settings Page (ทุก Tabs)
Step 24: Chat Widget (JS + CSS)
Step 25: Demo Page
Step 26: FacebookService + Controller + Webhook
Step 27: LineService + Controller + Webhook
Step 28: Integration Testing ทุก Platform
Step 29: Seed Data (Default settings, Super Admin user)
Step 30: README.md + Setup Guide



SEED DATA (Initial Setup)
sql
-- Default AI Models (จะ sync จาก OpenRouter จริง)
INSERT INTO ai_models VALUES
(1, 'openai/gpt-4o-mini', 'GPT-4o Mini', 'OpenAI', 
 'Fast and affordable', 128000, 0.00000015, 0.0000006, 
 1, 1, 1, 0, 1, 16384, NOW(), NULL, NOW(), NOW()),
(2, 'anthropic/claude-3-haiku', 'Claude 3 Haiku', 'Anthropic',
 'Fast and compact', 200000, 0.00000025, 0.00000125,
 1, 0, 2, 0, 1, 4096, NOW(), NULL, NOW(), NOW());

-- Default Settings
INSERT INTO system_settings (setting_key, setting_value, type, description) VALUES
('system_prompt', 'คุณเป็น AI Assistant ที่ช่วยตอบคำถาม...', 'string', 'System Prompt'),
('bot_name', 'AI Assistant', 'string', 'ชื่อ Bot'),
('welcome_message', 'สวัสดี มีอะไรให้ช่วยไหมครับ?', 'string', 'Welcome Message'),
('temperature', '0.7', 'string', 'AI Temperature'),
('max_tokens', '1000', 'integer', 'Max Response Tokens'),
('similarity_threshold', '0.7', 'string', 'Similarity Threshold'),
('top_k_chunks', '5', 'integer', 'Top K Chunks'),
('fallback_message', 'ขออภัย ไม่พบข้อมูลที่เกี่ยวข้อง...', 'string', 'Fallback Message'),
('primary_color', '#4F46E5', 'string', 'Widget Primary Color'),
('history_length', '10', 'integer', 'Conversation History Length');

-- Default Super Admin (Local)
INSERT INTO admin_users (username, email, password, display_name, role, auth_provider) VALUES
('superadmin', 'admin@yourdomain.com', 
 '$2y$12$[bcrypt_hash_of_Admin1234!]', 
 'Super Administrator', 'super_admin', 'local');



TESTING CHECKLIST - V2
OpenRouter Integration:
[ ] ดึงรายการ Models ได้
[ ] Sync Models ลง DB ได้
[ ] ส่งข้อความและรับคำตอบได้
[ ] Token usage ถูก log ครบ
[ ] Cost คำนวณถูกต้อง

Microsoft Auth:
[ ] ปุ่ม Login with Microsoft ทำงาน
[ ] Redirect ไป Microsoft ได้
[ ] Callback รับ code ได้
[ ] ดึงข้อมูล User จาก Graph API ได้
[ ] Domain validation ทำงาน
[ ] User ถูกสร้าง/อัพเดทใน DB
[ ] Session ถูกสร้าง
[ ] Redirect หลัง login ถูกต้อง

Model Management:
[ ] แสดงรายการ Models ได้
[ ] Sync จาก OpenRouter ได้
[ ] เปลี่ยน Default Model ได้
[ ] Drag & Drop เรียงลำดับได้
[ ] Test Model ทำงาน
[ ] Toggle Active/Inactive ได้

Token Analytics:
[ ] แสดงข้อมูลแยก Platform ได้
[ ] Chart แสดงถูกต้อง
[ ] Date Range filter ทำงาน
[ ] Export CSV ได้
[ ] Daily aggregation ทำงาน

User Management:
[ ] แสดงรายการ Users ได้
[ ] เพิ่ม User ได้ (Local + Microsoft)
[ ] เปลี่ยน Role ได้
[ ] Toggle Active/Inactive ได้
[ ] Permission check ทำงาน
[ ] Activity Log บันทึกครบ

เพิ่มเติมการเชื่อมต่อ office 365 เช็คสิทธ์ผ่านโปรแกรมกลาง  Office 365 + CAMS API - แนะนำการเชื่อมต่อ

 Flow ที่แนะนำ (ใช้ email เป็นหลัก)

STEP 1

User Login O365

Azure AD คืน email

STEP 2

ส่ง email + API Key

POST /auth/verify

STEP 3

ได้ข้อมูล + สิทธิ์

เก็บลง Session

ตัวอย่าง
<?php
/**
 * ตัวอย่าง: Login O365 แล้วเช็คสิทธิ์กับ CAMS
 * โปรแกรม: CBMS
 */
session_start();

// ============================================
// STEP 1: หลัง Login O365 callback ได้ email มา
// ============================================
$emailFromO365 = $azureUser['mail'];  // email จาก Azure AD
// เช่น "somchai.j@up.ac.th"

// ============================================
// STEP 2: ส่ง email ไปเช็คสิทธิ์กับ CAMS
// ============================================
$apiKey = getenv('CAMS_API_KEY'); // ดึงจาก .env — อย่า hardcode ลงโค้ด

$ch = curl_init('https://appupili.up.ac.th/cams/api/v1/auth/verify');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => json_encode([
        'email'        => $emailFromO365,
        'program_code' => 'CBMS',
    ]),
    CURLOPT_HTTPHEADER => [
        'Authorization: Bearer ' . $apiKey,
        'Content-Type: application/json',
    ],
]);

$response = curl_exec($ch);
curl_close($ch);
$result = json_decode($response, true);

// ============================================
// STEP 3: เช็คผลลัพธ์ → เก็บ session
// ============================================
if (!$result['success'] || !$result['authorized']) {
    // ไม่มีสิทธิ์ → แสดงหน้า Access Denied
    die('คุณไม่มีสิทธิ์เข้าใช้ระบบ CBMS');
}

// เก็บข้อมูลลง session (ใช้ได้ทั้งระบบ)
$_SESSION['user'] = $result['user'];
/*  ข้อมูลที่ได้:
    $result['user']['user_id']        → 42
    $result['user']['email']          → "somchai.j@up.ac.th"
    $result['user']['display_name']   → "สมชาย ใจดี"
    $result['user']['dept_code']      → "ICT"
    $result['user']['dept_name_th']   → "งานเทคโนโลยีฯ"
*/

$_SESSION['permission'] = $result['permission'];
/*  ข้อมูลสิทธิ์:
    $result['permission']['level_code']   → "admin"
    $result['permission']['level_name']   → "ผู้ดูแลระบบ"
    $result['permission']['level_value']  → 99
*/

// redirect ไปหน้าหลัก
header('Location: /dashboard');
exit;


// ============================================
// ใช้ในหน้าอื่น ๆ: เช็คสิทธิ์จาก session
// ============================================
function requireLevel($minLevel) {
    $level = $_SESSION['permission']['level_value'] ?? 0;
    if ($level < $minLevel) {
        die('สิทธิ์ไม่เพียงพอ');
    }
}

// ตัวอย่าง: เฉพาะ admin (level >= 1) เท่านั้น
requireLevel(1);
echo "ยินดีต้อนรับ " . $_SESSION['user']['display_name'];
สิทธ์ที่กำหนดมี 
Super Admin = 2 ทำได้ทุกอย่างกำหนดค่า api model
admin = กำหนดค่าเบื้องต้น


HOW TO USE WITH CLAUDE CODE
วิธีใช้ Prompt นี้:

ครั้งที่ 1 → วาง Prompt ทั้งหมด แล้วพิมพ์:
"เริ่ม Step 1-4 ก่อน: ตั้งค่า Project, composer.json, 
.env.example, Database Migration SQL ทั้งหมด, 
Database Helper และ Response Helper"

ครั้งที่ 2 → พิมพ์:
"ทำ Step 5-12: OpenRouterService, EmbeddingService, 
AIService (RAG), LogService, TokenAnalyticsService, 
GoogleDriveService, ChatController, /api/chat.php"

ครั้งที่ 3 → พิมพ์:
"ทำ Step 13-15: MicrosoftAuthService, Admin Login page,
auth-callback.php, Auth Helper, Admin Layout Template"

ครั้งที่ 4 → พิมพ์:
"ทำ Step 16-19: Dashboard, Models Management, 
User Management, Token Usage Analytics pages"

ครั้งที่ 5 → พิมพ์:
"ทำ Step 20-23: Conversations, Analytics, 
Knowledge Base, Settings pages"

ครั้งที่ 6 → พิมพ์:
"ทำ Step 24-30: Chat Widget, Demo Page, 
Facebook Webhook, LINE Webhook, Seed Data, README"

💡 Tips:
- ถ้าต้องการแก้ไขส่วนใด บอก Step number
- ถ้า Context หลุด ให้ paste Prompt ใหม่
- บอก domain จริงก่อนเริ่ม เช่น "APP_URL=https://chat.company.com"
- บอก Microsoft Tenant ID ถ้ามี เช่น Single Tenant หรือ Multi Tenant




---

> 💡 **Prompt นี้ครอบคลุมทุกส่วนที่เพิ่มมา พร้อมใช้งานกับ Claude Code ได้เลยครับ หากต้องการปรับ Domain, สี, หรือ Feature เพิ่มเติม บอกได้เลย**

