# 🤖 AI Chatbot System — CBMS

> ระบบ AI Chatbot แบบ Multi-Platform สำหรับมหาวิทยาลัยพะเยา  
> รองรับ Web Chat, Facebook Messenger และ LINE Official Account  
> พร้อม RAG Engine, Knowledge Base, และ Admin Dashboard แบบครบวงจร

**URL:** https://appupili.up.ac.th/cbms/  
**Admin:** https://appupili.up.ac.th/cbms/admin/login.php

---

## 🛠️ Tech Stack

| Layer | Technology |
|-------|-----------|
| **Language** | PHP 8.4 |
| **AI Engine** | OpenRouter API (Claude, GPT-4o, Gemini, Llama, etc.) |
| **RAG** | Cosine Similarity + Custom Chunking |
| **Auth** | Microsoft OAuth2 (Azure AD) + CAMS API |
| **Database** | MySQL 8.0+ |
| **Frontend** | TailwindCSS 3 + Alpine.js 3 + Chart.js 4 |
| **File Storage** | Google Drive API |
| **Web Server** | Apache (mod_rewrite) |
| **Platforms** | Web, Facebook Messenger, LINE Messaging API |

---

## 📁 Project Structure

```
z:\cbms\
├── app/
│   ├── Controllers/
│   │   ├── ChatController.php       # Web chat API
│   │   ├── FacebookController.php   # FB Messenger
│   │   └── LineController.php       # LINE Messaging
│   ├── Helpers/
│   │   ├── Auth.php                 # Session, permissions, CSRF
│   │   ├── Database.php             # PDO Singleton
│   │   └── Response.php             # JSON response
│   └── Services/
│       ├── AIService.php            # RAG pipeline
│       ├── OpenRouterService.php    # OpenRouter API
│       ├── EmbeddingService.php     # Vector search
│       ├── GoogleDriveService.php   # Drive file sync
│       ├── MicrosoftAuthService.php # OAuth2 + CAMS
│       ├── TokenAnalyticsService.php# Dashboard stats
│       └── LogService.php           # Logging
├── config/
│   ├── app.php                      # App configuration
│   ├── database.php                 # DB config
│   └── services.php                 # API keys config
├── database/
│   ├── migrations/
│   │   └── 001_create_all_tables.sql
│   └── seeds/
│       ├── 001_seed_data.sql
│       └── 002_seed_demo_data.sql
├── public/
│   ├── admin/
│   │   ├── login.php               # Login page
│   │   ├── auth-callback.php       # OAuth2 callback
│   │   ├── logout.php
│   │   ├── index.php               # Dashboard
│   │   ├── conversations.php
│   │   ├── analytics.php
│   │   ├── token-usage.php
│   │   ├── knowledge.php
│   │   ├── models.php
│   │   ├── users.php
│   │   ├── settings.php
│   │   ├── 403.php
│   │   └── layouts/
│   │       ├── header.php          # Sidebar + Topbar
│   │       └── footer.php          # Toast + JS helpers
│   ├── api/
│   │   ├── chat.php                # Web Chat API
│   │   ├── webhook-facebook.php    # FB Webhook
│   │   └── webhook-line.php        # LINE Webhook
│   ├── demo.php                    # Landing/Demo page
│   ├── index.php                   # → redirect to demo
│   ├── widget.js                   # Embeddable widget
│   └── .htaccess                   # Security + routing
├── storage/
│   └── logs/
├── sync.php                        # CLI tools
├── test-api.sh                     # API tests
├── composer.json
└── .env.example
```

---

## ⚡ Quick Start

### 1. Clone & Install

```bash
cd z:\cbms
composer install
cp .env.example .env
```

### 2. แก้ไข .env

```env
# Database
DB_HOST=localhost
DB_NAME=cbms_chatbot
DB_USER=root
DB_PASS=yourpassword

# OpenRouter (ดูที่ https://openrouter.ai/keys)
OPENROUTER_API_KEY=sk-or-v1-xxxxxxxx

# Microsoft OAuth (Azure AD App Registration)
MICROSOFT_CLIENT_ID=xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx
MICROSOFT_CLIENT_SECRET=xxxxxxxx
MICROSOFT_TENANT_ID=xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx

# Google Drive (Service Account JSON path)
GOOGLE_SERVICE_ACCOUNT_JSON=config/google-service-account.json
GOOGLE_DRIVE_FOLDER_ID=xxxxxxxx
```

