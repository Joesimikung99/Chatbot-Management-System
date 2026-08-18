<?php
define('BASE_PATH', dirname(__DIR__, 2));
require_once BASE_PATH . '/vendor/autoload.php';
use Dotenv\Dotenv;
use App\Helpers\Auth;
use App\Helpers\Response;
use App\Services\WikiService;
use App\Services\LogService;

$dotenv = Dotenv::createImmutable(BASE_PATH);
$dotenv->safeLoad();
date_default_timezone_set($_ENV['APP_TIMEZONE'] ?? 'Asia/Bangkok');

$pageTitle  = 'Wiki Knowledge';
$breadcrumb = ['Admin', 'Wiki Knowledge'];
Auth::requirePermission('manage_knowledge');

$logger = new LogService();
$botId  = Auth::requireBot();
$wiki   = new WikiService($botId);

// แก้ไข/publish สงวนไว้ให้ admin ขึ้นไป — viewer ที่ได้สิทธิ์ manage_knowledge
// ดูได้อย่างเดียว
$canEdit = in_array(Auth::role(), ['admin', 'super_admin'], true);

// ── AJAX Handlers ──────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Auth::validateCsrf()) Response::error('CSRF failed', 403);
    if (!$canEdit)             Response::error('ไม่มีสิทธิ์แก้ไขหน้า wiki', 403);

    $action = $_POST['action'] ?? '';
    $id     = (int)($_POST['id'] ?? 0);

    if ($action === 'save_page') {
        if (!$wiki->savePage($id, $_POST['title'] ?? '', $_POST['summary'] ?? '', $_POST['content'] ?? '')) {
            Response::error('ไม่พบหน้านี้ หรือไม่ได้ระบุหัวข้อ', 404);
        }
        $wiki->saveQuestions($id, array_values((array)($_POST['questions'] ?? [])));
        $logger->logActivity(Auth::get('id'), 'wiki.save', 'wiki_page', $id, null, null, $botId);
        Response::success(null, 'บันทึกแล้ว');
    }

    if ($action === 'publish') {
        set_time_limit(600);
        if (!$wiki->publishPage($id, (int)Auth::get('id'))) {
            Response::error('Publish ไม่สำเร็จ — ดู storage/logs/wiki_service.log', 500);
        }
        $logger->logActivity(Auth::get('id'), 'wiki.publish', 'wiki_page', $id, null, null, $botId);
        Response::success(null, 'Publish แล้ว — บอทใช้ข้อมูลนี้ตอบได้ทันที');
    }

    if ($action === 'unpublish') {
        if (!$wiki->unpublishPage($id)) Response::error('ไม่พบหน้านี้', 404);
        $logger->logActivity(Auth::get('id'), 'wiki.unpublish', 'wiki_page', $id, null, null, $botId);
        Response::success(null, 'ถอนหน้าออกจากระบบแล้ว');
    }

    if ($action === 'generate_questions') {
        set_time_limit(600);
        $count = $wiki->generateQuestions($id);
        if ($count === 0) Response::error('สร้างคำถามไม่สำเร็จ — ดู storage/logs/wiki_service.log', 500);
        $logger->logActivity(Auth::get('id'), 'wiki.generate_questions', 'wiki_page', $id, null, ['count' => $count], $botId);
        Response::success(['count' => $count], "สร้างคำถามใหม่ {$count} ข้อ — กด Publish อีกครั้งเพื่อให้ระบบค้นเจอ");
    }

    if ($action === 'delete_page') {
        if (!$wiki->deletePage($id)) Response::error('ไม่พบหน้านี้', 404);
        $logger->logActivity(Auth::get('id'), 'wiki.delete', 'wiki_page', $id, null, null, $botId);
        Response::success(null, 'ลบแล้ว');
    }

    if ($action === 'create_draft') {
        $newId = $wiki->createEmptyDraft($_POST['slug'] ?? '', $_POST['title'] ?? '', $_POST['summary'] ?? '');
        if ($newId === 0) Response::error('slug ซ้ำกับหน้าที่มีอยู่ หรือข้อมูลไม่ครบ', 400);
        $logger->logActivity(Auth::get('id'), 'wiki.create_draft', 'wiki_page', $newId, null, null, $botId);
        Response::success(['id' => $newId], 'สร้างร่างแล้ว — กรุณาเติมเนื้อหา');
    }

    if ($action === 'analyze_gaps') {
        set_time_limit(600);
        $days = max(1, min(365, (int)($_POST['days'] ?? 30)));
        Response::success(['topics' => $wiki->analyzeGaps($days)], '');
    }

    Response::error('Unknown action', 400);
}

