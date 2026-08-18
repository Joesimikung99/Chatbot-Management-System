<?php
define('BASE_PATH', dirname(__DIR__, 2));
require_once BASE_PATH . '/vendor/autoload.php';
use Dotenv\Dotenv;
use App\Helpers\Auth;
use App\Helpers\Response;
use App\Helpers\Database;
use App\Services\LogService;
use App\Services\EmailService;

$dotenv = Dotenv::createImmutable(BASE_PATH);
$dotenv->safeLoad();
date_default_timezone_set($_ENV['APP_TIMEZONE'] ?? 'Asia/Bangkok');

$pageTitle  = 'Email Settings';
$breadcrumb = ['Admin', 'Email Settings'];
Auth::requirePermission('manage_settings');

$db     = Database::getInstance();
$logger = new LogService();

// ── AJAX ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Auth::validateCsrf()) Response::error('CSRF failed', 403);
    $action = $_POST['action'] ?? '';

    if ($action === 'save_email_settings') {
        $keys = ['mail_enabled','mail_host','mail_port','mail_username','mail_password',
                 'mail_from_address','mail_from_name','mail_encryption',
                 'mail_handoff_subject','mail_handoff_template'];
        $saved = 0;
        foreach ($keys as $key) {
            if (isset($_POST[$key])) {
                $val = trim($_POST[$key]);
                if ($key === 'mail_password' && $val === '••••••••') continue;
                $db->upsert('system_settings', ['setting_key' => $key, 'setting_value' => $val, 'updated_at' => date('Y-m-d H:i:s')]);
                $saved++;
            }
        }
        $logger->logActivity(Auth::get('id'), 'email_settings.save', 'system_setting', null, null, "{$saved} keys");
        Response::success(['saved' => $saved], "บันทึกการตั้งค่าอีเมลสำเร็จ ({$saved} รายการ)");
    }

    if ($action === 'test_email') {
        $to = trim($_POST['test_email_to'] ?? '');
        if (!$to || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            Response::error('กรุณากรอกอีเมลที่ถูกต้อง', 422);
        }
        try {
            $emailService = new EmailService();
            $missing = $emailService->getMissingConfig();
            if (!empty($missing)) {
                Response::error('กรุณากรอกข้อมูล SMTP ให้ครบก่อนทดสอบ: ' . implode(', ', $missing), 422);
            }
            $ok = $emailService->sendTestEmail($to);
            if ($ok) {
                $logger->logActivity(Auth::get('id'), 'email.test', 'email', null, null, $to);
                Response::success(null, "ส่งอีเมลทดสอบไปยัง {$to} สำเร็จ");
            } else {
                Response::error('ไม่สามารถส่งอีเมลได้ กรุณาตรวจสอบการตั้งค่า SMTP', 500);
            }
        } catch (\Throwable $e) {
            Response::error('SMTP Error: ' . $e->getMessage(), 500);
        }
    }

    if ($action === 'test_handoff_email') {
        $to = trim($_POST['test_email_to'] ?? '');
        if (!$to || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            Response::error('กรุณากรอกอีเมลที่ถูกต้อง', 422);
        }
        try {
            $emailService = new EmailService();
            $missing = $emailService->getMissingConfig();
            if (!empty($missing)) {
                Response::error('กรุณากรอกข้อมูล SMTP ให้ครบก่อนทดสอบ: ' . implode(', ', $missing), 422);
            }
            $ok = $emailService->sendTestHandoff($to);
            if ($ok) {
                Response::success(null, "ส่งอีเมลทดสอบ Handoff ไปยัง {$to} สำเร็จ");
            } else {
                Response::error('ไม่สามารถส่งอีเมลได้ กรุณาตรวจสอบการตั้งค่า SMTP', 500);
            }
        } catch (\Throwable $e) {
            Response::error('SMTP Error: ' . $e->getMessage(), 500);
        }
    }
}

// ── Load Settings ──────────────────────────────────────────────────────
$allSettings = $db->fetchAll("SELECT setting_key, setting_value FROM system_settings WHERE setting_key LIKE 'mail_%'");
$mail = array_column($allSettings, 'setting_value', 'setting_key');
function m(string $key, string $default = ''): string { global $mail; return htmlspecialchars($mail[$key] ?? $default); }
function mb2(string $key, bool $default = false): bool { global $mail; $v = $mail[$key] ?? ($default ? 'true' : 'false'); return in_array($v, ['true','1','yes'], true); }

require __DIR__ . '/layouts/header.php';
?>
<meta name="csrf-token" content="<?= Auth::csrfToken() ?>">

