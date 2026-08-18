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

Auth::requirePermission('manage_bots');

$db     = Database::getInstance();
$logger = new LogService();
$appUrl = rtrim($_ENV['APP_URL'] ?? 'https://appupili.up.ac.th/cbms', '/');

$botId = (int)($_GET['id'] ?? 0);
if (!$botId) { header('Location: bots.php'); exit; }
if (!Auth::canAccessBot($botId)) { http_response_code(403); echo 'Forbidden'; exit; }

// Auto-switch active bot context so sidebar/navigation matches the bot being edited
if (Auth::activeBotId() !== $botId) {
    Auth::setActiveBot($botId);
}

$bot = $db->fetch('SELECT * FROM bots WHERE id = ?', [$botId]);
if (!$bot) { header('Location: bots.php'); exit; }

$pageTitle  = 'แก้ไขบอท: ' . $bot['name'];
$breadcrumb = ['Admin', 'Bots', $bot['name']];

// ── Load bot settings ──────────────────────────────────────────────────
$allSettings = $db->fetchAll('SELECT setting_key, setting_value FROM bot_settings WHERE bot_id = ?', [$botId]);
$settings    = array_column($allSettings, 'setting_value', 'setting_key');
function bs(string $key, string $default = ''): string { global $settings; return htmlspecialchars($settings[$key] ?? $default); }

// ── Load members ───────────────────────────────────────────────────────
$members = $db->fetchAll('
    SELECT bu.*, au.display_name, au.email, au.avatar_url, au.role AS user_role
    FROM bot_users bu
    INNER JOIN admin_users au ON au.id = bu.user_id
    WHERE bu.bot_id = ?
    ORDER BY FIELD(bu.role, "owner", "editor", "viewer"), au.display_name
', [$botId]);

$allUsers = $db->fetchAll('SELECT id, display_name, email FROM admin_users WHERE is_active = 1 ORDER BY display_name');

// ── AJAX Handlers ──────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Auth::validateCsrf()) Response::error('CSRF failed', 403);
    $action = $_POST['action'] ?? '';

    if ($action === 'save_general') {
        $name = trim($_POST['name'] ?? '');
        $desc = trim($_POST['description'] ?? '');
        $slug = trim($_POST['slug'] ?? '');

        if (empty($name)) Response::error('กรุณาระบุชื่อบอท', 422);

        $slugExists = $db->fetch('SELECT id FROM bots WHERE slug = ? AND id != ?', [$slug, $botId]);
        if ($slugExists) Response::error('Slug นี้ถูกใช้แล้ว', 409);

        $db->update('bots', [
            'name'        => $name,
            'slug'        => $slug,
            'description' => $desc ?: null,
            'avatar_url'  => trim($_POST['avatar_url'] ?? '') ?: null,
        ], ['id' => $botId]);

        $logger->logActivity(Auth::get('id'), 'bot.update_general', 'bot', $botId, null, ['name' => $name]);
        Response::success(null, 'บันทึกข้อมูลทั่วไปสำเร็จ');
    }

    if ($action === 'save_settings') {
        $group = $_POST['group'] ?? '';
        $settingGroups = [
            'ai'       => ['system_prompt','temperature','max_tokens','similarity_threshold','wiki_question_threshold','top_k_chunks','history_length','fallback_message'],
            'platform' => ['google_drive_folder_id','facebook_page_token','facebook_app_secret','facebook_verify_token','facebook_page_id','line_channel_token','line_channel_secret','line_channel_id'],
            'widget'   => ['bot_name','chat_welcome_message','primary_color','allow_feedback','widget_icon_url','widget_logo_url','widget_icon_size','widget_draggable','quick_replies','handoff_enabled','handoff_email'],
        ];

        $keys  = $settingGroups[$group] ?? [];
        $saved = 0;
        foreach ($keys as $key) {
            if (isset($_POST[$key])) {
                $val = trim($_POST[$key]);
                $db->upsert('bot_settings', [
                    'bot_id'        => $botId,
                    'setting_key'   => $key,
                    'setting_value' => $val,
                ]);
                $saved++;
            }
        }

        $logger->logActivity(Auth::get('id'), "bot.save_settings.{$group}", 'bot', $botId, null, ['saved' => $saved]);
        Response::success(['saved' => $saved], "บันทึกสำเร็จ ({$saved} รายการ)");
    }

    if ($action === 'add_member') {
        $userId = (int)($_POST['user_id'] ?? 0);
        $role   = $_POST['member_role'] ?? 'viewer';
        if (!in_array($role, ['owner','editor','viewer'])) Response::error('Invalid role', 422);

        $exists = $db->fetch('SELECT id FROM bot_users WHERE bot_id = ? AND user_id = ?', [$botId, $userId]);
        if ($exists) Response::error('ผู้ใช้นี้เป็นสมาชิกอยู่แล้ว', 409);

        $db->insert('bot_users', ['bot_id' => $botId, 'user_id' => $userId, 'role' => $role]);
        $logger->logActivity(Auth::get('id'), 'bot.add_member', 'bot', $botId, null, ['user_id' => $userId, 'role' => $role]);
        Response::success(null, 'เพิ่มสมาชิกสำเร็จ');
    }

    if ($action === 'update_member_role') {
        $userId = (int)($_POST['user_id'] ?? 0);
        $role   = $_POST['member_role'] ?? 'viewer';
        if (!in_array($role, ['owner','editor','viewer'])) Response::error('Invalid role', 422);

        $db->update('bot_users', ['role' => $role], ['bot_id' => $botId, 'user_id' => $userId]);
        $logger->logActivity(Auth::get('id'), 'bot.update_member_role', 'bot', $botId, null, ['user_id' => $userId, 'role' => $role]);
        Response::success(null, 'อัปเดต Role สำเร็จ');
    }

    if ($action === 'remove_member') {
        $userId = (int)($_POST['user_id'] ?? 0);
        if ($userId === Auth::get('id')) Response::error('ไม่สามารถลบตัวเองได้', 422);

        $db->delete('bot_users', ['bot_id' => $botId, 'user_id' => $userId]);
        $logger->logActivity(Auth::get('id'), 'bot.remove_member', 'bot', $botId, null, ['user_id' => $userId]);
        Response::success(null, 'ลบสมาชิกสำเร็จ');
    }

    Response::error('Unknown action', 400);
}

require __DIR__ . '/layouts/header.php';
?>
<meta name="csrf-token" content="<?= Auth::csrfToken() ?>">

