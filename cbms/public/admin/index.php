<?php
define('BASE_PATH', dirname(__DIR__, 2));
require_once BASE_PATH . '/vendor/autoload.php';
use Dotenv\Dotenv;
use App\Helpers\Auth;
use App\Services\TokenAnalyticsService;
use App\Services\OpenRouterService;
use App\Helpers\Database;

$dotenv = Dotenv::createImmutable(BASE_PATH);
$dotenv->safeLoad();
date_default_timezone_set($_ENV['APP_TIMEZONE'] ?? 'Asia/Bangkok');

$pageTitle  = 'Dashboard';
$breadcrumb = ['หน้าแรก', 'Dashboard'];
Auth::requirePermission('view_dashboard');

$db        = Database::getInstance();
$analytics = new TokenAnalyticsService();
$or        = new OpenRouterService();
$botId     = Auth::requireBot();

$summary      = $analytics->getDailySummary($botId);
$chartData    = $analytics->getPlatformDailyChart(30, $botId);
$costEstimate = $analytics->getCostEstimate($botId);
$defaultModel = $or->getDefaultModel();

// Platform breakdown today
$today = date('Y-m-d');
$platformStats = $analytics->getUsageByPlatform($today, $today, $botId);
$pMap = array_column($platformStats, null, 'platform');

// Recent conversations
$recentConvs = $db->fetchAll(
    "SELECT c.*, am.display_name AS model_name
     FROM conversations c
     LEFT JOIN ai_models am ON am.id = c.ai_model_id
     WHERE c.bot_id = ?
     ORDER BY c.last_activity_at DESC LIMIT 5",
    [$botId]
);

// Feedback stats
$feedbackStats = $db->fetch(
    "SELECT
       COUNT(CASE WHEN feedback = 'positive' THEN 1 END) AS positive,
       COUNT(CASE WHEN feedback = 'negative' THEN 1 END) AS negative,
       COUNT(CASE WHEN feedback IS NOT NULL THEN 1 END) AS total
     FROM messages m
     JOIN conversations c ON c.id = m.conversation_id
     WHERE m.role = 'assistant' AND c.bot_id = ?",
    [$botId]
);
$todayFeedback = $db->fetch(
    "SELECT
       COUNT(CASE WHEN feedback = 'positive' THEN 1 END) AS positive,
       COUNT(CASE WHEN feedback = 'negative' THEN 1 END) AS negative
     FROM messages m
     JOIN conversations c ON c.id = m.conversation_id
     WHERE m.role = 'assistant' AND DATE(m.created_at) = CURDATE() AND c.bot_id = ?",
    [$botId]
);

// Knowledge base status
$kbStatus = $db->fetch(
    "SELECT COUNT(*) AS total,
     SUM(CASE WHEN sync_status='synced' THEN 1 ELSE 0 END) AS synced,
     SUM(CASE WHEN sync_status='error' THEN 1 ELSE 0 END) AS errors,
     SUM(chunk_count) AS total_chunks
     FROM knowledge_sources WHERE is_active=1 AND bot_id = ?",
    [$botId]
);

require __DIR__ . '/layouts/header.php';
?>
<meta name="csrf-token" content="<?= Auth::csrfToken() ?>">

