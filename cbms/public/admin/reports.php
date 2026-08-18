<?php
define('BASE_PATH', dirname(__DIR__, 2));
require_once BASE_PATH . '/vendor/autoload.php';
use Dotenv\Dotenv;
use App\Helpers\Auth;
use App\Helpers\Database;
use App\Helpers\Response;

$dotenv = Dotenv::createImmutable(BASE_PATH);
$dotenv->safeLoad();
date_default_timezone_set($_ENV['APP_TIMEZONE'] ?? 'Asia/Bangkok');

$pageTitle  = 'Reports';
$breadcrumb = ['Admin', 'Reports'];
Auth::requirePermission('view_analytics');

$db    = Database::getInstance();
$botId = Auth::requireBot();

// ── Report types ──────────────────────────────────────────────────────
$reportTypes = [
    'daily'         => ['label' => 'สรุปรายวัน',        'icon' => '📅', 'desc' => 'ภาพรวมการใช้งานรายวัน (บทสนทนา, ข้อความ, Token, ค่าใช้จ่าย)'],
    'conversations' => ['label' => 'บทสนทนา',           'icon' => '💬', 'desc' => 'รายการบทสนทนาทั้งหมดพร้อมสถิติต่อ session'],
    'messages'      => ['label' => 'ข้อความ',            'icon' => '📝', 'desc' => 'รายละเอียดข้อความทุกรายการ (คำถาม-คำตอบ)'],
    'models'        => ['label' => 'การใช้โมเดล AI',     'icon' => '🤖', 'desc' => 'สรุปการเรียกใช้, Token และค่าใช้จ่ายแยกตามโมเดล'],
    'feedback'      => ['label' => 'Feedback',           'icon' => '👍', 'desc' => 'คำตอบที่ผู้ใช้กดถูกใจ/ไม่ถูกใจ'],
    'unanswered'    => ['label' => 'คำถามที่ตอบไม่ได้',  'icon' => '❓', 'desc' => 'คำถามที่บอทไม่พบข้อมูล (Fallback)'],
    'handoff'       => ['label' => 'Handoff',            'icon' => '🙋', 'desc' => 'คำขอติดต่อเจ้าหน้าที่และสถานะการดำเนินการ'],
    'knowledge'     => ['label' => 'คลังความรู้',        'icon' => '📚', 'desc' => 'สถานะไฟล์คลังความรู้และจำนวน Chunk'],
    'cache'         => ['label' => 'คำถามถามซ้ำ',       'icon' => '🔁', 'desc' => 'คำถามยอดนิยมที่ตอบจาก Cache (ไม่เรียก AI)'],
];

// ── Read + sanitize filters ───────────────────────────────────────────
$type = $_GET['type'] ?? 'daily';
if (!isset($reportTypes[$type])) $type = 'daily';

$dateFrom = $_GET['from'] ?? date('Y-m-01');
$dateTo   = $_GET['to']   ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) $dateFrom = date('Y-m-01');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo))   $dateTo   = date('Y-m-d');

$platform    = in_array($_GET['platform'] ?? '', ['web','facebook','line'], true) ? $_GET['platform'] : '';
$role        = in_array($_GET['role'] ?? '', ['user','assistant'], true) ? $_GET['role'] : '';
$fallback    = in_array($_GET['fallback'] ?? '', ['1','0'], true) ? $_GET['fallback'] : '';
$fbType      = in_array($_GET['fb'] ?? '', ['positive','negative'], true) ? $_GET['fb'] : '';
$reviewed    = in_array($_GET['reviewed'] ?? '', ['1','0'], true) ? $_GET['reviewed'] : '';
$resolved    = in_array($_GET['resolved'] ?? '', ['1','0'], true) ? $_GET['resolved'] : '';
$hStatus     = in_array($_GET['status'] ?? '', ['pending','contacted','resolved'], true) ? $_GET['status'] : '';
$contactType = in_array($_GET['contact'] ?? '', ['facebook','line','phone'], true) ? $_GET['contact'] : '';
$syncStatus  = in_array($_GET['sync'] ?? '', ['pending','processing','synced','error'], true) ? $_GET['sync'] : '';
$modelId     = (int)($_GET['model'] ?? 0);
$minHits     = max(0, (int)($_GET['min_hits'] ?? 0));
$search      = trim($_GET['q'] ?? '');

/**
 * Build SQL + params + column definitions for a report type.
 * Columns: key => [label, fmt]  (fmt: text|num|money|bool|ms)
 */
