<?php
define('BASE_PATH', dirname(__DIR__, 2));
require_once BASE_PATH . '/vendor/autoload.php';
use Dotenv\Dotenv;
use App\Helpers\Auth;
use App\Helpers\Response;
use App\Helpers\Database;
use App\Services\TokenAnalyticsService;

$dotenv = Dotenv::createImmutable(BASE_PATH);
$dotenv->safeLoad();
date_default_timezone_set($_ENV['APP_TIMEZONE'] ?? 'Asia/Bangkok');

$pageTitle  = 'Token Usage Analytics';
$breadcrumb = ['Analytics', 'Token Usage'];
Auth::requirePermission('view_token_usage');

$db        = Database::getInstance();
$analytics = new TokenAnalyticsService();
$botId     = Auth::requireBot();

// Date range from query string
$dateFrom  = $_GET['from'] ?? date('Y-m-01');           // Start of current month
$dateTo    = $_GET['to']   ?? date('Y-m-d');
$platform  = $_GET['platform'] ?? 'all';

// Validate dates
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) $dateFrom = date('Y-m-01');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo))   $dateTo   = date('Y-m-d');

// AJAX: export CSV
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'export_csv') {
    if (!Auth::validateCsrf()) {
        http_response_code(403);
        exit('CSRF failed');
    }
    Auth::requirePermission('export_data');
    $rows   = $analytics->getDailyTrend($dateFrom, $dateTo, $platform, $botId);
    $handle = fopen('php://output', 'w');
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="token_usage_' . $dateFrom . '_to_' . $dateTo . '.csv"');
    fputcsv($handle, ['Date','Conversations','Tokens Prompt','Tokens Completion','Tokens Total','Cost USD']);
    foreach ($rows as $row) {
        fputcsv($handle, [$row['date'],$row['conversations'],$row['tokens_prompt'],$row['tokens_completion'],$row['tokens_total'],number_format((float)$row['cost_usd'],6)]);
    }
    fclose($handle);
    exit;
}

// Load data
$dailyTrend     = $analytics->getDailyTrend($dateFrom, $dateTo, $platform, $botId);
$byPlatform     = $analytics->getUsageByPlatform($dateFrom, $dateTo, $botId);
$byModel        = $analytics->getUsageByModel($dateFrom, $dateTo, $botId);
$costEstimate   = $analytics->getCostEstimate($botId);
$summary        = $analytics->getDailySummary($botId);
$topCostModels  = $analytics->getTopCostModels(5, $botId);

// Aggregate period totals
$periodTokens = array_sum(array_column($dailyTrend, 'tokens_total'));
$periodCost   = array_sum(array_column($dailyTrend, 'cost_usd'));
$periodConvs  = array_sum(array_column($dailyTrend, 'conversations'));

// Chart data
$chartLabels     = array_column($dailyTrend, 'date');
$chartTokens     = array_map(fn($r) => (int)$r['tokens_total'],     $dailyTrend);
$chartCost       = array_map(fn($r) => round((float)$r['cost_usd'],6), $dailyTrend);
$chartConvs      = array_map(fn($r) => (int)$r['conversations'],     $dailyTrend);
$chartPrompt     = array_map(fn($r) => (int)$r['tokens_prompt'],     $dailyTrend);
$chartCompletion = array_map(fn($r) => (int)$r['tokens_completion'], $dailyTrend);

require __DIR__ . '/layouts/header.php';
?>
<meta name="csrf-token" content="<?= Auth::csrfToken() ?>">

