<?php
define('BASE_PATH', dirname(__DIR__, 2));
require_once BASE_PATH . '/vendor/autoload.php';
use Dotenv\Dotenv;
use App\Helpers\Auth;
use App\Helpers\Response;
use App\Helpers\Database;
use App\Services\GoogleDriveService;
use App\Services\LogService;

$dotenv = Dotenv::createImmutable(BASE_PATH);
$dotenv->safeLoad();
date_default_timezone_set($_ENV['APP_TIMEZONE'] ?? 'Asia/Bangkok');

$pageTitle  = 'Knowledge Base';
$breadcrumb = ['Admin', 'Knowledge Base'];
Auth::requirePermission('manage_knowledge');

$db     = Database::getInstance();
$logger = new LogService();
$botId  = Auth::requireBot();

// ── AJAX Handlers ──────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Auth::validateCsrf()) Response::error('CSRF failed', 403);
    $action = $_POST['action'] ?? '';

    if ($action === 'sync_drive') {
        try {
            $drive = new GoogleDriveService($botId);
            $stats = $drive->syncFilesFromDrive();
            $logger->logActivity(Auth::get('id'), 'knowledge.sync_drive', 'knowledge_source', null, null, $stats, $botId);
            Response::success($stats, "Synced: +{$stats['new']} new, {$stats['updated']} updated");
        } catch (\Throwable $e) {
            Response::error('Drive sync failed: ' . $e->getMessage(), 500);
        }
    }

    if ($action === 'process_source') {
        set_time_limit(1800); // 30 minutes
        ini_set('memory_limit', '1024M');
        $id = (int)($_POST['id'] ?? 0);
        try {
            $drive  = new GoogleDriveService($botId);
            $result = $drive->processSource($id);
            $logger->logActivity(Auth::get('id'), 'knowledge.process', 'knowledge_source', $id, null, $result, $botId);
            if ($result['success']) Response::success($result, "Embedded {$result['chunks']} chunks");
            else Response::error($result['error'] ?? 'Processing failed', 500);
        } catch (\Throwable $e) {
            Response::error($e->getMessage(), 500);
        }
    }

    if ($action === 'process_all_pending') {
        set_time_limit(1800); // 30 minutes
        ini_set('memory_limit', '1024M');
        try {
            $drive  = new GoogleDriveService($botId);
            $result = $drive->processPendingSources();
            $logger->logActivity(Auth::get('id'), 'knowledge.process_all', null, null, null, $result, $botId);
            Response::success($result, "Done: {$result['success']} success, {$result['failed']} failed");
        } catch (\Throwable $e) {
            Response::error($e->getMessage(), 500);
        }
    }

    if ($action === 'add_url') {
        $url = trim($_POST['url'] ?? '');
        if (!$url) Response::error('กรุณาระบุ URL');
        try {
            $drive = new GoogleDriveService($botId);
            $result = $drive->addFileByUrl($url);
            if ($result['success']) {
                $logger->logActivity(Auth::get('id'), 'knowledge.add_url', 'knowledge_source', $result['id'] ?? null, null, ['url' => $url]);
                Response::success(null, $result['message']);
            } else {
                Response::error($result['message'] ?? 'Failed to add URL', 400);
            }
        } catch (\Throwable $e) {
            Response::error($e->getMessage(), 500);
        }
    }

    if ($action === 'toggle_active') {
        $id  = (int)($_POST['id']        ?? 0);
        $val = (int)($_POST['is_active'] ?? 0);
        // BUG-13 fix: verify source belongs to current bot
        $source = $db->fetch('SELECT id FROM knowledge_sources WHERE id = ? AND bot_id = ?', [$id, $botId]);
        if (!$source) Response::error('Source not found', 404);
        $db->update('knowledge_sources', ['is_active' => $val], ['id' => $id, 'bot_id' => $botId]);
        $logger->logActivity(Auth::get('id'), 'knowledge.toggle', 'knowledge_source', $id, null, ['is_active' => $val], $botId);
        Response::success(null, 'Updated');
    }

    if ($action === 'delete_source') {
        $id = (int)($_POST['id'] ?? 0);
        // BUG-14 fix: verify source belongs to current bot before deleting
        $source = $db->fetch('SELECT id FROM knowledge_sources WHERE id = ? AND bot_id = ?', [$id, $botId]);
        if (!$source) Response::error('Source not found', 404);
        $db->delete('knowledge_chunks',  ['source_id' => $id]);
        $db->delete('knowledge_sources', ['id' => $id, 'bot_id' => $botId]);
        $logger->logActivity(Auth::get('id'), 'knowledge.delete', 'knowledge_source', $id, null, null, $botId);
        Response::success(null, 'Deleted');
    }

    Response::error('Unknown action', 400);
}