function buildReportQuery(string $type, array $f, int $botId): array
{
    switch ($type) {

    case 'daily':
        $where  = ['c.bot_id = ?', 'DATE(m.created_at) BETWEEN ? AND ?'];
        $params = [$botId, $f['from'], $f['to']];
        if ($f['platform']) { $where[] = 'c.platform = ?'; $params[] = $f['platform']; }
        $sql = "SELECT DATE(m.created_at) AS report_date,
                       COUNT(DISTINCT m.conversation_id)                    AS conversations,
                       SUM(m.role = 'user')                                 AS user_messages,
                       SUM(m.role = 'assistant')                            AS bot_messages,
                       SUM(m.tokens_prompt)                                 AS tokens_prompt,
                       SUM(m.tokens_completion)                             AS tokens_completion,
                       SUM(m.tokens_total)                                  AS tokens_total,
                       SUM(m.role = 'assistant' AND m.is_fallback = 1)      AS fallbacks,
                       ROUND(AVG(CASE WHEN m.role='assistant' THEN m.response_time_ms END)) AS avg_response_ms
                FROM messages m
                JOIN conversations c ON c.id = m.conversation_id
                WHERE " . implode(' AND ', $where) . "
                GROUP BY DATE(m.created_at)
                ORDER BY report_date DESC";
        $cols = [
            'report_date'       => ['วันที่', 'text'],
            'conversations'     => ['บทสนทนา', 'num'],
            'user_messages'     => ['ข้อความผู้ใช้', 'num'],
            'bot_messages'      => ['ข้อความบอท', 'num'],
            'tokens_prompt'     => ['Tokens Prompt', 'num'],
            'tokens_completion' => ['Tokens Completion', 'num'],
            'tokens_total'      => ['Tokens รวม', 'num'],
            'cost_usd'          => ['ค่าใช้จ่าย (USD)', 'money'],
            'fallbacks'         => ['ตอบไม่ได้', 'num'],
            'avg_response_ms'   => ['เวลาตอบเฉลี่ย', 'ms'],
        ];
        return [$sql, $params, $cols];

    case 'conversations':
        $where  = ['c.bot_id = ?', 'DATE(c.started_at) BETWEEN ? AND ?'];
        $params = [$botId, $f['from'], $f['to']];
        if ($f['platform'])       { $where[] = 'c.platform = ?';    $params[] = $f['platform']; }
        if ($f['model'])          { $where[] = 'c.ai_model_id = ?'; $params[] = $f['model']; }
        if ($f['resolved'] !== ''){ $where[] = 'c.is_resolved = ?'; $params[] = (int)$f['resolved']; }
        if ($f['q'])              { $where[] = '(c.session_id LIKE ? OR c.platform_username LIKE ?)'; $params[] = "%{$f['q']}%"; $params[] = "%{$f['q']}%"; }
        $sql = "SELECT c.session_id, c.platform, c.platform_username,
                       am.display_name AS model_name,
                       c.message_count, c.total_tokens, c.total_cost_usd,
                       c.is_resolved, c.started_at, c.last_activity_at
                FROM conversations c
                LEFT JOIN ai_models am ON am.id = c.ai_model_id
                WHERE " . implode(' AND ', $where) . "
                ORDER BY c.started_at DESC";
        $cols = [
            'session_id'        => ['Session ID', 'text'],
            'platform'          => ['Platform', 'text'],
            'platform_username' => ['ชื่อผู้ใช้', 'text'],
            'model_name'        => ['โมเดล', 'text'],
            'message_count'     => ['ข้อความ', 'num'],
            'total_tokens'      => ['Tokens', 'num'],
            'total_cost_usd'    => ['ค่าใช้จ่าย (USD)', 'money'],
            'is_resolved'       => ['ปิดเคส', 'bool'],
            'started_at'        => ['เริ่มเมื่อ', 'text'],
            'last_activity_at'  => ['ใช้งานล่าสุด', 'text'],
        ];
        return [$sql, $params, $cols];

    case 'messages':
        $where  = ['c.bot_id = ?', 'DATE(m.created_at) BETWEEN ? AND ?'];
        $params = [$botId, $f['from'], $f['to']];
        if ($f['platform'])        { $where[] = 'c.platform = ?';    $params[] = $f['platform']; }
        if ($f['role'])            { $where[] = 'm.role = ?';        $params[] = $f['role']; }
        if ($f['fallback'] !== ''){ $where[] = 'm.is_fallback = ?';  $params[] = (int)$f['fallback']; }
        if ($f['fb'])              { $where[] = 'm.feedback = ?';    $params[] = $f['fb']; }
        if ($f['q'])               { $where[] = 'm.content LIKE ?';  $params[] = "%{$f['q']}%"; }
        $sql = "SELECT m.created_at, c.session_id, c.platform, m.role, m.content,
                       m.tokens_total, m.response_time_ms, m.is_fallback, m.feedback
                FROM messages m
                JOIN conversations c ON c.id = m.conversation_id
                WHERE " . implode(' AND ', $where) . "
                ORDER BY m.created_at DESC";
        $cols = [
            'created_at'       => ['เวลา', 'text'],
            'session_id'       => ['Session ID', 'text'],
            'platform'         => ['Platform', 'text'],
            'role'             => ['Role', 'text'],
            'content'          => ['ข้อความ', 'text'],
            'tokens_total'     => ['Tokens', 'num'],
            'response_time_ms' => ['เวลาตอบ', 'ms'],
            'is_fallback'      => ['ตอบไม่ได้', 'bool'],
            'feedback'         => ['Feedback', 'text'],
        ];
        return [$sql, $params, $cols];

    case 'models':
        $where  = ['l.bot_id = ?', 'DATE(l.created_at) BETWEEN ? AND ?'];
        $params = [$botId, $f['from'], $f['to']];
        if ($f['platform']) { $where[] = 'l.platform = ?'; $params[] = $f['platform']; }
        $sql = "SELECT COALESCE(am.display_name, 'ไม่ระบุ') AS model_name,
                       COUNT(*)                    AS requests,
                       SUM(l.tokens_prompt)        AS tokens_prompt,
                       SUM(l.tokens_completion)    AS tokens_completion,
                       SUM(l.tokens_total)         AS tokens_total,
                       SUM(l.cost_usd)             AS cost_usd,
                       ROUND(AVG(l.response_time_ms)) AS avg_response_ms,
                       SUM(l.status = 'error')     AS errors
                FROM api_usage_logs l
                LEFT JOIN ai_models am ON am.id = l.ai_model_id
                WHERE " . implode(' AND ', $where) . "
                GROUP BY l.ai_model_id, am.display_name
                ORDER BY cost_usd DESC";
        $cols = [
            'model_name'        => ['โมเดล', 'text'],
            'requests'          => ['จำนวนครั้ง', 'num'],
            'tokens_prompt'     => ['Tokens Prompt', 'num'],
            'tokens_completion' => ['Tokens Completion', 'num'],
            'tokens_total'      => ['Tokens รวม', 'num'],
            'cost_usd'          => ['ค่าใช้จ่าย (USD)', 'money'],
            'avg_response_ms'   => ['เวลาตอบเฉลี่ย', 'ms'],
            'errors'            => ['Error', 'num'],
        ];
        return [$sql, $params, $cols];

    case 'feedback':
        $where  = ['c.bot_id = ?', 'm.feedback IS NOT NULL', 'DATE(m.created_at) BETWEEN ? AND ?'];
        $params = [$botId, $f['from'], $f['to']];
        if ($f['platform']) { $where[] = 'c.platform = ?'; $params[] = $f['platform']; }
        if ($f['fb'])       { $where[] = 'm.feedback = ?'; $params[] = $f['fb']; }
        $sql = "SELECT m.created_at, c.session_id, c.platform, m.feedback,
                       (SELECT m2.content FROM messages m2
                        WHERE m2.conversation_id = m.conversation_id AND m2.role='user' AND m2.id < m.id
                        ORDER BY m2.id DESC LIMIT 1) AS question,
                       m.content AS answer, m.tokens_total
                FROM messages m
                JOIN conversations c ON c.id = m.conversation_id
                WHERE " . implode(' AND ', $where) . "
                ORDER BY m.created_at DESC";
        $cols = [
            'created_at'   => ['เวลา', 'text'],
            'session_id'   => ['Session ID', 'text'],
            'platform'     => ['Platform', 'text'],
            'feedback'     => ['Feedback', 'text'],
            'question'     => ['คำถาม', 'text'],
            'answer'       => ['คำตอบ', 'text'],
            'tokens_total' => ['Tokens', 'num'],
        ];
        return [$sql, $params, $cols];

    case 'unanswered':
        $where  = ["m.role = 'assistant'", 'm.is_fallback = 1', 'c.bot_id = ?', 'DATE(m.created_at) BETWEEN ? AND ?'];
        $params = [$botId, $f['from'], $f['to']];
        if ($f['platform'])        { $where[] = 'c.platform = ?';    $params[] = $f['platform']; }
        if ($f['reviewed'] !== '') { $where[] = 'm.is_reviewed = ?'; $params[] = (int)$f['reviewed']; }
        if ($f['q']) {
            $where[]  = '(m.content LIKE ? OR EXISTS (SELECT 1 FROM messages mq WHERE mq.conversation_id = m.conversation_id AND mq.role=\'user\' AND mq.id < m.id AND mq.content LIKE ?))';
            $params[] = "%{$f['q']}%"; $params[] = "%{$f['q']}%";
        }
        $sql = "SELECT m.created_at, c.session_id, c.platform,
                       (SELECT m2.content FROM messages m2
                        WHERE m2.conversation_id = m.conversation_id AND m2.role='user' AND m2.id < m.id
                        ORDER BY m2.id DESC LIMIT 1) AS question,
                       m.content AS bot_reply, m.is_reviewed
                FROM messages m
                JOIN conversations c ON c.id = m.conversation_id
                WHERE " . implode(' AND ', $where) . "
                ORDER BY m.created_at DESC";
        $cols = [
            'created_at'  => ['เวลา', 'text'],
            'session_id'  => ['Session ID', 'text'],
            'platform'    => ['Platform', 'text'],
            'question'    => ['คำถามผู้ใช้', 'text'],
            'bot_reply'   => ['คำตอบบอท (Fallback)', 'text'],
            'is_reviewed' => ['ตรวจแล้ว', 'bool'],
        ];
        return [$sql, $params, $cols];

    case 'handoff':
        $where  = ['h.bot_id = ?', 'DATE(h.created_at) BETWEEN ? AND ?'];
        $params = [$botId, $f['from'], $f['to']];
        if ($f['status'])  { $where[] = 'h.status = ?';       $params[] = $f['status']; }
        if ($f['contact']) { $where[] = 'h.contact_type = ?'; $params[] = $f['contact']; }
        $sql = "SELECT h.created_at, h.session_id, h.contact_type, h.contact_id,
                       h.user_message, h.status, h.resolved_at,
                       au.display_name AS resolved_by_name
                FROM handoff_requests h
                LEFT JOIN admin_users au ON au.id = h.resolved_by
                WHERE " . implode(' AND ', $where) . "
                ORDER BY h.created_at DESC";
        $cols = [
            'created_at'       => ['เวลา', 'text'],
            'session_id'       => ['Session ID', 'text'],
            'contact_type'     => ['ช่องทาง', 'text'],
            'contact_id'       => ['ข้อมูลติดต่อ', 'text'],
            'user_message'     => ['ข้อความจากผู้ใช้', 'text'],
            'status'           => ['สถานะ', 'text'],
            'resolved_at'      => ['ปิดเคสเมื่อ', 'text'],
            'resolved_by_name' => ['ผู้ดำเนินการ', 'text'],
        ];
        return [$sql, $params, $cols];

    case 'knowledge':
        $where  = ['ks.bot_id = ?'];
        $params = [$botId];
        if ($f['sync']) { $where[] = 'ks.sync_status = ?'; $params[] = $f['sync']; }
        if ($f['q'])    { $where[] = 'ks.file_name LIKE ?'; $params[] = "%{$f['q']}%"; }
        $sql = "SELECT ks.file_name, ks.file_type, ks.sync_status, ks.chunk_count,
                       ks.is_active, ks.last_modified, ks.last_synced_at, ks.error_message
                FROM knowledge_sources ks
                WHERE " . implode(' AND ', $where) . "
                ORDER BY ks.updated_at DESC";
        $cols = [
            'file_name'      => ['ชื่อไฟล์', 'text'],
            'file_type'      => ['ประเภท', 'text'],
            'sync_status'    => ['สถานะ Sync', 'text'],
            'chunk_count'    => ['Chunks', 'num'],
            'is_active'      => ['เปิดใช้งาน', 'bool'],
            'last_modified'  => ['แก้ไขล่าสุด', 'text'],
            'last_synced_at' => ['Sync ล่าสุด', 'text'],
            'error_message'  => ['Error', 'text'],
        ];
        return [$sql, $params, $cols];

    case 'cache':
        $where  = ['ac.bot_id = ?', 'DATE(ac.created_at) BETWEEN ? AND ?'];
        $params = [$botId, $f['from'], $f['to']];
        if ($f['min_hits'] > 0) { $where[] = 'ac.hit_count >= ?';     $params[] = $f['min_hits']; }
        if ($f['q'])            { $where[] = 'ac.question_text LIKE ?'; $params[] = "%{$f['q']}%"; }
        $sql = "SELECT ac.question_text, ac.hit_count, ac.model_id,
                       ac.created_at, ac.expires_at, ac.reply
                FROM answer_cache ac
                WHERE " . implode(' AND ', $where) . "
                ORDER BY ac.hit_count DESC, ac.created_at DESC";
        $cols = [
            'question_text' => ['คำถาม', 'text'],
            'hit_count'     => ['ถามซ้ำ (ครั้ง)', 'num'],
            'model_id'      => ['โมเดล', 'text'],
            'created_at'    => ['สร้างเมื่อ', 'text'],
            'expires_at'    => ['หมดอายุ', 'text'],
            'reply'         => ['คำตอบ', 'text'],
        ];
        return [$sql, $params, $cols];
    }

    throw new InvalidArgumentException('Unknown report type');
}

