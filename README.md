# Chatbot Management System (CBMS)

ระบบจัดการ AI Chatbot แบบ multi-platform สำหรับหน่วยงานในมหาวิทยาลัยพะเยา — ตอบคำถามจาก Knowledge Base ของหน่วยงานด้วยเทคนิค **RAG (Retrieval-Augmented Generation)** ให้บริการผ่าน **Web widget, Facebook Messenger และ LINE** พร้อม Admin Dashboard สำหรับจัดการความรู้ ดูสถิติ และคุมค่าใช้จ่าย AI

**Production:** https://appupili.up.ac.th/cbms/ · **Admin:** `/admin/login.php` (Microsoft Office 365 + CAMS)

---

## 📦 อะไรอยู่ใน repo นี้

| Path | คืออะไร |
|---|---|
| [`SPEC.md`](SPEC.md) | สเปคของระบบ — Business Context, Tech Stack, MoSCoW, Data Models, API Endpoints, Edge Cases, Acceptance Criteria (AC-1…AC-41), Definition of Done |
| [`cbms/`](cbms) | ซอร์สโค้ดระบบทั้งหมด (PHP 8.4 custom framework) |
| [`cbms/docs/master-prompt.md`](cbms/docs/master-prompt.md) | master prompt ที่ใช้ตั้งต้นโปรเจกต์ (spec ฉบับแรกสุด) |
| [`cbms/docs/PLAN-LLM-WIKI.md`](cbms/docs/PLAN-LLM-WIKI.md) | แผนงานชั้นความรู้แบบ LLM Wiki |

ทุก acceptance criterion ใน `SPEC.md` ระบุ "อะไรเป็นคนตัดสิน" ไว้เสมอ (ชื่อ test / คำสั่ง / SQL query) — เกณฑ์ที่เครื่องเช็คไม่ได้ไม่นับเป็นเกณฑ์

---

## ✨ ความสามารถหลัก

| กลุ่ม | รายละเอียด |
|---|---|
| **Multi-platform** | Web widget ฝังเว็บอื่นได้ 1 บรรทัด · Facebook Messenger webhook · LINE Messaging API webhook — ใช้ Knowledge Base และการตั้งค่าชุดเดียวกัน |
| **Multi-bot** | หนึ่งระบบรองรับหลายหน่วยงาน แต่ละบอทแยก Knowledge Base / system prompt / โมเดล / สิทธิ์ (`?bot=slug`) |
| **RAG + Fallback** | ค้นความรู้ก่อนตอบ ถ้าไม่เจอข้อมูลที่เกี่ยวข้องจะตอบ fallback message ไม่ปล่อยให้โมเดลเดา (กัน hallucination) |
| **LLM Wiki layer** | LLM เรียบเรียงเอกสารเป็นหน้า Wiki + คำถามตัวอย่างต่อหน้า (doc2query) ต้องมีคนกด review ก่อน publish |
| **Semantic answer cache** | คำถามซ้ำ/คล้ายเดิมตอบจาก cache ไม่เสียค่า API · กด 👎 แล้ว cache แถวนั้นถูกล้างทันที |
| **Streaming** | ตอบทีละ token ผ่าน Server-Sent Events (มี fallback เป็น non-stream อัตโนมัติ) |
| **Knowledge pipeline** | ดึงไฟล์จาก Google Drive → แตกข้อความ (รวม PDF) → chunk → สร้าง embedding → ค้นด้วย cosine similarity + FULLTEXT ngram เป็น rescue tier |
| **Admin Dashboard** | 23 หน้า: dashboard, conversations, analytics, token usage, reports, knowledge, wiki, feedback, unanswered, handoff, bots, models, users, integration, settings |
| **Token & cost analytics** | บันทึก token/cost ต่อข้อความ แยกตาม platform และ model + สรุปรายวันแบบ pre-aggregated |
| **Auth & RBAC** | Microsoft OAuth2 (Azure AD) → ตรวจสิทธิ์กับ CAMS API ของมหาวิทยาลัย + local login · role ระดับระบบ (super_admin/admin/viewer) และระดับบอท (owner/editor/viewer) |
| **Human handoff** | ส่งต่อเจ้าหน้าที่ทางอีเมล/เบอร์โทรเมื่อบอทตอบไม่ได้ |