<div x-data="tokenAnalytics()">
  <!-- ── Date Filter Bar ─────────────────────────────────────────────── -->
  <div class="bg-white rounded-2xl p-4 border border-slate-100 mb-6 flex flex-wrap items-center gap-3">
    <div class="flex items-center gap-2">
      <label class="text-xs font-medium text-slate-500">จาก</label>
      <input type="date" id="date-from" value="<?= $dateFrom ?>"
             class="px-3 py-2 rounded-xl border border-slate-200 text-sm focus:ring-2 focus:ring-indigo-500 outline-none">
    </div>
    <div class="flex items-center gap-2">
      <label class="text-xs font-medium text-slate-500">ถึง</label>
      <input type="date" id="date-to" value="<?= $dateTo ?>"
             class="px-3 py-2 rounded-xl border border-slate-200 text-sm focus:ring-2 focus:ring-indigo-500 outline-none">
    </div>
    <select id="platform-filter" class="px-3 py-2 rounded-xl border border-slate-200 text-sm focus:ring-2 focus:ring-indigo-500 outline-none">
      <option value="all" <?= $platform==='all' ? 'selected' : '' ?>>🌐 ทุก Platform</option>
      <option value="web" <?= $platform==='web' ? 'selected' : '' ?>>Web Chat</option>
      <option value="facebook" <?= $platform==='facebook' ? 'selected' : '' ?>>Facebook</option>
      <option value="line" <?= $platform==='line' ? 'selected' : '' ?>>LINE</option>
    </select>
    <!-- Quick ranges -->
    <div class="flex gap-1.5 ml-auto">
      <?php foreach ([['7d','7 วัน'],['30d','30 วัน'],['mtd','เดือนนี้']] as [$key,$label]): ?>
      <button onclick="setRange('<?= $key ?>')"
              class="px-3 py-2 rounded-xl border border-slate-200 text-xs font-medium text-slate-600 hover:bg-indigo-50 hover:border-indigo-300 hover:text-indigo-600 transition-all">
        <?= $label ?>
      </button>
      <?php endforeach; ?>
      <button onclick="applyFilter()"
              class="px-4 py-2 rounded-xl bg-indigo-600 text-white text-sm font-semibold hover:bg-indigo-700 transition-colors">
        ค้นหา
      </button>
      <?php if (Auth::can('export_data')): ?>
      <button onclick="exportCsv()"
              class="px-4 py-2 rounded-xl border border-slate-200 text-slate-600 text-sm font-medium hover:bg-slate-50 flex items-center gap-1.5 transition-colors">
        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
        </svg>
        CSV
      </button>
      <?php endif; ?>
    </div>
  </div>

  <!-- ── Period Summary Cards ───────────────────────────────────────── -->
  <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
    <?php
    $summCards = [
      ['label'=>'Conversations', 'value'=>number_format($periodConvs), 'sub'=>'ในช่วงที่เลือก','icon'=>'M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z','color'=>'indigo'],
      ['label'=>'Tokens รวม',   'value'=>number_format($periodTokens),'sub'=>'Prompt + Completion','icon'=>'M13 10V3L4 14h7v7l9-11h-7z','color'=>'violet'],
      ['label'=>'Cost รวม',     'value'=>'$'.number_format($periodCost,4),'sub'=>'USD','icon'=>'M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z','color'=>'amber'],
      ['label'=>'เดือนนี้ (Projected)','value'=>'$'.number_format($costEstimate['projected_end_of_month'],2),'sub'=>'ใช้ไปแล้ว $'.number_format($costEstimate['month_to_date'],4),'icon'=>'M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z','color'=>'emerald'],
    ];
    $gradients = ['indigo'=>'from-indigo-500 to-indigo-700','violet'=>'from-violet-500 to-violet-700','amber'=>'from-amber-400 to-orange-500','emerald'=>'from-emerald-500 to-emerald-700'];
    foreach ($summCards as $c):
    ?>
    <div class="bg-white rounded-2xl p-5 border border-slate-100 card-hover">
      <div class="flex items-center justify-between mb-3">
        <p class="text-xs text-slate-500 font-medium"><?= $c['label'] ?></p>
        <div class="w-8 h-8 rounded-lg bg-gradient-to-br <?= $gradients[$c['color']] ?> flex items-center justify-center">
          <svg class="w-4 h-4 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
            <path stroke-linecap="round" stroke-linejoin="round" d="<?= $c['icon'] ?>"/>
          </svg>
        </div>
      </div>
      <p class="text-2xl font-bold text-slate-800"><?= $c['value'] ?></p>
      <p class="text-xs text-slate-400 mt-1"><?= $c['sub'] ?></p>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- ── Token Trend Chart ──────────────────────────────────────────── -->
  <div class="bg-white rounded-2xl p-5 border border-slate-100 mb-6">
    <div class="flex flex-wrap items-center justify-between mb-5 gap-3">
      <div>
        <h3 class="font-semibold text-slate-800">Token Usage Trend</h3>
        <p class="text-xs text-slate-400"><?= $dateFrom ?> — <?= $dateTo ?></p>
      </div>
      <div class="flex gap-2" x-data="{metric:'tokens'}">
        <button @click="metric='tokens'; updateMetric('tokens')"
                :class="metric==='tokens' ? 'bg-indigo-600 text-white' : 'bg-slate-100 text-slate-600'"
                class="px-3 py-1.5 rounded-lg text-xs font-medium transition-colors">Tokens</button>
        <button @click="metric='cost'; updateMetric('cost')"
                :class="metric==='cost' ? 'bg-indigo-600 text-white' : 'bg-slate-100 text-slate-600'"
                class="px-3 py-1.5 rounded-lg text-xs font-medium transition-colors">Cost</button>
        <button @click="metric='conversations'; updateMetric('conversations')"
                :class="metric==='conversations' ? 'bg-indigo-600 text-white' : 'bg-slate-100 text-slate-600'"
                class="px-3 py-1.5 rounded-lg text-xs font-medium transition-colors">Conversations</button>
      </div>
    </div>
    <div class="relative w-full h-[250px]">
      <canvas id="trendChart"></canvas>
    </div>
  </div>

  <!-- ── Prompt vs Completion Stacked ────────────────────────────────── -->
  <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-6">
    <div class="bg-white rounded-2xl p-5 border border-slate-100">
      <h3 class="font-semibold text-slate-800 mb-1 text-sm">Prompt vs Completion Tokens</h3>
      <p class="text-xs text-slate-400 mb-4">สัดส่วนการใช้ Token</p>
      <div class="relative w-full h-[300px]">
        <canvas id="stackedChart"></canvas>
      </div>
    </div>

    <!-- Platform Breakdown Table -->
    <div class="bg-white rounded-2xl border border-slate-100 overflow-hidden">
      <div class="px-5 py-4 border-b border-slate-50">
        <h3 class="font-semibold text-slate-800 text-sm">Platform Breakdown</h3>
        <p class="text-xs text-slate-400">ใช้ Token แยกตาม Platform</p>
      </div>
      <div class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead class="bg-slate-50">
            <tr>
              <th class="px-4 py-2.5 text-left text-xs font-semibold text-slate-500">Platform</th>
              <th class="px-4 py-2.5 text-right text-xs font-semibold text-slate-500">Sessions</th>
              <th class="px-4 py-2.5 text-right text-xs font-semibold text-slate-500">Tokens</th>
              <th class="px-4 py-2.5 text-right text-xs font-semibold text-slate-500">Cost</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-50">
            <?php foreach ($byPlatform as $p):
              $icon = match($p['platform']){'web'=>'🌐','facebook'=>'📘','line'=>'💚',default=>'📊'};
            ?>
            <tr class="hover:bg-slate-50">
              <td class="px-4 py-3 font-medium text-slate-700"><?= $icon ?> <?= ucfirst($p['platform']) ?></td>
              <td class="px-4 py-3 text-right text-slate-600 font-mono text-xs"><?= number_format($p['conversations']) ?></td>
              <td class="px-4 py-3 text-right text-slate-600 font-mono text-xs"><?= number_format($p['tokens_total']) ?></td>
              <td class="px-4 py-3 text-right font-mono text-xs text-emerald-700 font-semibold">$<?= number_format((float)$p['cost_usd'],4) ?></td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($byPlatform)): ?>
            <tr><td colspan="4" class="px-4 py-8 text-center text-slate-400 text-sm">ยังไม่มีข้อมูล</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- ── Model Usage Table ──────────────────────────────────────────── -->
  <div class="bg-white rounded-2xl border border-slate-100 overflow-hidden mb-6">
    <div class="px-5 py-4 border-b border-slate-50 flex items-center justify-between">
      <div>
        <h3 class="font-semibold text-slate-800 text-sm">Usage by AI Model</h3>
        <p class="text-xs text-slate-400">แยกตาม Model ที่ใช้</p>
      </div>
    </div>
    <div class="overflow-x-auto">
      <table class="w-full text-sm">
        <thead class="bg-slate-50 border-b border-slate-100">
          <tr>
            <th class="px-5 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Model</th>
            <th class="px-5 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider hidden md:table-cell">Provider</th>
            <th class="px-5 py-3 text-right text-xs font-semibold text-slate-500 uppercase tracking-wider">Conversations</th>
            <th class="px-5 py-3 text-right text-xs font-semibold text-slate-500 uppercase tracking-wider">Tokens</th>
            <th class="px-5 py-3 text-right text-xs font-semibold text-slate-500 uppercase tracking-wider">Cost USD</th>
            <th class="px-5 py-3 text-right text-xs font-semibold text-slate-500 uppercase tracking-wider hidden lg:table-cell">Avg Resp.</th>
            <th class="px-5 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">สัดส่วน</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-50">
          <?php
          $totalModelCost = array_sum(array_column($byModel, 'cost_usd')) ?: 1;
          foreach ($byModel as $m):
            $pct = round($m['cost_usd'] / $totalModelCost * 100, 1);
          ?>
          <tr class="hover:bg-slate-50 transition-colors">
            <td class="px-5 py-3.5">
              <p class="font-semibold text-slate-800 text-sm"><?= htmlspecialchars($m['model_name'] ?? $m['model_id']) ?></p>
              <p class="text-xs text-slate-400"><?= htmlspecialchars($m['model_id'] ?? '') ?></p>
            </td>
            <td class="px-5 py-3.5 hidden md:table-cell">
              <span class="px-2 py-1 rounded-lg bg-slate-100 text-slate-600 text-xs"><?= htmlspecialchars($m['provider'] ?? '—') ?></span>
            </td>
            <td class="px-5 py-3.5 text-right font-mono text-xs text-slate-600"><?= number_format($m['conversations']) ?></td>
            <td class="px-5 py-3.5 text-right font-mono text-xs text-slate-600"><?= number_format($m['tokens_total']) ?></td>
            <td class="px-5 py-3.5 text-right font-mono text-xs font-bold text-emerald-700">$<?= number_format((float)$m['cost_usd'],4) ?></td>
            <td class="px-5 py-3.5 text-right text-xs text-slate-500 hidden lg:table-cell"><?= number_format($m['avg_response_ms'] ?? 0) ?>ms</td>
            <td class="px-5 py-3.5">
              <div class="flex items-center gap-2">
                <div class="flex-1 bg-slate-100 rounded-full h-1.5">
                  <div class="h-1.5 rounded-full bg-indigo-500" style="width:<?= min(100,$pct) ?>%"></div>
                </div>
                <span class="text-xs text-slate-500 w-8 text-right"><?= $pct ?>%</span>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php if (empty($byModel)): ?>
          <tr><td colspan="7" class="px-5 py-10 text-center text-slate-400 text-sm">ยังไม่มีข้อมูลการใช้งาน</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<script>