<div x-data="botEditPage()" x-init="init()">

  <!-- Back link -->
  <a href="bots.php" class="inline-flex items-center gap-1.5 text-sm text-slate-500 hover:text-brand-600 mb-4 transition-colors">
    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
      <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/>
    </svg>
    กลับไปรายการบอท
  </a>

  <!-- Toast -->
  <div x-show="banner" x-transition class="mb-5 p-4 rounded-xl text-sm flex items-center gap-2"
       :class="bannerOk ? 'bg-emerald-50 border border-emerald-200 text-emerald-700' : 'bg-red-50 border border-red-200 text-red-700'">
    <svg class="w-5 h-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
      <path x-show="bannerOk" stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
      <path x-show="!bannerOk" stroke-linecap="round" stroke-linejoin="round" d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z"/>
    </svg>
    <span x-text="banner"></span>
    <button @click="banner=''" class="ml-auto opacity-60 hover:opacity-100">&times;</button>
  </div>

  <!-- Tab Navigation -->
  <div class="flex gap-1 mb-6 bg-white rounded-2xl border border-slate-100 p-1.5 w-fit flex-wrap">
    <?php foreach ([['general','📋 General'],['ai','🤖 AI Settings'],['platform','📱 Platforms'],['widget','🎨 Widget'],['members','👥 Members']] as [$k,$l]): ?>
    <button @click="tab='<?=$k?>'" :class="tab==='<?=$k?>' ? 'bg-indigo-600 text-white shadow' : 'text-slate-600 hover:text-slate-800'"
            class="px-5 py-2.5 rounded-xl text-sm font-semibold transition-all"><?=$l?></button>
    <?php endforeach; ?>
  </div>

  <!-- ══════════════ General Tab ══════════════ -->
  <div x-show="tab==='general'" x-transition>
    <form @submit.prevent="saveGeneral()">
      <div class="bg-white rounded-2xl border border-slate-100 divide-y divide-slate-50 mb-5">
        <div class="p-6 grid grid-cols-1 md:grid-cols-2 gap-5">
          <div>
            <label class="block text-sm font-semibold text-slate-800 mb-1">ชื่อบอท <span class="text-red-500">*</span></label>
            <input type="text" name="name" value="<?= htmlspecialchars($bot['name']) ?>" required
                   class="w-full px-4 py-2.5 rounded-xl border border-slate-200 text-sm focus:ring-2 focus:ring-indigo-500 outline-none">
          </div>
          <div>
            <label class="block text-sm font-semibold text-slate-800 mb-1">Slug</label>
            <input type="text" name="slug" value="<?= htmlspecialchars($bot['slug']) ?>"
                   class="w-full px-4 py-2.5 rounded-xl border border-slate-200 text-sm font-mono focus:ring-2 focus:ring-indigo-500 outline-none"
                   <?= $bot['slug'] === 'default' ? 'readonly' : '' ?>>
            <p class="text-[11px] text-slate-400 mt-1">ใช้ใน URL: widget.js data-bot="<?= htmlspecialchars($bot['slug']) ?>"</p>
          </div>
        </div>
        <div class="p-6">
          <label class="block text-sm font-semibold text-slate-800 mb-1">คำอธิบาย</label>
          <textarea name="description" rows="2"
                    class="w-full px-4 py-2.5 rounded-xl border border-slate-200 text-sm focus:ring-2 focus:ring-indigo-500 outline-none resize-none"><?= htmlspecialchars($bot['description'] ?? '') ?></textarea>
        </div>
        <div class="p-6">
          <label class="block text-sm font-semibold text-slate-800 mb-1">Avatar URL</label>
          <p class="text-xs text-slate-400 mb-2">URL รูปโปรไฟล์ของบอท (ถ้าว่างจะแสดงตัวอักษรย่อ)</p>
          <input type="url" name="avatar_url" value="<?= htmlspecialchars($bot['avatar_url'] ?? '') ?>"
                 placeholder="https://example.com/avatar.png"
                 class="w-full px-4 py-2.5 rounded-xl border border-slate-200 text-sm focus:ring-2 focus:ring-indigo-500 outline-none">
        </div>

        <!-- Bot Info -->
        <div class="p-6 bg-slate-50 grid grid-cols-2 md:grid-cols-4 gap-4 text-center">
          <div>
            <p class="text-xs text-slate-400">Bot ID</p>
            <p class="text-sm font-bold text-slate-800"><?= $botId ?></p>
          </div>
          <div>
            <p class="text-xs text-slate-400">Status</p>
            <p class="text-sm font-bold <?= $bot['is_active'] ? 'text-emerald-600' : 'text-slate-500' ?>">
              <?= $bot['is_active'] ? 'Active' : 'Inactive' ?>
            </p>
          </div>
          <div>
            <p class="text-xs text-slate-400">Created</p>
            <p class="text-sm font-bold text-slate-800"><?= date('d M Y', strtotime($bot['created_at'])) ?></p>
          </div>
          <div>
            <p class="text-xs text-slate-400">Updated</p>
            <p class="text-sm font-bold text-slate-800"><?= $bot['updated_at'] ? date('d M Y H:i', strtotime($bot['updated_at'])) : '-' ?></p>
          </div>
        </div>
      </div>
      <div class="flex justify-end">
        <button type="submit" :disabled="saving" class="px-6 py-2.5 rounded-xl bg-indigo-600 text-white font-semibold text-sm hover:bg-indigo-700 disabled:opacity-60 transition-colors">
          <span x-text="saving ? 'กำลังบันทึก...' : 'บันทึกข้อมูลทั่วไป'"></span>
        </button>
      </div>
    </form>
  </div>

  <!-- ══════════════ AI Settings Tab ══════════════ -->
  <div x-show="tab==='ai'" x-transition>
    <form @submit.prevent="saveSettings('ai')">
      <div class="bg-white rounded-2xl border border-slate-100 divide-y divide-slate-50 mb-5">
        <div class="p-6">
          <label class="block text-sm font-semibold text-slate-800 mb-1">System Prompt</label>
          <p class="text-xs text-slate-400 mb-3">คำสั่งหลักที่บอก AI ว่าเป็นใครและทำหน้าที่อะไร</p>
          <textarea name="system_prompt" rows="8"
                    class="w-full px-4 py-3 rounded-xl border border-slate-200 text-sm font-mono focus:ring-2 focus:ring-indigo-500 outline-none resize-y"
                    placeholder="คุณคือผู้ช่วย AI..."><?= bs('system_prompt') ?></textarea>
        </div>
        <div class="p-6">
          <label class="block text-sm font-semibold text-slate-800 mb-1">Fallback Message</label>
          <p class="text-xs text-slate-400 mb-3">ข้อความเมื่อไม่พบข้อมูลที่เกี่ยวข้อง</p>
          <input type="text" name="fallback_message"
                 value="<?= bs('fallback_message','ขออภัยครับ ไม่พบข้อมูลที่เกี่ยวข้อง กรุณาติดต่อเจ้าหน้าที่โดยตรง') ?>"
                 class="w-full px-4 py-2.5 rounded-xl border border-slate-200 text-sm focus:ring-2 focus:ring-indigo-500 outline-none">
        </div>
        <div class="p-6 grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-5">
          <?php
          $sliders = [
              ['temperature','Temperature (ความสร้างสรรค์)','0','1','0.1',bs('temperature','0.3'),'0 = ตอบแบบจริงจัง, 1 = สร้างสรรค์'],
              ['similarity_threshold','Similarity Threshold','0','1','0.05',bs('similarity_threshold','0.35'),'ความใกล้เคียงขั้นต่ำ (Cosine)'],
              ['wiki_question_threshold','Wiki Question Threshold','0','1','0.05',bs('wiki_question_threshold','0.45'),'คะแนนขั้นต่ำจับคู่คำถามตัวอย่างของหน้า Wiki'],
          ];
          foreach ($sliders as [$name,$label,$min,$max,$step,$val,$hint]):
          ?>
          <div x-data="{val: '<?=$val?>'}">
            <label class="block text-sm font-semibold text-slate-800 mb-1"><?=$label?></label>
            <p class="text-xs text-slate-400 mb-2"><?=$hint?></p>
            <div class="flex items-center gap-3">
              <input type="range" name="<?=$name?>" :value="val" @input="val=$event.target.value"
                     min="<?=$min?>" max="<?=$max?>" step="<?=$step?>" class="flex-1 accent-indigo-600">
              <span class="text-sm font-mono font-bold text-indigo-600 w-10 text-right" x-text="val"></span>
            </div>
          </div>
          <?php endforeach; ?>

          <?php
          $numberFields = [
              ['max_tokens','Max Tokens (Output)',1,8192,1,bs('max_tokens','1024'),'จำนวน tokens สูงสุดของคำตอบ'],
              ['top_k_chunks','Top K Chunks (RAG)',1,20,1,bs('top_k_chunks','5'),'จำนวน chunks ที่ดึงมา'],
              ['history_length','History Length',0,50,1,bs('history_length','10'),'จำนวนข้อความย้อนหลัง'],
          ];
          foreach ($numberFields as [$name,$label,$min,$max,$step,$val,$hint]):
          ?>
          <div>
            <label class="block text-sm font-semibold text-slate-800 mb-1"><?=$label?></label>
            <p class="text-xs text-slate-400 mb-2"><?=$hint?></p>
            <input type="number" name="<?=$name?>" value="<?=$val?>" min="<?=$min?>" max="<?=$max?>"
                   class="w-full px-3 py-2.5 rounded-xl border border-slate-200 text-sm focus:ring-2 focus:ring-indigo-500 outline-none">
          </div>
          <?php endforeach; ?>
        </div>
      </div>
      <div class="flex justify-end">
        <button type="submit" :disabled="saving" class="px-6 py-2.5 rounded-xl bg-indigo-600 text-white font-semibold text-sm hover:bg-indigo-700 disabled:opacity-60 transition-colors">
          <span x-text="saving ? 'กำลังบันทึก...' : 'บันทึก AI Settings'"></span>
        </button>
      </div>
    </form>
  </div>

  <!-- ══════════════ Platform Tab ══════════════ -->
  <div x-show="tab==='platform'" x-transition>
    <form @submit.prevent="saveSettings('platform')" class="space-y-5">

      <!-- Google Drive -->
      <div class="bg-white rounded-2xl border border-slate-100 overflow-hidden">
        <div class="px-6 py-4 bg-yellow-50 border-b border-yellow-100 flex items-center gap-2">
          <span class="text-lg">📁</span>
          <h3 class="font-semibold text-yellow-800">Google Drive</h3>
        </div>
        <div class="p-6">
          <label class="block text-sm font-semibold text-slate-700 mb-1">Folder ID</label>
          <p class="text-xs text-slate-400 mb-2">Google Drive Folder ID สำหรับ Knowledge Base ของบอทนี้</p>
          <input type="text" name="google_drive_folder_id" value="<?= bs('google_drive_folder_id') ?>"
                 placeholder="1ABCdefGHIjklMNOpqrSTUvwxYZ"
                 class="w-full px-3 py-2.5 rounded-xl border border-slate-200 text-sm focus:ring-2 focus:ring-indigo-500 outline-none font-mono">
        </div>
      </div>

      <!-- Facebook -->
      <div class="bg-white rounded-2xl border border-slate-100 overflow-hidden">
        <div class="px-6 py-4 bg-blue-50 border-b border-blue-100 flex items-center gap-2">
          <span class="text-lg">📘</span>
          <h3 class="font-semibold text-blue-800">Facebook Messenger</h3>
        </div>
        <div class="p-6 grid grid-cols-1 md:grid-cols-2 gap-5">
          <?php foreach ([
              ['facebook_page_token','Page Access Token','text','EAAxxxxxxx...'],
              ['facebook_app_secret','App Secret','password','xxxxxxxxxx'],
              ['facebook_verify_token','Webhook Verify Token','text','my-verify-token'],
              ['facebook_page_id','Page ID','text','123456789'],
          ] as [$n,$l,$t,$ph]): ?>
          <div>
            <label class="block text-sm font-semibold text-slate-700 mb-1"><?=$l?></label>
            <input type="<?=$t?>" name="<?=$n?>" value="<?=$t==='password'?'':bs($n)?>"
                   placeholder="<?=$ph?>"
                   class="w-full px-3 py-2.5 rounded-xl border border-slate-200 text-sm focus:ring-2 focus:ring-indigo-500 outline-none font-mono">
          </div>
          <?php endforeach; ?>
          <div class="md:col-span-2 p-3 bg-blue-50 rounded-xl text-xs text-blue-700">
            <strong>Webhook URL:</strong>
            <code class="ml-1"><?= $appUrl ?>/api/webhook-facebook.php?bot=<?= htmlspecialchars($bot['slug']) ?></code>
          </div>
        </div>
      </div>

      <!-- LINE -->
      <div class="bg-white rounded-2xl border border-slate-100 overflow-hidden">
        <div class="px-6 py-4 bg-emerald-50 border-b border-emerald-100 flex items-center gap-2">
          <span class="text-lg">💚</span>
          <h3 class="font-semibold text-emerald-800">LINE Messaging API</h3>
        </div>
        <div class="p-6 grid grid-cols-1 md:grid-cols-2 gap-5">
          <?php foreach ([
              ['line_channel_id','Channel ID','text','12345678'],
              ['line_channel_token','Channel Access Token','password','xxxxxxxx...'],
              ['line_channel_secret','Channel Secret','password','xxxxxxxxxx'],
          ] as [$n,$l,$t,$ph]): ?>
          <div>
            <label class="block text-sm font-semibold text-slate-700 mb-1"><?=$l?></label>
            <input type="<?=$t?>" name="<?=$n?>" value="<?=$t==='password'?'':bs($n)?>"
                   placeholder="<?=$ph?>"
                   class="w-full px-3 py-2.5 rounded-xl border border-slate-200 text-sm focus:ring-2 focus:ring-indigo-500 outline-none font-mono">
          </div>
          <?php endforeach; ?>
          <div class="md:col-span-2 p-3 bg-emerald-50 rounded-xl text-xs text-emerald-700">
            <strong>Webhook URL:</strong>
            <code class="ml-1"><?= $appUrl ?>/api/webhook-line.php?bot=<?= htmlspecialchars($bot['slug']) ?></code>
          </div>
        </div>
      </div>

      <div class="flex justify-end">
        <button type="submit" :disabled="saving" class="px-6 py-2.5 rounded-xl bg-indigo-600 text-white font-semibold text-sm hover:bg-indigo-700 disabled:opacity-60 transition-colors">
          <span x-text="saving ? 'กำลังบันทึก...' : 'บันทึก Platform Settings'"></span>
        </button>
      </div>
    </form>
  </div>

  <!-- ══════════════ Widget Tab ══════════════ -->
  <div x-show="tab==='widget'" x-transition x-data="widgetPreview()" x-init="initPreview()">

    <div class="grid grid-cols-1 xl:grid-cols-5 gap-6">

      <!-- ── Left: Controls ── -->
      <div class="xl:col-span-2 space-y-5">
        <form @submit.prevent="saveWidget()" id="widget-form">

          <!-- Bot Name & Welcome -->
          <div class="bg-white rounded-2xl border border-slate-100 divide-y divide-slate-50 mb-5">
            <div class="p-5">
              <label class="block text-sm font-semibold text-slate-800 mb-1">Bot Name (แสดงใน Widget)</label>
              <input type="text" name="bot_name" x-model="cfg.botName" @input="refreshPreview()"
                     class="w-full px-4 py-2.5 rounded-xl border border-slate-200 text-sm focus:ring-2 focus:ring-indigo-500 outline-none">
            </div>
            <div class="p-5">
              <label class="block text-sm font-semibold text-slate-800 mb-1">Welcome Message</label>
              <p class="text-xs text-slate-400 mb-2">ข้อความต้อนรับเมื่อเปิด Widget</p>
              <input type="text" name="chat_welcome_message" x-model="cfg.welcomeMsg" @input="refreshPreview()"
                     class="w-full px-4 py-2.5 rounded-xl border border-slate-200 text-sm focus:ring-2 focus:ring-indigo-500 outline-none">
            </div>
          </div>

          <!-- Theme Color -->
          <div class="bg-white rounded-2xl border border-slate-100 p-5 mb-5">
            <label class="block text-sm font-semibold text-slate-800 mb-3">Theme Color</label>
            <div class="flex items-center gap-3 mb-3">
              <input type="color" name="primary_color" x-model="cfg.color" @input="refreshPreview()"
                     class="w-10 h-10 rounded-lg border border-slate-200 cursor-pointer p-0.5">
              <input type="text" :value="cfg.color" @input="cfg.color=$event.target.value; refreshPreview()"
                     class="flex-1 px-3 py-2.5 rounded-xl border border-slate-200 text-sm font-mono focus:ring-2 focus:ring-indigo-500 outline-none">
            </div>
            <div class="flex flex-wrap gap-2">
              <template x-for="c in presetColors" :key="c">
                <button type="button" @click="cfg.color=c; refreshPreview()"
                        class="w-8 h-8 rounded-full border-2 transition-transform hover:scale-110 focus:outline-none"
                        :class="cfg.color===c ? 'border-slate-800 scale-110 ring-2 ring-offset-1 ring-slate-400' : 'border-transparent'"
                        :style="'background:'+c" :title="c"></button>
              </template>
            </div>
          </div>

          <!-- Icon Settings -->
          <div class="bg-white rounded-2xl border border-slate-100 p-5 mb-5">
            <label class="block text-sm font-semibold text-slate-800 mb-3">Chat Icon &amp; Logo</label>
            <div class="space-y-5">

              <!-- Chat Icon Upload -->
              <div>
                <label class="block text-xs font-medium text-slate-600 mb-2">Icon ปุ่มแชท</label>
                <input type="hidden" name="widget_icon_url" :value="cfg.iconUrl">
                <div class="flex items-center gap-4">
                  <!-- Preview -->
                  <div class="relative w-14 h-14 rounded-full shrink-0 flex items-center justify-center overflow-hidden border-2 transition-colors"
                       :class="cfg.iconUrl ? 'border-indigo-200 bg-white' : 'border-dashed border-slate-300 bg-slate-50'"
                       :style="!cfg.iconUrl ? 'background:linear-gradient(135deg,'+cfg.color+','+cfg.color+'dd);' : ''">
                    <template x-if="cfg.iconUrl">
                      <img :src="cfg.iconUrl" class="w-full h-full object-cover rounded-full">
                    </template>
                    <template x-if="!cfg.iconUrl">
                      <svg class="w-6 h-6 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                        <path stroke-linecap="round" stroke-linejoin="round"
                              d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z"/>
                      </svg>
                    </template>
                  </div>
                  <!-- Buttons -->
                  <div class="flex-1">
                    <div class="flex items-center gap-2">
                      <label class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-indigo-50 text-indigo-700 text-xs font-semibold cursor-pointer hover:bg-indigo-100 transition-colors">
                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                          <path stroke-linecap="round" stroke-linejoin="round" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                        </svg>
                        อัปโหลดรูป
                        <input type="file" accept="image/*" class="hidden" @change="handleIconUpload($event)">
                      </label>
                      <button type="button" x-show="cfg.iconUrl" @click="cfg.iconUrl=''; refreshPreview()"
                              class="inline-flex items-center gap-1 px-2.5 py-1.5 rounded-lg bg-red-50 text-red-600 text-xs font-semibold hover:bg-red-100 transition-colors">
                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                          <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                        </svg>
                        ลบ
                      </button>
                    </div>
                    <p class="text-[10px] text-slate-400 mt-1.5">PNG, JPG, SVG, WebP (แนะนำ 120x120px, ไม่เกิน 500KB)</p>
                  </div>
                </div>
              </div>

              <!-- Header Logo Upload -->
              <div>
                <label class="block text-xs font-medium text-slate-600 mb-2">Logo ใน Header แชท</label>
                <input type="hidden" name="widget_logo_url" :value="cfg.logoUrl">
                <div class="flex items-center gap-4">
                  <!-- Preview -->
                  <div class="relative w-14 h-14 rounded-xl shrink-0 flex items-center justify-center overflow-hidden border-2 transition-colors"
                       :class="cfg.logoUrl ? 'border-indigo-200 bg-white' : 'border-dashed border-slate-300'"
                       :style="!cfg.logoUrl ? 'background:rgba(79,70,229,.15);' : ''">
                    <template x-if="cfg.logoUrl">
                      <img :src="cfg.logoUrl" class="w-full h-full object-cover">
                    </template>
                    <template x-if="!cfg.logoUrl">
                      <span class="text-2xl">🤖</span>
                    </template>
                  </div>
                  <!-- Buttons -->
                  <div class="flex-1">
                    <div class="flex items-center gap-2">
                      <label class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-indigo-50 text-indigo-700 text-xs font-semibold cursor-pointer hover:bg-indigo-100 transition-colors">
                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                          <path stroke-linecap="round" stroke-linejoin="round" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                        </svg>
                        อัปโหลดรูป
                        <input type="file" accept="image/*" class="hidden" @change="handleLogoUpload($event)">
                      </label>
                      <button type="button" x-show="cfg.logoUrl" @click="cfg.logoUrl=''; refreshPreview()"
                              class="inline-flex items-center gap-1 px-2.5 py-1.5 rounded-lg bg-red-50 text-red-600 text-xs font-semibold hover:bg-red-100 transition-colors">
                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                          <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                        </svg>
                        ลบ
                      </button>
                    </div>
                    <p class="text-[10px] text-slate-400 mt-1.5">PNG, JPG, SVG, WebP (แนะนำ 120x120px, ไม่เกิน 500KB)</p>
                  </div>
                </div>
              </div>

              <!-- Icon Size Slider -->
              <div>
                <label class="block text-xs font-medium text-slate-600 mb-1">Icon Size: <span class="font-bold text-indigo-600" x-text="cfg.iconSize+'px'"></span></label>
                <input type="range" name="widget_icon_size" x-model="cfg.iconSize" @input="refreshPreview()"
                       min="40" max="80" step="2" class="w-full accent-indigo-600">
              </div>
            </div>
          </div>

          <!-- Options -->
          <div class="bg-white rounded-2xl border border-slate-100 divide-y divide-slate-50 mb-5">
            <div class="p-5 flex items-center justify-between">
              <div>
                <p class="text-sm font-semibold text-slate-800">Allow Feedback</p>
                <p class="text-xs text-slate-400">แสดงปุ่ม 👍👎 ใน Widget</p>
              </div>
              <input type="hidden" name="allow_feedback" value="false">
              <input type="checkbox" name="allow_feedback" value="true" x-model="cfg.allowFeedback"
                     class="w-5 h-5 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500 cursor-pointer">
            </div>
            <div class="p-5 flex items-center justify-between">
              <div>
                <p class="text-sm font-semibold text-slate-800">Draggable Icon</p>
                <p class="text-xs text-slate-400">ลากปุ่มแชทได้</p>
              </div>
              <input type="hidden" name="widget_draggable" value="false">
              <input type="checkbox" name="widget_draggable" value="true" x-model="cfg.draggable"
                     class="w-5 h-5 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500 cursor-pointer">
            </div>
          </div>

          <!-- Quick Replies -->
          <div class="bg-white rounded-2xl border border-slate-100 p-5 mb-5">
            <label class="block text-sm font-semibold text-slate-800 mb-2">Quick Replies (คำถามแนะนำ)</label>
            <p class="text-xs text-slate-400 mb-3">ปุ่มคำถามยอดนิยมที่แสดงเมื่อเปิดแชท (สูงสุด 5 รายการ)</p>
            <div class="space-y-2">
              <template x-for="(qr, idx) in cfg.quickReplies" :key="idx">
                <div class="flex items-center gap-2">
                  <input type="text" x-model="cfg.quickReplies[idx]" @input="refreshPreview()"
                         class="flex-1 px-3 py-2 rounded-xl border border-slate-200 text-sm focus:ring-2 focus:ring-indigo-500 outline-none"
                         placeholder="พิมพ์คำถามแนะนำ...">
                  <button type="button" @click="cfg.quickReplies.splice(idx,1); refreshPreview()"
                          class="p-1.5 text-slate-400 hover:text-red-500 transition-colors">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                  </button>
                </div>
              </template>
            </div>
            <button type="button" @click="if(cfg.quickReplies.length<5){cfg.quickReplies.push(''); refreshPreview()}"
                    x-show="cfg.quickReplies.length < 5"
                    class="mt-3 px-3 py-1.5 text-xs font-semibold text-indigo-600 bg-indigo-50 rounded-lg hover:bg-indigo-100 transition-colors">
              + เพิ่มคำถาม
            </button>
            <input type="hidden" name="quick_replies" :value="JSON.stringify(cfg.quickReplies.filter(q=>q.trim()))">
          </div>

          <!-- Handoff Settings (Web Only) -->
          <div class="bg-white rounded-2xl border border-slate-100 p-5 mb-5">
            <label class="block text-sm font-semibold text-slate-800 mb-2">Human Handoff (ขอคุยกับเจ้าหน้าที่)</label>
            <p class="text-xs text-slate-400 mb-3">เปิดใช้งานปุ่มให้ผู้ใช้ส่งคำขอติดต่อเจ้าหน้าที่ผ่าน Web Widget</p>
            <div class="flex items-center justify-between mb-4">
              <p class="text-sm text-slate-700">เปิดใช้งาน Handoff</p>
              <input type="hidden" name="handoff_enabled" value="false">
              <input type="checkbox" name="handoff_enabled" value="true" x-model="cfg.handoffEnabled"
                     class="w-5 h-5 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500 cursor-pointer">
            </div>
            <div x-show="cfg.handoffEnabled" x-transition class="pt-3 border-t border-slate-100">
              <label class="block text-sm font-semibold text-slate-700 mb-1">อีเมลแจ้งเตือน Handoff</label>
              <p class="text-xs text-slate-400 mb-2">เมื่อมีคำขอใหม่ ระบบจะส่งอีเมลแจ้งเตือนไปยังอีเมลนี้ (ต้องตั้งค่า SMTP ในเมนู Email Settings ก่อน)</p>
              <input type="text" name="handoff_email" x-model="cfg.handoffEmail" placeholder="admin@example.com (คั่นด้วย , สำหรับหลายอีเมล)"
                     class="w-full px-4 py-2.5 rounded-xl border border-slate-200 text-sm focus:ring-2 focus:ring-indigo-500 outline-none">
            </div>
          </div>

          <!-- Embed Code -->
          <div class="bg-white rounded-2xl border border-slate-100 p-5 mb-5">
            <label class="block text-sm font-semibold text-slate-800 mb-2">Embed Code</label>
            <p class="text-xs text-slate-400 mb-3">คัดลอก code ด้านล่างไปวางก่อน &lt;/body&gt; ในเว็บไซต์</p>
            <div class="relative group">
              <pre class="bg-slate-800 text-emerald-400 text-xs p-4 rounded-xl overflow-x-auto leading-relaxed" x-html="embedCode()"></pre>
              <button type="button" @click="copyEmbed()" class="absolute top-2 right-2 px-2 py-1 bg-slate-700 text-slate-300 rounded-lg text-xs hover:bg-slate-600 opacity-0 group-hover:opacity-100 transition-opacity">
                <span x-text="copied ? 'Copied!' : 'Copy'"></span>
              </button>
            </div>
          </div>

          <div class="flex justify-end">
            <button type="submit" :disabled="saving" class="px-6 py-2.5 rounded-xl bg-indigo-600 text-white font-semibold text-sm hover:bg-indigo-700 disabled:opacity-60 transition-colors">
              <span x-text="saving ? 'กำลังบันทึก...' : 'บันทึก Widget Settings'"></span>
            </button>
          </div>
        </form>
      </div>

      <!-- ── Right: Live Preview ── -->
      <div class="xl:col-span-3">
        <div class="sticky top-4">
          <div class="flex items-center justify-between mb-3">
            <h3 class="text-sm font-bold text-slate-600 uppercase tracking-wider">Live Preview</h3>
            <div class="flex gap-2">
              <button type="button" @click="previewDevice='desktop'; dragPos={bottom:20,right:20}" :class="previewDevice==='desktop' ? 'bg-indigo-100 text-indigo-700' : 'bg-slate-100 text-slate-500'"
                      class="px-3 py-1.5 rounded-lg text-xs font-semibold transition-colors">Desktop</button>
              <button type="button" @click="previewDevice='mobile'; dragPos={bottom:20,right:20}" :class="previewDevice==='mobile' ? 'bg-indigo-100 text-indigo-700' : 'bg-slate-100 text-slate-500'"
                      class="px-3 py-1.5 rounded-lg text-xs font-semibold transition-colors">Mobile</button>
            </div>
          </div>

          <!-- Preview Container -->
          <div class="bg-gradient-to-br from-slate-100 to-slate-200 rounded-2xl border border-slate-200 overflow-hidden relative"
               :style="previewDevice==='mobile' ? 'max-width:390px;margin:0 auto;' : ''">

            <!-- Fake Browser Bar -->
            <div class="bg-white border-b border-slate-200 px-4 py-2 flex items-center gap-2">
              <div class="flex gap-1.5">
                <span class="w-3 h-3 rounded-full bg-red-400"></span>
                <span class="w-3 h-3 rounded-full bg-amber-400"></span>
                <span class="w-3 h-3 rounded-full bg-emerald-400"></span>
              </div>
              <div class="flex-1 mx-3 px-3 py-1 bg-slate-100 rounded-lg text-xs text-slate-400 font-mono truncate">
                https://your-website.com
              </div>
            </div>

            <!-- Preview Page Body -->
            <div class="relative bg-white" style="min-height:520px;" id="preview-viewport">
              <!-- Fake page content -->
              <div class="p-6 space-y-4">
                <div class="h-8 bg-slate-100 rounded-lg w-3/4"></div>
                <div class="h-4 bg-slate-50 rounded w-full"></div>
                <div class="h-4 bg-slate-50 rounded w-5/6"></div>
                <div class="h-4 bg-slate-50 rounded w-4/6"></div>
                <div class="h-32 bg-slate-50 rounded-xl mt-4"></div>
                <div class="h-4 bg-slate-50 rounded w-full"></div>
                <div class="h-4 bg-slate-50 rounded w-3/4"></div>
              </div>

              <!-- ── Float Chat Icon ── -->
              <button @click="if(!_dragMoved) togglePreviewChat()" id="preview-bubble"
                      @mousedown="startDrag($event)" @touchstart.passive="startDrag($event)"
                      class="absolute transition-all duration-200 focus:outline-none"
                      :class="cfg.draggable ? 'cursor-grab active:cursor-grabbing' : 'cursor-pointer hover:scale-110'"
                      :style="'bottom:'+(dragPos.bottom??20)+'px;right:'+(dragPos.right??20)+'px;width:'+cfg.iconSize+'px;height:'+cfg.iconSize+'px;border-radius:50%;border:none;display:flex;align-items:center;justify-content:center;box-shadow:0 8px 32px rgba(0,0,0,.25);overflow:hidden;'+
                              (cfg.iconUrl ? 'background:transparent;' : 'background:linear-gradient(135deg,'+cfg.color+','+cfg.color+'dd);')">
                <template x-if="cfg.iconUrl">
                  <img :src="cfg.iconUrl" style="width:100%;height:100%;object-fit:cover;border-radius:50%;pointer-events:none;">
                </template>
                <template x-if="!cfg.iconUrl">
                  <svg x-show="!chatOpen" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"
                       style="width:28px;height:28px;color:#fff;pointer-events:none;">
                    <path stroke-linecap="round" stroke-linejoin="round"
                          d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z"/>
                  </svg>
                </template>
                <svg x-show="chatOpen && !cfg.iconUrl" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"
                     style="width:24px;height:24px;color:#fff;pointer-events:none;">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                </svg>
              </button>

              <!-- ── Chat Window ── -->
              <div x-show="chatOpen" x-transition:enter="transition ease-out duration-200"
                   x-transition:enter-start="opacity-0 scale-90" x-transition:enter-end="opacity-1 scale-100"
                   x-transition:leave="transition ease-in duration-150"
                   x-transition:leave-start="opacity-1 scale-100" x-transition:leave-end="opacity-0 scale-90"
                   class="absolute shadow-2xl flex flex-col overflow-hidden"
                   :style="'bottom:'+(parseInt(cfg.iconSize)+32)+'px;right:20px;width:340px;max-width:calc(100% - 40px);height:420px;border-radius:20px;background:#fff;'">

                <!-- Header -->
                <div class="px-4 py-3 text-white flex items-center gap-3 shrink-0"
                     :style="'background:linear-gradient(135deg,'+cfg.color+','+cfg.color+'dd);'">
                  <div class="w-9 h-9 rounded-xl flex items-center justify-center overflow-hidden"
                       style="background:rgba(255,255,255,.2);">
                    <template x-if="cfg.logoUrl">
                      <img :src="cfg.logoUrl" style="width:100%;height:100%;object-fit:cover;">
                    </template>
                    <template x-if="!cfg.logoUrl">
                      <span style="font-size:18px;">🤖</span>
                    </template>
                  </div>
                  <div class="flex-1 min-w-0">
                    <p class="text-sm font-bold truncate" x-text="cfg.botName || 'AI Assistant'"></p>
                    <p class="text-[11px] opacity-80"><span class="inline-block w-2 h-2 rounded-full bg-green-400 mr-1"></span>Online</p>
                  </div>
                  <button @click="chatOpen=false" class="p-1 rounded-lg hover:bg-white/20 transition-colors">
                    <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                      <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                    </svg>
                  </button>
                </div>

                <!-- Messages -->
                <div class="flex-1 overflow-y-auto p-3 space-y-2.5 bg-slate-50" id="preview-messages"
                     style="font-family:'Sarabun',sans-serif;">
                  <!-- Welcome message -->
                  <template x-for="(m, i) in chatMessages" :key="i">
                    <div>
                      <div :class="m.role==='user' ? 'flex justify-end' : 'flex justify-start'">
                        <div class="max-w-[80%] px-3 py-2 text-[13px] leading-relaxed"
                             :class="m.role==='user'
                               ? 'rounded-2xl rounded-br-sm text-white'
                               : 'rounded-2xl rounded-bl-sm bg-white border border-slate-200 text-slate-800 ' + (m.fallback ? 'border-red-200 bg-red-50' : '')"
                             :style="m.role==='user' ? 'background:linear-gradient(135deg,'+cfg.color+','+cfg.color+'dd);box-shadow:0 2px 8px '+cfg.color+'40;' : ''"
                             x-html="m.role==='user' ? escHtml(m.content).replace(/\n/g,'<br>') : formatBotMsg(m.content)"></div>
                      </div>
                      <!-- Feedback buttons -->
                      <div x-show="m.role==='assistant' && cfg.allowFeedback && m.messageId" class="flex gap-1 mt-1">
                        <button @click="sendFeedback(m, 'positive')" :disabled="m.feedback"
                                :class="m.feedback==='positive' ? 'text-emerald-500 bg-emerald-50' : 'text-slate-300 hover:text-emerald-500 hover:bg-emerald-50'"
                                class="p-1 rounded transition-colors disabled:cursor-default" title="ถูกใจ">
                          <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M14 9V5a3 3 0 00-3-3l-4 9v11h11.28a2 2 0 002-1.7l1.38-9a2 2 0 00-2-2.3H14z"/></svg>
                        </button>
                        <button @click="sendFeedback(m, 'negative')" :disabled="m.feedback"
                                :class="m.feedback==='negative' ? 'text-red-500 bg-red-50' : 'text-slate-300 hover:text-red-500 hover:bg-red-50'"
                                class="p-1 rounded transition-colors disabled:cursor-default" title="ไม่ถูกใจ">
                          <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M10 15v4a3 3 0 003 3l4-9V2H5.72a2 2 0 00-2 1.7l-1.38 9a2 2 0 002 2.3H10z"/></svg>
                        </button>
                      </div>
                    </div>
                  </template>

                  <!-- Typing indicator -->
                  <div x-show="chatLoading" class="flex justify-start">
                    <div class="bg-white border border-slate-200 rounded-2xl rounded-bl-sm px-3 py-2 flex items-center gap-1.5">
                      <span class="w-2 h-2 rounded-full bg-slate-400 animate-bounce" style="animation-delay:0s"></span>
                      <span class="w-2 h-2 rounded-full bg-slate-400 animate-bounce" style="animation-delay:0.15s"></span>
                      <span class="w-2 h-2 rounded-full bg-slate-400 animate-bounce" style="animation-delay:0.3s"></span>
                    </div>
                  </div>
                </div>

                <!-- Quick Replies -->
                <div x-show="showQuickReplies && cfg.quickReplies.length > 0" class="px-3 pb-2 flex flex-wrap gap-1.5 bg-slate-50">
                  <template x-for="(qr, qi) in cfg.quickReplies" :key="qi">
                    <button x-show="qr.trim()" @click="sendPreviewMsg(qr); showQuickReplies=false"
                            class="px-2.5 py-1 rounded-xl text-[11px] border transition-colors cursor-pointer"
                            :style="'border-color:'+cfg.color+'60; color:'+cfg.color"
                            @mouseenter="$el.style.background=cfg.color; $el.style.color='#fff'"
                            @mouseleave="$el.style.background=''; $el.style.color=cfg.color"
                            x-text="qr"></button>
                  </template>
                </div>

                <!-- Handoff Button -->
                <div x-show="cfg.handoffEnabled && !handoffSent" class="px-3 pb-1.5 bg-slate-50">
                  <button @click="showHandoffForm=!showHandoffForm"
                          class="w-full flex items-center justify-center gap-1.5 px-3 py-1.5 rounded-xl border border-dashed border-amber-400 bg-amber-50 text-amber-700 text-[11px] font-medium hover:bg-amber-100 transition-colors">
                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                    ขอคุยกับเจ้าหน้าที่
                  </button>
                </div>

                <!-- Handoff Form -->
                <div x-show="showHandoffForm && cfg.handoffEnabled" x-transition
                     class="px-3 py-2 bg-amber-50 border-t border-amber-200 text-[11px] space-y-1.5">
                  <p class="font-semibold text-amber-800">ช่องทางที่สะดวกให้ติดต่อกลับ</p>
                  <select x-model="handoffType" class="w-full px-2 py-1 rounded-lg border border-amber-200 text-[11px] outline-none">
                    <option value="line">LINE</option><option value="facebook">Facebook</option><option value="phone">โทรศัพท์</option>
                  </select>
                  <input type="text" x-model="handoffContact" :placeholder="handoffType==='phone' ? 'เบอร์โทรศัพท์...' : 'LINE ID หรือ ชื่อ Facebook...'" class="w-full px-2 py-1 rounded-lg border border-amber-200 text-[11px] outline-none">
                  <textarea x-model="handoffMessage" rows="2" placeholder="ข้อความเพิ่มเติม (ไม่บังคับ)..." class="w-full px-2 py-1 rounded-lg border border-amber-200 text-[11px] outline-none resize-none"></textarea>
                  <button @click="submitHandoff()" :disabled="handoffSubmitting || !handoffContact.trim()"
                          class="w-full px-2 py-1 rounded-lg bg-amber-500 text-white text-[11px] font-semibold hover:bg-amber-600 transition-colors disabled:opacity-50">
                    <span x-text="handoffSubmitting ? 'กำลังส่ง...' : 'ส่งคำขอติดต่อ'"></span>
                  </button>
                </div>

                <!-- Input Area -->
                <div class="px-3 py-2.5 border-t border-slate-100 bg-white flex items-end gap-2 shrink-0">
                  <textarea x-ref="previewInput" @keydown.enter.prevent="sendPreviewMsg()"
                            placeholder="พิมพ์ข้อความ..." rows="1"
                            class="flex-1 resize-none border border-slate-200 rounded-xl px-3 py-2 text-[13px] outline-none focus:border-indigo-400 focus:ring-1 focus:ring-indigo-200 max-h-20 overflow-y-auto"
                            style="font-family:'Sarabun',sans-serif;"></textarea>
                  <button @click="sendPreviewMsg()" :disabled="chatLoading"
                          class="w-9 h-9 rounded-xl text-white flex items-center justify-center shrink-0 transition-transform hover:scale-105 disabled:opacity-50"
                          :style="'background:linear-gradient(135deg,'+cfg.color+','+cfg.color+'dd);'">
                    <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                      <path stroke-linecap="round" stroke-linejoin="round" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"/>
                    </svg>
                  </button>
                </div>

                <p class="text-center text-[10px] text-slate-400 py-1 bg-white">Powered by AI Chatbot</p>
              </div>

            </div><!-- /preview-viewport -->
          </div><!-- /preview container -->

          <p class="text-xs text-slate-400 mt-3 text-center">
            ทดสอบพิมพ์ข้อความจริงได้ ระบบจะส่งไปยัง API ของบอท <strong><?= htmlspecialchars($bot['slug']) ?></strong>
          </p>
        </div>
      </div>
    </div>
  </div>

  <!-- ══════════════ Members Tab ══════════════ -->
  <div x-show="tab==='members'" x-transition>
    <div class="bg-white rounded-2xl border border-slate-100 mb-5">
      <!-- Add Member Form -->
      <div class="px-6 py-4 border-b border-slate-100 flex flex-wrap items-end gap-3">
        <div class="flex-1 min-w-[200px]">
          <label class="block text-xs font-semibold text-slate-600 mb-1">เพิ่มสมาชิก</label>
          <select x-model="newMember.userId"
                  class="w-full px-3 py-2 rounded-xl border border-slate-200 text-sm focus:ring-2 focus:ring-indigo-500 outline-none">
            <option value="">-- เลือกผู้ใช้ --</option>
            <?php foreach ($allUsers as $u): ?>
            <option value="<?= $u['id'] ?>"><?= htmlspecialchars($u['display_name'] . ' (' . $u['email'] . ')') ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="w-36">
          <label class="block text-xs font-semibold text-slate-600 mb-1">Role</label>
          <select x-model="newMember.role"
                  class="w-full px-3 py-2 rounded-xl border border-slate-200 text-sm focus:ring-2 focus:ring-indigo-500 outline-none">
            <option value="viewer">Viewer</option>
            <option value="editor">Editor</option>
            <option value="owner">Owner</option>
          </select>
        </div>
        <button @click="addMember()" :disabled="!newMember.userId || saving"
                class="px-4 py-2 rounded-xl bg-indigo-600 text-white text-sm font-semibold hover:bg-indigo-700 disabled:opacity-50 transition-colors">
          เพิ่ม
        </button>
      </div>

      <!-- Members List -->
      <div class="divide-y divide-slate-50">
        <?php foreach ($members as $m): ?>
        <div class="px-6 py-3 flex items-center gap-3 hover:bg-slate-50 transition-colors">
          <?php if ($m['avatar_url']): ?>
            <img src="<?= htmlspecialchars($m['avatar_url']) ?>" class="w-8 h-8 rounded-lg object-cover">
          <?php else: ?>
            <div class="w-8 h-8 rounded-lg flex items-center justify-center text-white text-xs font-bold"
                 style="background: linear-gradient(135deg,#4f46e5,#7c3aed);">
              <?= strtoupper(substr($m['display_name'] ?? 'U', 0, 1)) ?>
            </div>
          <?php endif; ?>

          <div class="flex-1 min-w-0">
            <p class="text-sm font-semibold text-slate-800 truncate"><?= htmlspecialchars($m['display_name']) ?></p>
            <p class="text-xs text-slate-400 truncate"><?= htmlspecialchars($m['email']) ?></p>
          </div>

          <select onchange="updateMemberRole(<?= (int)$m['user_id'] ?>, this.value)"
                  class="px-2 py-1 rounded-lg border border-slate-200 text-xs font-semibold focus:ring-2 focus:ring-indigo-500 outline-none
                         <?= match($m['role']) { 'owner' => 'text-purple-700 bg-purple-50', 'editor' => 'text-blue-700 bg-blue-50', default => 'text-slate-600 bg-slate-50' } ?>">
            <option value="owner" <?= $m['role']==='owner' ? 'selected' : '' ?>>Owner</option>
            <option value="editor" <?= $m['role']==='editor' ? 'selected' : '' ?>>Editor</option>
            <option value="viewer" <?= $m['role']==='viewer' ? 'selected' : '' ?>>Viewer</option>
          </select>

          <?php if ((int)$m['user_id'] !== Auth::get('id')): ?>
          <button onclick="removeMember(<?= (int)$m['user_id'] ?>, '<?= addslashes($m['display_name']) ?>')"
                  class="p-1.5 rounded-lg text-slate-400 hover:text-red-600 hover:bg-red-50 transition-colors" title="ลบ">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
            </svg>
          </button>
          <?php else: ?>
          <span class="text-[10px] text-slate-400 px-2">คุณ</span>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>

        <?php if (empty($members)): ?>
        <div class="px-6 py-8 text-center text-slate-400 text-sm">ยังไม่มีสมาชิก</div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<script>
