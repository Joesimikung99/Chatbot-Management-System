<?php
define('BASE_PATH', dirname(__DIR__, 2));
require_once BASE_PATH . '/vendor/autoload.php';
use Dotenv\Dotenv;
use App\Helpers\Auth;
use App\Helpers\Response;
use App\Helpers\Database;

$dotenv = Dotenv::createImmutable(BASE_PATH);
$dotenv->safeLoad();
date_default_timezone_set($_ENV['APP_TIMEZONE'] ?? 'Asia/Bangkok');

$pageTitle  = 'Conversations';
$breadcrumb = ['Admin', 'Conversations'];
Auth::requirePermission('view_conversations');

$db    = Database::getInstance();
$botId = Auth::requireBot();

// ── AJAX: Get messages for a conversation ──────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['conv_id'])) {
    $convId = (int)$_GET['conv_id'];
    // BUG-06 fix: verify conversation belongs to active bot BEFORE fetching messages
    $conv = $db->fetch('SELECT * FROM conversations WHERE id=? AND bot_id=?', [$convId, $botId]);
    if (!$conv) {
        Response::error('Conversation not found', 404);
    }
    $messages = $db->fetchAll(
        "SELECT role, content, created_at, tokens_total, is_fallback, feedback, response_time_ms
         FROM messages WHERE conversation_id=? ORDER BY created_at ASC",
        [$convId]
    );
    Response::success(['conversation'=>$conv, 'messages'=>$messages]);
}

// ── AJAX: Export ─────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action']??'') === 'export') {
    Auth::requirePermission('export_data');
    if (!Auth::validateCsrf()) Response::error('CSRF failed', 403);

    $from = ($_POST['from'] ?? '') ?: date('Y-m-01');
    $to   = ($_POST['to']   ?? '') ?: date('Y-m-d');
    $type = $_POST['type'] ?? 'summary';

    if ($type === 'messages') {
        $rows = $db->fetchAll(
            "SELECT c.session_id, c.platform, m.role, m.content, m.created_at, m.tokens_total, m.is_fallback
             FROM messages m
             JOIN conversations c ON c.id = m.conversation_id
             WHERE DATE(c.started_at) BETWEEN ? AND ? AND c.bot_id = ?
             ORDER BY c.started_at DESC, m.created_at ASC",
            [$from, $to, $botId]
        );
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="chat_messages_' . $from . '_to_' . $to . '.csv"');
        $h = fopen('php://output','w');
        fprintf($h, chr(0xEF).chr(0xBB).chr(0xBF)); // BOM for Excel Thai characters
        fputcsv($h, ['Session ID','Platform','Role','Message Content','Tokens','Timestamp','Is Fallback']);
        foreach ($rows as $r) {
            // Message content is user-typed — sanitize against Excel formula injection
            fputcsv($h, array_map([Response::class, 'csvSanitizeCell'],
                [$r['session_id'],$r['platform'],$r['role'],$r['content'],$r['tokens_total'],$r['created_at'],$r['is_fallback']]));
        }
        fclose($h); exit;
    } else {
        $rows = $db->fetchAll(
            "SELECT c.session_id, c.platform, c.started_at, c.last_activity_at,
                    c.message_count, c.total_tokens, am.display_name AS model_name
             FROM conversations c
             LEFT JOIN ai_models am ON am.id=c.ai_model_id
             WHERE DATE(c.started_at) BETWEEN ? AND ? AND c.bot_id = ?
             ORDER BY c.started_at DESC",
            [$from, $to, $botId]
        );
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="chat_summary_' . $from . '_to_' . $to . '.csv"');
        $h = fopen('php://output','w');
        fprintf($h, chr(0xEF).chr(0xBB).chr(0xBF)); // BOM for Excel Thai characters
        fputcsv($h, ['Session ID','Platform','Model','Total Messages','Total Tokens','Started','Last Activity']);
        foreach ($rows as $r) {
            fputcsv($h, [$r['session_id'],$r['platform'],$r['model_name'],$r['message_count'],$r['total_tokens'],$r['started_at'],$r['last_activity_at']]);
        }
        fclose($h); exit;
    }
}

