<?php
define('BASE_PATH', dirname(__DIR__, 2));
require_once BASE_PATH . '/vendor/autoload.php';
use Dotenv\Dotenv;
use App\Helpers\Auth;
use App\Helpers\Response;
use App\Helpers\Database;
use App\Services\LogService;

$dotenv = Dotenv::createImmutable(BASE_PATH);
$dotenv->safeLoad();
date_default_timezone_set($_ENV['APP_TIMEZONE'] ?? 'Asia/Bangkok');

$pageTitle  = 'System Settings';
$breadcrumb = ['Admin', 'Settings'];
Auth::requirePermission('manage_settings');

$db     = Database::getInstance();
$logger = new LogService();

// ── Save Settings ──────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Auth::validateCsrf()) Response::error('CSRF failed', 403);

    $keys = ['app_name','rate_limit_enabled','rate_limit_per_minute','rate_limit_per_hour','allow_local_login'];
    $saved = 0;
    foreach ($keys as $key) {
        if (isset($_POST[$key])) {
            $old = $db->fetch('SELECT setting_value FROM system_settings WHERE setting_key=?', [$key]);
            $val = is_array($_POST[$key]) ? implode(',', $_POST[$key]) : trim($_POST[$key]);
            $db->upsert('system_settings', ['setting_key'=>$key, 'setting_value'=>$val, 'updated_at'=>date('Y-m-d H:i:s')]);
            $logger->logActivity(Auth::get('id'), "settings.save", 'system_setting', null, $old['setting_value']??null, $val);
            $saved++;
        }
    }
    Response::success(['saved'=>$saved], "บันทึกการตั้งค่าสำเร็จ ({$saved} รายการ)");
}

// ── Load All Settings ──────────────────────────────────────────────────
$allSettings = $db->fetchAll('SELECT setting_key, setting_value FROM system_settings');
$settings = array_column($allSettings, 'setting_value', 'setting_key');
function s(string $key, mixed $default = ''): string { global $settings; return htmlspecialchars($settings[$key] ?? $default); }
function sb(string $key, bool $default = false): bool  { global $settings; $v = $settings[$key] ?? ($default ? 'true' : 'false'); return in_array($v, ['true','1','yes'],true); }

// Bot count
$botCount = (int)$db->fetchColumn('SELECT COUNT(*) FROM bots');

require __DIR__ . '/layouts/header.php';
?>
<meta name="csrf-token" content="<?= Auth::csrfToken() ?>">