// ── Edit View ──────────────────────────────────────────────────────────
$editPage = null;
if (isset($_GET['id'])) {
    $editPage = $wiki->findPage((int)$_GET['id']);
    if ($editPage === null) {
        header('Location: wiki.php');
        exit;
    }
    $editQuestions = $wiki->pageQuestions((int)$editPage['id']);
    $editSources   = $wiki->sourceNames($editPage['source_ids'] ?? null);
    $breadcrumb    = ['Admin', 'Wiki Knowledge', $editPage['title']];
}

// ── List View ──────────────────────────────────────────────────────────
$filterStatus = $_GET['status'] ?? '';
$pages = $editPage === null
    ? $wiki->listPages(in_array($filterStatus, ['draft', 'published', 'archived'], true) ? $filterStatus : '')
    : [];

$allPages = $editPage === null ? $wiki->listPages() : [];
$stats = [
    'total'     => count($allPages),
    'draft'     => count(array_filter($allPages, fn($p) => $p['status'] === 'draft')),
    'published' => count(array_filter($allPages, fn($p) => $p['status'] === 'published')),
    'archived'  => count(array_filter($allPages, fn($p) => $p['status'] === 'archived')),
];

$statusConfig = [
    'draft'     => ['label' => 'ร่าง',      'cls' => 'bg-amber-100 text-amber-700'],
    'published' => ['label' => 'เผยแพร่',   'cls' => 'bg-emerald-100 text-emerald-700'],
    'archived'  => ['label' => 'เก็บเข้ากรุ', 'cls' => 'bg-slate-100 text-slate-500'],
];

require __DIR__ . '/layouts/header.php';
?>
<meta name="csrf-token" content="<?= Auth::csrfToken() ?>">

<?php if ($editPage !== null): ?>
<!-- ══════════════════════ Edit View ══════════════════════ -->
<?php
  $isStale = $editPage['status'] === 'published'
          && $editPage['content_hash'] !== md5((string)$editPage['content']);
  $badge   = $statusConfig[$editPage['status']] ?? $statusConfig['draft'];