<!-- ── Summary Cards Row 1 ─────────────────────────────────────────── -->
<div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
  <?php
  $cards = [
    ['label'=>'Conversations วันนี้','value'=>$summary['today_conversations'],'prev'=>$summary['yesterday_conversations'],'icon'=>'M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z','color'=>'indigo'],
    ['label'=>'Messages วันนี้','value'=>$summary['today_messages'],'prev'=>$summary['yesterday_messages'],'icon'=>'M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z','color'=>'violet'],
    ['label'=>'Tokens วันนี้','value'=>number_format($summary['today_tokens']),'prev'=>$summary['yesterday_tokens'],'icon'=>'M13 10V3L4 14h7v7l9-11h-7z','color'=>'amber','format'=>'number'],
    ['label'=>'Cost วันนี้','value'=>'$'.number_format($summary['today_cost'],4),'prev'=>$summary['yesterday_cost'],'icon'=>'M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z','color'=>'emerald'],
  ];
  $colors = ['indigo'=>['bg'=>'bg-indigo-50','icon'=>'bg-indigo-600','text'=>'text-indigo-700'],
             'violet'=>['bg'=>'bg-violet-50','icon'=>'bg-violet-600','text'=>'text-violet-700'],
             'amber' =>['bg'=>'bg-amber-50', 'icon'=>'bg-amber-500', 'text'=>'text-amber-700'],
             'emerald'=>['bg'=>'bg-emerald-50','icon'=>'bg-emerald-600','text'=>'text-emerald-700']];
  foreach ($cards as $c):
    $col = $colors[$c['color']];
    $numVal = is_numeric($c['prev']) ? (float)$c['prev'] : 0;
    $todayNum = is_numeric($summary['today_'.(strstr($c['label'],' ',true)==='Conversations'?'conversations':(strstr($c['label'],' ',true)==='Messages'?'messages':(strstr($c['label'],' ',true)==='Tokens'?'tokens':'cost')))]) ? true : false;
    $change = $numVal > 0
        ? round(((is_numeric(str_replace(['$',','],'',$c['value'])) ? (float)str_replace(['$',','],'',$c['value']) : $numVal) - $numVal) / $numVal * 100, 1)
        : 0;
  ?>
  <div class="bg-white rounded-2xl p-5 border border-slate-100 card-hover">
    <div class="flex items-start justify-between mb-3">
      <p class="text-xs font-medium text-slate-500"><?= $c['label'] ?></p>
      <div class="w-9 h-9 <?= $col['icon'] ?> rounded-xl flex items-center justify-center">
        <svg class="w-5 h-5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
          <path stroke-linecap="round" stroke-linejoin="round" d="<?= $c['icon'] ?>"/>
        </svg>
      </div>
    </div>
    <p class="text-2xl font-bold text-slate-800"><?= $c['value'] ?></p>
    <p class="text-xs text-slate-400 mt-1">
      เมื่อวาน:
      <?php if (is_numeric($c['prev'])): ?>
        <?= $c['color']==='emerald' ? '$'.number_format($c['prev'],4) : number_format($c['prev']) ?>
        <?php if ($change !== 0): ?>
          <span class="ml-1 font-medium <?= $change > 0 ? 'text-emerald-600' : 'text-red-500' ?>">
            <?= ($change > 0 ? '↑' : '↓') . abs($change) ?>%
          </span>
        <?php endif; ?>
      <?php else: ?>—<?php endif; ?>
    </p>
  </div>
  <?php endforeach; ?>
</div>

<!-- ── Platform Cards Row 2 ─────────────────────────────────────────── -->
<div class="grid grid-cols-3 gap-4 mb-6">
  <?php
  $platforms = [
    ['key'=>'web',     'label'=>'🌐 Web Chat', 'color'=>'indigo'],
    ['key'=>'facebook','label'=>'📘 Facebook', 'color'=>'blue'],
    ['key'=>'line',    'label'=>'💚 LINE',     'color'=>'emerald'],
  ];
  foreach ($platforms as $p):
    $stat = $pMap[$p['key']] ?? [];
  ?>
  <div class="bg-white rounded-2xl p-4 border border-slate-100 card-hover">
    <p class="text-sm font-semibold text-slate-700 mb-3"><?= $p['label'] ?></p>
    <div class="space-y-1.5">
      <div class="flex justify-between text-xs">
        <span class="text-slate-500">Sessions</span>
        <span class="font-semibold text-slate-700"><?= number_format($stat['conversations'] ?? 0) ?></span>
      </div>
      <div class="flex justify-between text-xs">
        <span class="text-slate-500">Messages</span>
        <span class="font-semibold text-slate-700"><?= number_format($stat['messages'] ?? 0) ?></span>
      </div>
      <div class="flex justify-between text-xs">
        <span class="text-slate-500">Tokens</span>
        <span class="font-semibold text-slate-700"><?= number_format($stat['tokens_total'] ?? 0) ?></span>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<!-- ── Feedback Summary ────────────────────────────────────────────────── -->
