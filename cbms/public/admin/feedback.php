<?php
if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__, 2));
}
require_once BASE_PATH . '/vendor/autoload.php';
use App\Helpers\Auth;
use App\Helpers\Database;
use App\Helpers\Response;

$dotenv = \Dotenv\Dotenv::createImmutable(BASE_PATH);
$dotenv->safeLoad();
date_default_timezone_set($_ENV['APP_TIMEZONE'] ?? 'Asia/Bangkok');

$pageTitle  = 'Feedback Analysis';
$breadcrumb = ['Admin', 'Feedback Analysis'];
Auth::requirePermission('view_conversations');

$db    = Database::getInstance();
$botId = Auth::requireBot();

// ── Filters ──────────────────────────────────────────────────────────
$filterType     = $_GET['type'] ?? 'all';
$filterPlatform = $_GET['platform'] ?? 'all';
$filterDateFrom = $_GET['date_from'] ?? date('Y-m-d', strtotime('-30 days'));
$filterDateTo   = $_GET['date_to'] ?? date('Y-m-d');
$page           = max(1, (int)($_GET['page'] ?? 1));
$perPage        = 20;
$offset         = ($page - 1) * $perPage;

// ── Summary Stats ────────────────────────────────────────────────────
$statsSQL = "SELECT
    COUNT(CASE WHEN m.feedback = 'positive' THEN 1 END) AS total_positive,
    COUNT(CASE WHEN m.feedback = 'negative' THEN 1 END) AS total_negative,
    COUNT(CASE WHEN m.feedback IS NOT NULL THEN 1 END) AS total_feedback,
    COUNT(*) AS total_assistant_messages
    FROM messages m
    JOIN conversations c ON c.id = m.conversation_id
    WHERE m.role = 'assistant'
      AND m.created_at BETWEEN ? AND DATE_ADD(?, INTERVAL 1 DAY)
      AND c.bot_id = ?";
$statsParams = [$filterDateFrom, $filterDateTo, $botId];

if ($filterPlatform !== 'all') {
    $statsSQL .= " AND c.platform = ?";
    $statsParams[] = $filterPlatform;
}

$stats = $db->fetch($statsSQL, $statsParams);
$satisfactionRate = $stats['total_feedback'] > 0
    ? round($stats['total_positive'] / $stats['total_feedback'] * 100, 1)
    : 0;

// ── Daily Trend (for chart) ──────────────────────────────────────────
$trendSQL = "SELECT DATE(m.created_at) AS feedback_date,
    COUNT(CASE WHEN m.feedback = 'positive' THEN 1 END) AS positive,
    COUNT(CASE WHEN m.feedback = 'negative' THEN 1 END) AS negative
    FROM messages m
    JOIN conversations c ON c.id = m.conversation_id
    WHERE m.role = 'assistant' AND m.feedback IS NOT NULL
      AND m.created_at BETWEEN ? AND DATE_ADD(?, INTERVAL 1 DAY)
      AND c.bot_id = ?";
$trendParams = [$filterDateFrom, $filterDateTo, $botId];

if ($filterPlatform !== 'all') {
    $trendSQL .= " AND c.platform = ?";
    $trendParams[] = $filterPlatform;
}
$trendSQL .= " GROUP BY DATE(m.created_at) ORDER BY feedback_date ASC";
$trendData = $db->fetchAll($trendSQL, $trendParams);

$chartLabels   = array_column($trendData, 'feedback_date');
$chartPositive = array_map('intval', array_column($trendData, 'positive'));
$chartNegative = array_map('intval', array_column($trendData, 'negative'));

// ── Feedback List ────────────────────────────────────────────────────
$where = "m.role = 'assistant' AND m.feedback IS NOT NULL
          AND m.created_at BETWEEN ? AND DATE_ADD(?, INTERVAL 1 DAY)
          AND c.bot_id = ?";
$params = [$filterDateFrom, $filterDateTo, $botId];

if ($filterType === 'positive') {
    $where .= " AND m.feedback = 'positive'";
} elseif ($filterType === 'negative') {
    $where .= " AND m.feedback = 'negative'";
}

if ($filterPlatform !== 'all') {
    $where .= " AND c.platform = ?";
    $params[] = $filterPlatform;
}

$total = (int)$db->fetchColumn(
    "SELECT COUNT(*) FROM messages m JOIN conversations c ON c.id = m.conversation_id WHERE $where",
    $params
);
$totalPages = max(1, ceil($total / $perPage));