?>
<div x-data="wikiEditor(<?= (int)$editPage['id'] ?>)">

  <div class="flex flex-wrap items-start justify-between gap-3 mb-6">
    <div>
      <a href="wiki.php" class="text-xs text-slate-400 hover:text-indigo-600">← กลับไปรายการหน้า wiki</a>
      <div class="flex items-center gap-2 mt-1">
        <h2 class="text-xl font-bold text-slate-800"><?= htmlspecialchars($editPage['title']) ?></h2>
        <span class="px-2 py-0.5 rounded-lg text-xs font-medium <?= $badge['cls'] ?>"><?= $badge['label'] ?></span>
        <?php if ($isStale): ?>
        <span class="px-2 py-0.5 rounded-lg text-xs font-medium bg-orange-100 text-orange-700">แก้ไขแล้ว ยังไม่ re-publish</span>
        <?php endif; ?>
      </div>
      <p class="text-xs text-slate-400 mt-1 font-mono"><?= htmlspecialchars($editPage['slug']) ?></p>
    </div>

    <?php if ($canEdit): ?>
    <div class="flex gap-2 flex-wrap">
      <button @click="save()" :disabled="busy"
              class="px-4 py-2.5 rounded-xl border border-slate-200 text-sm font-medium text-slate-600 hover:bg-slate-50 disabled:opacity-60 transition-colors">
        บันทึกร่าง
      </button>
      <button @click="publish()" :disabled="busy"
              class="px-4 py-2.5 rounded-xl bg-indigo-600 text-white text-sm font-semibold hover:bg-indigo-700 disabled:opacity-60 transition-colors">
        บันทึก &amp; Publish
      </button>
      <?php if ($editPage['status'] === 'published'): ?>
      <button @click="unpublish()" :disabled="busy"
              class="px-4 py-2.5 rounded-xl border border-red-200 text-sm font-medium text-red-600 hover:bg-red-50 disabled:opacity-60 transition-colors">
        ถอนออก
      </button>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </div>

  <div x-show="banner" x-transition class="mb-4 p-4 rounded-xl text-sm flex items-center gap-2"
       :class="bannerType==='success' ? 'bg-emerald-50 border border-emerald-200 text-emerald-700' : 'bg-red-50 border border-red-200 text-red-700'">
    <span x-text="banner"></span>
    <button @click="banner=''" class="ml-auto text-current opacity-60 hover:opacity-100">✕</button>
  </div>

  <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">

    <!-- ── Content ─────────────────────────────────────────── -->
    <div class="lg:col-span-2 bg-white rounded-2xl border border-slate-100 p-5">
      <label class="block text-xs font-medium text-slate-500 mb-1">หัวข้อ</label>
      <input type="text" x-model="title" :disabled="!canEdit"
             class="w-full px-3 py-2.5 mb-4 rounded-xl border border-slate-200 text-sm focus:ring-2 focus:ring-indigo-500 outline-none disabled:bg-slate-50">

      <label class="block text-xs font-medium text-slate-500 mb-1">สรุปสั้น (ใช้ทำสารบัญใน system prompt)</label>
      <input type="text" x-model="summary" :disabled="!canEdit"
             class="w-full px-3 py-2.5 mb-4 rounded-xl border border-slate-200 text-sm focus:ring-2 focus:ring-indigo-500 outline-none disabled:bg-slate-50">

      <div class="flex items-center justify-between mb-1">
        <label class="block text-xs font-medium text-slate-500">เนื้อหา (markdown)</label>
        <span class="text-xs" :class="content.length > 2000 ? 'text-orange-600' : 'text-slate-400'"
              x-text="content.length + ' / 2,000 ตัวอักษร'"></span>
      </div>
      <textarea x-model="content" rows="22" :disabled="!canEdit"
                class="w-full px-3 py-2.5 rounded-xl border border-slate-200 text-sm font-mono leading-relaxed focus:ring-2 focus:ring-indigo-500 outline-none disabled:bg-slate-50"></textarea>
      <p class="text-xs text-slate-400 mt-2">
        หน้าที่ยาวเกิน ~2,000 ตัวอักษรจะถูกตัดเป็นหลาย chunk — ทำให้ปัญหา "ตัดกลางเนื้อหา" กลับมา ถ้ายาวเกินให้แยกเป็นหน้าใหม่
      </p>
    </div>

    <!-- ── Sidebar ─────────────────────────────────────────── -->
    <div class="space-y-4">

      <!-- Provenance -->
      <div class="bg-white rounded-2xl border border-slate-100 p-5">
        <p class="text-xs font-semibold text-slate-700 mb-2">แหล่งที่มาของเนื้อหา</p>
        <?php if (empty($editSources)): ?>
          <p class="text-xs text-slate-400">เขียนเองโดยผู้ดูแล (ไม่มีเอกสารต้นทาง)</p>
        <?php else: ?>
          <ul class="space-y-1">
            <?php foreach ($editSources as $name): ?>
            <li class="text-xs text-slate-500 flex gap-1.5">
              <span class="shrink-0">📄</span><span class="truncate" title="<?= htmlspecialchars($name) ?>"><?= htmlspecialchars($name) ?></span>
            </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
        <?php if ($editPage['generated_by']): ?>
        <p class="text-[10px] text-slate-400 mt-3 pt-3 border-t border-slate-50">
          ร่างโดย <?= htmlspecialchars($editPage['generated_by']) ?>
        </p>
        <?php endif; ?>
      </div>

      <!-- Example questions -->
      <div class="bg-white rounded-2xl border border-slate-100 p-5">
        <div class="flex items-center justify-between mb-2">
          <p class="text-xs font-semibold text-slate-700">คำถามตัวอย่าง</p>
          <?php if ($canEdit): ?>
          <button @click="regenerate()" :disabled="busy"
                  class="text-xs text-indigo-600 hover:text-indigo-700 disabled:opacity-60">
            Generate ใหม่
          </button>
          <?php endif; ?>
        </div>
        <p class="text-[11px] text-slate-400 mb-3">
          ผู้ใช้ถามตรงกับข้อไหน ระบบจะส่งหน้านี้ให้บอทตอบทันที — เขียนแบบภาษาที่คนพิมพ์จริง
        </p>

        <template x-for="(q, i) in questions" :key="i">
          <div class="flex gap-1.5 mb-2">
            <input type="text" x-model="questions[i]" :disabled="!canEdit"
                   class="flex-1 px-2.5 py-2 rounded-lg border border-slate-200 text-xs focus:ring-2 focus:ring-indigo-500 outline-none disabled:bg-slate-50">
            <?php if ($canEdit): ?>
            <button @click="questions.splice(i,1)"
                    class="px-2 rounded-lg text-slate-400 hover:text-red-600 hover:bg-red-50 transition-colors">✕</button>
            <?php endif; ?>
          </div>
        </template>

        <?php if ($canEdit): ?>
        <button @click="questions.push('')"
                class="w-full mt-1 py-2 rounded-lg border border-dashed border-slate-200 text-xs text-slate-400 hover:text-indigo-600 hover:border-indigo-300 transition-colors">
          + เพิ่มคำถาม
        </button>
        <?php endif; ?>

        <?php
          $unembedded = count(array_filter($editQuestions, fn($q) => !$q['is_embedded']));
          if ($unembedded > 0 && $editPage['status'] === 'published'):
        ?>
        <p class="text-[11px] text-orange-600 mt-3 pt-3 border-t border-slate-50">
          มีคำถาม <?= $unembedded ?> ข้อที่ยังไม่ถูก embed — กด "บันทึก &amp; Publish" เพื่อให้ระบบค้นเจอ
        </p>
        <?php endif; ?>
      </div>

      <?php if ($canEdit): ?>
      <button @click="destroy()" :disabled="busy"
              class="w-full py-2.5 rounded-xl border border-red-200 text-sm font-medium text-red-600 hover:bg-red-50 disabled:opacity-60 transition-colors">
        ลบหน้านี้ถาวร
      </button>
      <?php endif; ?>
    </div>
  </div>
