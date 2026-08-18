<?php
define('BASE_PATH', dirname(__DIR__, 2));
require_once BASE_PATH . '/vendor/autoload.php';
use Dotenv\Dotenv;
use App\Helpers\Auth;
use App\Helpers\Response;
use App\Helpers\Database;
use App\Services\OpenRouterService;
use App\Services\LogService;

$dotenv = Dotenv::createImmutable(BASE_PATH);
$dotenv->safeLoad();
date_default_timezone_set($_ENV['APP_TIMEZONE'] ?? 'Asia/Bangkok');

$pageTitle  = 'AI Model Management';
$breadcrumb = ['Admin', 'AI Models'];
Auth::requirePermission('manage_models');

$db     = Database::getInstance();
$or     = new OpenRouterService();
$logger = new LogService();

// ── AJAX Handlers ──────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Auth::validateCsrf()) {
        Response::error('CSRF validation failed', 403);
    }
    $action = $_POST['action'] ?? '';

    if ($action === 'sync') {
        $stats = $or->syncModelsToDatabase();
        $logger->logActivity(Auth::get('id'), 'models.sync', 'ai_model', null, null, $stats);
        Response::success($stats, "Synced: +{$stats['added']} added, {$stats['updated']} updated");
    }

    if ($action === 'toggle_active') {
        $id     = (int)($_POST['id'] ?? 0);
        $active = (int)($_POST['is_active'] ?? 0);
        $db->update('ai_models', ['is_active' => $active], ['id' => $id]);
        $logger->logActivity(Auth::get('id'), 'model.toggle', 'ai_model', $id, null, ['is_active' => $active]);
        Response::success(null, 'Updated');
    }

    if ($action === 'set_default') {
        $id = (int)($_POST['id'] ?? 0);
        $db->query('UPDATE ai_models SET is_default = 0');
        $db->update('ai_models', ['is_default' => 1], ['id' => $id]);
        $logger->logActivity(Auth::get('id'), 'model.set_default', 'ai_model', $id);
        Response::success(null, 'Default model updated');
    }

    if ($action === 'update') {
        $id   = (int)($_POST['id'] ?? 0);
        $data = [
            'display_name'  => trim($_POST['display_name'] ?? ''),
            'max_tokens'    => (int)($_POST['max_tokens'] ?? 4096),
            'custom_notes'  => trim($_POST['custom_notes'] ?? ''),
            'sort_order'    => (int)($_POST['sort_order']  ?? 0),
        ];
        $old = $db->fetch('SELECT * FROM ai_models WHERE id=?', [$id]);
        $db->update('ai_models', $data, ['id' => $id]);
        $logger->logActivity(Auth::get('id'), 'model.update', 'ai_model', $id, $old, $data);
        Response::success(null, 'Model updated');
    }

    if ($action === 'test') {
        $modelId = $_POST['model_id'] ?? '';
        $result  = $or->testModel($modelId);
        Response::success($result);
    }

    if ($action === 'reorder') {
        $orders = json_decode($_POST['orders'] ?? '[]', true);
        foreach ($orders as $item) {
            $db->update('ai_models', ['sort_order' => (int)$item['order']], ['id' => (int)$item['id']]);
        }
        Response::success(null, 'Order saved');
    }

    Response::error('Unknown action', 400);
}

// ── Load Models ────────────────────────────────────────────────────────
$models = $db->fetchAll('SELECT * FROM ai_models ORDER BY sort_order ASC, id ASC');
$lastSync = $db->fetchColumn(
    "SELECT MAX(last_fetched_at) FROM ai_models WHERE last_fetched_at IS NOT NULL"
);
$cheapest = !empty($models) ? array_reduce($models, fn($c,$m) => (!$c || (float)$m['pricing_prompt'] < (float)$c['pricing_prompt'] ? $m : $c)) : null;
$default  = $or->getDefaultModel();

require __DIR__ . '/layouts/header.php';
?>
<meta name="csrf-token" content="<?= Auth::csrfToken() ?>">