$listSQL = "SELECT m.id AS msg_id, m.content AS bot_reply, m.feedback, m.created_at,
    m.tokens_total, m.is_fallback,
    c.platform, c.session_id,
    am.display_name AS model_name,
    (SELECT content FROM messages WHERE conversation_id = m.conversation_id AND id < m.id AND role = 'user' ORDER BY id DESC LIMIT 1) AS user_question
    FROM messages m
    JOIN conversations c ON c.id = m.conversation_id
    LEFT JOIN ai_models am ON am.id = m.ai_model_id
    WHERE $where
    ORDER BY m.created_at DESC
    LIMIT " . (int)$perPage . " OFFSET " . (int)$offset;

$feedbackList = $db->fetchAll($listSQL, $params);

require __DIR__ . '/layouts/header.php';
?>
<meta name="csrf-token" content="<?= Auth::csrfToken() ?>">

<!-- ── Summary Cards ───────────────────────────────────────────────── -->
<div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
  <div class="bg-white rounded-2xl p-5 border border-slate-100 card-hover">
    <p class="text-xs font-medium text-slate-500 mb-2">Feedback ทั้งหมด</p>
    <p class="text-2xl font-bold text-slate-800"><?= number_format($stats['total_feedback']) ?></p>
    <p class="text-xs text-slate-400 mt-1">จาก <?= number_format($stats['total_assistant_messages']) ?> คำตอบ</p>
  </div>
  <div class="bg-white rounded-2xl p-5 border border-slate-100 card-hover">
    <div class="flex items-start justify-between mb-2">
      <p class="text-xs font-medium text-slate-500">ถูกใจ</p>
      <div class="w-8 h-8 bg-emerald-100 rounded-xl flex items-center justify-center">
        <svg class="w-4 h-4 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M14 9V5a3 3 0 00-3-3l-4 9v11h11.28a2 2 0 002-1.7l1.38-9a2 2 0 00-2-2.3H14z"/></svg>
      </div>
    </div>
    <p class="text-2xl font-bold text-emerald-600"><?= number_format($stats['total_positive']) ?></p>
  </div>
  <div class="bg-white rounded-2xl p-5 border border-slate-100 card-hover">
    <div class="flex items-start justify-between mb-2">
      <p class="text-xs font-medium text-slate-500">ไม่ถูกใจ</p>
      <div class="w-8 h-8 bg-red-100 rounded-xl flex items-center justify-center">
        <svg class="w-4 h-4 text-red-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M10 15v4a3 3 0 003 3l4-9V2H5.72a2 2 0 00-2 1.7l-1.38 9a2 2 0 002 2.3H10z"/></svg>
      </div>
    </div>
    <p class="text-2xl font-bold text-red-600"><?= number_format($stats['total_negative']) ?></p>
  </div>
  <div class="bg-white rounded-2xl p-5 border border-slate-100 card-hover">
    <p class="text-xs font-medium text-slate-500 mb-2">อัตราความพึงพอใจ</p>
    <p class="text-2xl font-bold <?= $satisfactionRate >= 70 ? 'text-emerald-600' : ($satisfactionRate >= 40 ? 'text-amber-600' : 'text-red-600') ?>">
      <?= $satisfactionRate ?>%
    </p>
    <div class="w-full bg-slate-100 rounded-full h-2 mt-2">
      <div class="h-2 rounded-full transition-all <?= $satisfactionRate >= 70 ? 'bg-emerald-500' : ($satisfactionRate >= 40 ? 'bg-amber-500' : 'bg-red-500') ?>"
           style="width:<?= min(100, $satisfactionRate) ?>%"></div>
    </div>
  </div>
</div>