// ── Load Knowledge Sources ─────────────────────────────────────────────
$sources = $db->fetchAll('SELECT * FROM knowledge_sources WHERE bot_id = ? ORDER BY file_name ASC', [$botId]);
$stats = [
    'total'      => count($sources),
    'synced'     => count(array_filter($sources, fn($s) => $s['sync_status']==='synced')),
    'pending'    => count(array_filter($sources, fn($s) => $s['sync_status']==='pending')),
    'errors'     => count(array_filter($sources, fn($s) => $s['sync_status']==='error')),
    'total_chunks'=> array_sum(array_column($sources, 'chunk_count')),
];

$fileIcons = [
    'google_doc' => ['icon'=>'📄', 'color'=>'blue'],
    'pdf'        => ['icon'=>'📑', 'color'=>'red'],
    'docx'       => ['icon'=>'📘', 'color'=>'blue'],
    'pptx'       => ['icon'=>'📊', 'color'=>'orange'],
    'txt'        => ['icon'=>'📃', 'color'=>'slate'],
    'wiki'       => ['icon'=>'📚', 'color'=>'violet'],
    'unknown'    => ['icon'=>'📎', 'color'=>'slate'],
];
$statusConfig = [
    'synced'     => ['label'=>'Synced',     'cls'=>'bg-emerald-100 text-emerald-700'],
    'pending'    => ['label'=>'Pending',    'cls'=>'bg-amber-100 text-amber-700'],
    'processing' => ['label'=>'Processing', 'cls'=>'bg-blue-100 text-blue-700'],
    'error'      => ['label'=>'Error',      'cls'=>'bg-red-100 text-red-700'],
];

require __DIR__ . '/layouts/header.php';
?>
<meta name="csrf-token" content="<?= Auth::csrfToken() ?>">