/** Merge daily cost (from api_usage_logs) into daily report rows. */
function mergeDailyCost(array $rows, array $f, int $botId, Database $db): array
{
    if (empty($rows)) return $rows;
    $where  = ['bot_id = ?', 'DATE(created_at) BETWEEN ? AND ?'];
    $params = [$botId, $f['from'], $f['to']];
    if ($f['platform']) { $where[] = 'platform = ?'; $params[] = $f['platform']; }
    $costs = $db->fetchAll(
        "SELECT DATE(created_at) AS d, SUM(cost_usd) AS cost
         FROM api_usage_logs WHERE " . implode(' AND ', $where) . " GROUP BY DATE(created_at)",
        $params
    );
    $map = array_column($costs, 'cost', 'd');
    foreach ($rows as &$r) {
        $r['cost_usd'] = $map[$r['report_date']] ?? 0;
    }
    return $rows;
}

$filters = [
    'from' => $dateFrom, 'to' => $dateTo, 'platform' => $platform,
    'role' => $role, 'fallback' => $fallback, 'fb' => $fbType,
    'reviewed' => $reviewed, 'resolved' => $resolved,
    'status' => $hStatus, 'contact' => $contactType, 'sync' => $syncStatus,
    'model' => $modelId, 'min_hits' => $minHits, 'q' => $search,
];