---

## 🏗 สถาปัตยกรรม

```mermaid
flowchart TB
    subgraph ช่องทางผู้ใช้
        W[Web widget]
        F[Facebook Messenger]
        L[LINE OA]
    end

    W --> API[public/api/chat.php]
    F --> FW[webhook-facebook.php]
    L --> LW[webhook-line.php]

    API --> CC[ChatController]
    FW --> FC[FacebookController]
    LW --> LC[LineController]

    CC --> AI[AIService · RAG]
    FC --> AI
    LC --> AI

    AI --> CACHE[(AnswerCache)]
    AI --> EMB[EmbeddingService]
    EMB --> KB[(knowledge_chunks<br/>wiki_questions)]
    AI --> OR[OpenRouterService]
    OR --> LLM[OpenRouter API<br/>GPT-4o / Gemini / Claude / Llama]

    DRIVE[Google Drive] --> SYNC[sync.php CLI]
    SYNC --> KB
    SYNC --> WIKI[WikiService · LLM wiki pages]
    WIKI --> KB

    ADMIN[Admin Dashboard] --> DB[(MySQL 8.0)]
    AI --> DB
    O365[Microsoft Azure AD] --> ADMIN
    CAMS[CAMS API] --> ADMIN
```

**ลำดับการตอบหนึ่งคำถาม:** ตรวจ rate limit → เช็ค answer cache → embed คำถาม (memoize + cache 30 วัน) → *Tier 1* เทียบกับคำถามตัวอย่างของหน้า Wiki → *Tier 2* ค้น chunk ด้วย cosine similarity → *Rescue* FULLTEXT ngram สำหรับคำเฉพาะ → ประกอบ prompt (system prompt + วันที่ไทย + สารบัญความรู้ + context + history) → เรียก LLM → บันทึก message/token/cost → ถ้าไม่เจอความรู้เลยตอบ fallback

---

## 🛠 Tech Stack

- **Backend:** PHP 8.4 (custom framework — ไม่ใช้ Laravel/Symfony)
- **Database:** MySQL 8.0 · `utf8mb4` · 20 ตาราง · migration 001–011
- **AI:** OpenRouter API (สลับโมเดลได้จากหน้า Admin โดยไม่แก้โค้ด)
- **Frontend:** TailwindCSS 3 + Alpine.js 3 + Chart.js 4 (CDN, ไม่มี build step) · widget เป็น vanilla JS
- **Auth:** `thenetworg/oauth2-azure` + `league/oauth2-client` + CAMS API (`program_code=CBMS`)
- **Integrations:** Google Drive API v3 · Facebook Graph API v19.0 · LINE Messaging API v2
- **Libraries:** `guzzlehttp/guzzle` · `smalot/pdfparser` · `ramsey/uuid` · `firebase/php-jwt` · `vlucas/phpdotenv`
- **Test:** PHPUnit 11 (`tests/Unit` 8 ไฟล์ · `tests/Feature` 5 ไฟล์)
- **Deploy:** IIS + PHP FastCGI (`web.config`, `responseBufferLimit=0` สำหรับ SSE) · งานตามเวลารันด้วย Windows Task Scheduler

---

## 📁 โครงสร้างโค้ด

```
cbms/
├── app/
│   ├── Controllers/     ChatController · FacebookController · LineController
│   ├── Services/        AIService (RAG) · OpenRouterService · EmbeddingService
│   │                    AnswerCacheService · WikiService · WikiPrompts
│   │                    GoogleDriveService · TokenAnalyticsService
│   │                    MicrosoftAuthService · EmailService · LogService
│   └── Helpers/         Auth · Database (PDO) · Response · RateLimiter
│                        Network (proxy-aware IP) · BotSettingsTrait
├── config/              app.php · database.php · services.php
├── database/
│   ├── migrations/      001–011 (core → multi-bot → security → features
│   │                    → handoff → login throttle → answer cache
│   │                    → wiki pages → hybrid search + query cache)
│   └── seeds/           ค่าตั้งต้น + demo data
├── public/
│   ├── admin/           23 หน้า + layouts + internal API
│   ├── api/             chat.php · webhook-facebook.php · webhook-line.php
│   ├── widget.js        embeddable chat widget
│   ├── demo.php         landing / demo chat
│   └── *_manual.html    คู่มือผู้ใช้ / คู่มือแอดมิน
├── tests/               Unit + Feature (PHPUnit)
├── scripts/             cron-build-wiki.ps1
└── sync.php             CLI: sync, embed, aggregate, wiki, re-embed
```

