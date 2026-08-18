<?php
define('BASE_PATH', dirname(__DIR__, 2));
require_once BASE_PATH . '/vendor/autoload.php';
use Dotenv\Dotenv;
use App\Helpers\Auth;
use App\Helpers\Database;
use App\Services\TokenAnalyticsService;
$dotenv = Dotenv::createImmutable(BASE_PATH);
$dotenv->safeLoad();
date_default_timezone_set($_ENV['APP_TIMEZONE'] ?? 'Asia/Bangkok');
$pageTitle  = 'Analytics';
$breadcrumb = ['Analytics'];
Auth::requirePermission('view_analytics');
$db = Database::getInstance();
$analytics = new TokenAnalyticsService();
$botId     = Auth::requireBot();
$dateFrom  = $_GET['from'] ?? date('Y-m-01');
$dateTo    = $_GET['to']   ?? date('Y-m-d');
$summary   = $analytics->getDailySummary($botId);
$byPlatform= $analytics->getUsageByPlatform($dateFrom, $dateTo, $botId);
$dailyTrend= $analytics->getDailyTrend($dateFrom, $dateTo, 'all', $botId);
$topModels = $analytics->getTopCostModels(5, $botId);
$costEst   = $analytics->getCostEstimate($botId);
$chartData = $analytics->getPlatformDailyChart(30, $botId);
// Conversation stats
$convStats = $db->fetch(
    "SELECT COUNT(*) AS total,
     SUM(CASE WHEN platform='web'      THEN 1 ELSE 0 END) AS web,
     SUM(CASE WHEN platform='facebook' THEN 1 ELSE 0 END) AS facebook,
     SUM(CASE WHEN platform='line'     THEN 1 ELSE 0 END) AS line,
     AVG(message_count) AS avg_messages,
     AVG(total_tokens)  AS avg_tokens
     FROM conversations WHERE DATE(started_at) BETWEEN ? AND ? AND bot_id = ?",
    [$dateFrom, $dateTo, $botId]
);
// Fallback rate
$fbRate = $db->fetch(
    "SELECT COUNT(*) AS total, SUM(m.is_fallback) AS fallbacks
     FROM messages m
     JOIN conversations c ON c.id = m.conversation_id
     WHERE m.role='assistant' AND DATE(m.created_at) BETWEEN ? AND ? AND c.bot_id = ?",
    [$dateFrom, $dateTo, $botId]
);
$fallbackPct = $fbRate['total'] > 0 ? round($fbRate['fallbacks']/$fbRate['total']*100,1) : 0;
require __DIR__ . '/layouts/header.php';
?>
<meta name="csrf-token" content="<?= Auth::csrfToken() ?>">

<!-- Date Filter -->
<div class="bg-white rounded-2xl p-4 border border-slate-100 mb-5 flex flex-wrap gap-3 items-center">
  <form method="GET" class="flex flex-wrap gap-3 items-center">
    <input type="date" name="from" value="<?= $dateFrom ?>" class="px-3 py-2 rounded-xl border border-slate-200 text-sm focus:ring-2 focus:ring-indigo-500 outline-none">
    <span class="text-slate-400 text-sm">ถึง</span>
    <input type="date" name="to" value="<?= $dateTo ?>" class="px-3 py-2 rounded-xl border border-slate-200 text-sm focus:ring-2 focus:ring-indigo-500 outline-none">
    <button type="submit" class="px-4 py-2 rounded-xl bg-indigo-600 text-white text-sm font-semibold">กรอง</button>
    <?php foreach ([['7d','7 วัน'],['30d','30 วัน'],['mtd','เดือนนี้']] as [$k,$l]): ?>
    <button type="button" onclick="qr('<?=$k?>')" class="px-3 py-2 rounded-xl border border-slate-200 text-xs text-slate-600 hover:bg-indigo-50 hover:border-indigo-300 hover:text-indigo-600 transition-all"><?=$l?></button>
    <?php endforeach; ?>
  </form>
</div>

<!-- KPI Cards -->
<div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
  <?php $cards = [
    ['Conversations','😊',number_format($convStats['total']??0),'ช่วงที่เลือก'],
    ['Avg Messages/Session','💬',round($convStats['avg_messages']??0,1),'ต่อ conversation'],
    ['Avg Tokens/Session','⚡',number_format(round($convStats['avg_tokens']??0)),'ต่อ conversation'],
    ['Fallback Rate','⚠️',$fallbackPct.'%','คำตอบที่ไม่พบข้อมูล'],
  ]; foreach ($cards as [$la,$ic,$va,$su]): ?>
  <div class="bg-white rounded-2xl p-5 border border-slate-100 card-hover">
    <div class="flex items-center justify-between mb-2">
      <p class="text-xs text-slate-500"><?=$la?></p>
      <span class="text-xl"><?=$ic?></span>
    </div>
    <p class="text-2xl font-bold text-slate-800"><?=$va?></p>
    <p class="text-xs text-slate-400 mt-1"><?=$su?></p>
  </div>
  <?php endforeach; ?>
</div>

<!-- Charts Row -->
<div class="grid grid-cols-1 lg:grid-cols-3 gap-4 mb-6">
  <div class="lg:col-span-2 bg-white rounded-2xl p-5 border border-slate-100">
    <h3 class="font-semibold text-slate-800 text-sm mb-4">Conversations (30 วันล่าสุด)</h3>
    <div class="relative w-full h-[250px]">
      <canvas id="convChart"></canvas>
    </div>
  </div>
  <div class="bg-white rounded-2xl p-5 border border-slate-100">
    <h3 class="font-semibold text-slate-800 text-sm mb-4">Platform Distribution</h3>
    <div class="relative w-full h-[250px]">
      <canvas id="donutChart"></canvas>
    </div>
    <div class="mt-4 space-y-2">
      <?php foreach ($byPlatform as $p):
        $icon=['web'=>'🌐','facebook'=>'📘','line'=>'💚'][$p['platform']]??'📊';
      ?>
      <div class="flex items-center justify-between text-xs">
        <span class="text-slate-600"><?=$icon?> <?=ucfirst($p['platform'])?></span>
        <span class="font-semibold text-slate-800"><?=number_format($p['conversations'])?> sessions</span>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<!-- Top Models + Cost -->