[$sql, $params, $columns] = buildReportQuery($type, $filters, $botId);

// ── AJAX: export CSV (full filtered result, no pagination) ────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'export_csv') {
    Auth::requirePermission('export_data');
    if (!Auth::validateCsrf()) Response::error('CSRF failed', 403);

    $rows = $db->fetchAll($sql, $params);
    if ($type === 'daily') $rows = mergeDailyCost($rows, $filters, $botId, $db);

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="report_' . $type . '_' . $dateFrom . '_to_' . $dateTo . '.csv"');
    $h = fopen('php://output', 'w');
    fprintf($h, chr(0xEF).chr(0xBB).chr(0xBF)); // BOM for Excel Thai characters
    fputcsv($h, array_map(fn($c) => $c[0], array_values($columns)));
    foreach ($rows as $r) {
        $line = [];
        foreach ($columns as $key => $def) {
            $v = $r[$key] ?? '';
            if ($def[1] === 'money') $v = number_format((float)$v, 6, '.', '');
            if ($def[1] === 'bool')  $v = ((int)$v === 1) ? 'Yes' : 'No';
            $line[] = $v;
        }
        // Rows may contain user-typed chat text — sanitize against Excel formula injection
        fputcsv($h, array_map([Response::class, 'csvSanitizeCell'], $line));
    }
    fclose($h);
    exit;
}