<div x-data="settingsPage()" x-init="init()">

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

  <!-- Info Banner: per-bot settings moved -->
  <div class="mb-6 p-4 rounded-xl bg-blue-50 border border-blue-200 text-blue-800 text-sm flex items-start gap-3">
    <svg class="w-5 h-5 shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
      <path stroke-linecap="round" stroke-linejoin="round" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
    </svg>
    <div>
      <p class="font-semibold">Multi-Bot Mode</p>
      <p class="mt-1">การตั้งค่า AI, Platform (Facebook/LINE), และ Widget ของแต่ละบอทอยู่ในหน้า
        <a href="bots.php" class="underline font-semibold hover:text-blue-900">Bots</a> &rarr; แก้ไขบอท
        หน้านี้เป็นการตั้งค่าระบบกลาง (System-wide) ที่ใช้ร่วมกันทุกบอท</p>
    </div>
  </div>

  <!-- System-wide Settings -->
  <form @submit.prevent="save()">
    <div class="bg-white rounded-2xl border border-slate-100 divide-y divide-slate-50 mb-5">

      <!-- App Name -->
      <div class="p-6">
        <label class="block text-sm font-semibold text-slate-800 mb-1">ชื่อระบบ (App Name)</label>
        <p class="text-xs text-slate-400 mb-2">ชื่อที่แสดงใน Admin Dashboard</p>
        <input type="text" name="app_name" value="<?= s('app_name', $_ENV['APP_NAME']??'AI Chatbot System') ?>"
               class="w-full px-4 py-2.5 rounded-xl border border-slate-200 text-sm focus:ring-2 focus:ring-indigo-500 outline-none">
      </div>

      <!-- Rate Limiting -->
      <div class="p-6">
        <h3 class="text-sm font-bold text-slate-800 mb-4 flex items-center gap-2">
          <svg class="w-4 h-4 text-amber-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
          </svg>
          Rate Limiting (ทุกบอท)
        </h3>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-5">
          <div class="flex items-center justify-between p-4 bg-slate-50 rounded-xl">
            <div>
              <p class="text-sm font-semibold text-slate-800">เปิดใช้งาน</p>
              <p class="text-xs text-slate-400">จำกัดจำนวนข้อความ</p>
            </div>
            <input type="hidden" name="rate_limit_enabled" value="false">
            <input type="checkbox" name="rate_limit_enabled" value="true" <?= sb('rate_limit_enabled',true)?'checked':'' ?>
                   class="w-5 h-5 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500 cursor-pointer">
          </div>
          <div>
            <label class="block text-sm font-semibold text-slate-700 mb-1">สูงสุด/นาที</label>
            <input type="number" name="rate_limit_per_minute" value="<?= s('rate_limit_per_minute','20') ?>" min="1" max="100"
                   class="w-full px-3 py-2.5 rounded-xl border border-slate-200 text-sm focus:ring-2 focus:ring-indigo-500 outline-none">
          </div>
          <div>
            <label class="block text-sm font-semibold text-slate-700 mb-1">สูงสุด/ชั่วโมง</label>
            <input type="number" name="rate_limit_per_hour" value="<?= s('rate_limit_per_hour','100') ?>" min="1" max="1000"
                   class="w-full px-3 py-2.5 rounded-xl border border-slate-200 text-sm focus:ring-2 focus:ring-indigo-500 outline-none">
          </div>
        </div>
      </div>

      <!-- Allow Local Login -->
      <div class="p-6 flex items-center justify-between">
        <div>
          <p class="text-sm font-semibold text-slate-800">Allow Local Login</p>
          <p class="text-xs text-slate-400">อนุญาตให้ Login ด้วย Username/Password (นอกจาก Microsoft)</p>
        </div>
        <input type="hidden" name="allow_local_login" value="false">
        <input type="checkbox" name="allow_local_login" value="true" <?= sb('allow_local_login',true)?'checked':'' ?>
               class="w-5 h-5 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500 cursor-pointer">
      </div>

      <!-- System Stats -->
      <div class="p-6 bg-slate-50">
        <h3 class="text-xs font-bold text-slate-400 uppercase tracking-widest mb-3">ข้อมูลระบบ</h3>
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4 text-center">
          <div>
            <p class="text-xs text-slate-400">Bots</p>
            <p class="text-lg font-bold text-slate-800"><?= $botCount ?></p>
          </div>
          <div>
            <p class="text-xs text-slate-400">PHP</p>
            <p class="text-lg font-bold text-slate-800"><?= PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION ?></p>
          </div>
          <div>
            <p class="text-xs text-slate-400">Timezone</p>
            <p class="text-lg font-bold text-slate-800"><?= date_default_timezone_get() ?></p>
          </div>
          <div>
            <p class="text-xs text-slate-400">Environment</p>
            <p class="text-lg font-bold text-slate-800"><?= htmlspecialchars($_ENV['APP_ENV'] ?? 'production') ?></p>
          </div>
        </div>
      </div>
    </div>

    <div class="flex justify-end">
      <button type="submit" :disabled="saving" class="px-6 py-2.5 rounded-xl bg-indigo-600 text-white font-semibold text-sm hover:bg-indigo-700 disabled:opacity-60 transition-colors">
        <span x-text="saving ? 'กำลังบันทึก...' : 'บันทึกการตั้งค่า'"></span>
      </button>
    </div>
  </form>
</div>

<script>
const CSRF = document.querySelector('meta[name="csrf-token"]').content;
function settingsPage() {
  return {
    saving: false, banner: '', bannerOk: true,
    init() {},
    async save() {
      this.saving = true; this.banner = '';
      const form = document.querySelector('form');
      const fd = new FormData(form);
      fd.append('_csrf_token', CSRF);
      const res = await fetch('', { method:'POST', body:fd });
      const d = await res.json();
      this.bannerOk = d.success; this.banner = d.message;
      this.saving = false;
      window.scrollTo({top:0, behavior:'smooth'});
    }
  }
}
</script>

<?php require __DIR__ . '/layouts/footer.php'; ?>
