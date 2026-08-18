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

$pageTitle  = 'Bots';
$breadcrumb = ['Admin', 'Bots'];
Auth::requirePermission('manage_bots');

$db     = Database::getInstance();
$logger = new LogService();

// ── AJAX Handlers ──────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Auth::validateCsrf()) Response::error('CSRF failed', 403);
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $name = trim($_POST['name'] ?? '');
        $slug = trim($_POST['slug'] ?? '');
        $desc = trim($_POST['description'] ?? '');

        if (empty($name)) Response::error('กรุณาระบุชื่อบอท', 422);

        if (empty($slug)) {
            $slug = preg_replace('/[^a-z0-9\-]/', '', str_replace(' ', '-', strtolower($name)));
        }
        $slug = preg_replace('/[^a-z0-9\-]/', '', strtolower($slug));
        if (empty($slug)) $slug = 'bot-' . time();

        $exists = $db->fetch('SELECT id FROM bots WHERE slug = ?', [$slug]);
        if ($exists) Response::error('Slug นี้ถูกใช้แล้ว กรุณาเปลี่ยนชื่อ', 409);

        $botId = $db->insert('bots', [
            'slug'        => $slug,
            'name'        => $name,
            'description' => $desc ?: null,
            'is_active'   => 1,
            'created_by'  => Auth::get('id'),
        ]);

        $db->insert('bot_users', [
            'bot_id'  => $botId,
            'user_id' => Auth::get('id'),
            'role'    => 'owner',
        ]);

        $defaultSettings = [
            'system_prompt'        => '',
            'temperature'          => '0.3',
            'max_tokens'           => '1024',
            'similarity_threshold' => '0.35',
            'top_k_chunks'         => '5',
            'history_length'       => '10',
            'fallback_message'     => 'ขออภัยครับ ไม่พบข้อมูลที่ตรงกับคำถามของคุณ',
            'bot_name'             => $name,
            'chat_welcome_message' => 'สวัสดีครับ! ยินดีให้บริการ 😊',
            'primary_color'        => '#4f46e5',
        ];
        foreach ($defaultSettings as $key => $val) {
            $db->insert('bot_settings', [
                'bot_id'        => $botId,
                'setting_key'   => $key,
                'setting_value' => $val,
            ]);
        }

        $logger->logActivity(Auth::get('id'), 'bot.create', 'bot', (int)$botId, null, ['name' => $name, 'slug' => $slug]);
        Response::success(['id' => $botId, 'slug' => $slug], 'สร้างบอทสำเร็จ');
    }

    if ($action === 'toggle_active') {
        $id  = (int)($_POST['id'] ?? 0);
        $val = (int)($_POST['is_active'] ?? 0);
        if (!Auth::canAccessBot($id)) Response::error('ไม่มีสิทธิ์', 403);
        $db->update('bots', ['is_active' => $val], ['id' => $id]);
        $logger->logActivity(Auth::get('id'), 'bot.toggle', 'bot', $id, null, ['is_active' => $val]);
        Response::success(null, $val ? 'เปิดใช้งานบอทแล้ว' : 'ปิดใช้งานบอทแล้ว');
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if (!Auth::canAccessBot($id)) Response::error('ไม่มีสิทธิ์', 403);
        $role = Auth::botRole($id);
        if ($role !== 'owner' && !Auth::isSuperAdmin()) Response::error('เฉพาะ Owner เท่านั้นที่สามารถลบบอทได้', 403);

        $bot = $db->fetch('SELECT slug FROM bots WHERE id = ?', [$id]);
        if ($bot && $bot['slug'] === 'default') Response::error('ไม่สามารถลบ Default Bot ได้', 422);

        $db->delete('bot_settings', ['bot_id' => $id]);
        $db->delete('bot_users', ['bot_id' => $id]);
        $db->delete('bots', ['id' => $id]);
        $logger->logActivity(Auth::get('id'), 'bot.delete', 'bot', $id);
        Response::success(null, 'ลบบอทสำเร็จ');
    }

    Response::error('Unknown action', 400);
}