<div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
  <div class="bg-white rounded-2xl border border-slate-100 overflow-hidden">
    <div class="px-5 py-4 border-b border-slate-50"><h3 class="font-semibold text-slate-800 text-sm">Top AI Models (Cost)</h3></div>
    <div class="p-4 space-y-3">
      <?php if (empty($topModels)): ?>
        <p class="text-center text-slate-400 text-sm py-4">ยังไม่มีข้อมูล</p>
      <?php else: $maxCost=max(array_column($topModels,'cost_usd'))?:1; foreach ($topModels as $m):
        $pct=round($m['cost_usd']/$maxCost*100); ?>
      <div>
        <div class="flex justify-between text-xs mb-1">
          <span class="font-medium text-slate-700 truncate max-w-[60%]"><?=htmlspecialchars($m['model_name']??'Unknown')?></span>
          <span class="font-bold text-emerald-700">$<?=number_format((float)$m['cost_usd'],4)?></span>
        </div>
        <div class="h-1.5 bg-slate-100 rounded-full">
          <div class="h-1.5 bg-indigo-500 rounded-full transition-all" style="width:<?=$pct?>%"></div>
        </div>
      </div>
      <?php endforeach; endif; ?>
    </div>
  </div>

  <div class="bg-gradient-to-br from-indigo-600 to-violet-700 rounded-2xl p-6 text-white">
    <h3 class="text-sm font-semibold text-indigo-200 uppercase tracking-wider mb-4">💰 Cost Summary</h3>
    <div class="space-y-3">
      <?php $items = [
        ['วันนี้', '$'.number_format($summary['today_cost'],4)],
        ['เดือนนี้ (ใช้ไป)', '$'.number_format($costEst['month_to_date'],4)],
        ['คาดการณ์สิ้นเดือน', '$'.number_format($costEst['projected_end_of_month'],2)],
        ['เดือนที่แล้ว', '$'.number_format($costEst['last_month_total'],2)],
      ]; foreach ($items as [$la,$va]): ?>
      <div class="flex justify-between items-center py-2 border-b border-white/10">
        <span class="text-indigo-200 text-sm"><?=$la?></span>
        <span class="font-bold"><?=$va?></span>
      </div>
      <?php endforeach; ?>
      <div class="pt-2 text-sm">
        <?php $chg=$costEst['change_percent']; ?>
        <span class="<?=$chg>0?'text-red-300':'text-emerald-300'?> font-semibold">
          <?=($chg>=0?'↑':'↓').abs($chg)?>% vs เดือนที่แล้ว
        </span>
      </div>
    </div>
  </div>
</div>

<script>
const cd = <?= json_encode($chartData, JSON_UNESCAPED_UNICODE) ?>;
new Chart(document.getElementById('convChart'),{
  type:'line',
  data:{labels:cd.labels,datasets:[
    {label:'Web',     data:cd.web,     borderColor:'#4f46e5',backgroundColor:'rgba(79,70,229,.07)',borderWidth:2,pointRadius:0,fill:true,tension:.4},
    {label:'Facebook',data:cd.facebook,borderColor:'#1d4ed8',backgroundColor:'rgba(29,78,216,.07)',borderWidth:2,pointRadius:0,fill:true,tension:.4},
    {label:'LINE',    data:cd.line,    borderColor:'#10b981',backgroundColor:'rgba(16,185,129,.07)',borderWidth:2,pointRadius:0,fill:true,tension:.4},
  ]},
  options:{responsive:true,maintainAspectRatio:false,
    plugins:{legend:{position:'bottom',labels:{font:{size:11},usePointStyle:true}}},
    scales:{x:{grid:{display:false},ticks:{font:{size:10},maxTicksLimit:8}},y:{grid:{color:'#f1f5f9'},ticks:{font:{size:10},precision:0}}}}
});

const bp = <?= json_encode(array_values(array_map(fn($p)=>$p['conversations'],array_values(array_filter($byPlatform,fn($p)=>in_array($p['platform'],['web','facebook','line'])))))) ?>;
const bpl= <?= json_encode(array_values(array_map(fn($p)=>ucfirst($p['platform']),array_values(array_filter($byPlatform,fn($p)=>in_array($p['platform'],['web','facebook','line'])))))) ?>;
new Chart(document.getElementById('donutChart'),{
  type:'doughnut',
  data:{labels:bpl.length?bpl:['Web','Facebook','LINE'],datasets:[{data:bp.length?bp:[0,0,0],backgroundColor:['#4f46e5','#1d4ed8','#10b981'],borderWidth:0,hoverOffset:4}]},
  options:{responsive:true,maintainAspectRatio:false,cutout:'70%',plugins:{legend:{display:false}}}
});

function qr(r){
  const today=new Date(),fmt=d=>d.toISOString().split('T')[0],from=new Date(today);
  if(r==='7d') from.setDate(today.getDate()-6);
  else if(r==='30d') from.setDate(today.getDate()-29);
  else from.setDate(1);
  window.location.href=`?from=${fmt(from)}&to=${fmt(today)}`;
}
</script>
<?php require __DIR__ . '/layouts/footer.php'; ?>