const CHART_LABELS     = <?= json_encode($chartLabels) ?>;
const CHART_TOKENS     = <?= json_encode($chartTokens) ?>;
const CHART_COST       = <?= json_encode($chartCost) ?>;
const CHART_CONVS      = <?= json_encode($chartConvs) ?>;
const CHART_PROMPT     = <?= json_encode($chartPrompt) ?>;
const CHART_COMPLETION = <?= json_encode($chartCompletion) ?>;
const CSRF = document.querySelector('meta[name="csrf-token"]').content;

// ── Trend Chart ────────────────────────────────────────────────────────
let trendChart;
function buildTrendChart(labels, data, label, color) {
  const ctx = document.getElementById('trendChart');
  if (trendChart) trendChart.destroy();
  trendChart = new Chart(ctx, {
    type: 'bar',
    data: {
      labels,
      datasets: [{
        label, data,
        backgroundColor: color + '33',
        borderColor:     color,
        borderWidth: 1.5,
        borderRadius: 4,
        borderSkipped: false,
      }]
    },
    options: {
      responsive:true, maintainAspectRatio:false,
      plugins:{ legend:{ display:false } },
      scales:{
        x:{ grid:{ display:false }, ticks:{ font:{size:10}, maxTicksLimit:12 } },
        y:{ grid:{ color:'#f1f5f9' }, ticks:{ font:{size:10} } }
      }
    }
  });
}