</div>

<script>
const CSRF = document.querySelector('meta[name="csrf-token"]').content;
function post(data) {
  const fd = new FormData(); fd.append('_csrf_token', CSRF);
  Object.entries(data).forEach(([k, v]) => {
    if (Array.isArray(v)) v.forEach(item => fd.append(k + '[]', item));
    else fd.append(k, v);
  });
  return fetch('', {method: 'POST', body: fd}).then(r => r.json());
}

function wikiEditor(id) {
  return {
    id,
    canEdit: <?= $canEdit ? 'true' : 'false' ?>,
    busy: false, banner: '', bannerType: 'success',
    title:     <?= json_encode($editPage['title'], JSON_UNESCAPED_UNICODE) ?>,
    summary:   <?= json_encode($editPage['summary'] ?? '', JSON_UNESCAPED_UNICODE) ?>,
    content:   <?= json_encode($editPage['content'], JSON_UNESCAPED_UNICODE) ?>,
    questions: <?= json_encode(array_column($editQuestions, 'question'), JSON_UNESCAPED_UNICODE) ?>,

    show(d, fallback) {
      this.banner = d.message || fallback;
      this.bannerType = d.success ? 'success' : 'error';
      return d.success;
    },
    async saveOnly() {
      return post({
        action: 'save_page', id: this.id,
        title: this.title, summary: this.summary, content: this.content,
        questions: this.questions.filter(q => q.trim() !== ''),
      });
    },
    async save() {
      this.busy = true;
      this.show(await this.saveOnly(), 'บันทึกล้มเหลว');
      this.busy = false;
    },
    async publish() {
      this.busy = true;
      const saved = await this.saveOnly();
      if (!this.show(saved, 'บันทึกล้มเหลว')) { this.busy = false; return; }
      const d = await post({action: 'publish', id: this.id});
      if (this.show(d, 'Publish ล้มเหลว')) setTimeout(() => location.reload(), 1200);
      this.busy = false;
    },
    async unpublish() {
      if (!await Noti.confirm('ถอนหน้านี้ออกจากระบบ? บอทจะไม่ใช้ข้อมูลนี้ตอบอีก')) return;
      this.busy = true;
      const d = await post({action: 'unpublish', id: this.id});
      if (this.show(d, 'ถอนล้มเหลว')) setTimeout(() => location.reload(), 1200);
      this.busy = false;
    },
    async regenerate() {
      if (!await Noti.confirm('สร้างคำถามตัวอย่างชุดใหม่? คำถามที่แก้ไว้เองจะถูกแทนที่')) return;
      this.busy = true;
      const d = await post({action: 'generate_questions', id: this.id});
      if (this.show(d, 'สร้างคำถามล้มเหลว')) setTimeout(() => location.reload(), 1500);
      this.busy = false;
    },
    async destroy() {
      if (!await Noti.confirm('ลบหน้านี้ถาวร? ข้อมูลที่ publish ไว้จะถูกลบด้วย')) return;
      this.busy = true;
      const d = await post({action: 'delete_page', id: this.id});
      if (d.success) { location.href = 'wiki.php'; return; }
      this.show(d, 'ลบล้มเหลว');
      this.busy = false;
    },
  }
}
</script>