// ── Pagination & Filters ──────────────────────────────────────────────
$page     = max(1, (int)($_GET['page']     ?? 1));
$perPage  = 25;
$platform = $_GET['platform'] ?? '';
$dateFrom = $_GET['from']     ?? '';
$dateTo   = $_GET['to']       ?? '';
$search   = trim($_GET['q']   ?? '');
$tagFilter = trim($_GET['tag'] ?? '');

$where  = ['c.bot_id = ?'];
$params = [$botId];
if ($platform)  { $where[] = 'c.platform=?'; $params[] = $platform; }
if ($dateFrom)  { $where[] = 'DATE(c.started_at)>=?'; $params[] = $dateFrom; }
if ($dateTo)    { $where[] = 'DATE(c.started_at)<=?'; $params[] = $dateTo; }
if ($search)    { $where[] = 'c.session_id LIKE ?'; $params[] = "%$search%"; }
if ($tagFilter) { $where[] = 'JSON_CONTAINS(c.tags, ?)'; $params[] = json_encode($tagFilter); }
$whereStr = implode(' AND ', $where);

// ── Tag Statistics ───────────────────────────────────────────────────
$allTags = $db->fetchAll(
    "SELECT tags FROM conversations WHERE bot_id = ? AND tags IS NOT NULL AND tags != '[]'",
    [$botId]
);
$tagCounts = [];
foreach ($allTags as $row) {
    $decoded = json_decode($row['tags'], true) ?: [];
    foreach ($decoded as $t) {
        $tagCounts[$t] = ($tagCounts[$t] ?? 0) + 1;
    }
}
arsort($tagCounts);

$total  = (int)$db->fetchColumn("SELECT COUNT(*) FROM conversations c WHERE $whereStr", $params);
$offset = ($page - 1) * $perPage;

$conversations = $db->fetchAll(
    "SELECT c.*, am.display_name AS model_name,
            (SELECT content FROM messages WHERE conversation_id = c.id AND role = 'user' ORDER BY id ASC LIMIT 1) AS first_question,
            (SELECT content FROM messages WHERE conversation_id = c.id AND role = 'assistant' ORDER BY id ASC LIMIT 1) AS first_answer
     FROM conversations c
     LEFT JOIN ai_models am ON am.id=c.ai_model_id
     WHERE $whereStr
     ORDER BY c.last_activity_at DESC
     LIMIT ? OFFSET ?",
    array_merge($params, [$perPage, $offset])
);

$pages = (int)ceil($total / $perPage);

function timeAgo(string $dt): string {
    $diff = time()-strtotime($dt);
    if ($diff < 60) return 'เมื่อกี้';
    if ($diff < 3600) return floor($diff/60).'ม. ที่แล้ว';
    if ($diff < 86400) return floor($diff/3600).'ชม. ที่แล้ว';
    return date('d/m/Y H:i', strtotime($dt));
}

$pColor = ['web'=>'bg-indigo-100 text-indigo-700','facebook'=>'bg-blue-100 text-blue-700','line'=>'bg-emerald-100 text-emerald-700'];

require __DIR__ . '/layouts/header.php';
?>
<meta name="csrf-token" content="<?= Auth::csrfToken() ?>">