// ── Load Bots ─────────────────────────────────────────────────────────
if (Auth::isSuperAdmin()) {
    $bots = $db->fetchAll('
        SELECT b.*, au.display_name AS creator_name,
               (SELECT COUNT(*) FROM knowledge_sources WHERE bot_id = b.id) AS knowledge_count,
               (SELECT COUNT(*) FROM conversations WHERE bot_id = b.id) AS conversation_count,
               (SELECT COUNT(*) FROM bot_users WHERE bot_id = b.id) AS member_count
        FROM bots b
        LEFT JOIN admin_users au ON au.id = b.created_by
        ORDER BY b.created_at DESC
    ');
} else {
    $bots = $db->fetchAll('
        SELECT b.*, au.display_name AS creator_name, bu.role AS my_role,
               (SELECT COUNT(*) FROM knowledge_sources WHERE bot_id = b.id) AS knowledge_count,
               (SELECT COUNT(*) FROM conversations WHERE bot_id = b.id) AS conversation_count,
               (SELECT COUNT(*) FROM bot_users WHERE bot_id = b.id) AS member_count
        FROM bots b
        INNER JOIN bot_users bu ON bu.bot_id = b.id AND bu.user_id = ?
        LEFT JOIN admin_users au ON au.id = b.created_by
        ORDER BY b.created_at DESC
    ', [Auth::get('id')]);
}

$stats = [
    'total'    => count($bots),
    'active'   => count(array_filter($bots, fn($b) => $b['is_active'])),
    'inactive' => count(array_filter($bots, fn($b) => !$b['is_active'])),
];

require __DIR__ . '/layouts/header.php';
?>
<meta name="csrf-token" content="<?= Auth::csrfToken() ?>">

<div x-data="botsPage()" x-init="init()">

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

  <!-- Stats Cards -->
  <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6">
    <div class="bg-white rounded-2xl border border-slate-100 p-5 flex items-center gap-4">
      <div class="w-12 h-12 rounded-xl flex items-center justify-center bg-brand-50 text-brand-600">
        <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
          <path stroke-linecap="round" stroke-linejoin="round" d="M9.75 3.104v5.714a2.25 2.25 0 01-.659 1.591L5 14.5M9.75 3.104c-.251.023-.501.05-.75.082m.75-.082a24.301 24.301 0 014.5 0m0 0v5.714a2.25 2.25 0 00.659 1.591L19 14.5"/>
        </svg>
      </div>
      <div>
        <p class="text-2xl font-bold text-slate-800"><?= $stats['total'] ?></p>
        <p class="text-sm text-slate-500">บอททั้งหมด</p>
      </div>
    </div>
    <div class="bg-white rounded-2xl border border-slate-100 p-5 flex items-center gap-4">
      <div class="w-12 h-12 rounded-xl flex items-center justify-center bg-emerald-50 text-emerald-600">
        <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
          <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
        </svg>
      </div>
      <div>
        <p class="text-2xl font-bold text-slate-800"><?= $stats['active'] ?></p>
        <p class="text-sm text-slate-500">เปิดใช้งาน</p>
      </div>
    </div>
    <div class="bg-white rounded-2xl border border-slate-100 p-5 flex items-center gap-4">
      <div class="w-12 h-12 rounded-xl flex items-center justify-center bg-amber-50 text-amber-600">
        <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
          <path stroke-linecap="round" stroke-linejoin="round" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"/>
        </svg>
      </div>
      <div>
        <p class="text-2xl font-bold text-slate-800"><?= $stats['inactive'] ?></p>
        <p class="text-sm text-slate-500">ปิดใช้งาน</p>
      </div>
    </div>
  </div>

  <!-- Action Bar -->
  <div class="flex flex-wrap items-center justify-between gap-3 mb-6">
    <div class="flex items-center gap-2">
      <input type="text" x-model="search" placeholder="ค้นหาบอท..."
             class="px-4 py-2 rounded-xl border border-slate-200 bg-white text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent w-64">
    </div>
    <button @click="showCreate = true"
            class="flex items-center gap-2 px-4 py-2.5 rounded-xl text-sm font-semibold text-white transition-all shadow-sm hover:shadow-md"
            style="background: linear-gradient(135deg,#4f46e5,#7c3aed);">
      <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
      </svg>
      สร้างบอทใหม่
    </button>
  </div>

  <!-- Bot Cards Grid -->
  <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
    <?php foreach ($bots as $bot): ?>
    <div class="bg-white rounded-2xl border border-slate-100 p-5 card-hover relative"
         x-show="!search || '<?= addslashes(strtolower($bot['name'] . ' ' . $bot['slug'])) ?>'.includes(search.toLowerCase())">

      <!-- Status badge -->
      <div class="absolute top-4 right-4">
        <?php if ($bot['is_active']): ?>
          <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[11px] font-semibold bg-emerald-50 text-emerald-700 border border-emerald-200">
            <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span> Active
          </span>
        <?php else: ?>
          <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[11px] font-semibold bg-slate-100 text-slate-500 border border-slate-200">
            <span class="w-1.5 h-1.5 rounded-full bg-slate-400"></span> Inactive
          </span>
        <?php endif; ?>
      </div>

      <!-- Bot info -->
      <div class="flex items-center gap-3 mb-4">
        <?php if ($bot['avatar_url']): ?>
          <img src="<?= htmlspecialchars($bot['avatar_url']) ?>" class="w-12 h-12 rounded-xl object-cover border border-slate-100">
        <?php else: ?>
          <div class="w-12 h-12 rounded-xl flex items-center justify-center text-white text-lg font-bold"
               style="background: linear-gradient(135deg,#4f46e5,#7c3aed);">
            <?= strtoupper(mb_substr($bot['name'], 0, 1)) ?>
          </div>
        <?php endif; ?>
        <div class="min-w-0 flex-1">
          <h3 class="text-sm font-bold text-slate-800 truncate"><?= htmlspecialchars($bot['name']) ?></h3>
          <p class="text-xs text-slate-400 font-mono"><?= htmlspecialchars($bot['slug']) ?></p>
        </div>
      </div>

      <?php if ($bot['description']): ?>
        <p class="text-xs text-slate-500 mb-4 line-clamp-2"><?= htmlspecialchars($bot['description']) ?></p>
      <?php endif; ?>

      <!-- Stats row -->
      <div class="flex items-center gap-4 text-xs text-slate-500 mb-4">
        <span class="flex items-center gap-1">
          <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"/>
          </svg>
          <?= (int)$bot['knowledge_count'] ?> Knowledge
        </span>
        <span class="flex items-center gap-1">
          <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/>
          </svg>
          <?= (int)$bot['conversation_count'] ?> Chats
        </span>
        <span class="flex items-center gap-1">
          <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/>
          </svg>
          <?= (int)$bot['member_count'] ?> Members
        </span>
      </div>

      <!-- Footer -->
      <div class="flex items-center justify-between pt-3 border-t border-slate-100">
        <div class="text-[11px] text-slate-400">
          สร้างโดย <?= htmlspecialchars($bot['creator_name'] ?? '-') ?>
        </div>
        <div class="flex items-center gap-1">
          <a href="bot-edit.php?id=<?= (int)$bot['id'] ?>"
             class="p-1.5 rounded-lg text-slate-400 hover:text-brand-600 hover:bg-brand-50 transition-colors" title="แก้ไข">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
            </svg>
          </a>
          <button @click="toggleBot(<?= (int)$bot['id'] ?>, <?= $bot['is_active'] ? 0 : 1 ?>)"
                  class="p-1.5 rounded-lg transition-colors <?= $bot['is_active'] ? 'text-slate-400 hover:text-amber-600 hover:bg-amber-50' : 'text-slate-400 hover:text-emerald-600 hover:bg-emerald-50' ?>"
                  title="<?= $bot['is_active'] ? 'ปิดใช้งาน' : 'เปิดใช้งาน' ?>">
            <?php if ($bot['is_active']): ?>
              <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"/>
              </svg>
            <?php else: ?>
              <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
              </svg>
            <?php endif; ?>
          </button>
          <?php if ($bot['slug'] !== 'default'): ?>
          <button @click="deleteBot(<?= (int)$bot['id'] ?>, '<?= addslashes($bot['name']) ?>')"
                  class="p-1.5 rounded-lg text-slate-400 hover:text-red-600 hover:bg-red-50 transition-colors" title="ลบ">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
            </svg>
          </button>
          <?php endif; ?>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>

  <?php if (empty($bots)): ?>
  <div class="text-center py-16 text-slate-400">
    <svg class="w-16 h-16 mx-auto mb-4 opacity-30" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1">
      <path stroke-linecap="round" stroke-linejoin="round" d="M9.75 3.104v5.714a2.25 2.25 0 01-.659 1.591L5 14.5M9.75 3.104c-.251.023-.501.05-.75.082m.75-.082a24.301 24.301 0 014.5 0m0 0v5.714a2.25 2.25 0 00.659 1.591L19 14.5"/>
    </svg>
    <p class="text-lg font-semibold">ยังไม่มีบอท</p>
    <p class="text-sm mt-1">สร้างบอทตัวแรกเพื่อเริ่มต้นใช้งาน</p>
  </div>
  <?php endif; ?>

  <!-- Create Bot Modal -->
  <div x-show="showCreate" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 backdrop-blur-sm"
       x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
       x-transition:leave="transition ease-in duration-150" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"
       @click.self="showCreate = false" @keydown.escape.window="showCreate = false">
    <div class="bg-white rounded-2xl shadow-xl w-full max-w-md mx-4 overflow-hidden"
         x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0 scale-95" x-transition:enter-end="opacity-100 scale-100">

      <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between">
        <h3 class="text-lg font-bold text-slate-800">สร้างบอทใหม่</h3>
        <button @click="showCreate = false" class="text-slate-400 hover:text-slate-600">
          <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
          </svg>
        </button>
      </div>

      <form @submit.prevent="createBot()" class="p-6 space-y-4">
        <div>
          <label class="block text-sm font-semibold text-slate-700 mb-1">ชื่อบอท <span class="text-red-500">*</span></label>
          <input type="text" x-model="newBot.name" required
                 @input="newBot.slug = newBot.name.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '')"
                 class="w-full px-4 py-2.5 rounded-xl border border-slate-200 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent"
                 placeholder="เช่น UP Chatbot">
        </div>
        <div>
          <label class="block text-sm font-semibold text-slate-700 mb-1">Slug</label>
          <input type="text" x-model="newBot.slug"
                 class="w-full px-4 py-2.5 rounded-xl border border-slate-200 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent"
                 placeholder="auto-generated">
          <p class="text-[11px] text-slate-400 mt-1">ใช้ใน URL เช่น widget.js?data-bot="slug"</p>
        </div>
        <div>
          <label class="block text-sm font-semibold text-slate-700 mb-1">คำอธิบาย</label>
          <textarea x-model="newBot.description" rows="2"
                    class="w-full px-4 py-2.5 rounded-xl border border-slate-200 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent resize-none"
                    placeholder="อธิบายสั้นๆ เกี่ยวกับบอทนี้"></textarea>
        </div>

        <div class="flex justify-end gap-2 pt-2">
          <button type="button" @click="showCreate = false"
                  class="px-4 py-2.5 rounded-xl text-sm font-semibold text-slate-600 bg-slate-100 hover:bg-slate-200 transition-colors">
            ยกเลิก
          </button>
          <button type="submit" :disabled="saving"
                  class="px-5 py-2.5 rounded-xl text-sm font-semibold text-white transition-all shadow-sm hover:shadow-md disabled:opacity-50"
                  style="background: linear-gradient(135deg,#4f46e5,#7c3aed);">
            <span x-show="!saving">สร้างบอท</span>
            <span x-show="saving">กำลังสร้าง...</span>
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
function botsPage() {
  const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
  return {
    search: '',
    showCreate: false,
    saving: false,
    banner: '', bannerOk: true,
    newBot: { name: '', slug: '', description: '' },

    init() {},

    async createBot() {
      this.saving = true;
      try {
        const fd = new FormData();
        fd.append('action', 'create');
        fd.append('_csrf_token', csrf);
        fd.append('name', this.newBot.name);
        fd.append('slug', this.newBot.slug);
        fd.append('description', this.newBot.description);

        const res = await fetch('bots.php', { method: 'POST', body: fd });
        const json = await res.json();

        if (json.success) {
          this.showBanner(json.message, true);
          this.showCreate = false;
          setTimeout(() => window.location.href = 'bot-edit.php?id=' + json.data.id, 600);
        } else {
          this.showBanner(json.message, false);
        }
      } catch (e) {
        this.showBanner('เกิดข้อผิดพลาด: ' + e.message, false);
      }
      this.saving = false;
    },

    async toggleBot(id, active) {
      const fd = new FormData();
      fd.append('action', 'toggle_active');
      fd.append('_csrf_token', csrf);
      fd.append('id', id);
      fd.append('is_active', active);
      try {
        const res = await fetch('bots.php', { method: 'POST', body: fd });
        const json = await res.json();
        this.showBanner(json.message, json.success);
        if (json.success) setTimeout(() => location.reload(), 500);
      } catch (e) {
        this.showBanner('Error: ' + e.message, false);
      }
    },

    async deleteBot(id, name) {
      if (!await Noti.confirm('ลบบอท "' + name + '" ?\n\nข้อมูล Knowledge Base และ Conversations ที่เชื่อมกับบอทนี้จะยังคงอยู่')) return;
      const fd = new FormData();
      fd.append('action', 'delete');
      fd.append('_csrf_token', csrf);
      fd.append('id', id);
      try {
        const res = await fetch('bots.php', { method: 'POST', body: fd });
        const json = await res.json();
        this.showBanner(json.message, json.success);
        if (json.success) setTimeout(() => location.reload(), 500);
      } catch (e) {
        this.showBanner('Error: ' + e.message, false);
      }
    },

    showBanner(msg, ok) {
      this.banner = msg;
      this.bannerOk = ok;
      if (ok) setTimeout(() => this.banner = '', 4000);
    }
  };
}
</script>

<?php require __DIR__ . '/layouts/footer.php'; ?>