// ── Preview: paginated ────────────────────────────────────────────────
$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 50;
$total   = (int)$db->fetchColumn("SELECT COUNT(*) FROM ($sql) t", $params);
$pages   = max(1, (int)ceil($total / $perPage));
$page    = min($page, $pages);
$offset  = ($page - 1) * $perPage;

$rows = $db->fetchAll($sql . " LIMIT $perPage OFFSET $offset", $params);
if ($type === 'daily') $rows = mergeDailyCost($rows, $filters, $botId, $db);

// Models dropdown (for conversations filter)
$botModels = [];
if ($type === 'conversations') {
    $botModels = $db->fetchAll(
        "SELECT DISTINCT am.id, am.display_name
         FROM conversations c JOIN ai_models am ON am.id = c.ai_model_id
         WHERE c.bot_id = ? ORDER BY am.display_name",
        [$botId]
    );
}

// Which filters does the current report use?
$usesDate     = $type !== 'knowledge';
$usesPlatform = in_array($type, ['daily','conversations','messages','models','feedback','unanswered'], true);
$usesSearch   = in_array($type, ['conversations','messages','unanswered','knowledge','cache'], true);

$searchPlaceholder = match ($type) {
    'conversations' => 'ค้นหา Session / ชื่อผู้ใช้…',
    'messages'      => 'ค้นหาในข้อความ…',
    'unanswered'    => 'ค้นหาคำถาม/คำตอบ…',
    'knowledge'     => 'ค้นหาชื่อไฟล์…',
    'cache'         => 'ค้นหาคำถาม…',
    default         => 'ค้นหา…',
};

require __DIR__ . '/layouts/header.php';
?>

<!-- Report Type Selector -->
<div class="bg-white rounded-2xl p-4 border border-slate-100 mb-5">
  <div class="flex flex-wrap gap-2">
    <?php foreach ($reportTypes as $key => $rt): $active = ($key === $type); ?>
    <a href="?type=<?= $key ?>"
       class="px-3.5 py-2 rounded-xl text-sm font-medium transition-all border
              <?= $active
                ? 'bg-indigo-600 text-white border-indigo-600 shadow-md shadow-indigo-200'
                : 'bg-white text-slate-600 border-slate-200 hover:border-indigo-300 hover:text-indigo-600 hover:bg-indigo-50' ?>">
      <span class="mr-1"><?= $rt['icon'] ?></span><?= htmlspecialchars($rt['label']) ?>
    </a>
    <?php endforeach; ?>
  </div>
  <p class="text-xs text-slate-400 mt-3 px-1"><?= htmlspecialchars($reportTypes[$type]['desc']) ?></p>