<!-- Filter Bar -->
<div class="bg-white rounded-2xl p-4 border border-slate-100 mb-5 flex flex-wrap gap-3 items-center">
  <form method="GET" class="flex flex-wrap gap-3 flex-1">
    <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="🔍 ค้นหา Session ID..."
           class="flex-1 min-w-48 px-3 py-2 rounded-xl border border-slate-200 text-sm focus:ring-2 focus:ring-indigo-500 outline-none">
    <select name="platform" class="px-3 py-2 rounded-xl border border-slate-200 text-sm focus:ring-2 focus:ring-indigo-500 outline-none">
      <option value="">ทุก Platform</option>
      <?php foreach (['web','facebook','line'] as $p): ?>
      <option value="<?=$p?>" <?= $platform===$p?'selected':'' ?>><?= ucfirst($p) ?></option>
      <?php endforeach; ?>
    </select>
    <input type="date" name="from" value="<?= htmlspecialchars($dateFrom) ?>" class="px-3 py-2 rounded-xl border border-slate-200 text-sm focus:ring-2 focus:ring-indigo-500 outline-none">
    <input type="date" name="to"   value="<?= htmlspecialchars($dateTo) ?>"   class="px-3 py-2 rounded-xl border border-slate-200 text-sm focus:ring-2 focus:ring-indigo-500 outline-none">
    <button type="submit" class="px-4 py-2 rounded-xl bg-indigo-600 text-white text-sm font-semibold hover:bg-indigo-700 transition-colors">กรอง</button>
    <a href="conversations.php" class="px-4 py-2 rounded-xl border border-slate-200 text-slate-600 text-sm font-medium hover:bg-slate-50 transition-colors">รีเซ็ต</a>
  </form>
  <?php if (Auth::can('export_data')): ?>
  <div class="flex items-center gap-2">
    <button onclick="exportCsv('summary')" class="flex items-center gap-1.5 px-4 py-2 rounded-xl border border-slate-200 text-slate-600 text-sm font-medium hover:bg-slate-50 transition-colors" title="โหลดเฉพาะสรุปสถิติ">
      <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
      Export Summary
    </button>
    <button onclick="exportCsv('messages')" class="flex items-center gap-1.5 px-4 py-2 rounded-xl bg-indigo-50 border border-indigo-100 text-indigo-700 text-sm font-medium hover:bg-indigo-100 transition-colors" title="โหลดข้อความแชททั้งหมดไปวิเคราะห์">
      <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z"/></svg>
      Export Messages
    </button>
  </div>
  <?php endif; ?>
</div>

<?php if (!empty($tagCounts)): ?>
<div class="bg-white rounded-2xl p-4 border border-slate-100 mb-5">
  <div class="flex items-center justify-between mb-3">
    <h3 class="text-sm font-semibold text-slate-700">Tag Summary</h3>
    <?php if ($tagFilter): ?>
      <a href="?<?= http_build_query(array_filter(['platform'=>$platform,'from'=>$dateFrom,'to'=>$dateTo,'q'=>$search])) ?>"
         class="text-xs text-indigo-600 hover:underline">ล้างตัวกรอง tag</a>
    <?php endif; ?>
  </div>
  <div class="flex flex-wrap gap-2">
    <?php foreach ($tagCounts as $tag => $count): ?>
    <a href="?tag=<?= urlencode($tag) ?>&platform=<?= urlencode($platform) ?>&from=<?= urlencode($dateFrom) ?>&to=<?= urlencode($dateTo) ?>&q=<?= urlencode($search) ?>"
       class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg text-xs font-medium transition-all
              <?= $tagFilter === $tag ? 'bg-indigo-600 text-white' : 'bg-amber-50 text-amber-700 border border-amber-100 hover:bg-amber-100' ?>">
      <?= htmlspecialchars($tag) ?>
      <span class="<?= $tagFilter === $tag ? 'bg-indigo-500 text-white' : 'bg-amber-200/60 text-amber-800' ?> px-1.5 py-0.5 rounded text-[10px] font-bold"><?= $count ?></span>
    </a>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<p class="text-xs text-slate-400 mb-3">
  <?= number_format($total) ?> conversations พบ
  <?php if ($tagFilter): ?>
    <span class="ml-2 px-2 py-0.5 bg-indigo-100 text-indigo-700 rounded-lg text-xs font-semibold">tag: <?= htmlspecialchars($tagFilter) ?></span>
  <?php endif; ?>
</p>