const CSRF = document.querySelector('meta[name="csrf-token"]')?.content || '';
const BOT_ID = <?= $botId ?>;
const BOT_SLUG = '<?= addslashes($bot['slug']) ?>';
const APP_URL  = '<?= addslashes($appUrl) ?>';

function botEditPage() {
  return {
    tab: 'general', saving: false, banner: '', bannerOk: true,
    newMember: { userId: '', role: 'viewer' },

    init() {
      const hash = window.location.hash.replace('#', '');
      if (['general','ai','platform','widget','members'].includes(hash)) this.tab = hash;
    },

    async saveGeneral() {
      this.saving = true;
      const form = document.querySelector('[x-show="tab===\'general\'"] form');
      const fd = new FormData(form);
      fd.append('action', 'save_general');
      fd.append('_csrf_token', CSRF);
      try {
        const res = await fetch('bot-edit.php?id=' + BOT_ID, { method: 'POST', body: fd });
        const json = await res.json();
        this.showBanner(json.message, json.success);
      } catch (e) {
        this.showBanner('Error: ' + e.message, false);
      }
      this.saving = false;
    },

    async saveSettings(group) {
      this.saving = true;
      const form = document.querySelector(`[x-show="tab==='${group}'"] form`);
      const fd = new FormData(form);
      fd.append('action', 'save_settings');
      fd.append('group', group);
      fd.append('_csrf_token', CSRF);
      try {
        const res = await fetch('bot-edit.php?id=' + BOT_ID, { method: 'POST', body: fd });
        const json = await res.json();
        this.showBanner(json.message, json.success);
      } catch (e) {
        this.showBanner('Error: ' + e.message, false);
      }
      this.saving = false;
    },

    async addMember() {
      if (!this.newMember.userId) return;
      this.saving = true;
      const fd = new FormData();
      fd.append('action', 'add_member');
      fd.append('_csrf_token', CSRF);
      fd.append('user_id', this.newMember.userId);
      fd.append('member_role', this.newMember.role);
      try {
        const res = await fetch('bot-edit.php?id=' + BOT_ID, { method: 'POST', body: fd });
        const json = await res.json();
        this.showBanner(json.message, json.success);
        if (json.success) setTimeout(() => location.reload(), 500);
      } catch (e) {
        this.showBanner('Error: ' + e.message, false);
      }
      this.saving = false;
    },

    showBanner(msg, ok) {
      this.banner = msg;
      this.bannerOk = ok;
      window.scrollTo({ top: 0, behavior: 'smooth' });
      if (ok) setTimeout(() => this.banner = '', 4000);
    }
  };
}