</div>

<!-- Filters -->
<div class="bg-white rounded-2xl p-4 border border-slate-100 mb-5">
  <form method="GET" id="filter-form" class="flex flex-wrap gap-3 items-center">
    <input type="hidden" name="type" value="<?= $type ?>">

    <?php if ($usesDate): ?>
    <input type="date" name="from" id="date-from" value="<?= $dateFrom ?>"
           class="px-3 py-2 rounded-xl border border-slate-200 text-sm focus:ring-2 focus:ring-indigo-500 outline-none">
    <span class="text-slate-400 text-sm">ถึง</span>
    <input type="date" name="to" id="date-to" value="<?= $dateTo ?>"
           class="px-3 py-2 rounded-xl border border-slate-200 text-sm focus:ring-2 focus:ring-indigo-500 outline-none">
    <?php endif; ?>

    <?php if ($usesPlatform): ?>
    <select name="platform" class="px-3 py-2 rounded-xl border border-slate-200 text-sm focus:ring-2 focus:ring-indigo-500 outline-none">
      <option value="">ทุก Platform</option>
      <option value="web"      <?= $platform==='web'?'selected':'' ?>>🌐 Web</option>
      <option value="facebook" <?= $platform==='facebook'?'selected':'' ?>>📘 Facebook</option>
      <option value="line"     <?= $platform==='line'?'selected':'' ?>>💚 LINE</option>
    </select>
    <?php endif; ?>

    <?php if ($type === 'messages'): ?>
    <select name="role" class="px-3 py-2 rounded-xl border border-slate-200 text-sm outline-none">
      <option value="">ทุก Role</option>
      <option value="user"      <?= $role==='user'?'selected':'' ?>>ผู้ใช้</option>
      <option value="assistant" <?= $role==='assistant'?'selected':'' ?>>บอท</option>
    </select>
    <select name="fallback" class="px-3 py-2 rounded-xl border border-slate-200 text-sm outline-none">
      <option value="">Fallback ทั้งหมด</option>
      <option value="1" <?= $fallback==='1'?'selected':'' ?>>เฉพาะตอบไม่ได้</option>
      <option value="0" <?= $fallback==='0'?'selected':'' ?>>เฉพาะตอบได้</option>
    </select>
    <?php endif; ?>

    <?php if ($type === 'messages' || $type === 'feedback'): ?>
    <select name="fb" class="px-3 py-2 rounded-xl border border-slate-200 text-sm outline-none">
      <option value="">Feedback ทั้งหมด</option>
      <option value="positive" <?= $fbType==='positive'?'selected':'' ?>>👍 Positive</option>
      <option value="negative" <?= $fbType==='negative'?'selected':'' ?>>👎 Negative</option>
    </select>
    <?php endif; ?>

    <?php if ($type === 'conversations'): ?>
    <select name="model" class="px-3 py-2 rounded-xl border border-slate-200 text-sm outline-none">
      <option value="">ทุกโมเดล</option>
      <?php foreach ($botModels as $m): ?>
      <option value="<?= (int)$m['id'] ?>" <?= $modelId===(int)$m['id']?'selected':'' ?>><?= htmlspecialchars($m['display_name']) ?></option>
      <?php endforeach; ?>
    </select>
    <select name="resolved" class="px-3 py-2 rounded-xl border border-slate-200 text-sm outline-none">
      <option value="">สถานะทั้งหมด</option>
      <option value="1" <?= $resolved==='1'?'selected':'' ?>>ปิดเคสแล้ว</option>
      <option value="0" <?= $resolved==='0'?'selected':'' ?>>ยังไม่ปิดเคส</option>
    </select>
    <?php endif; ?>

    <?php if ($type === 'unanswered'): ?>
    <select name="reviewed" class="px-3 py-2 rounded-xl border border-slate-200 text-sm outline-none">
      <option value="">ทั้งหมด</option>
      <option value="0" <?= $reviewed==='0'?'selected':'' ?>>ยังไม่ตรวจ</option>
      <option value="1" <?= $reviewed==='1'?'selected':'' ?>>ตรวจแล้ว</option>
    </select>
    <?php endif; ?>

    <?php if ($type === 'handoff'): ?>
    <select name="status" class="px-3 py-2 rounded-xl border border-slate-200 text-sm outline-none">
      <option value="">ทุกสถานะ</option>
      <option value="pending"   <?= $hStatus==='pending'?'selected':'' ?>>รอดำเนินการ</option>
      <option value="contacted" <?= $hStatus==='contacted'?'selected':'' ?>>ติดต่อแล้ว</option>
      <option value="resolved"  <?= $hStatus==='resolved'?'selected':'' ?>>ปิดเคสแล้ว</option>
    </select>
    <select name="contact" class="px-3 py-2 rounded-xl border border-slate-200 text-sm outline-none">
      <option value="">ทุกช่องทาง</option>
      <option value="facebook" <?= $contactType==='facebook'?'selected':'' ?>>📘 Facebook</option>
      <option value="line"     <?= $contactType==='line'?'selected':'' ?>>💚 LINE</option>
      <option value="phone"    <?= $contactType==='phone'?'selected':'' ?>>📞 โทรศัพท์</option>
    </select>
    <?php endif; ?>

    <?php if ($type === 'knowledge'): ?>
    <select name="sync" class="px-3 py-2 rounded-xl border border-slate-200 text-sm outline-none">
      <option value="">ทุกสถานะ Sync</option>
      <option value="pending"    <?= $syncStatus==='pending'?'selected':'' ?>>รอ Sync</option>
      <option value="processing" <?= $syncStatus==='processing'?'selected':'' ?>>กำลัง Sync</option>
      <option value="synced"     <?= $syncStatus==='synced'?'selected':'' ?>>Sync แล้ว</option>
      <option value="error"      <?= $syncStatus==='error'?'selected':'' ?>>Error</option>
    </select>
    <?php endif; ?>

    <?php if ($type === 'cache'): ?>
    <input type="number" name="min_hits" min="0" value="<?= $minHits ?: '' ?>" placeholder="ถามซ้ำขั้นต่ำ"
           class="w-32 px-3 py-2 rounded-xl border border-slate-200 text-sm outline-none">
    <?php endif; ?>

    <?php if ($usesSearch): ?>
    <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="<?= $searchPlaceholder ?>"
           class="px-3 py-2 rounded-xl border border-slate-200 text-sm focus:ring-2 focus:ring-indigo-500 outline-none w-52">
    <?php endif; ?>

    <button type="submit" class="px-4 py-2 rounded-xl bg-indigo-600 text-white text-sm font-semibold hover:bg-indigo-700 transition-colors">
      กรอง
    </button>

    <?php if ($usesDate): foreach ([['7d','7 วัน'],['30d','30 วัน'],['mtd','เดือนนี้']] as [$k,$l]): ?>
    <button type="button" onclick="qr('<?= $k ?>')"
            class="px-3 py-2 rounded-xl border border-slate-200 text-xs text-slate-600 hover:bg-indigo-50 hover:border-indigo-300 hover:text-indigo-600 transition-all"><?= $l ?></button>
    <?php endforeach; endif; ?>

    <?php if (Auth::can('export_data')): ?>
    <button type="button" onclick="exportCsv()"
            class="ml-auto px-4 py-2 rounded-xl border border-emerald-200 bg-emerald-50 text-emerald-700 text-sm font-semibold hover:bg-emerald-100 flex items-center gap-1.5 transition-colors">
      <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
      </svg>
      ดาวน์โหลด CSV
    </button>
    <?php endif; ?>
  </form>