<div class="bg-white rounded-2xl border border-slate-100 p-5 mb-6">
  <div class="flex items-center justify-between mb-4">
    <div>
      <h3 class="text-sm font-semibold text-slate-800">Feedback Overview</h3>
      <p class="text-xs text-slate-400">สรุปความพึงพอใจผู้ใช้</p>
    </div>
    <?php if (Auth::can('view_conversations')): ?>
      <a href="feedback.php" class="text-xs text-indigo-600 hover:text-indigo-700 font-medium">ดูรายละเอียด →</a>
    <?php endif; ?>
  </div>
  <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
    <div class="text-center p-3 bg-slate-50 rounded-xl">
      <p class="text-2xl font-bold text-slate-800"><?= number_format($feedbackStats['total']) ?></p>
      <p class="text-xs text-slate-500">Feedback ทั้งหมด</p>
    </div>
    <div class="text-center p-3 bg-emerald-50 rounded-xl">
      <p class="text-2xl font-bold text-emerald-600"><?= number_format($feedbackStats['positive']) ?></p>
      <p class="text-xs text-emerald-600">ถูกใจ</p>
    </div>
    <div class="text-center p-3 bg-red-50 rounded-xl">
      <p class="text-2xl font-bold text-red-600"><?= number_format($feedbackStats['negative']) ?></p>
      <p class="text-xs text-red-600">ไม่ถูกใจ</p>
    </div>
    <div class="text-center p-3 bg-indigo-50 rounded-xl">
      <?php $rate = $feedbackStats['total'] > 0 ? round($feedbackStats['positive'] / $feedbackStats['total'] * 100, 1) : 0; ?>
      <p class="text-2xl font-bold text-indigo-600"><?= $rate ?>%</p>
      <p class="text-xs text-indigo-600">อัตราพึงพอใจ</p>
    </div>
  </div>
  <?php if ($todayFeedback['positive'] > 0 || $todayFeedback['negative'] > 0): ?>
  <p class="text-xs text-slate-400 mt-3 text-center">
    วันนี้:
    <span class="text-emerald-600 font-medium"><?= $todayFeedback['positive'] ?> ถูกใจ</span> ·
    <span class="text-red-500 font-medium"><?= $todayFeedback['negative'] ?> ไม่ถูกใจ</span>
  </p>
  <?php endif; ?>
</div>

<!-- ── Charts Row ─────────────────────────────────────────────────────── -->
<div class="grid grid-cols-1 lg:grid-cols-5 gap-4 mb-6">
  <!-- Line Chart 30 days -->
  <div class="lg:col-span-3 bg-white rounded-2xl p-5 border border-slate-100">
    <div class="flex items-center justify-between mb-4">
      <div>
        <h3 class="text-sm font-semibold text-slate-800">Conversations (30 วัน)</h3>
        <p class="text-xs text-slate-400">แยกตาม Platform</p>
      </div>
      <div class="flex gap-3 text-xs">
        <span class="flex items-center gap-1.5"><span class="w-3 h-0.5 bg-indigo-500 inline-block rounded"></span>Web</span>
        <span class="flex items-center gap-1.5"><span class="w-3 h-0.5 bg-blue-700 inline-block rounded"></span>Facebook</span>
        <span class="flex items-center gap-1.5"><span class="w-3 h-0.5 bg-emerald-500 inline-block rounded"></span>LINE</span>
      </div>
    </div>
    <div class="relative w-full h-[250px]">
      <canvas id="convChart"></canvas>
    </div>
  </div>

  <!-- Donut Chart -->
  <div class="lg:col-span-2 bg-white rounded-2xl p-5 border border-slate-100 flex flex-col">
    <h3 class="text-sm font-semibold text-slate-800 mb-1">Platform Distribution</h3>
    <p class="text-xs text-slate-400 mb-4">สัดส่วน Conversations วันนี้</p>
    <div class="relative w-full flex-1 min-h-[200px]">
      <canvas id="donutChart"></canvas>
    </div>
  </div>
</div>