<?php else: ?>
<!-- ══════════════════════ List View ══════════════════════ -->
<div x-data="wikiList()">

  <div class="flex flex-wrap items-center justify-between gap-3 mb-6">
    <p class="text-xs text-slate-400">
      <?= $stats['total'] ?> หน้า · <?= $stats['published'] ?> หน้าที่บอทใช้ตอบอยู่
    </p>
    <?php if ($canEdit): ?>
    <div class="flex gap-2 flex-wrap">
      <select x-model="gapDays"
              class="px-3 py-2.5 rounded-xl border border-slate-200 text-sm text-slate-600 focus:ring-2 focus:ring-indigo-500 outline-none">
        <option value="7">7 วันล่าสุด</option>
        <option value="30">30 วันล่าสุด</option>
        <option value="90">90 วันล่าสุด</option>
        <option value="365">1 ปีล่าสุด</option>
      </select>
      <button @click="loadGaps()" :disabled="busy"
              class="flex items-center gap-2 px-4 py-2.5 rounded-xl border border-slate-200 text-sm font-medium text-slate-600 hover:bg-slate-50 disabled:opacity-60 transition-colors">
        <span :class="busy ? 'animate-pulse' : ''">🔍</span> วิเคราะห์หัวข้อที่ขาด
      </button>
      <button @click="createDraft()" :disabled="busy"
              class="px-4 py-2.5 rounded-xl bg-indigo-600 text-white text-sm font-semibold hover:bg-indigo-700 disabled:opacity-60 transition-colors">
        + สร้างหน้าใหม่
      </button>
    </div>
    <?php endif; ?>
  </div>

  <div x-show="banner" x-transition class="mb-4 p-4 rounded-xl text-sm flex items-center gap-2"
       :class="bannerType==='success' ? 'bg-emerald-50 border border-emerald-200 text-emerald-700' : 'bg-red-50 border border-red-200 text-red-700'">
    <span x-text="banner"></span>
    <button @click="banner=''" class="ml-auto text-current opacity-60 hover:opacity-100">✕</button>
  </div>

  <!-- ── Stats ───────────────────────────────────────────────── -->
  <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
    <?php foreach ([
      ['label' => 'ทั้งหมด',    'value' => $stats['total']],
      ['label' => 'ร่าง',       'value' => $stats['draft']],
      ['label' => 'เผยแพร่',    'value' => $stats['published']],
      ['label' => 'เก็บเข้ากรุ', 'value' => $stats['archived']],
    ] as $s): ?>
    <div class="bg-white rounded-2xl p-5 border border-slate-100 card-hover">
      <p class="text-xs text-slate-500 mb-1"><?= $s['label'] ?></p>
      <p class="text-3xl font-bold text-slate-800"><?= $s['value'] ?></p>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- ── Gap analysis ────────────────────────────────────────── -->
  <div x-show="gaps !== null" x-transition class="bg-white rounded-2xl border border-slate-100 p-5 mb-6" style="display:none">
    <p class="text-sm font-semibold text-slate-700 mb-1">
      หัวข้อที่ผู้ใช้ถามแต่ยังไม่มีข้อมูล (<span x-text="gapDaysShown"></span> วันล่าสุด)
    </p>
    <p class="text-xs text-slate-400 mb-4">
      สร้างร่างแล้วต้องเขียนเนื้อหาเอง — ระบบไม่ให้ AI แต่งเนื้อหาจากคำถามที่ตอบไม่ได้ เพราะไม่มีเอกสารรองรับ
    </p>
    <template x-if="gaps && gaps.length === 0">
      <p class="text-xs text-slate-400 py-4 text-center">ไม่พบหัวข้อที่ขาด 🎉</p>
    </template>
    <template x-for="t in (gaps || [])" :key="t.suggested_slug">
      <div class="flex items-start gap-3 py-3 border-t border-slate-50">
        <span class="px-2 py-0.5 rounded-lg text-xs font-bold bg-red-50 text-red-600 shrink-0" x-text="t.frequency + '×'"></span>
        <div class="flex-1 min-w-0">
          <p class="text-sm font-medium text-slate-700" x-text="t.topic"></p>
          <p class="text-xs text-slate-400 truncate" x-text="t.sample_questions.join(' · ')"></p>
        </div>
        <button @click="createDraft(t.suggested_slug, t.topic)" :disabled="busy"
                class="px-3 py-1.5 rounded-lg border border-slate-200 text-xs font-medium text-slate-600 hover:bg-slate-50 shrink-0 disabled:opacity-60">
          สร้างร่าง
        </button>
      </div>
    </template>
  </div>

  <!-- ── Filter ──────────────────────────────────────────────── -->
  <div class="flex flex-wrap gap-3 mb-4">
    <input type="text" x-model="search" placeholder="🔍 ค้นหาหัวข้อ..."
           class="flex-1 min-w-48 px-3 py-2.5 rounded-xl border border-slate-200 text-sm focus:ring-2 focus:ring-indigo-500 outline-none">
    <?php foreach (['' => 'ทุกสถานะ', 'draft' => 'ร่าง', 'published' => 'เผยแพร่', 'archived' => 'เก็บเข้ากรุ'] as $val => $label): ?>
    <a href="?<?= $val === '' ? '' : 'status=' . $val ?>"
       class="px-3 py-2.5 rounded-xl border text-sm transition-colors <?= $filterStatus === $val ? 'bg-indigo-50 border-indigo-200 text-indigo-700 font-medium' : 'border-slate-200 text-slate-500 hover:bg-slate-50' ?>">
      <?= $label ?>
    </a>
    <?php endforeach; ?>
  </div>

  <!-- ── Pages table ─────────────────────────────────────────── -->
  <div class="bg-white rounded-2xl border border-slate-100 overflow-hidden">
    <?php if (empty($pages)): ?>
    <div class="py-20 text-center text-slate-400">
      <p class="text-5xl mb-3">📚</p>
      <p class="font-medium">ยังไม่มีหน้า wiki</p>
      <p class="text-sm mt-1">รัน <code class="px-1.5 py-0.5 rounded bg-slate-100 text-xs">php sync.php --build-wiki</code> เพื่อให้ AI ร่างหน้าจากเอกสารใน Knowledge Base</p>
    </div>
    <?php else: ?>
    <table class="w-full text-sm">
      <thead class="bg-slate-50 text-xs text-slate-500">
        <tr>
          <th class="text-left font-medium px-5 py-3">หัวข้อ</th>
          <th class="text-left font-medium px-5 py-3 hidden md:table-cell">แหล่งที่มา</th>
          <th class="text-center font-medium px-5 py-3">คำถาม</th>
          <th class="text-center font-medium px-5 py-3">สถานะ</th>
          <th class="text-right font-medium px-5 py-3 hidden lg:table-cell">แก้ไขล่าสุด</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($pages as $p):
          $st      = $statusConfig[$p['status']] ?? $statusConfig['draft'];
          $sources = $wiki->sourceNames($p['source_ids'] ?? null);
          $isStale = $p['status'] === 'published' && $p['content_hash'] !== md5((string)$p['content']);
        ?>
        <tr class="border-t border-slate-50 hover:bg-slate-50/60 transition-colors"
            x-show="matchesSearch(<?= htmlspecialchars(json_encode(['title' => $p['title'], 'slug' => $p['slug']])) ?>)">
          <td class="px-5 py-3">
            <a href="?id=<?= (int)$p['id'] ?>" class="font-medium text-slate-700 hover:text-indigo-600">
              <?= htmlspecialchars($p['title']) ?>
            </a>
            <p class="text-xs text-slate-400 font-mono"><?= htmlspecialchars($p['slug']) ?></p>
            <?php if ($isStale): ?>
            <span class="inline-block mt-1 px-2 py-0.5 rounded-lg text-[10px] font-medium bg-orange-100 text-orange-700">แก้ไขแล้ว ยังไม่ re-publish</span>
            <?php endif; ?>
          </td>
          <td class="px-5 py-3 hidden md:table-cell text-xs text-slate-400 max-w-56 truncate"
              title="<?= htmlspecialchars(implode(', ', $sources)) ?>">
            <?= $sources ? htmlspecialchars(implode(', ', $sources)) : '—' ?>
          </td>
          <td class="px-5 py-3 text-center text-xs text-slate-500">
            <?= (int)$p['question_count'] ?>
            <?php if ($p['status'] === 'published' && (int)$p['unembedded_count'] > 0): ?>
            <span class="text-orange-600" title="ยังไม่ถูก embed">⚠</span>
            <?php endif; ?>
          </td>
          <td class="px-5 py-3 text-center">
            <span class="px-2 py-0.5 rounded-lg text-xs font-medium <?= $st['cls'] ?>"><?= $st['label'] ?></span>
          </td>
          <td class="px-5 py-3 text-right text-xs text-slate-400 hidden lg:table-cell">
            <?= date('d/m/Y H:i', strtotime($p['updated_at'])) ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