<!-- ── Filters ─────────────────────────────────────────────────────── -->
<div class="bg-white rounded-2xl border border-slate-100 p-4 mb-6">
  <form method="GET" class="flex flex-wrap items-end gap-4">
    <div>
      <label class="block text-xs font-medium text-slate-500 mb-1">ประเภท</label>
      <select name="type" class="px-3 py-2 rounded-xl border border-slate-200 text-sm focus:ring-2 focus:ring-indigo-400 outline-none">
        <option value="all" <?= $filterType==='all'?'selected':'' ?>>ทั้งหมด</option>
        <option value="positive" <?= $filterType==='positive'?'selected':'' ?>>ถูกใจ</option>
        <option value="negative" <?= $filterType==='negative'?'selected':'' ?>>ไม่ถูกใจ</option>
      </select>
    </div>
    <div>
      <label class="block text-xs font-medium text-slate-500 mb-1">Platform</label>
      <select name="platform" class="px-3 py-2 rounded-xl border border-slate-200 text-sm focus:ring-2 focus:ring-indigo-400 outline-none">
        <option value="all" <?= $filterPlatform==='all'?'selected':'' ?>>ทั้งหมด</option>
        <option value="web" <?= $filterPlatform==='web'?'selected':'' ?>>Web</option>
        <option value="facebook" <?= $filterPlatform==='facebook'?'selected':'' ?>>Facebook</option>
        <option value="line" <?= $filterPlatform==='line'?'selected':'' ?>>LINE</option>
      </select>
    </div>
    <div>
      <label class="block text-xs font-medium text-slate-500 mb-1">จากวันที่</label>
      <input type="date" name="date_from" value="<?= $filterDateFrom ?>" class="px-3 py-2 rounded-xl border border-slate-200 text-sm focus:ring-2 focus:ring-indigo-400 outline-none">
    </div>
    <div>
      <label class="block text-xs font-medium text-slate-500 mb-1">ถึงวันที่</label>
      <input type="date" name="date_to" value="<?= $filterDateTo ?>" class="px-3 py-2 rounded-xl border border-slate-200 text-sm focus:ring-2 focus:ring-indigo-400 outline-none">
    </div>
    <button type="submit" class="px-5 py-2 bg-indigo-600 text-white rounded-xl text-sm font-medium hover:bg-indigo-700 transition-colors">
      กรอง
    </button>
    <a href="feedback.php" class="px-4 py-2 bg-slate-100 text-slate-600 rounded-xl text-sm font-medium hover:bg-slate-200 transition-colors">
      รีเซ็ต
    </a>
  </form>
</div>

<!-- ── Trend Chart ─────────────────────────────────────────────────── -->
<div class="bg-white rounded-2xl border border-slate-100 p-5 mb-6">
  <div class="flex items-center justify-between mb-4">
    <div>
      <h3 class="text-sm font-semibold text-slate-800">แนวโน้ม Feedback</h3>
      <p class="text-xs text-slate-400">ถูกใจ vs ไม่ถูกใจ รายวัน</p>
    </div>
    <div class="flex gap-4 text-xs">
      <span class="flex items-center gap-1.5"><span class="w-3 h-0.5 bg-emerald-500 inline-block rounded"></span>ถูกใจ</span>
      <span class="flex items-center gap-1.5"><span class="w-3 h-0.5 bg-red-500 inline-block rounded"></span>ไม่ถูกใจ</span>
    </div>
  </div>
  <div class="relative w-full h-[250px]">
    <canvas id="feedbackChart"></canvas>
  </div>
</div>