---

## 🚀 เริ่มใช้งาน

```bash
git clone https://github.com/Joesimikung99/Chatbot-Management-System.git
cd Chatbot-Management-System/cbms
composer install
cp .env.example .env      # แล้วกรอกค่าจริงทั้งหมด
```

สร้างฐานข้อมูลและรัน migration ตามลำดับ:

```bash
mysql -u root -p -e "CREATE DATABASE cbms CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
for f in database/migrations/*.sql; do mysql -u root -p cbms < "$f"; done
mysql -u root -p cbms < database/seeds/001_seed_data.sql
```

> รหัสผ่าน super admin ใน seed เป็น `CHANGE_ME_BCRYPT_HASH` ต้องสร้างเอง:
> `php -r "echo password_hash('YOUR_PASSWORD', PASSWORD_DEFAULT);"`

ตั้งค่าที่ต้องมีใน `.env` (ดูรายการเต็มใน `.env.example`): `DB_*` · `OPENROUTER_API_KEY` · `OPENROUTER_DEFAULT_MODEL` · `OPENROUTER_EMBEDDING_MODEL` · `MICROSOFT_CLIENT_ID/SECRET/TENANT_ID` · `MICROSOFT_ALLOWED_DOMAINS` · `CAMS_API_URL` + `CAMS_API_KEY` · `GOOGLE_APPLICATION_CREDENTIALS` + `GOOGLE_DRIVE_FOLDER_ID` · `FACEBOOK_*` · `LINE_*` · `RATE_LIMIT_PER_MINUTE/HOUR`

จากนั้น:

```bash
php sync.php --sync-models     # ดึงรายการโมเดลจาก OpenRouter
php sync.php                   # sync Google Drive → chunk → embed
php sync.php --test-ai         # ทดสอบว่าเรียก AI ได้
php vendor/bin/phpunit         # รัน test ทั้งหมด
```

ฝัง widget ในเว็บไซต์อื่น:

```html
<script src="https://appupili.up.ac.th/cbms/widget.js"
        data-bot="your-bot-slug"
        data-color="#4f46e5"
        data-logo="https://your-site/logo.png"
        data-icon-size="60"
        data-draggable="true"></script>
```

มีแค่ `src` ก็ทำงานได้ (จะใช้บอท `default`) · attribute อื่นเป็นตัวเลือกทั้งหมด

---

## ⌨️ CLI (`php sync.php`)

| คำสั่ง | หน้าที่ |
|---|---|
| `php sync.php` | sync Google Drive แล้ว embed ทุกอย่างที่ค้าง |
| `--sync-only` / `--source=ID` | sync ไม่ embed / ประมวลผลเฉพาะ source เดียว |
| `--sync-models` | รีเฟรชรายการโมเดล + ราคาจาก OpenRouter |
| `--aggregate` / `--aggregate-date=YYYY-MM-DD` | สรุปสถิติ token/cost รายวัน (รันเป็น cron) |
| `--test-ai` | ยิงข้อความทดสอบกับโมเดล default |
| `--build-wiki` | ให้ LLM ร่างหน้า Wiki จากเอกสารที่เปลี่ยน |
| `--publish-wiki=ID` \| `=all` | publish หน้า Wiki ที่ review แล้ว → สร้าง embedding |
| `--wiki-gaps [--wiki-days=N]` | ดูหัวข้อที่ผู้ใช้ถามแต่ยังไม่มีหน้า Wiki |
| `--re-embed-all` | re-embed ทุก chunk + คำถาม Wiki ด้วยโมเดลปัจจุบัน แล้วล้าง cache (ต้องรันหลังเปลี่ยน embedding model) |
| `--bot=ID` · `--list` · `--help` | จำกัดเฉพาะบอท / ดูรายการบอท / ดูวิธีใช้ |

---

## 🔌 API