</div>

<!-- Result Table -->
<div class="bg-white rounded-2xl border border-slate-100 overflow-hidden">
  <div class="px-5 py-4 border-b border-slate-50 flex items-center justify-between">
    <h3 class="font-semibold text-slate-800 text-sm">
      <?= $reportTypes[$type]['icon'] ?> รายงาน<?= htmlspecialchars($reportTypes[$type]['label']) ?>
    </h3>
    <span class="text-xs text-slate-400"><?= number_format($total) ?> รายการ</span>
  </div>

  <div class="overflow-x-auto">
    <table class="w-full text-sm">
      <thead>
        <tr class="bg-slate-50 text-left">
          <th class="px-4 py-3 text-xs font-semibold text-slate-500 whitespace-nowrap">#</th>
          <?php foreach ($columns as $def): ?>
          <th class="px-4 py-3 text-xs font-semibold text-slate-500 whitespace-nowrap <?= in_array($def[1],['num','money','ms'],true)?'text-right':'' ?>">
            <?= htmlspecialchars($def[0]) ?>
          </th>
          <?php endforeach; ?>
        </tr>
      </thead>
      <tbody class="divide-y divide-slate-50">
        <?php if (empty($rows)): ?>
        <tr><td colspan="<?= count($columns)+1 ?>" class="px-4 py-10 text-center text-slate-400">ไม่พบข้อมูลตามเงื่อนไขที่เลือก</td></tr>
        <?php else: foreach ($rows as $i => $r): ?>
        <tr class="hover:bg-slate-50/60 transition-colors">
          <td class="px-4 py-2.5 text-slate-400 text-xs"><?= $offset + $i + 1 ?></td>
          <?php foreach ($columns as $key => $def):
            $v = $r[$key] ?? null;
            $align = in_array($def[1],['num','money','ms'],true) ? 'text-right' : '';
          ?>
          <td class="px-4 py-2.5 text-slate-700 <?= $align ?> align-top">
            <?php if ($v === null || $v === ''): ?>
              <span class="text-slate-300">—</span>
            <?php elseif ($def[1] === 'num'): ?>
              <?= number_format((float)$v) ?>
            <?php elseif ($def[1] === 'money'): ?>
              <span class="font-medium text-emerald-700">$<?= number_format((float)$v, 4) ?></span>
            <?php elseif ($def[1] === 'ms'): ?>
              <?= number_format((float)$v) ?> ms
            <?php elseif ($def[1] === 'bool'): ?>
              <?php if ((int)$v === 1): ?>
                <span class="inline-block px-2 py-0.5 rounded-full bg-emerald-100 text-emerald-700 text-[11px] font-semibold">✓</span>
              <?php else: ?>
                <span class="inline-block px-2 py-0.5 rounded-full bg-slate-100 text-slate-500 text-[11px] font-semibold">✗</span>
              <?php endif; ?>
            <?php else: ?>
              <span class="block max-w-xs truncate" title="<?= htmlspecialchars(mb_substr((string)$v, 0, 500)) ?>">
                <?= htmlspecialchars(mb_strimwidth((string)$v, 0, 120, '…', 'UTF-8')) ?>
              </span>
            <?php endif; ?>
          </td>
          <?php endforeach; ?>
        </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>

  <!-- Pagination -->
  <?php if ($pages > 1):
    $qs = $_GET; unset($qs['page']);
    $baseQs = http_build_query($qs);
  ?>
  <div class="px-5 py-4 border-t border-slate-50 flex items-center justify-between">
    <p class="text-xs text-slate-400">
      หน้า <?= $page ?> จาก <?= number_format($pages) ?> (แสดง <?= number_format(count($rows)) ?> จาก <?= number_format($total) ?> รายการ)
    </p>
    <div class="flex gap-1">
      <?php if ($page > 1): ?>
      <a href="?<?= $baseQs ?>&page=<?= $page-1 ?>" class="px-3 py-1.5 rounded-lg border border-slate-200 text-xs text-slate-600 hover:bg-slate-50">← ก่อนหน้า</a>
      <?php endif; ?>
      <?php
        $start = max(1, $page - 2); $end = min($pages, $page + 2);
        for ($p = $start; $p <= $end; $p++):
      ?>
      <a href="?<?= $baseQs ?>&page=<?= $p ?>"
         class="px-3 py-1.5 rounded-lg text-xs <?= $p === $page ? 'bg-indigo-600 text-white font-semibold' : 'border border-slate-200 text-slate-600 hover:bg-slate-50' ?>"><?= $p ?></a>
      <?php endfor; ?>
      <?php if ($page < $pages): ?>
      <a href="?<?= $baseQs ?>&page=<?= $page+1 ?>" class="px-3 py-1.5 rounded-lg border border-slate-200 text-xs text-slate-600 hover:bg-slate-50">ถัดไป →</a>
      <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>