<!-- ── Bottom Row ─────────────────────────────────────────────────────── -->
<div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
  <!-- Recent Conversations -->
  <div class="bg-white rounded-2xl border border-slate-100 overflow-hidden">
    <div class="px-5 py-4 border-b border-slate-50 flex items-center justify-between">
      <h3 class="text-sm font-semibold text-slate-800">Recent Conversations</h3>
      <a href="conversations.php" class="text-xs text-indigo-600 hover:text-indigo-700 font-medium">ดูทั้งหมด →</a>
    </div>
    <div class="divide-y divide-slate-50">
      <?php if (empty($recentConvs)): ?>
        <p class="px-5 py-8 text-center text-sm text-slate-400">ยังไม่มี Conversations</p>
      <?php else: foreach ($recentConvs as $conv):
        $platformColors = ['web'=>'bg-indigo-100 text-indigo-700','facebook'=>'bg-blue-100 text-blue-700','line'=>'bg-emerald-100 text-emerald-700'];
        $pColor = $platformColors[$conv['platform']] ?? 'bg-slate-100 text-slate-600';
        $timeAgo = function($dt) { $diff = time()-strtotime($dt); return $diff<60?'เมื่อกี้':($diff<3600?floor($diff/60).'ม.':($diff<86400?floor($diff/3600).'ชม.':floor($diff/86400).'ว.')); };
      ?>
      <div class="px-5 py-3 flex items-center gap-3 hover:bg-slate-50 transition-colors">
        <span class="inline-flex items-center px-2 py-0.5 rounded-md text-xs font-medium <?= $pColor ?>">
          <?= ucfirst($conv['platform']) ?>
        </span>
        <div class="flex-1 min-w-0">
          <p class="text-xs text-slate-600 truncate"><?= htmlspecialchars($conv['session_id']) ?></p>
          <p class="text-xs text-slate-400"><?= $conv['message_count'] ?> messages · <?= $conv['model_name'] ?? 'N/A' ?></p>
        </div>
        <span class="text-xs text-slate-400 shrink-0"><?= $timeAgo($conv['last_activity_at']) ?></span>
      </div>
      <?php endforeach; endif; ?>
    </div>
  </div>

  <!-- Right Panel -->
  <div class="space-y-4">
    <!-- Default Model Card -->
    <div class="bg-white rounded-2xl p-5 border border-slate-100">
      <h3 class="text-xs font-semibold text-slate-500 uppercase tracking-wider mb-3">Default AI Model</h3>
      <?php if ($defaultModel): ?>
      <div class="flex items-center gap-3">
        <div class="w-10 h-10 rounded-xl bg-indigo-600 flex items-center justify-center">
          <svg class="w-5 h-5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
            <path stroke-linecap="round" stroke-linejoin="round" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
          </svg>
        </div>
        <div>
          <p class="font-semibold text-slate-800"><?= htmlspecialchars($defaultModel['display_name']) ?></p>
          <p class="text-xs text-slate-500"><?= htmlspecialchars($defaultModel['provider_name'] ?? '') ?> · Context <?= number_format($defaultModel['context_length'] ?? 0) ?> tokens</p>
        </div>
      </div>
      <div class="grid grid-cols-2 gap-3 mt-3">
        <div class="bg-slate-50 rounded-xl p-3 text-center">
          <p class="text-xs text-slate-500">Input</p>
          <p class="text-sm font-bold text-slate-700">$<?= number_format((float)$defaultModel['pricing_prompt']*1000000,2) ?>/1M</p>
        </div>
        <div class="bg-slate-50 rounded-xl p-3 text-center">
          <p class="text-xs text-slate-500">Output</p>
          <p class="text-sm font-bold text-slate-700">$<?= number_format((float)$defaultModel['pricing_completion']*1000000,2) ?>/1M</p>
        </div>
      </div>
      <?php else: ?>
        <p class="text-sm text-slate-400">ยังไม่มี Model — <a href="models.php" class="text-indigo-600">ตั้งค่า</a></p>
      <?php endif; ?>
    </div>

    <!-- Knowledge Base Status -->
    <div class="bg-white rounded-2xl p-5 border border-slate-100">
      <div class="flex items-center justify-between mb-3">
        <h3 class="text-xs font-semibold text-slate-500 uppercase tracking-wider">Knowledge Base</h3>
        <?php if (Auth::can('manage_knowledge')): ?>
          <a href="knowledge.php" class="text-xs text-indigo-600 font-medium">จัดการ →</a>
        <?php endif; ?>
      </div>
      <div class="grid grid-cols-3 gap-2">
        <div class="text-center p-2 bg-slate-50 rounded-xl">
          <p class="text-lg font-bold text-slate-800"><?= $kbStatus['total'] ?? 0 ?></p>
          <p class="text-xs text-slate-500">ไฟล์ทั้งหมด</p>
        </div>
        <div class="text-center p-2 bg-emerald-50 rounded-xl">
          <p class="text-lg font-bold text-emerald-700"><?= $kbStatus['synced'] ?? 0 ?></p>
          <p class="text-xs text-emerald-600">Synced</p>
        </div>
        <div class="text-center p-2 <?= ($kbStatus['errors']??0)>0 ? 'bg-red-50' : 'bg-slate-50' ?> rounded-xl">
          <p class="text-lg font-bold <?= ($kbStatus['errors']??0)>0 ? 'text-red-600' : 'text-slate-800' ?>"><?= $kbStatus['errors'] ?? 0 ?></p>
          <p class="text-xs <?= ($kbStatus['errors']??0)>0 ? 'text-red-500' : 'text-slate-500' ?>">Errors</p>
        </div>
      </div>
      <p class="text-xs text-slate-400 mt-2 text-center"><?= number_format($kbStatus['total_chunks'] ?? 0) ?> chunks รวม</p>
    </div>
    <!-- Integrations Status -->
    <div class="bg-white rounded-2xl p-5 border border-slate-100">
      <h3 class="text-xs font-semibold text-slate-500 uppercase tracking-wider mb-3">Integrations & API</h3>
      <div class="space-y-3">
        
        <!-- Facebook -->
        <div class="flex items-center justify-between p-3 bg-slate-50 rounded-xl">
          <div class="flex items-center gap-3">
            <div class="w-8 h-8 bg-blue-100 rounded-lg flex items-center justify-center text-blue-600">
              <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24"><path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.469h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.469h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/></svg>
            </div>
            <div class="min-w-0">
              <p class="text-sm font-semibold text-slate-800">Facebook</p>
              <p class="text-xs text-slate-500 truncate" id="fb-status-text">
                <?= !empty($_ENV['FACEBOOK_PAGE_ACCESS_TOKEN']) ? 'ตั้งค่า Token แล้ว' : 'ยังไม่ได้ตั้งค่า Token' ?>
              </p>
            </div>
          </div>
          <?php if (!empty($_ENV['FACEBOOK_PAGE_ACCESS_TOKEN'])): ?>
            <button onclick="testIntegration('facebook')" class="shrink-0 px-3 py-1.5 text-xs font-medium text-blue-600 bg-blue-50 hover:bg-blue-100 rounded-lg transition-colors flex items-center gap-1" id="fb-test-btn">
              Test
            </button>
          <?php endif; ?>
        </div>

        <!-- LINE -->
        <div class="flex items-center justify-between p-3 bg-slate-50 rounded-xl">
          <div class="flex items-center gap-3">
            <div class="w-8 h-8 bg-emerald-100 rounded-lg flex items-center justify-center text-emerald-600">
              <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24"><path d="M24 10.304c0-5.369-5.383-9.738-12-9.738-6.616 0-12 4.369-12 9.738 0 4.814 3.961 8.892 9.387 9.605.367.079.866.243.992.56.113.284.073.729.034 1.026-.046.368-.222 1.082-.222 1.082-.066.332.336.568.618.423.28-.145 1.503-.787 2.766-1.579 1.157-.728 4.223-2.909 5.864-4.524 2.809-2.747 4.562-5.744 4.562-8.593z"/></svg>
            </div>
            <div class="min-w-0">
              <p class="text-sm font-semibold text-slate-800">LINE</p>
              <p class="text-xs text-slate-500 truncate" id="line-status-text">
                <?= !empty($_ENV['LINE_CHANNEL_ACCESS_TOKEN']) ? 'ตั้งค่า Token แล้ว' : 'ยังไม่ได้ตั้งค่า Token' ?>
              </p>
            </div>
          </div>
          <?php if (!empty($_ENV['LINE_CHANNEL_ACCESS_TOKEN'])): ?>
            <button onclick="testIntegration('line')" class="shrink-0 px-3 py-1.5 text-xs font-medium text-emerald-600 bg-emerald-50 hover:bg-emerald-100 rounded-lg transition-colors flex items-center gap-1" id="line-test-btn">
              Test
            </button>
          <?php endif; ?>
        </div>
        
      </div>
    </div>

    <!-- Cost Estimate -->
    <div class="bg-gradient-to-br from-indigo-600 to-violet-600 rounded-2xl p-5 text-white">
      <h3 class="text-xs font-semibold text-indigo-200 uppercase tracking-wider mb-3">Cost ประมาณการเดือนนี้</h3>
      <p class="text-3xl font-bold"><?= '$'.number_format($costEstimate['projected_end_of_month'],2) ?></p>
      <p class="text-sm text-indigo-200 mt-1">ใช้ไปแล้ว: $<?= number_format($costEstimate['month_to_date'],4) ?></p>
      <div class="flex items-center gap-2 mt-2">
        <?php $chg = $costEstimate['change_percent']; ?>
        <span class="text-sm font-medium <?= $chg > 0 ? 'text-red-300' : 'text-emerald-300' ?>">
          <?= ($chg >= 0 ? '↑' : '↓') . abs($chg) ?>% vs เดือนที่แล้ว
        </span>
      </div>
    </div>
  </div>