<div x-data="kbManager()" x-init="init()">

  <!-- ── Header Actions ─────────────────────────────────────────────── -->
  <div class="flex flex-wrap items-center justify-between gap-3 mb-6">
    <p class="text-xs text-slate-400">
      <?= $stats['total'] ?> files · <?= number_format($stats['total_chunks']) ?> chunks รวม
    </p>
    <div class="flex gap-2 flex-wrap">
      <button @click="processAll()" :disabled="busy"
              class="flex items-center gap-2 px-4 py-2.5 rounded-xl border border-slate-200 text-sm font-medium text-slate-600 hover:bg-slate-50 disabled:opacity-60 transition-colors">
        <svg class="w-4 h-4" :class="busy?'animate-spin':''" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M9 3v2m6-2v2M9 19v2m6-2v2M5 9H3m2 6H3m18-6h-2m2 6h-2M7 19h10a2 2 0 002-2V7a2 2 0 00-2-2H7a2 2 0 00-2 2v10a2 2 0 002 2zM9 9h6v6H9V9z"/>
        </svg>
        Embed ทั้งหมด (Pending)
      </button>
      <button @click="addUrl()" :disabled="busy"
              class="flex items-center gap-2 px-4 py-2.5 rounded-xl bg-indigo-600 text-white text-sm font-semibold hover:bg-indigo-700 disabled:opacity-60 transition-colors">
        <svg class="w-4 h-4" :class="busy?'animate-spin':''" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"/>
        </svg>
        เพิ่มลิงก์ (Google Drive)
      </button>
    </div>
  </div>

  <!-- Result banner -->
  <div x-show="banner" x-transition class="mb-4 p-4 rounded-xl text-sm flex items-center gap-2"
       :class="bannerType==='success' ? 'bg-emerald-50 border border-emerald-200 text-emerald-700' : 'bg-red-50 border border-red-200 text-red-700'">
    <span x-text="banner"></span>
    <button @click="banner=''" class="ml-auto text-current opacity-60 hover:opacity-100">✕</button>
  </div>

  <!-- ── Stats Cards ─────────────────────────────────────────────────── -->
  <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
    <?php foreach ([
      ['label'=>'ไฟล์ทั้งหมด','value'=>$stats['total'],      'color'=>'slate',   'bg'=>'bg-slate-100'],
      ['label'=>'Synced',     'value'=>$stats['synced'],     'color'=>'emerald', 'bg'=>'bg-emerald-100'],
      ['label'=>'Pending',    'value'=>$stats['pending'],    'color'=>'amber',   'bg'=>'bg-amber-100'],
      ['label'=>'Errors',     'value'=>$stats['errors'],     'color'=>'red',     'bg'=>'bg-red-100'],
    ] as $s): ?>
    <div class="bg-white rounded-2xl p-5 border border-slate-100 card-hover">
      <p class="text-xs text-slate-500 mb-1"><?= $s['label'] ?></p>
      <p class="text-3xl font-bold text-slate-800"><?= $s['value'] ?></p>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- ── Filter ──────────────────────────────────────────────────────── -->
  <div class="flex flex-wrap gap-3 mb-4">
    <input type="text" x-model="search" placeholder="🔍 ค้นหาชื่อไฟล์..."
           class="flex-1 min-w-48 px-3 py-2.5 rounded-xl border border-slate-200 text-sm focus:ring-2 focus:ring-indigo-500 outline-none">
    <select x-model="filterStatus" class="px-3 py-2.5 rounded-xl border border-slate-200 text-sm focus:ring-2 focus:ring-indigo-500 outline-none">
      <option value="">ทุกสถานะ</option>
      <option value="synced">Synced</option>
      <option value="pending">Pending</option>
      <option value="error">Error</option>
    </select>
    <select x-model="filterType" class="px-3 py-2.5 rounded-xl border border-slate-200 text-sm focus:ring-2 focus:ring-indigo-500 outline-none">
      <option value="">ทุกประเภท</option>
      <option value="google_doc">Google Doc</option>
      <option value="pdf">PDF</option>
      <option value="docx">DOCX</option>
      <option value="txt">TXT</option>
    </select>
  </div>

  <!-- ── Knowledge Sources Grid ────────────────────────────────────────── -->
  <div class="grid grid-cols-1 lg:grid-cols-2 xl:grid-cols-3 gap-4">
    <?php foreach ($sources as $src):
      $icon   = $fileIcons[$src['file_type']] ?? $fileIcons['unknown'];
      $status = $statusConfig[$src['sync_status']] ?? $statusConfig['pending'];
      $lastSync = $src['last_synced_at'] ? date('d/m/Y H:i', strtotime($src['last_synced_at'])) : '—';
      $lastMod  = $src['last_modified']  ? date('d/m/Y', strtotime($src['last_modified']))       : '—';
    ?>
    <div class="bg-white rounded-2xl border border-slate-100 p-5 card-hover"
         x-show="matchesFilter(<?= htmlspecialchars(json_encode(['file_name'=>$src['file_name'],'sync_status'=>$src['sync_status'],'file_type'=>$src['file_type']])) ?>)">

      <!-- File Header -->
      <div class="flex items-start gap-3 mb-3">
        <span class="text-2xl shrink-0 mt-0.5"><?= $icon['icon'] ?></span>
        <div class="flex-1 min-w-0">
          <p class="font-semibold text-slate-800 text-sm truncate"
             title="<?= htmlspecialchars($src['file_name']) ?>">
            <?= htmlspecialchars($src['file_name']) ?>
          </p>
          <p class="text-xs text-slate-400 uppercase tracking-wide"><?= $src['file_type'] ?></p>
        </div>
        <span class="px-2 py-0.5 rounded-lg text-xs font-medium shrink-0 <?= $status['cls'] ?>">
          <?= $status['label'] ?>
        </span>
      </div>

      <!-- Stats -->
      <div class="grid grid-cols-3 gap-2 mb-3">
        <div class="bg-slate-50 rounded-xl p-2 text-center">
          <p class="text-sm font-bold text-slate-700"><?= number_format($src['chunk_count'] ?? 0) ?></p>
          <p class="text-[10px] text-slate-400">Chunks</p>
        </div>
        <div class="bg-slate-50 rounded-xl p-2 text-center col-span-2">
          <p class="text-xs font-medium text-slate-600 truncate"><?= $lastSync ?></p>
          <p class="text-[10px] text-slate-400">Last Synced</p>
        </div>
      </div>

      <!-- Error message -->
      <?php if ($src['sync_status'] === 'error' && $src['error_message']): ?>
      <div class="mb-3 p-2 rounded-lg bg-red-50 border border-red-100">
        <p class="text-xs text-red-600 line-clamp-2"><?= htmlspecialchars($src['error_message']) ?></p>
      </div>
      <?php endif; ?>

      <!-- Actions -->
      <div class="flex items-center gap-2 pt-2 border-t border-slate-50">
        <?php if ($src['google_drive_url']): ?>
        <a href="<?= htmlspecialchars($src['google_drive_url']) ?>" target="_blank"
           class="p-1.5 rounded-lg text-slate-400 hover:text-indigo-600 hover:bg-indigo-50 transition-colors" title="เปิดใน Drive">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/>
          </svg>
        </a>
        <?php endif; ?>

        <button @click="processSingleSource(<?= $src['id'] ?>)" title="Re-embed"
                class="p-1.5 rounded-lg text-slate-400 hover:text-violet-600 hover:bg-violet-50 transition-colors">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M9 3v2m6-2v2M9 19v2m6-2v2M5 9H3m2 6H3m18-6h-2m2 6h-2M7 19h10a2 2 0 002-2V7a2 2 0 00-2-2H7a2 2 0 00-2 2v10a2 2 0 002 2zM9 9h6v6H9V9z"/>
          </svg>
        </button>

        <button onclick="toggleSource(<?= $src['id'] ?>, <?= $src['is_active'] ? 0 : 1 ?>)"
                class="ml-auto relative inline-flex h-5 w-10 rounded-full transition-colors <?= $src['is_active'] ? 'bg-emerald-500' : 'bg-slate-200' ?>"
                title="<?= $src['is_active'] ? 'Deactivate' : 'Activate' ?>">
          <span class="inline-block h-4 w-4 rounded-full bg-white shadow transition-transform mt-0.5 <?= $src['is_active'] ? 'translate-x-5' : 'translate-x-0.5' ?>"></span>
        </button>

        <button onclick="deleteSource(<?= $src['id'] ?>, '<?= addslashes($src['file_name']) ?>')"
                class="p-1.5 rounded-lg text-slate-400 hover:text-red-600 hover:bg-red-50 transition-colors" title="ลบ">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
          </svg>
        </button>
      </div>
    </div>
    <?php endforeach; ?>
    <?php if (empty($sources)): ?>
    <div class="col-span-full py-20 text-center text-slate-400">
      <p class="text-5xl mb-3">📂</p>
      <p class="font-medium">ยังไม่มีไฟล์ใน Knowledge Base</p>
      <p class="text-sm mt-1">กด "เพิ่มลิงก์ (Google Drive)" เพื่อดึงไฟล์</p>
    </div>
    <?php endif; ?>
  </div>

  <!-- Progress Bar Modal -->
  <div x-show="showProgress" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/60 backdrop-blur-sm" style="display: none;">
    <div class="bg-white rounded-2xl p-8 max-w-md w-full mx-4 shadow-2xl text-center">
      <div class="inline-flex items-center justify-center w-16 h-16 rounded-full bg-indigo-50 text-indigo-600 mb-4 animate-pulse">
        <svg class="w-8 h-8" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
        </svg>
      </div>
      <h3 class="text-xl font-bold text-slate-800 mb-2">กำลังประมวลผล (Embedding)</h3>
      <p class="text-sm text-slate-500 mb-6">กรุณารอสักครู่... การอ่านเนื้อหาและการแปลงข้อมูลอาจใช้เวลา 1-3 นาทีสำหรับไฟล์ขนาดใหญ่ ห้ามปิดหน้านี้</p>
      
      <div class="w-full bg-slate-100 rounded-full h-3 mb-2 overflow-hidden relative">
        <div class="bg-indigo-600 h-3 rounded-full transition-all duration-300 ease-out" :style="'width: ' + progress + '%'"></div>
      </div>
      <p class="text-sm font-bold text-indigo-600" x-text="Math.round(progress) + '%'"></p>
    </div>
  </div>