</div>

<script>
const CSRF = document.querySelector('meta[name="csrf-token"]')?.content ?? '';

function qr(r){
  const today = new Date(), fmt = d => d.toISOString().split('T')[0], from = new Date(today);
  if (r === '7d') from.setDate(today.getDate() - 6);
  else if (r === '30d') from.setDate(today.getDate() - 29);
  else from.setDate(1);
  document.getElementById('date-from').value = fmt(from);
  document.getElementById('date-to').value   = fmt(today);
  document.getElementById('filter-form').submit();
}

function exportCsv(){
  const form = document.getElementById('filter-form');
  const qs = new URLSearchParams(new FormData(form)).toString();
  const fd = new FormData();
  fd.append('action', 'export_csv');
  fd.append('_csrf_token', CSRF);
  fetch(`?${qs}`, { method: 'POST', body: fd })
    .then(r => {
      if (!r.ok) throw new Error('Export failed');
      return r.blob();
    })
    .then(b => {
      const a = document.createElement('a');
      a.href = URL.createObjectURL(b);
      a.download = `report_<?= $type ?>_<?= $dateFrom ?>_to_<?= $dateTo ?>.csv`;
      a.click();
      URL.revokeObjectURL(a.href);
    })
    .catch(() => Swal.fire({ icon: 'error', title: 'ดาวน์โหลดไม่สำเร็จ', text: 'กรุณาตรวจสอบสิทธิ์ Export Data' }));
}
</script>
<?php require __DIR__ . '/layouts/footer.php'; ?>