buildTrendChart(CHART_LABELS, CHART_TOKENS, 'Tokens', '#4f46e5');

function updateMetric(m) {
  const map = {
    tokens:        [CHART_TOKENS,'Tokens','#4f46e5'],
    cost:          [CHART_COST,'Cost (USD)','#10b981'],
    conversations: [CHART_CONVS,'Conversations','#f59e0b'],
  };
  buildTrendChart(CHART_LABELS, ...map[m]);
}

// ── Stacked Chart ──────────────────────────────────────────────────────
new Chart(document.getElementById('stackedChart'), {
  type: 'bar',
  data: {
    labels: CHART_LABELS,
    datasets: [
      { label:'Prompt',     data: CHART_PROMPT,     backgroundColor:'#818cf8', borderRadius:{topLeft:0,topRight:0}, stack:'tok' },
      { label:'Completion', data: CHART_COMPLETION, backgroundColor:'#4f46e5', borderRadius:{topLeft:3,topRight:3}, stack:'tok' },
    ]
  },
  options: {
    responsive:true, maintainAspectRatio:false,
    plugins:{ legend:{ position:'bottom', labels:{ font:{size:11}, usePointStyle:true } } },
    scales:{
      x:{ stacked:true, grid:{ display:false }, ticks:{ font:{size:9}, maxTicksLimit:10 } },
      y:{ stacked:true, grid:{ color:'#f1f5f9' }, ticks:{ font:{size:10} } }
    }
  }
});