</div>

<script>
const CSRF = document.querySelector('meta[name="csrf-token"]').content;
function post(data) {
  const fd = new FormData(); fd.append('_csrf_token', CSRF);
  Object.entries(data).forEach(([k, v]) => fd.append(k, v));
  return fetch('', {method: 'POST', body: fd}).then(r => r.json());
}

function wikiList() {
  return {
    search: '', busy: false, banner: '', bannerType: 'success', gaps: null,
    gapDays: '30', gapDaysShown: '30',

    matchesSearch(p) {
      if (!this.search) return true;
      const q = this.search.toLowerCase();
      return p.title.toLowerCase().includes(q) || p.slug.toLowerCase().includes(q);
    },
    async loadGaps() {
      this.busy = true; this.banner = '';
      const d = await post({action: 'analyze_gaps', days: this.gapDays});
      if (d.success) { this.gaps = d.data.topics; this.gapDaysShown = this.gapDays; }
      else { this.banner = d.message || 'วิเคราะห์ล้มเหลว'; this.bannerType = 'error'; }
      this.busy = false;
    },
    async createDraft(slug = '', title = '') {
      if (!title) {
        title = await Noti.prompt('ชื่อหัวข้อของหน้าใหม่ (ภาษาไทย)');
        if (!title) return;
      }
      if (!slug) {
        slug = await Noti.prompt('slug (ตัวอักษรอังกฤษพิมพ์เล็ก คั่นด้วยขีด เช่น library-hours)');
        if (!slug) return;
      }
      this.busy = true;
      const d = await post({action: 'create_draft', slug, title});
      if (d.success) { location.href = '?id=' + d.data.id; return; }
      this.banner = d.message || 'สร้างล้มเหลว'; this.bannerType = 'error';
      this.busy = false;
    },
  }
}
</script>
<?php endif; ?>

<?php require __DIR__ . '/layouts/footer.php'; ?>