<div x-data="emailSettingsPage()" x-init="init()">

  <!-- Toast Banner -->
  <div x-show="banner" x-transition class="mb-5 p-4 rounded-xl text-sm flex items-center gap-2"
       :class="bannerOk ? 'bg-emerald-50 border border-emerald-200 text-emerald-700' : 'bg-red-50 border border-red-200 text-red-700'">
    <svg class="w-5 h-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
      <path x-show="bannerOk" stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
      <path x-show="!bannerOk" stroke-linecap="round" stroke-linejoin="round" d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z"/>
    </svg>
    <span x-text="banner"></span>
    <button @click="banner=''" class="ml-auto opacity-60 hover:opacity-100">&times;</button>
  </div>

  <div class="flex flex-wrap items-center justify-between gap-4 mb-6">
    <div>
      <h1 class="text-2xl font-bold text-slate-800">ตั้งค่าอีเมลแจ้งเตือน</h1>
      <p class="text-sm text-slate-500 mt-1">กำหนด SMTP Server สำหรับส่งอีเมลแจ้งเตือนเมื่อมีคำขอ Handoff</p>
    </div>
  </div>

  <!-- SMTP Settings -->
  <form @submit.prevent="saveSettings()">
    <div class="bg-white rounded-2xl border border-slate-100 divide-y divide-slate-50 mb-5">

      <!-- Enable -->
      <div class="p-6 flex items-center justify-between">
        <div>
          <p class="text-sm font-semibold text-slate-800">เปิดใช้งานอีเมลแจ้งเตือน</p>
          <p class="text-xs text-slate-400">เมื่อเปิด ระบบจะส่งอีเมลเมื่อมี Handoff request ใหม่</p>
        </div>
        <input type="hidden" name="mail_enabled" value="false">
        <input type="checkbox" name="mail_enabled" value="true" <?= mb2('mail_enabled') ? 'checked' : '' ?>
               class="w-5 h-5 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500 cursor-pointer">
      </div>

      <!-- SMTP Config -->
      <div class="p-6">
        <h3 class="text-sm font-bold text-slate-800 mb-4 flex items-center gap-2">
          <svg class="w-4 h-4 text-indigo-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
          </svg>
          SMTP Server
        </h3>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div>
            <label class="block text-sm font-semibold text-slate-700 mb-1">SMTP Host</label>
            <input type="text" name="mail_host" value="<?= m('mail_host') ?>" placeholder="smtp.gmail.com"
                   class="w-full px-4 py-2.5 rounded-xl border border-slate-200 text-sm focus:ring-2 focus:ring-indigo-500 outline-none">
          </div>
          <div>
            <label class="block text-sm font-semibold text-slate-700 mb-1">SMTP Port</label>
            <input type="number" name="mail_port" value="<?= m('mail_port', '587') ?>" placeholder="587"
                   class="w-full px-4 py-2.5 rounded-xl border border-slate-200 text-sm focus:ring-2 focus:ring-indigo-500 outline-none">
          </div>
          <div>
            <label class="block text-sm font-semibold text-slate-700 mb-1">Username / Email</label>
            <input type="text" name="mail_username" value="<?= m('mail_username') ?>" placeholder="noreply@example.com"
                   class="w-full px-4 py-2.5 rounded-xl border border-slate-200 text-sm focus:ring-2 focus:ring-indigo-500 outline-none">
          </div>
          <div>
            <label class="block text-sm font-semibold text-slate-700 mb-1">Password</label>
            <input type="password" name="mail_password" value="<?= !empty($mail['mail_password']) ? '••••••••' : '' ?>" placeholder="App Password"
                   class="w-full px-4 py-2.5 rounded-xl border border-slate-200 text-sm focus:ring-2 focus:ring-indigo-500 outline-none">
          </div>
          <div>
            <label class="block text-sm font-semibold text-slate-700 mb-1">Encryption</label>
            <select name="mail_encryption" class="w-full px-4 py-2.5 rounded-xl border border-slate-200 text-sm focus:ring-2 focus:ring-indigo-500 outline-none">
              <option value="tls" <?= m('mail_encryption','tls') === 'tls' ? 'selected' : '' ?>>TLS (Port 587)</option>
              <option value="ssl" <?= m('mail_encryption') === 'ssl' ? 'selected' : '' ?>>SSL (Port 465)</option>
              <option value="none" <?= m('mail_encryption') === 'none' ? 'selected' : '' ?>>None (Port 25)</option>
            </select>
          </div>
        </div>
      </div>

      <!-- Sender Info -->
      <div class="p-6">
        <h3 class="text-sm font-bold text-slate-800 mb-4">ข้อมูลผู้ส่ง</h3>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div>
            <label class="block text-sm font-semibold text-slate-700 mb-1">From Email</label>
            <input type="text" name="mail_from_address" value="<?= m('mail_from_address') ?>" placeholder="noreply@example.com (ค่าเริ่มต้น: ใช้ Username)"
                   class="w-full px-4 py-2.5 rounded-xl border border-slate-200 text-sm focus:ring-2 focus:ring-indigo-500 outline-none">
          </div>
          <div>
            <label class="block text-sm font-semibold text-slate-700 mb-1">From Name</label>
            <input type="text" name="mail_from_name" value="<?= m('mail_from_name', 'CBMS Notification') ?>" placeholder="CBMS Notification"
                   class="w-full px-4 py-2.5 rounded-xl border border-slate-200 text-sm focus:ring-2 focus:ring-indigo-500 outline-none">
          </div>
        </div>
      </div>
    </div>

    <div class="flex justify-end mb-8">
      <button type="submit" :disabled="saving"
              class="px-6 py-2.5 rounded-xl bg-indigo-600 text-white font-semibold text-sm hover:bg-indigo-700 disabled:opacity-60 transition-colors">
        <span x-text="saving ? 'กำลังบันทึก...' : 'บันทึกการตั้งค่า SMTP'"></span>
      </button>
    </div>
  </form>

  <!-- Test Email Section -->
  <div class="bg-white rounded-2xl border border-slate-100 p-6 mb-5">
    <h3 class="text-sm font-bold text-slate-800 mb-1 flex items-center gap-2">
      <svg class="w-4 h-4 text-emerald-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
      </svg>
      ทดสอบส่งอีเมล
    </h3>
    <p class="text-xs text-slate-400 mb-4">ทดสอบว่า SMTP ทำงานถูกต้อง โดยส่งอีเมลทดสอบไปยังอีเมลที่กำหนด</p>
    <div class="flex flex-wrap gap-3 items-end">
      <div class="flex-1 min-w-[250px]">
        <label class="block text-sm font-semibold text-slate-700 mb-1">อีเมลปลายทาง</label>
        <input type="email" x-model="testEmail" placeholder="your@email.com"
               class="w-full px-4 py-2.5 rounded-xl border border-slate-200 text-sm focus:ring-2 focus:ring-indigo-500 outline-none">
      </div>
      <button @click="sendTest('test_email')" :disabled="testing"
              class="px-5 py-2.5 rounded-xl bg-emerald-600 text-white font-semibold text-sm hover:bg-emerald-700 disabled:opacity-60 transition-colors whitespace-nowrap">
        <span x-text="testing ? 'กำลังส่ง...' : 'ส่งอีเมลทดสอบ'"></span>
      </button>
      <button @click="sendTest('test_handoff_email')" :disabled="testing"
              class="px-5 py-2.5 rounded-xl bg-amber-500 text-white font-semibold text-sm hover:bg-amber-600 disabled:opacity-60 transition-colors whitespace-nowrap">
        <span x-text="testing ? 'กำลังส่ง...' : 'ทดสอบอีเมล Handoff'"></span>
      </button>
    </div>
  </div>

  <!-- Handoff Email Template -->
  <div class="bg-white rounded-2xl border border-slate-100 p-6 mb-5">
    <h3 class="text-sm font-bold text-slate-800 mb-1 flex items-center gap-2">
      <svg class="w-4 h-4 text-amber-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
      </svg>
      กำหนดข้อความแจ้งเตือน Handoff
    </h3>
    <p class="text-xs text-slate-400 mb-4">ปรับแต่งหัวข้อและเนื้อหาอีเมลเมื่อมีคำขอ Handoff ใหม่ ใช้ตัวแปร: <code class="bg-slate-100 px-1 rounded">{bot_name}</code> <code class="bg-slate-100 px-1 rounded">{contact_type}</code> <code class="bg-slate-100 px-1 rounded">{contact_id}</code> <code class="bg-slate-100 px-1 rounded">{message}</code> <code class="bg-slate-100 px-1 rounded">{datetime}</code> <code class="bg-slate-100 px-1 rounded">{admin_url}</code></p>

    <form @submit.prevent="saveTemplate()">
      <div class="space-y-4">
        <div>
          <label class="block text-sm font-semibold text-slate-700 mb-1">หัวข้ออีเมล (Subject)</label>
          <input type="text" name="mail_handoff_subject" value="<?= m('mail_handoff_subject', '[CBMS] คำขอคุยกับเจ้าหน้าที่ - {bot_name}') ?>"
                 class="w-full px-4 py-2.5 rounded-xl border border-slate-200 text-sm focus:ring-2 focus:ring-indigo-500 outline-none">
        </div>
        <div>
          <label class="block text-sm font-semibold text-slate-700 mb-1">เนื้อหาอีเมล (HTML Template)</label>
          <p class="text-xs text-slate-400 mb-2">หากเว้นว่าง จะใช้ Template เริ่มต้นของระบบ</p>
          <textarea name="mail_handoff_template" rows="8" placeholder="เว้นว่างเพื่อใช้ Template เริ่มต้น..."
                    class="w-full px-4 py-2.5 rounded-xl border border-slate-200 text-sm focus:ring-2 focus:ring-indigo-500 outline-none resize-y font-mono"><?= m('mail_handoff_template') ?></textarea>
        </div>
      </div>
      <div class="flex justify-end mt-4">
        <button type="submit" :disabled="savingTpl"
                class="px-6 py-2.5 rounded-xl bg-indigo-600 text-white font-semibold text-sm hover:bg-indigo-700 disabled:opacity-60 transition-colors">
          <span x-text="savingTpl ? 'กำลังบันทึก...' : 'บันทึก Template'"></span>
        </button>
      </div>
    </form>
  </div>

  <!-- Info: Per-bot email -->
  <div class="p-4 rounded-xl bg-blue-50 border border-blue-200 text-blue-800 text-sm flex items-start gap-3">
    <svg class="w-5 h-5 shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
      <path stroke-linecap="round" stroke-linejoin="round" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
    </svg>
    <div>
      <p class="font-semibold">อีเมลแจ้งเตือนรายบอท</p>
      <p class="mt-1">กำหนดอีเมลผู้รับแจ้งเตือนของแต่ละบอทได้ที่ <strong>Bots</strong> &rarr; แก้ไขบอท &rarr; แท็บ <strong>Widget</strong> &rarr; ส่วน Handoff</p>
    </div>
  </div>