</div>

<script>
const CSRF = document.querySelector('meta[name="csrf-token"]').content;
function post(data) {
  const fd = new FormData(); fd.append('_csrf_token', CSRF);
  Object.entries(data).forEach(([k,v]) => fd.append(k,v));
  return fetch('', {method:'POST', body:fd}).then(r=>r.json());
}

function kbManager() {
  return {
    search:'', filterStatus:'', filterType:'', busy:false, banner:'', bannerType:'success',
    showProgress: false, progress: 0, progressInterval: null,
    init() {},
    matchesFilter(src) {
      if (this.search && !src.file_name.toLowerCase().includes(this.search.toLowerCase())) return false;
      if (this.filterStatus && src.sync_status !== this.filterStatus) return false;
      if (this.filterType  && src.file_type  !== this.filterType)   return false;
      return true;
    },
    startFakeProgress() {
      this.showProgress = true;
      this.progress = 0;
      this.progressInterval = setInterval(() => {
        if (this.progress < 50) this.progress += Math.random() * 3;
        else if (this.progress < 85) this.progress += Math.random() * 1;
        else if (this.progress < 95) this.progress += Math.random() * 0.2;
        this.progress = Math.min(95, this.progress);
      }, 800);
    },
    stopFakeProgress(success) {
      clearInterval(this.progressInterval);
      this.progress = 100;
      setTimeout(() => {
        this.showProgress = false;
        location.reload();
      }, 1000);
    },
    async addUrl() {
      const url = await Noti.prompt('กรุณาวางลิงก์ Google Drive ของไฟล์ (ต้องเปิดแชร์ลิงก์เป็น Anyone with the link)');
      if (!url) return;
      this.busy = true; this.banner = '';
      const d = await post({action:'add_url', url});
      this.banner = d.message || (d.success ? 'เพิ่มสำเร็จ' : 'เพิ่มล้มเหลว');
      this.bannerType = d.success ? 'success' : 'error';
      this.busy = false;
      if (d.success) setTimeout(() => location.reload(), 1500);
    },
    async processAll() {
      this.startFakeProgress();
      const d = await post({action:'process_all_pending'});
      if(!d.success) Noti.error(d.message || 'ล้มเหลว');
      this.stopFakeProgress();
    },
    async processSingleSource(id) {
      if (!await Noti.confirm('Re-embed ไฟล์นี้? การทำ Embed อาจใช้เวลาหลายนาทีหากไฟล์มีขนาดใหญ่')) return;
      this.startFakeProgress();
      const d = await post({action:'process_source', id});
      if(!d.success) Noti.error(d.error || d.message || 'Processing failed');
      this.stopFakeProgress();
    }
  }
}

async function toggleSource(id, val) {
  await post({action:'toggle_active', id, is_active:val});
  location.reload();
}

async function deleteSource(id, name) {
  if (!await Noti.confirm(`ลบ "${name}" และ chunks ทั้งหมด?`)) return;
  const d = await post({action:'delete_source', id});
  d.success ? (Noti.success('ลบสำเร็จ'), setTimeout(() => location.reload(), 1000)) : Noti.error(d.message);
}
</script>

<?php require __DIR__ . '/layouts/footer.php'; ?>