### 3. Setup Database

```bash
# สร้างฐานข้อมูล
mysql -u root -p -e "CREATE DATABASE cbms_chatbot CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

# รัน Migrations
mysql -u root -p cbms_chatbot < database/migrations/001_create_all_tables.sql
mysql -u root -p cbms_chatbot < database/seeds/001_seed_data.sql
mysql -u root -p cbms_chatbot < database/seeds/002_seed_demo_data.sql
```

### 4. Sync AI Models

```bash
php sync.php --sync-models
```

### 5. เข้าสู่ Admin Dashboard

```
URL:      https://appupili.up.ac.th/cbms/admin/login.php
Username: admin
Password: Passw0rd!
```
> ⚠️ เปลี่ยน Password ทันทีหลัง Login ครั้งแรก

---

## 🤖 CLI Tools (`sync.php`)

```bash
# Sync AI models จาก OpenRouter
php sync.php --sync-models

# Sync ไฟล์จาก Google Drive + Embed
php sync.php --sync-drive

# Embed ไฟล์ทั้งหมดที่ pending
php sync.php --embed-all

# Aggregate token analytics (run ด้วย cron)
php sync.php --aggregate

# ทดสอบ AI
php sync.php --test-ai "มหาวิทยาลัยพะเยาอยู่ที่ไหน"
```

---

## 📱 Platform Setup

### Web Chat Widget

ฝังในเว็บไซต์ใดก็ได้:
```html
<script src="https://appupili.up.ac.th/cbms/widget.js"></script>
```

### Facebook Messenger

1. สร้าง Facebook App ที่ [developers.facebook.com](https://developers.facebook.com)
2. ตั้งค่า Webhook URL: `https://appupili.up.ac.th/cbms/api/webhook-facebook.php`
3. ใส่ Page Access Token, App Secret, Verify Token ใน Admin > Settings
4. Subscribe events: `messages`, `messaging_postbacks`

### LINE Official Account

1. สร้าง LINE Channel ที่ [developers.line.biz](https://developers.line.biz)
2. ตั้งค่า Webhook URL: `https://appupili.up.ac.th/cbms/api/webhook-line.php`
3. ใส่ Channel Token และ Channel Secret ใน Admin > Settings

---

## 📊 Web API

### POST `/api/chat.php`

```json
// Request
{
  "message":    "มหาวิทยาลัยพะเยาอยู่ที่ไหน",
  "session_id": "w-abc123",
  "platform":   "web"
}

// Response
{
  "success": true,
  "data": {
    "reply":       "มหาวิทยาลัยพะเยาตั้งอยู่ที่...",
    "session_id":  "w-abc123",
    "tokens_total": 234,
    "is_fallback": false,
    "model_used":  "claude-3.5-sonnet"
  }
}
```

---

## ⏰ Cron Jobs

```bash
# Daily token aggregation (23:59)
59 23 * * * cd /path/to/cbms && php sync.php --aggregate >> storage/logs/cron.log 2>&1

# Weekly Google Drive sync (Sunday 02:00)
0 2 * * 0 cd /path/to/cbms && php sync.php --sync-drive >> storage/logs/cron.log 2>&1
```

---

## 🔐 Security

- ✅ HTTPS enforced (.htaccess)
- ✅ CSRF Token (all POST forms)
- ✅ Session Fixation prevention (regenerate on login)
- ✅ HTTPOnly + Secure session cookies
- ✅ Microsoft OAuth2 + CAMS verification
- ✅ Role-based access control (Super Admin / Admin / Viewer)
- ✅ Facebook webhook signature verification (HMAC-SHA256)
- ✅ LINE webhook signature verification (HMAC-SHA256)
- ✅ Rate limiting (configurable)
- ✅ SQL Injection prevention (PDO prepared statements)
- ✅ XSS prevention (htmlspecialchars throughout)

---

## 📄 License

Internal use — มหาวิทยาลัยพะเยา © 2025