<!-- Table -->
<div class="bg-white rounded-2xl border border-slate-100 overflow-hidden mb-4" x-data="{open:null}">
  <table class="w-full text-sm">
    <thead class="bg-slate-50 border-b border-slate-100">
      <tr>
        <th class="px-5 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Session</th>
        <th class="px-5 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">คำถาม</th>
        <th class="px-5 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">คำตอบ</th>
        <th class="px-5 py-3 text-center text-xs font-semibold text-slate-500 uppercase tracking-wider">Platform</th>
        <th class="px-5 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider hidden lg:table-cell">Tags</th>
        <th class="px-5 py-3 text-right text-xs font-semibold text-slate-500 uppercase tracking-wider hidden sm:table-cell">Messages</th>
        <th class="px-5 py-3 text-right text-xs font-semibold text-slate-500 uppercase tracking-wider hidden md:table-cell">Tokens</th>
        <th class="px-5 py-3 text-right text-xs font-semibold text-slate-500 uppercase tracking-wider hidden md:table-cell">Cost</th>
        <th class="px-5 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider hidden lg:table-cell">Last Activity</th>
        <th class="px-5 py-3 text-center text-xs font-semibold text-slate-500 uppercase tracking-wider">View</th>
      </tr>
    </thead>
    <tbody class="divide-y divide-slate-50">
      <?php foreach ($conversations as $conv):
        $cost = (float)$conv['total_cost_usd'];
      ?>
      <tr class="hover:bg-slate-50 transition-colors">
        <td class="px-5 py-3.5">
          <p class="font-mono text-xs text-slate-700 truncate max-w-[180px]" title="<?= htmlspecialchars($conv['session_id']) ?>">
            <?= htmlspecialchars(substr($conv['session_id'], 0, 18)) ?>...
          </p>
          <p class="text-xs text-slate-400"><?= htmlspecialchars($conv['model_name'] ?? '—') ?></p>
        </td>
        <td class="px-5 py-3.5">
          <p class="text-xs text-slate-600 truncate max-w-[250px]" title="<?= htmlspecialchars($conv['first_question'] ?? '') ?>">
            <?= htmlspecialchars(mb_strimwidth($conv['first_question'] ?? '—', 0, 80, '...')) ?>
          </p>
        </td>
        <td class="px-5 py-3.5">
          <p class="text-xs text-slate-500 truncate max-w-[250px]" title="<?= htmlspecialchars($conv['first_answer'] ?? '') ?>">
            <?= htmlspecialchars(mb_strimwidth($conv['first_answer'] ?? '—', 0, 80, '...')) ?>
          </p>
        </td>
        <td class="px-5 py-3.5 text-center">
          <span class="px-2 py-1 rounded-lg text-xs font-medium <?= $pColor[$conv['platform']] ?? 'bg-slate-100 text-slate-600' ?>">
            <?= ucfirst($conv['platform']) ?>
          </span>
        </td>
        <td class="px-5 py-3.5 hidden lg:table-cell">
          <?php $tags = json_decode($conv['tags'] ?? '[]', true) ?: []; foreach (array_slice($tags, 0, 3) as $tag): ?>
            <a href="?tag=<?= urlencode($tag) ?>" class="inline-block px-1.5 py-0.5 rounded text-[10px] font-medium bg-amber-50 text-amber-700 border border-amber-100 mr-0.5 mb-0.5 hover:bg-amber-100 transition-colors"><?= htmlspecialchars($tag) ?></a>
          <?php endforeach; ?>
        </td>
        <td class="px-5 py-3.5 text-right font-mono text-xs text-slate-600 hidden sm:table-cell"><?= $conv['message_count'] ?></td>
        <td class="px-5 py-3.5 text-right font-mono text-xs text-slate-600 hidden md:table-cell"><?= number_format($conv['total_tokens']) ?></td>
        <td class="px-5 py-3.5 text-right font-mono text-xs text-emerald-700 font-semibold hidden md:table-cell">
          <?= $cost > 0 ? '$'.number_format($cost, 4) : '—' ?>
        </td>
        <td class="px-5 py-3.5 text-xs text-slate-500 hidden lg:table-cell"><?= timeAgo($conv['last_activity_at']) ?></td>
        <td class="px-5 py-3.5 text-center">
          <button @click="if(open === <?= $conv['id'] ?>) { open=null } else { open=<?= $conv['id'] ?>; window.loadConv(<?= $conv['id'] ?>) }"
                  class="p-1.5 rounded-lg text-slate-400 hover:text-indigo-600 hover:bg-indigo-50 transition-colors">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
              <path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
            </svg>
          </button>
        </td>
      </tr>
      <!-- Expanded messages row -->
      <tr x-show="open === <?= $conv['id'] ?>" x-transition>
        <td colspan="10" class="px-5 py-4 bg-slate-50">
          <div class="max-h-80 overflow-y-auto space-y-2 pr-2" id="conv-<?= $conv['id'] ?>">
            <p class="text-xs text-slate-400 text-center py-4">กำลังโหลด...</p>
          </div>
        </td>
      </tr>
      <?php endforeach; ?>
      <?php if (empty($conversations)): ?>
      <tr><td colspan="10" class="px-5 py-12 text-center text-slate-400 text-sm">ยังไม่มี Conversations</td></tr>
      <?php endif; ?>
    </tbody>
  </table>