/* ── Widget Preview Component ─────────────────────────────────── */
function widgetPreview() {
  return {
    cfg: {
      botName:    '<?= addslashes(bs('bot_name', $bot['name'])) ?>',
      welcomeMsg: '<?= addslashes(bs('chat_welcome_message','สวัสดีครับ! ยินดีให้บริการ 😊')) ?>',
      color:      '<?= addslashes(bs('primary_color','#4f46e5')) ?>',
      iconUrl:    '<?= addslashes(bs('widget_icon_url','')) ?>',
      logoUrl:    '<?= addslashes(bs('widget_logo_url','')) ?>',
      iconSize:   <?= (int)(($settings['widget_icon_size'] ?? '') ?: 60) ?>,
      allowFeedback: <?= ($settings['allow_feedback'] ?? 'true') !== 'false' ? 'true' : 'false' ?>,
      draggable:  <?= ($settings['widget_draggable'] ?? 'false') === 'true' ? 'true' : 'false' ?>,
      quickReplies: <?= json_encode(json_decode($settings['quick_replies'] ?? '[]', true) ?: [], JSON_UNESCAPED_UNICODE) ?>,
      handoffEnabled: <?= ($settings['handoff_enabled'] ?? 'false') === 'true' ? 'true' : 'false' ?>,
      handoffEmail: '<?= addslashes($settings['handoff_email'] ?? '') ?>',
    },
    presetColors: ['#4f46e5','#7c3aed','#2563eb','#0891b2','#059669','#d97706','#dc2626','#db2777','#4b5563','#0f172a'],
    previewDevice: 'desktop',
    chatOpen: false,
    chatMessages: [],
    showQuickReplies: true,
    showHandoffForm: false,
    handoffSent: false,
    handoffSubmitting: false,
    handoffType: 'line',
    handoffContact: '',
    handoffMessage: '',
    chatLoading: false,
    chatSessionId: null,
    copied: false,
    saving: false,
    dragPos: { bottom: 20, right: 20 },
    _dragMoved: false,
    _dragData: null,

    initPreview() {
      this.chatSessionId = BOT_SLUG + '-preview-' + Math.random().toString(36).substr(2, 9) + '-' + Date.now();
    },

    refreshPreview() {
      // reactive via x-model — no extra work needed
    },

    handleIconUpload(event) {
      this._processImageUpload(event, (dataUrl) => {
        this.cfg.iconUrl = dataUrl;
        this.refreshPreview();
      });
    },

    handleLogoUpload(event) {
      this._processImageUpload(event, (dataUrl) => {
        this.cfg.logoUrl = dataUrl;
        this.refreshPreview();
      });
    },

    _processImageUpload(event, callback) {
      const file = event.target.files[0];
      if (!file) return;
      const showErr = (msg) => {
        const parent = Alpine.$data(document.querySelector('[x-data="botEditPage()"]'));
        if (parent) parent.showBanner(msg, false);
      };
      // Validate type
      if (!file.type.match(/^image\/(png|jpe?g|svg\+xml|webp|gif)$/)) {
        showErr('รองรับเฉพาะไฟล์รูปภาพ (PNG, JPG, SVG, WebP)');
        event.target.value = '';
        return;
      }
      // Validate size (2MB raw max)
      if (file.size > 2 * 1024 * 1024) {
        showErr('ไฟล์ใหญ่เกินไป (ไม่เกิน 2MB)');
        event.target.value = '';
        return;
      }
      // SVG: read directly (small files, no canvas needed)
      if (file.type === 'image/svg+xml') {
        const reader = new FileReader();
        reader.onload = (e) => callback(e.target.result);
        reader.readAsDataURL(file);
        event.target.value = '';
        return;
      }
      // Raster images: resize to max 200x200 via canvas to keep base64 small
      const reader = new FileReader();
      reader.onload = (e) => {
        const img = new Image();
        img.onload = () => {
          const MAX = 200;
          let w = img.width, h = img.height;
          if (w > MAX || h > MAX) {
            const ratio = Math.min(MAX / w, MAX / h);
            w = Math.round(w * ratio);
            h = Math.round(h * ratio);
          }
          const canvas = document.createElement('canvas');
          canvas.width = w;
          canvas.height = h;
          const ctx = canvas.getContext('2d');
          ctx.drawImage(img, 0, 0, w, h);
          // Use PNG for transparency support, quality compression
          const dataUrl = canvas.toDataURL('image/png');
          callback(dataUrl);
        };
        img.src = e.target.result;
      };
      reader.readAsDataURL(file);
      event.target.value = ''; // reset so same file can be re-uploaded
    },

    // ── Drag support for preview chat button ──
    startDrag(e) {
      if (!this.cfg.draggable) return;
      this._dragMoved = false;
      const isTouch = e.type === 'touchstart';
      const startX = isTouch ? e.touches[0].clientX : e.clientX;
      const startY = isTouch ? e.touches[0].clientY : e.clientY;
      const startRight = this.dragPos.right;
      const startBottom = this.dragPos.bottom;
      const viewport = document.getElementById('preview-viewport');
      if (!viewport) return;
      const rect = viewport.getBoundingClientRect();

      const onMove = (ev) => {
        const cx = isTouch ? ev.touches[0].clientX : ev.clientX;
        const cy = isTouch ? ev.touches[0].clientY : ev.clientY;
        const dx = cx - startX;
        const dy = cy - startY;
        if (Math.abs(dx) > 3 || Math.abs(dy) > 3) this._dragMoved = true;
        const maxR = rect.width - parseInt(this.cfg.iconSize) - 4;
        const maxB = rect.height - parseInt(this.cfg.iconSize) - 4;
        this.dragPos.right  = Math.max(0, Math.min(maxR, startRight - dx));
        this.dragPos.bottom = Math.max(0, Math.min(maxB, startBottom - dy));
      };
      const onUp = () => {
        document.removeEventListener(isTouch ? 'touchmove' : 'mousemove', onMove);
        document.removeEventListener(isTouch ? 'touchend' : 'mouseup', onUp);
        // Reset _dragMoved after a tick so click handler can read it
        setTimeout(() => { this._dragMoved = false; }, 50);
      };
      document.addEventListener(isTouch ? 'touchmove' : 'mousemove', onMove);
      document.addEventListener(isTouch ? 'touchend' : 'mouseup', onUp);
    },

    togglePreviewChat() {
      this.chatOpen = !this.chatOpen;
      if (this.chatOpen && this.chatMessages.length === 0) {
        this.chatMessages.push({
          role: 'assistant',
          content: this.cfg.welcomeMsg || 'สวัสดีครับ! ยินดีให้บริการ 😊',
          fallback: false,
          messageId: null,
          feedback: null
        });
      }
      if (this.chatOpen) {
        this.$nextTick(() => {
          if (this.$refs.previewInput) this.$refs.previewInput.focus();
          this.scrollPreview();
        });
      }
    },

    async sendFeedback(msg, type) {
      if (msg.feedback) return;
      msg.feedback = type;
      if (msg.messageId) {
        fetch(APP_URL + '/api/chat.php?action=feedback&bot=' + encodeURIComponent(BOT_SLUG), {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ message_id: msg.messageId, feedback: type })
        }).catch(() => {});
      }
    },

    async sendPreviewMsg(quickReplyText) {
      const input = this.$refs.previewInput;
      const text = quickReplyText || (input?.value || '').trim();
      if (!text || this.chatLoading) return;
      if (input) input.value = '';

      this.chatMessages.push({ role: 'user', content: text, fallback: false, messageId: null, feedback: null });
      this.chatLoading = true;
      this.$nextTick(() => this.scrollPreview());

      try {
        const res = await fetch(APP_URL + '/api/chat.php?bot=' + encodeURIComponent(BOT_SLUG), {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ message: text, session_id: this.chatSessionId })
        });
        const data = await res.json();
        if (data.success) {
          this.chatMessages.push({
            role: 'assistant',
            content: data.data.reply,
            fallback: !!data.data.is_fallback,
            messageId: data.data.message_id || null,
            feedback: null
          });
        } else {
          this.chatMessages.push({ role: 'assistant', content: 'เกิดข้อผิดพลาด: ' + (data.message || 'Unknown'), fallback: true, messageId: null, feedback: null });
        }
      } catch (e) {
        this.chatMessages.push({ role: 'assistant', content: 'ไม่สามารถเชื่อมต่อเซิร์ฟเวอร์ได้', fallback: true, messageId: null, feedback: null });
      }
      this.chatLoading = false;
      this.$nextTick(() => this.scrollPreview());
    },

    async submitHandoff() {
      if (!this.handoffContact.trim()) return;
      this.handoffSubmitting = true;
      try {
        const res = await fetch(APP_URL + '/api/chat.php?action=handoff&bot=' + encodeURIComponent(BOT_SLUG), {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            session_id: this.chatSessionId,
            contact_type: this.handoffType,
            contact_id: this.handoffContact.trim(),
            message: this.handoffMessage.trim()
          })
        });
        const data = await res.json();
        if (data.success) {
          this.showHandoffForm = false;
          this.handoffSent = true;
          this.chatMessages.push({ role: 'assistant', content: 'ได้รับคำขอเรียบร้อยแล้วครับ เจ้าหน้าที่จะติดต่อกลับโดยเร็ว 🙏', fallback: false, messageId: null, feedback: null });
          this.$nextTick(() => this.scrollPreview());
        } else {
          Noti.error(data.message || 'เกิดข้อผิดพลาด');
        }
      } catch (e) { Noti.error('ไม่สามารถส่งคำขอได้: ' + e.message); }
      this.handoffSubmitting = false;
    },

    scrollPreview() {
      const el = document.getElementById('preview-messages');
      if (el) el.scrollTop = el.scrollHeight;
    },

    escHtml(t) {
      return t.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    },

    formatBotMsg(raw) {
      let t = this.escHtml(raw);
      const clr = this.cfg.color || '#4f46e5';
      t = t.replace(/\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)/g, `<a href="$2" target="_blank" rel="noopener noreferrer" style="color:${clr};text-decoration:underline;">$1</a>`);
      t = t.replace(/(?<!href="|href=&quot;|>)(https?:\/\/[^\s<,)"&*]+)/g, `<a href="$1" target="_blank" rel="noopener noreferrer" style="color:${clr};text-decoration:underline;word-break:break-all;">$1</a>`);
      t = t.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
      t = t.replace(/^### (.+)$/gm, '<strong style="display:block;margin:6px 0 2px;font-size:13px;">$1</strong>');
      t = t.replace(/^## (.+)$/gm, '<strong style="display:block;margin:6px 0 2px;font-size:14px;">$1</strong>');
      t = t.replace(/^&gt; (.+)$/gm, '<div style="border-left:3px solid #cbd5e1;padding:2px 8px;margin:2px 0;color:#64748b;font-size:12px;">$1</div>');
      t = this._formatTable(t);
      t = t.replace(/^[\-\*] (.+)$/gm, '<div style="padding-left:10px;">• $1</div>');
      t = t.replace(/^(\d+)\. (.+)$/gm, '<div style="padding-left:10px;">$1. $2</div>');
      t = t.replace(/^  [\-\*] (.+)$/gm, '<div style="padding-left:20px;">◦ $1</div>');
      t = t.replace(/\n/g, '<br>');
      t = t.replace(/(<\/div>)<br>/g, '$1');
      t = t.replace(/(<\/table>)<br>/g, '$1');
      t = t.replace(/(<\/strong>)<br>/g, '$1');
      return t;
    },

    _formatTable(text) {
      const lines = text.split('\n'); let result = [], inTable = false, html = '', isHeader = true;
      for (let i = 0; i < lines.length; i++) {
        const line = lines[i].trim();
        if (/^\|(.+)\|$/.test(line)) {
          if (/^\|[\s\-:|]+\|$/.test(line)) continue;
          if (!inTable) { inTable = true; isHeader = true; html = '<table style="border-collapse:collapse;width:100%;margin:4px 0;font-size:12px;">'; }
          const cells = line.split('|').filter(c => c !== '').map(c => c.trim());
          const tag = isHeader ? 'th' : 'td';
          const s = isHeader ? 'border:1px solid #e2e8f0;padding:3px 6px;background:#f1f5f9;font-weight:600;text-align:left;' : 'border:1px solid #e2e8f0;padding:3px 6px;';
          html += '<tr>' + cells.map(c => `<${tag} style="${s}">${c}</${tag}>`).join('') + '</tr>';
          isHeader = false;
        } else {
          if (inTable) { html += '</table>'; result.push(html); inTable = false; html = ''; }
          result.push(lines[i]);
        }
      }
      if (inTable) { html += '</table>'; result.push(html); }
      return result.join('\n');
    },

    embedCode() {
      let attrs = `\n        data-bot="${this.escHtml(BOT_SLUG)}"`;
      if (this.cfg.color && this.cfg.color !== '#4f46e5') attrs += `\n        data-color="${this.escHtml(this.cfg.color)}"`;
      if (this.cfg.iconUrl) attrs += `\n        data-icon="${this.escHtml(this.cfg.iconUrl)}"`;
      if (this.cfg.logoUrl) attrs += `\n        data-logo="${this.escHtml(this.cfg.logoUrl)}"`;
      if (this.cfg.iconSize && this.cfg.iconSize != 60) attrs += `\n        data-icon-size="${this.cfg.iconSize}"`;
      if (this.cfg.draggable) attrs += `\n        data-draggable="true"`;
      return `&lt;script src="${this.escHtml(APP_URL)}/widget.js"${attrs}&gt;&lt;/script&gt;`;
    },

    copyEmbed() {
      let attrs = `\n        data-bot="${BOT_SLUG}"`;
      if (this.cfg.color && this.cfg.color !== '#4f46e5') attrs += `\n        data-color="${this.cfg.color}"`;
      if (this.cfg.iconUrl) attrs += `\n        data-icon="${this.cfg.iconUrl}"`;
      if (this.cfg.logoUrl) attrs += `\n        data-logo="${this.cfg.logoUrl}"`;
      if (this.cfg.iconSize && this.cfg.iconSize != 60) attrs += `\n        data-icon-size="${this.cfg.iconSize}"`;
      if (this.cfg.draggable) attrs += `\n        data-draggable="true"`;
      const code = `<script src="${APP_URL}/widget.js"${attrs}><\/script>`;
      navigator.clipboard.writeText(code).then(() => {
        this.copied = true;
        setTimeout(() => this.copied = false, 2000);
      });
    },

    async saveWidget() {
      this.saving = true;
      const form = document.getElementById('widget-form');
      const fd = new FormData(form);
      fd.append('action', 'save_settings');
      fd.append('group', 'widget');
      fd.append('_csrf_token', CSRF);
      // Checkboxes: use Alpine state (boolean) as source of truth
      fd.set('allow_feedback', this.cfg.allowFeedback ? 'true' : 'false');
      fd.set('widget_draggable', this.cfg.draggable ? 'true' : 'false');
      fd.set('quick_replies', JSON.stringify(this.cfg.quickReplies.filter(q => q.trim())));
      fd.set('handoff_enabled', this.cfg.handoffEnabled ? 'true' : 'false');
      fd.set('handoff_email', this.cfg.handoffEmail || '');
      try {
        const res = await fetch('bot-edit.php?id=' + BOT_ID, { method: 'POST', body: fd });
        const json = await res.json();
        // Access parent component's showBanner
        const parent = Alpine.$data(document.querySelector('[x-data="botEditPage()"]'));
        if (parent) parent.showBanner(json.message, json.success);
      } catch (e) {
        const parent = Alpine.$data(document.querySelector('[x-data="botEditPage()"]'));
        if (parent) parent.showBanner('Error: ' + e.message, false);
      }
      this.saving = false;
    }
  };
}

async function updateMemberRole(userId, role) {
  const fd = new FormData();
  fd.append('action', 'update_member_role');
  fd.append('_csrf_token', CSRF);
  fd.append('user_id', userId);
  fd.append('member_role', role);
  try {
    const res = await fetch('bot-edit.php?id=' + BOT_ID, { method: 'POST', body: fd });
    const json = await res.json();
    if (!json.success) Noti.error(json.message);
  } catch (e) {
    Noti.error('Error: ' + e.message);
  }
}

async function removeMember(userId, name) {
  if (!await Noti.confirm('ลบสมาชิก "' + name + '" ออกจากบอทนี้?')) return;
  const fd = new FormData();
  fd.append('action', 'remove_member');
  fd.append('_csrf_token', CSRF);
  fd.append('user_id', userId);
  try {
    const res = await fetch('bot-edit.php?id=' + BOT_ID, { method: 'POST', body: fd });
    const json = await res.json();
    if (json.success) location.reload();
    else Noti.error(json.message);
  } catch (e) {
    Noti.error('Error: ' + e.message);
  }
}
</script>

<?php require __DIR__ . '/layouts/footer.php'; ?>