</div>

<script>
// ── Conversations Line Chart ───────────────────────────────────────────
const chartData = <?= json_encode($chartData, JSON_UNESCAPED_UNICODE) ?>;
new Chart(document.getElementById('convChart'), {
  type: 'line',
  data: {
    labels: chartData.labels,
    datasets: [
      { label:'Web',      data: chartData.web,      borderColor:'#4f46e5', backgroundColor:'rgba(79,70,229,.08)',  borderWidth:2, pointRadius:0, fill:true, tension:.4 },
      { label:'Facebook', data: chartData.facebook,  borderColor:'#1d4ed8', backgroundColor:'rgba(29,78,216,.08)',  borderWidth:2, pointRadius:0, fill:true, tension:.4 },
      { label:'LINE',     data: chartData.line,      borderColor:'#10b981', backgroundColor:'rgba(16,185,129,.08)', borderWidth:2, pointRadius:0, fill:true, tension:.4 },
    ]
  },
  options: {
    responsive:true, maintainAspectRatio:false,
    plugins: { legend:{ display:false } },
    scales: {
      x: { grid:{ display:false }, ticks:{ font:{size:10}, maxTicksLimit:8 } },
      y: { grid:{ color:'#f1f5f9' }, ticks:{ font:{size:10}, precision:0 }, suggestedMax: 5, beginAtZero: true }
    }
  }
});