</div>

<script>
const CSRF = document.querySelector('meta[name="csrf-token"]').content;

function emailSettingsPage() {
  return {
    saving: false,
    savingTpl: false,
    testing: false,
    banner: '',
    bannerOk: true,
    testEmail: '',

    init() {},

    async saveSettings() {
      this.saving = true; this.banner = '';
      const form = document.querySelector('form');
      const fd = new FormData(form);
      fd.append('action', 'save_email_settings');
      fd.append('_csrf_token', CSRF);
      try {
        const res = await fetch('', { method: 'POST', body: fd });
        const d = await res.json();
        this.bannerOk = d.success; this.banner = d.message;
      } catch (e) { this.bannerOk = false; this.banner = 'Error: ' + e.message; }
      this.saving = false;
      window.scrollTo({ top: 0, behavior: 'smooth' });
    },

    async saveTemplate() {
      this.savingTpl = true; this.banner = '';
      const fd = new FormData();
      fd.append('action', 'save_email_settings');
      fd.append('_csrf_token', CSRF);
      const tplForm = document.querySelectorAll('form')[1];
      for (const el of tplForm.querySelectorAll('[name]')) {
        fd.append(el.name, el.value);
      }
      try {
        const res = await fetch('', { method: 'POST', body: fd });
        const d = await res.json();
        this.bannerOk = d.success; this.banner = d.message;
      } catch (e) { this.bannerOk = false; this.banner = 'Error: ' + e.message; }
      this.savingTpl = false;
      window.scrollTo({ top: 0, behavior: 'smooth' });
    },

    async sendTest(action) {
      if (!this.testEmail.trim()) { Noti.warning('กรุณากรอกอีเมลปลายทาง'); return; }
      this.testing = true; this.banner = '';
      const fd = new FormData();
      fd.append('action', action);
      fd.append('test_email_to', this.testEmail.trim());
      fd.append('_csrf_token', CSRF);
      try {
        const res = await fetch('', { method: 'POST', body: fd });
        const d = await res.json();
        this.bannerOk = d.success; this.banner = d.message;
      } catch (e) { this.bannerOk = false; this.banner = 'Error: ' + e.message; }
      this.testing = false;
      window.scrollTo({ top: 0, behavior: 'smooth' });
    }
  }
}
</script>

<?php require __DIR__ . '/layouts/footer.php'; ?>