<!-- ── Feedback List ───────────────────────────────────────────────── -->
<div class="bg-white rounded-2xl border border-slate-100 overflow-hidden">
  <div class="px-5 py-4 border-b border-slate-50 flex items-center justify-between">
    <h3 class="text-sm font-semibold text-slate-800">รายการ Feedback (<?= number_format($total) ?>)</h3>
  </div>

  <?php if (empty($feedbackList)): ?>
  <div class="p-12 text-center">
    <div class="w-14 h-14 bg-slate-50 rounded-full flex items-center justify-center mx-auto mb-3">
      <svg class="w-7 h-7 text-slate-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/>
      </svg>
    </div>
    <p class="text-sm text-slate-400">ยังไม่มี Feedback ในช่วงเวลานี้</p>
  </div>
  <?php else: ?>
  <div class="divide-y divide-slate-50">
    <?php foreach ($feedbackList as $item):
      $isPositive = $item['feedback'] === 'positive';
      $platformColors = ['web'=>'bg-indigo-100 text-indigo-700','facebook'=>'bg-blue-100 text-blue-700','line'=>'bg-emerald-100 text-emerald-700'];
      $pColor = $platformColors[$item['platform']] ?? 'bg-slate-100 text-slate-600';
    ?>
    <div class="p-5 hover:bg-slate-50/50 transition-colors">
      <div class="flex flex-col md:flex-row gap-4 items-start">
        <!-- Meta -->
        <div class="w-full md:w-44 shrink-0 space-y-2">
          <div class="flex items-center gap-2">
            <span class="inline-flex items-center px-2 py-0.5 rounded-md text-[10px] font-bold uppercase tracking-wider <?= $pColor ?>">
              <?= $item['platform'] ?>
            </span>
            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-md text-[10px] font-bold
              <?= $isPositive ? 'bg-emerald-50 text-emerald-700' : 'bg-red-50 text-red-700' ?>">
              <?php if ($isPositive): ?>
                <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M14 9V5a3 3 0 00-3-3l-4 9v11h11.28a2 2 0 002-1.7l1.38-9a2 2 0 00-2-2.3H14z"/></svg>
                ถูกใจ
              <?php else: ?>
                <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M10 15v4a3 3 0 003 3l4-9V2H5.72a2 2 0 00-2 1.7l-1.38 9a2 2 0 002 2.3H10z"/></svg>
                ไม่ถูกใจ
              <?php endif; ?>
            </span>
          </div>
          <p class="text-xs text-slate-400"><?= date('d/m/Y H:i', strtotime($item['created_at'])) ?></p>
          <p class="text-[10px] text-slate-400 font-mono"><?= htmlspecialchars($item['model_name'] ?? 'N/A') ?></p>
        </div>

        <!-- Q&A -->
        <div class="flex-1 space-y-2 min-w-0">
          <div class="bg-slate-50 rounded-xl p-3 border border-slate-100">
            <p class="text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-1">คำถาม</p>
            <p class="text-sm text-slate-800"><?= htmlspecialchars($item['user_question'] ?? '(ไม่พบคำถาม)') ?></p>
          </div>
          <div class="rounded-xl p-3 border <?= $isPositive ? 'bg-emerald-50/50 border-emerald-100' : 'bg-red-50/50 border-red-100' ?>">
            <p class="text-[10px] font-bold <?= $isPositive ? 'text-emerald-500' : 'text-red-500' ?> uppercase tracking-wider mb-1">คำตอบ AI</p>
            <p class="text-sm text-slate-700 line-clamp-4"><?= htmlspecialchars($item['bot_reply']) ?></p>
          </div>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- Pagination -->
  <?php if ($totalPages > 1): ?>
  <div class="px-5 py-4 border-t border-slate-100 flex items-center justify-between">
    <p class="text-xs text-slate-500">หน้า <?= $page ?> จาก <?= $totalPages ?> (<?= number_format($total) ?> รายการ)</p>
    <div class="flex gap-1">
      <?php
      $queryBase = http_build_query(array_filter([
          'type' => $filterType !== 'all' ? $filterType : null,
          'platform' => $filterPlatform !== 'all' ? $filterPlatform : null,
          'date_from' => $filterDateFrom,
          'date_to' => $filterDateTo,
      ]));
      ?>
      <?php if ($page > 1): ?>
        <a href="?<?= $queryBase ?>&page=<?= $page-1 ?>" class="px-3 py-1.5 rounded-lg text-xs font-medium bg-slate-100 text-slate-600 hover:bg-slate-200 transition-colors">ก่อนหน้า</a>
      <?php endif; ?>
      <?php if ($page < $totalPages): ?>
        <a href="?<?= $queryBase ?>&page=<?= $page+1 ?>" class="px-3 py-1.5 rounded-lg text-xs font-medium bg-indigo-600 text-white hover:bg-indigo-700 transition-colors">ถัดไป</a>
      <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>
  <?php endif; ?>
</div>

<script>
new Chart(document.getElementById('feedbackChart'), {
  type: 'bar',
  data: {
    labels: <?= json_encode($chartLabels) ?>,
    datasets: [
      {
        label: 'ถูกใจ',
        data: <?= json_encode($chartPositive) ?>,
        backgroundColor: 'rgba(16,185,129,.7)',
        borderRadius: 6,
        barPercentage: 0.6,
      },
      {
        label: 'ไม่ถูกใจ',
        data: <?= json_encode($chartNegative) ?>,
        backgroundColor: 'rgba(239,68,68,.7)',
        borderRadius: 6,
        barPercentage: 0.6,
      }
    ]
  },
  options: {
    responsive: true,
    maintainAspectRatio: false,
    plugins: { legend: { display: false } },
    scales: {
      x: { grid: { display: false }, ticks: { font: { size: 10 }, maxTicksLimit: 10 }, stacked: true },
      y: { grid: { color: '#f1f5f9' }, ticks: { font: { size: 10 }, precision: 0 }, beginAtZero: true, stacked: true }
    }
  }
});
</script>

<?php require __DIR__ . '/layouts/footer.php'; ?>