| Endpoint | ใช้ทำอะไร | Response |
|---|---|---|
| `POST /api/chat.php?bot=<slug>` | ส่งคำถาม | `200` + `{reply, session_id, is_fallback, tokens_total, response_time_ms, model_used}` · `400` · `429` |
| `POST /api/chat.php?bot=<slug>&action=stream` | ตอบแบบ SSE ทีละ token | `text/event-stream` → `{delta}` … `{done:true}` |
| `GET /api/chat.php?action=history&session_id=` | ประวัติบทสนทนา | `200` + array |
| `POST /api/chat.php?action=feedback` | 👍/👎 ต่อข้อความ | `200` (👎 ล้าง answer cache) |
| `GET /api/chat.php?action=config&bot=<slug>` | ค่าหน้าตา/ข้อความต้อนรับของบอท (widget เรียกตอนเปิด) | `200` + config |
| `POST /api/chat.php?action=handoff&bot=<slug>` | ขอคุยกับเจ้าหน้าที่ (ส่งอีเมลแจ้ง) | `200` · `400` ข้อมูลติดต่อไม่ครบ |
| `GET/POST /api/webhook-facebook.php?bot=<slug>` | verify / รับข้อความ FB | `200` · `403` ถ้า `X-Hub-Signature-256` ไม่ตรง |
| `POST /api/webhook-line.php?bot=<slug>` | รับ event LINE | `200` · `403` ถ้า `X-Line-Signature` ไม่ตรง |

ทุก response ใช้ envelope เดียวกัน: `{success, data?, error?}` · CORS จำกัดเฉพาะ `APP_URL` / `CORS_ALLOWED_ORIGINS` (ไม่ใช้ `*`) · rate limit ค่าเริ่มต้น 20/นาที และ 100/ชม. ต่อ IP

---

## 🔐 ความปลอดภัย

- HTTPS enforced + security headers (`X-Frame-Options`, `X-Content-Type-Options`, CSP)
- PDO prepared statements ทุก query · `htmlspecialchars()` ทุก output
- CSRF token ทุก POST · session `HttpOnly` + `Secure` + `SameSite` + regenerate หลัง login
- Login throttle (`admin_login_attempts`) · rate limit ทุก channel รวม FB/LINE
- Webhook signature ตรวจด้วย `hash_equals()` (กัน timing attack) ทั้ง Facebook และ LINE
- OAuth2 state parameter + จำกัด email domain + ตรวจสิทธิ์กับ CAMS ก่อนให้เข้าระบบ
- RBAC 2 ชั้น (ระบบ + ต่อบอท) และ permission flags แบบละเอียด
- Audit trail ทุกการเปลี่ยนแปลงของแอดมิน (`admin_activity_logs` เก็บ old/new value)
- Secret ทั้งหมดอยู่ใน `.env` เท่านั้น — ไม่มี key ใดใน repo

---

## 🚫 สิ่งที่ไม่ได้อยู่ใน repo (ตั้งใจไม่ push)

| ไม่มี | เหตุผล / ต้องทำอะไร |
|---|---|
| `.env`, `google-credentials.json` | credential จริง — สร้างจาก `.env.example` และวาง service-account JSON เองตาม path ใน env |
| `vendor/`, `node_modules/` | รัน `composer install` (และ `npm install` ถ้าจะใช้สคริปต์ screenshot) |
| `storage/logs/*` | log จาก production |
| `public/assets/manual/*.png` | ภาพประกอบคู่มือมีชื่อ-อีเมลเจ้าหน้าที่จริง (PII) — `admin_manual.html` / `user_manual.html` จะแสดงผลได้แต่รูปหาย |
| รหัสผ่าน super admin ใน seed | ถูกแทนด้วย `CHANGE_ME_BCRYPT_HASH` |

---

## 🏫 บริบทของโปรเจกต์

พัฒนาโดยงานเทคโนโลยีสารสนเทศ สถาบันนวัตกรรมการเรียนรู้ มหาวิทยาลัยพะเยา ใช้งานจริงบนเซิร์ฟเวอร์ของมหาวิทยาลัย · เอกสาร `SPEC.md` จัดทำในรูปแบบ spec-driven development (GSD) สำหรับ AI Accelerated Development Workshop & UP AI Hackathon 2026

**License:** ใช้งานภายใน — มหาวิทยาลัยพะเยา