// ── Date filter ────────────────────────────────────────────────────────
function setRange(r) {
  const today = new Date();
  const fmt = d => d.toISOString().split('T')[0];
  const from = new Date(today);
  if (r==='7d')  from.setDate(today.getDate()-6);
  if (r==='30d') from.setDate(today.getDate()-29);
  if (r==='mtd') from.setDate(1);
  document.getElementById('date-from').value = fmt(from);
  document.getElementById('date-to').value   = fmt(today);
  applyFilter();
}

function applyFilter() {
  const from = document.getElementById('date-from').value;
  const to   = document.getElementById('date-to').value;
  const p    = document.getElementById('platform-filter').value;
  window.location.href = `?from=${from}&to=${to}&platform=${p}`;
}

function exportCsv() {
  const from = document.getElementById('date-from').value;
  const to   = document.getElementById('date-to').value;
  const p    = document.getElementById('platform-filter').value;
  const fd = new FormData();
  fd.append('action','export_csv'); fd.append('_csrf_token', CSRF);
  fetch(`?from=${from}&to=${to}&platform=${p}`, { method:'POST', body:fd })
    .then(r => r.blob()).then(b => {
      const a = document.createElement('a'); a.href = URL.createObjectURL(b);
      a.download = `token_usage_${from}_to_${to}.csv`; a.click();
    });
}

function tokenAnalytics() { return {}; }
</script>

<?php require __DIR__ . '/layouts/footer.php'; ?>