<div x-data="modelManager()" x-init="init()">

  <!-- ── Header Actions ─────────────────────────────────────────────── -->
  <div class="flex flex-wrap items-center justify-between gap-3 mb-6">
    <div>
      <p class="text-xs text-slate-400 mt-1">
        <?= count($models) ?> models ·
        Last synced: <?= $lastSync ? date('d/m/Y H:i', strtotime($lastSync)) : 'ยังไม่เคย sync' ?>
      </p>
    </div>
    <div class="flex gap-3">
      <button @click="syncModels()" :disabled="syncing"
              class="flex items-center gap-2 px-4 py-2.5 rounded-xl text-sm font-semibold text-white bg-indigo-600 hover:bg-indigo-700 transition-all disabled:opacity-60">
        <svg class="w-4 h-4" :class="syncing ? 'animate-spin' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
        </svg>
        <span x-text="syncing ? 'กำลัง Sync...' : 'Sync จาก OpenRouter'"></span>
      </button>
    </div>
  </div>

  <!-- ── Sync Result Banner ──────────────────────────────────────────── -->
  <div x-show="syncResult" x-transition class="mb-4 p-4 rounded-xl bg-emerald-50 border border-emerald-200 text-sm text-emerald-700 flex items-center gap-2">
    <svg class="w-5 h-5 text-emerald-600 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
      <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
    </svg>
    <span x-text="syncResult"></span>
    <button @click="syncResult=''" class="ml-auto text-emerald-600 hover:text-emerald-800">✕</button>
  </div>

  <!-- ── Summary Cards ──────────────────────────────────────────────── -->
  <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
    <div class="bg-white rounded-2xl p-4 border border-slate-100">
      <p class="text-xs text-slate-500 mb-1">Total Models</p>
      <p class="text-2xl font-bold text-slate-800"><?= count($models) ?></p>
    </div>
    <div class="bg-white rounded-2xl p-4 border border-slate-100">
      <p class="text-xs text-slate-500 mb-1">Active</p>
      <p class="text-2xl font-bold text-emerald-600"><?= count(array_filter($models, fn($m)=>$m['is_active'])) ?></p>
    </div>
    <div class="bg-white rounded-2xl p-4 border border-slate-100">
      <p class="text-xs text-slate-500 mb-1">Default Model</p>
      <p class="text-sm font-bold text-slate-800 truncate"><?= htmlspecialchars($default['display_name'] ?? '—') ?></p>
    </div>
    <div class="bg-white rounded-2xl p-4 border border-slate-100">
      <p class="text-xs text-slate-500 mb-1">ถูกสุด</p>
      <p class="text-sm font-bold text-slate-800 truncate"><?= htmlspecialchars($cheapest['display_name'] ?? '—') ?></p>
    </div>
  </div>

  <!-- ── Filter Bar ─────────────────────────────────────────────────── -->
  <div class="bg-white rounded-2xl p-4 border border-slate-100 mb-4 flex flex-wrap gap-3 items-center">
    <input type="text" x-model="search" placeholder="🔍 ค้นหา Model..."
           class="flex-1 min-w-48 px-3 py-2 rounded-xl border border-slate-200 text-sm focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 outline-none">
    <select x-model="filterProvider" class="px-3 py-2 rounded-xl border border-slate-200 text-sm focus:ring-2 focus:ring-indigo-500 outline-none">
      <option value="">ทุก Provider</option>
      <?php
      $providers = array_unique(array_column($models, 'provider_name'));
      sort($providers);
      foreach ($providers as $p): ?>
        <option value="<?= htmlspecialchars($p) ?>"><?= htmlspecialchars($p) ?></option>
      <?php endforeach; ?>
    </select>
    <select x-model="filterStatus" class="px-3 py-2 rounded-xl border border-slate-200 text-sm focus:ring-2 focus:ring-indigo-500 outline-none">
      <option value="">ทุกสถานะ</option>
      <option value="1">Active เท่านั้น</option>
      <option value="0">Inactive</option>
    </select>
    <label class="flex items-center gap-2 text-sm text-slate-600 cursor-pointer">
      <input type="checkbox" x-model="filterFree" class="rounded border-slate-300 text-emerald-600"> Free
    </label>
    <label class="flex items-center gap-2 text-sm text-slate-600 cursor-pointer">
      <input type="checkbox" x-model="filterVision" class="rounded border-slate-300 text-indigo-600"> Vision
    </label>
    <label class="flex items-center gap-2 text-sm text-slate-600 cursor-pointer">
      <input type="checkbox" x-model="filterTools" class="rounded border-slate-300 text-indigo-600"> Tools
    </label>
    <select x-model.number="perPage" class="px-3 py-2 rounded-xl border border-slate-200 text-sm focus:ring-2 focus:ring-indigo-500 outline-none">
      <option value="25">25 / หน้า</option>
      <option value="50">50 / หน้า</option>
      <option value="100">100 / หน้า</option>
      <option value="0">ทั้งหมด</option>
    </select>
  </div>

  <!-- ── Result Info ──────────────────────────────────────────────────── -->
  <div class="flex flex-wrap items-center justify-between mb-2 px-1">
    <p class="text-xs text-slate-500">
      แสดง <span class="font-semibold text-slate-700" x-text="paginatedModels.length"></span>
      จาก <span class="font-semibold text-slate-700" x-text="filteredModels.length"></span> รายการ
      <span x-show="search || filterProvider || filterStatus !== '' || filterFree || filterVision || filterTools" class="text-indigo-500">(กรองแล้ว)</span>
    </p>
    <button x-show="search || filterProvider || filterStatus !== '' || filterFree || filterVision || filterTools"
            @click="search=''; filterProvider=''; filterStatus=''; filterFree=false; filterVision=false; filterTools=false;"
            class="text-xs text-red-500 hover:text-red-700 underline">ล้างตัวกรอง</button>
  </div>

  <!-- ── Models Table ────────────────────────────────────────────────── -->
  <div class="bg-white rounded-2xl border border-slate-100 overflow-hidden">
    <table class="w-full text-sm">
      <thead class="bg-slate-50 border-b border-slate-100">
        <tr>
          <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider w-8">#</th>
          <th @click="sortBy('display_name')" class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider cursor-pointer hover:text-indigo-600 select-none">
            Model <span x-text="sortIcon('display_name')" class="ml-1"></span>
          </th>
          <th @click="sortBy('provider_name')" class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider hidden md:table-cell cursor-pointer hover:text-indigo-600 select-none">
            Provider <span x-text="sortIcon('provider_name')" class="ml-1"></span>
          </th>
          <th @click="sortBy('context_length')" class="px-4 py-3 text-right text-xs font-semibold text-slate-500 uppercase tracking-wider hidden lg:table-cell cursor-pointer hover:text-indigo-600 select-none">
            Context <span x-text="sortIcon('context_length')" class="ml-1"></span>
          </th>
          <th @click="sortBy('pricing_prompt')" class="px-4 py-3 text-right text-xs font-semibold text-slate-500 uppercase tracking-wider hidden lg:table-cell cursor-pointer hover:text-indigo-600 select-none">
            $/1M Input <span x-text="sortIcon('pricing_prompt')" class="ml-1"></span>
          </th>
          <th @click="sortBy('pricing_completion')" class="px-4 py-3 text-right text-xs font-semibold text-slate-500 uppercase tracking-wider hidden lg:table-cell cursor-pointer hover:text-indigo-600 select-none">
            $/1M Output <span x-text="sortIcon('pricing_completion')" class="ml-1"></span>
          </th>
          <th class="px-4 py-3 text-center text-xs font-semibold text-slate-500 uppercase tracking-wider">Active</th>
          <th class="px-4 py-3 text-center text-xs font-semibold text-slate-500 uppercase tracking-wider">Default</th>
          <th class="px-4 py-3 text-center text-xs font-semibold text-slate-500 uppercase tracking-wider">Actions</th>
        </tr>
      </thead>
      <tbody class="divide-y divide-slate-50" id="sortable-models">
        <template x-for="(model, idx) in paginatedModels" :key="model.id">
          <tr class="hover:bg-slate-50 transition-colors">
            <td class="px-4 py-3 text-slate-400 text-xs" x-text="(currentPage - 1) * (perPage || filteredModels.length) + idx + 1"></td>
            <td class="px-4 py-3">
              <div>
                <p class="font-semibold text-slate-800" x-text="model.display_name"></p>
                <p class="text-xs text-slate-400" x-text="model.openrouter_model_id"></p>
                <div class="flex gap-1 mt-1">
                  <span x-show="model.supports_vision" class="px-1.5 py-0.5 rounded text-[10px] bg-violet-100 text-violet-700">Vision</span>
                  <span x-show="model.supports_tools"  class="px-1.5 py-0.5 rounded text-[10px] bg-amber-100 text-amber-700">Tools</span>
                </div>
              </div>
            </td>
            <td class="px-4 py-3 hidden md:table-cell">
              <span class="px-2 py-1 rounded-lg bg-slate-100 text-slate-600 text-xs font-medium" x-text="model.provider_name || '—'"></span>
            </td>
            <td class="px-4 py-3 text-right text-xs text-slate-600 hidden lg:table-cell" x-text="model.context_length ? Number(model.context_length).toLocaleString() : '—'"></td>
            <td class="px-4 py-3 text-right text-xs hidden lg:table-cell">
              <span x-show="parseFloat(model.pricing_prompt) <= 0" class="px-1.5 py-0.5 rounded bg-emerald-100 text-emerald-700 font-semibold">Free</span>
              <span x-show="parseFloat(model.pricing_prompt) > 0" class="font-mono text-slate-700" x-text="'$' + (parseFloat(model.pricing_prompt)*1000000).toFixed(2)"></span>
            </td>
            <td class="px-4 py-3 text-right text-xs hidden lg:table-cell">
              <span x-show="parseFloat(model.pricing_completion) <= 0" class="px-1.5 py-0.5 rounded bg-emerald-100 text-emerald-700 font-semibold">Free</span>
              <span x-show="parseFloat(model.pricing_completion) > 0" class="font-mono text-slate-700" x-text="'$' + (parseFloat(model.pricing_completion)*1000000).toFixed(2)"></span>
            </td>
            <td class="px-4 py-3 text-center">
              <button @click="toggleActive(model)"
                      class="relative inline-flex h-6 w-11 rounded-full transition-colors duration-200 focus:outline-none"
                      :class="model.is_active ? 'bg-emerald-500' : 'bg-slate-200'">
                <span class="inline-block h-5 w-5 transform rounded-full bg-white shadow transition-transform duration-200 mt-0.5"
                      :class="model.is_active ? 'translate-x-5' : 'translate-x-0.5'"></span>
              </button>
            </td>
            <td class="px-4 py-3 text-center">
              <input type="radio" name="default_model" :checked="model.is_default"
                     @change="setDefault(model)"
                     class="w-4 h-4 text-indigo-600 border-slate-300 focus:ring-indigo-500 cursor-pointer">
            </td>
            <td class="px-4 py-3 text-center">
              <div class="flex items-center justify-center gap-1.5">
                <button @click="testModel(model)" title="ทดสอบ"
                        class="p-1.5 rounded-lg text-slate-400 hover:text-emerald-600 hover:bg-emerald-50 transition-colors">
                  <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z"/>
                    <path stroke-linecap="round" stroke-linejoin="round" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                  </svg>
                </button>
                <button @click="editModel(model)" title="แก้ไข"
                        class="p-1.5 rounded-lg text-slate-400 hover:text-indigo-600 hover:bg-indigo-50 transition-colors">
                  <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                  </svg>
                </button>
              </div>
            </td>
          </tr>
        </template>
      </tbody>
    </table>
    <div x-show="filteredModels.length === 0" class="py-16 text-center text-slate-400 text-sm">ไม่พบ Model ที่ตรงกับเงื่อนไข</div>

    <!-- ── Pagination ──────────────────────────────────────────────────── -->
    <div x-show="totalPages > 1" class="flex items-center justify-between px-4 py-3 border-t border-slate-100 bg-slate-50">
      <p class="text-xs text-slate-500">
        หน้า <span x-text="currentPage"></span> / <span x-text="totalPages"></span>
      </p>
      <div class="flex gap-1">
        <button @click="currentPage = 1" :disabled="currentPage <= 1"
                class="px-2.5 py-1.5 rounded-lg text-xs font-medium border border-slate-200 hover:bg-white disabled:opacity-40 disabled:cursor-not-allowed transition-colors">«</button>
        <button @click="currentPage--" :disabled="currentPage <= 1"
                class="px-2.5 py-1.5 rounded-lg text-xs font-medium border border-slate-200 hover:bg-white disabled:opacity-40 disabled:cursor-not-allowed transition-colors">‹</button>
        <template x-for="p in pageNumbers" :key="p">
          <button @click="currentPage = p"
                  :class="p === currentPage ? 'bg-indigo-600 text-white border-indigo-600' : 'border-slate-200 hover:bg-white'"
                  class="px-2.5 py-1.5 rounded-lg text-xs font-medium border transition-colors" x-text="p"></button>
        </template>
        <button @click="currentPage++" :disabled="currentPage >= totalPages"
                class="px-2.5 py-1.5 rounded-lg text-xs font-medium border border-slate-200 hover:bg-white disabled:opacity-40 disabled:cursor-not-allowed transition-colors">›</button>
        <button @click="currentPage = totalPages" :disabled="currentPage >= totalPages"
                class="px-2.5 py-1.5 rounded-lg text-xs font-medium border border-slate-200 hover:bg-white disabled:opacity-40 disabled:cursor-not-allowed transition-colors">»</button>
      </div>
    </div>
  </div>

  <!-- ── Edit Modal ─────────────────────────────────────────────────── -->
  <div x-show="editModal" x-transition class="fixed inset-0 z-50 flex items-center justify-center p-4">
    <div class="absolute inset-0 bg-slate-900/50 backdrop-blur-sm" @click="editModal=false"></div>
    <div class="relative bg-white rounded-2xl shadow-2xl w-full max-w-md p-6" @click.stop>
      <div class="flex items-center justify-between mb-5">
        <h2 class="text-lg font-bold text-slate-800">แก้ไข Model</h2>
        <button @click="editModal=false" class="text-slate-400 hover:text-slate-600">
          <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
        </button>
      </div>
      <div class="space-y-4">
        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1">Model ID</label>
          <input type="text" :value="editing.openrouter_model_id" readonly class="w-full px-3 py-2 rounded-xl border border-slate-200 text-sm bg-slate-50 text-slate-500 cursor-not-allowed">
        </div>
        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1">Display Name</label>
          <input type="text" x-model="editing.display_name" class="w-full px-3 py-2 rounded-xl border border-slate-200 text-sm focus:ring-2 focus:ring-indigo-500 outline-none">
        </div>
        <div class="grid grid-cols-2 gap-3">
          <div>
            <label class="block text-sm font-medium text-slate-700 mb-1">Max Tokens</label>
            <input type="number" x-model="editing.max_tokens" class="w-full px-3 py-2 rounded-xl border border-slate-200 text-sm focus:ring-2 focus:ring-indigo-500 outline-none">
          </div>
          <div>
            <label class="block text-sm font-medium text-slate-700 mb-1">Sort Order</label>
            <input type="number" x-model="editing.sort_order" class="w-full px-3 py-2 rounded-xl border border-slate-200 text-sm focus:ring-2 focus:ring-indigo-500 outline-none">
          </div>
        </div>
        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1">Custom Notes</label>
          <textarea x-model="editing.custom_notes" rows="3" class="w-full px-3 py-2 rounded-xl border border-slate-200 text-sm focus:ring-2 focus:ring-indigo-500 outline-none resize-none"></textarea>
        </div>
      </div>
      <div class="flex gap-3 mt-6">
        <button @click="editModal=false" class="flex-1 py-2.5 rounded-xl border border-slate-200 text-sm font-medium text-slate-600 hover:bg-slate-50">ยกเลิก</button>
        <button @click="saveEdit()" :disabled="saving"
                class="flex-1 py-2.5 rounded-xl bg-indigo-600 text-white text-sm font-semibold hover:bg-indigo-700 disabled:opacity-60 transition-colors">
          <span x-text="saving ? 'กำลังบันทึก...' : 'บันทึก'"></span>
        </button>
      </div>
    </div>
  </div>

  <!-- ── Test Result Modal ───────────────────────────────────────────── -->
  <div x-show="testModal" x-transition class="fixed inset-0 z-50 flex items-center justify-center p-4">
    <div class="absolute inset-0 bg-slate-900/50 backdrop-blur-sm" @click="testModal=false"></div>
    <div class="relative bg-white rounded-2xl shadow-2xl w-full max-w-sm p-6" @click.stop>
      <h2 class="text-lg font-bold text-slate-800 mb-4">ทดสอบ Model</h2>
      <div x-show="testing" class="flex items-center justify-center py-8 gap-3 text-slate-500">
        <svg class="w-5 h-5 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
        กำลังทดสอบ...
      </div>
      <div x-show="!testing && testResult">
        <div :class="testResult?.success ? 'bg-emerald-50 border-emerald-200' : 'bg-red-50 border-red-200'"
             class="p-4 rounded-xl border mb-3">
          <p class="text-sm font-semibold mb-1" :class="testResult?.success ? 'text-emerald-700' : 'text-red-700'"
             x-text="testResult?.success ? '✅ ทดสอบสำเร็จ' : '❌ ทดสอบล้มเหลว'"></p>
          <p class="text-sm text-slate-600" x-text="testResult?.response || testResult?.error"></p>
        </div>
        <p class="text-xs text-slate-400 text-right" x-text="testResult?.response_time_ms + 'ms'"></p>
      </div>
      <button @click="testModal=false" class="w-full mt-4 py-2.5 rounded-xl border border-slate-200 text-sm font-medium text-slate-600 hover:bg-slate-50">ปิด</button>
    </div>
  </div>