</div>

<!-- Pagination -->
<?php if ($pages > 1): ?>
<div class="flex items-center justify-center gap-1.5">
  <?php for ($i = max(1,$page-3); $i <= min($pages,$page+3); $i++): ?>
  <a href="?page=<?=$i?>&platform=<?=$platform?>&from=<?=$dateFrom?>&to=<?=$dateTo?>&q=<?=urlencode($search)?>&tag=<?=urlencode($tagFilter)?>"
     class="px-3.5 py-2 rounded-xl text-sm font-medium transition-all
            <?= $i===$page ? 'bg-indigo-600 text-white' : 'bg-white border border-slate-200 text-slate-600 hover:bg-slate-50' ?>">
    <?=$i?>
  </a>
  <?php endfor; ?>
</div>
<?php endif; ?>

<script>
const CSRF = document.querySelector('meta[name="csrf-token"]').content;
const pRoles = {user:'bg-indigo-600',assistant:'bg-slate-200'};
const _fetch = fetch.bind(window);

async function loadConv(id) {
  const container = document.getElementById('conv-'+id);
  if (!container) return;
  const res  = await _fetch(`?conv_id=${id}`);
  const data = await res.json();
  const msgs = data?.data?.messages ?? [];
  if (!msgs.length) { container.innerHTML = '<p class="text-center text-xs text-slate-400 py-4">ไม่มีข้อความ</p>'; return; }
  container.innerHTML = msgs.map(m => `
    <div class="flex ${m.role==='user'?'justify-end':'justify-start'} gap-2">
      <div class="max-w-[75%] px-3.5 py-2.5 rounded-2xl text-sm
                  ${m.role==='user' ? 'bg-indigo-600 text-white rounded-br-sm' : 'bg-white border border-slate-200 text-slate-700 rounded-bl-sm'}">
        <p class="leading-relaxed whitespace-pre-wrap">${m.content.replace(/</g,'&lt;').replace(/>/g,'&gt;')}</p>
        <p class="text-[10px] mt-1 opacity-60">${m.tokens_total?m.tokens_total+' tok · ':''} ${new Date(m.created_at).toLocaleTimeString('th-TH',{hour:'2-digit',minute:'2-digit'})}</p>
      </div>
    </div>`).join('');
  container.scrollTop = container.scrollHeight;
}

function exportCsv(type = 'summary') {
  const from = document.querySelector('[name="from"]')?.value ?? '';
  const to   = document.querySelector('[name="to"]')?.value   ?? '';
  const fd = new FormData(); 
  fd.append('action','export'); 
  fd.append('_csrf_token',CSRF); 
  fd.append('from',from); 
  fd.append('to',to);
  fd.append('type',type);
  
  fetch('', {method:'POST', body:fd}).then(r=>r.blob()).then(b => {
    const a=document.createElement('a'); a.href=URL.createObjectURL(b);
    a.download=`chat_${type}_${from||'all'}.csv`; a.click();
  });
}
</script>

<?php require __DIR__ . '/layouts/footer.php'; ?>