// ── Platform Donut ─────────────────────────────────────────────────────
const pData = <?= json_encode(array_values(array_map(fn($p) => $pMap[$p]['conversations']??0, ['web','facebook','line']))) ?>;
const hasData = pData.some(v => v > 0);

new Chart(document.getElementById('donutChart'), {
  type: 'doughnut',
  data: {
    labels: hasData ? ['Web','Facebook','LINE'] : ['ไม่มีข้อมูล (วันนี้)'],
    datasets: [{ 
      data: hasData ? pData : [1], 
      backgroundColor: hasData ? ['#4f46e5','#1d4ed8','#10b981'] : ['#f1f5f9'], 
      borderWidth: 0, 
      hoverOffset: hasData ? 6 : 0 
    }]
  },
  options: {
    responsive:true, maintainAspectRatio:false, cutout:'72%',
    plugins: { 
      legend:{ position:'bottom', labels:{ font:{size:11}, padding:16, usePointStyle:true } },
      tooltip: { enabled: hasData }
    }
  }
});

// ── Integrations Test ──────────────────────────────────────────────────
async function testIntegration(platform) {
  const btn = document.getElementById(platform === 'facebook' ? 'fb-test-btn' : 'line-test-btn');
  const txt = document.getElementById(platform === 'facebook' ? 'fb-status-text' : 'line-status-text');
  
  const originalText = btn.innerHTML;
  btn.disabled = true;
  btn.innerHTML = `<svg class="animate-spin h-3 w-3" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg> Test...`;
  
  try {
    const res = await fetch(`api/test_integration.php?platform=${platform}`);
    const data = await res.json();
    
    if (data.success) {
      txt.innerHTML = `<span class="text-emerald-600 flex items-center gap-1"><svg class="w-3 h-3 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" /></svg> <span class="truncate">${data.message}</span></span>`;
    } else {
      txt.innerHTML = `<span class="text-red-500 truncate" title="${data.message}">${data.message}</span>`;
    }
  } catch (err) {
    txt.innerHTML = `<span class="text-red-500">Network Error</span>`;
  } finally {
    btn.disabled = false;
    btn.innerHTML = originalText;
  }
}
</script>

<?php require __DIR__ . '/layouts/footer.php'; ?>