</div>

<script>
const MODELS_DATA = <?= json_encode($models, JSON_UNESCAPED_UNICODE) ?>;
const CSRF = document.querySelector('meta[name="csrf-token"]').content;

function modelManager() {
  return {
    models: MODELS_DATA,
    search: '', filterProvider: '', filterStatus: '', filterFree: false, filterVision: false, filterTools: false,
    syncing: false, syncResult: '', saving: false, testing: false,
    editModal: false, testModal: false, editing: {}, testResult: null,
    sortCol: '', sortDir: 'asc',
    currentPage: 1, perPage: 25,

    init() {
      this.$watch('search', () => this.currentPage = 1);
      this.$watch('filterProvider', () => this.currentPage = 1);
      this.$watch('filterStatus', () => this.currentPage = 1);
      this.$watch('filterFree', () => this.currentPage = 1);
      this.$watch('filterVision', () => this.currentPage = 1);
      this.$watch('filterTools', () => this.currentPage = 1);
      this.$watch('perPage', () => this.currentPage = 1);
    },

    sortBy(col) {
      if (this.sortCol === col) {
        this.sortDir = this.sortDir === 'asc' ? 'desc' : 'asc';
      } else {
        this.sortCol = col;
        this.sortDir = (col === 'context_length') ? 'desc' : 'asc';
      }
    },

    sortIcon(col) {
      if (this.sortCol !== col) return '↕';
      return this.sortDir === 'asc' ? '↑' : '↓';
    },

    get filteredModels() {
      let result = this.models.filter(m => {
        if (this.search) {
          const q = this.search.toLowerCase();
          if (!m.display_name?.toLowerCase().includes(q) &&
              !m.openrouter_model_id?.toLowerCase().includes(q) &&
              !m.provider_name?.toLowerCase().includes(q)) return false;
        }
        if (this.filterProvider && m.provider_name !== this.filterProvider) return false;
        if (this.filterStatus !== '' && String(m.is_active) !== this.filterStatus) return false;
        if (this.filterFree && (parseFloat(m.pricing_prompt) > 0 || parseFloat(m.pricing_completion) > 0)) return false;
        if (this.filterVision && !m.supports_vision) return false;
        if (this.filterTools  && !m.supports_tools)  return false;
        return true;
      });

      if (this.sortCol) {
        const col = this.sortCol;
        const dir = this.sortDir === 'asc' ? 1 : -1;
        const numeric = ['context_length', 'pricing_prompt', 'pricing_completion'];
        result.sort((a, b) => {
          let va = a[col], vb = b[col];
          if (numeric.includes(col)) {
            va = parseFloat(va) || 0;
            vb = parseFloat(vb) || 0;
          } else {
            va = (va || '').toLowerCase();
            vb = (vb || '').toLowerCase();
          }
          if (va < vb) return -1 * dir;
          if (va > vb) return 1 * dir;
          return 0;
        });
      }
      return result;
    },

    get totalPages() {
      if (!this.perPage) return 1;
      return Math.max(1, Math.ceil(this.filteredModels.length / this.perPage));
    },

    get paginatedModels() {
      if (!this.perPage) return this.filteredModels;
      const start = (this.currentPage - 1) * this.perPage;
      return this.filteredModels.slice(start, start + this.perPage);
    },

    get pageNumbers() {
      const total = this.totalPages;
      const cur = this.currentPage;
      const pages = [];
      let start = Math.max(1, cur - 2);
      let end = Math.min(total, start + 4);
      start = Math.max(1, end - 4);
      for (let i = start; i <= end; i++) pages.push(i);
      return pages;
    },

    async syncModels() {
      this.syncing = true; this.syncResult = '';
      try {
        const fd = new FormData(); fd.append('action','sync'); fd.append('_csrf_token', CSRF);
        const res = await fetch('', { method:'POST', body:fd });
        const data = await res.json();
        if (data.success) {
          this.syncResult = data.message;
          location.reload();
        }
      } catch(e) { Noti.error('Sync failed: ' + e.message); }
      this.syncing = false;
    },

    async toggleActive(model) {
      const newVal = model.is_active ? 0 : 1;
      const fd = new FormData(); fd.append('action','toggle_active'); fd.append('id', model.id); fd.append('is_active', newVal); fd.append('_csrf_token', CSRF);
      await fetch('', { method:'POST', body:fd });
      model.is_active = newVal;
    },

    async setDefault(model) {
      this.models.forEach(m => m.is_default = 0);
      model.is_default = 1;
      const fd = new FormData(); fd.append('action','set_default'); fd.append('id', model.id); fd.append('_csrf_token', CSRF);
      await fetch('', { method:'POST', body:fd });
    },

    editModel(model) { this.editing = {...model}; this.editModal = true; },

    async saveEdit() {
      this.saving = true;
      const fd = new FormData();
      fd.append('action','update'); fd.append('_csrf_token', CSRF);
      ['id','display_name','max_tokens','custom_notes','sort_order'].forEach(k => fd.append(k, this.editing[k] ?? ''));
      const res = await fetch('', { method:'POST', body:fd });
      const data = await res.json();
      if (data.success) {
        const idx = this.models.findIndex(m => m.id === this.editing.id);
        if (idx >= 0) Object.assign(this.models[idx], this.editing);
        this.editModal = false;
      }
      this.saving = false;
    },

    async testModel(model) {
      this.testResult = null; this.testing = true; this.testModal = true;
      const fd = new FormData(); fd.append('action','test'); fd.append('model_id', model.openrouter_model_id); fd.append('_csrf_token', CSRF);
      const res = await fetch('', { method:'POST', body:fd });
      const data = await res.json();
      this.testResult = data.data;
      this.testing = false;
    }
  }
}
</script>

<?php require __DIR__ . '/layouts/footer.php'; ?>
