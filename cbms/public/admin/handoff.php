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

$pageTitle  = 'Handoff Requests';
$breadcrumb = ['Admin', 'Handoff Requests'];
Auth::requirePermission('view_conversations');

$db    = Database::getInstance();
$botId = Auth::requireBot();

// ── AJAX ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Auth::validateCsrf()) Response::error('CSRF failed', 403);
    $action = $_POST['action'] ?? '';

    if ($action === 'update_status') {
        $id     = (int)($_POST['id'] ?? 0);
        $status = $_POST['status'] ?? '';
        if (!in_array($status, ['contacted', 'resolved'], true)) {
            Response::error('Invalid status', 422);
        }
        $update = ['status' => $status];
        if ($status === 'resolved') {
            $update['resolved_at'] = date('Y-m-d H:i:s');
            $update['resolved_by'] = Auth::get('id');
        }
        $db->query(
            "UPDATE handoff_requests SET status = ?, resolved_at = ?, resolved_by = ? WHERE id = ? AND bot_id = ?",
            [$update['status'], $update['resolved_at'] ?? null, $update['resolved_by'] ?? null, $id, $botId]
        );
        Response::success(null, 'อัปเดตสถานะสำเร็จ');
    }
}

// ── DATA ──────────────────────────────────────────────────────────────
$statusFilter = $_GET['status'] ?? '';
$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;
$offset  = ($page - 1) * $perPage;

$where  = ['bot_id = ?'];
$params = [$botId];
if ($statusFilter) {
    $where[] = 'status = ?';
    $params[] = $statusFilter;
}
$whereStr = implode(' AND ', $where);

$total = (int)$db->fetchColumn("SELECT COUNT(*) FROM handoff_requests WHERE $whereStr", $params);
$totalPages = ceil($total / $perPage);

$requests = $db->fetchAll(
    "SELECT * FROM handoff_requests WHERE $whereStr ORDER BY created_at DESC LIMIT ? OFFSET ?",
    array_merge($params, [$perPage, $offset])
);

$pendingCount = (int)$db->fetchColumn(
    "SELECT COUNT(*) FROM handoff_requests WHERE bot_id = ? AND status = 'pending'",
    [$botId]
);

$statusColors = [
    'pending'   => 'bg-amber-100 text-amber-700',
    'contacted' => 'bg-blue-100 text-blue-700',
    'resolved'  => 'bg-emerald-100 text-emerald-700',
];
$statusLabels = [
    'pending'   => 'รอดำเนินการ',
    'contacted' => 'ติดต่อแล้ว',
    'resolved'  => 'เสร็จสิ้น',
];

require __DIR__ . '/layouts/header.php';
?>

<div x-data="handoffManager()">
    <div class="flex flex-wrap items-center justify-between gap-4 mb-6">
        <div>
            <h1 class="text-2xl font-bold text-slate-800">คำขอคุยกับเจ้าหน้าที่</h1>
            <p class="text-sm text-slate-500 mt-1">รายการผู้ใช้ที่ต้องการให้เจ้าหน้าที่ติดต่อกลับ
                <?php if ($pendingCount > 0): ?>
                    <span class="ml-2 px-2 py-0.5 bg-amber-100 text-amber-700 rounded-lg text-xs font-semibold"><?= $pendingCount ?> รอดำเนินการ</span>
                <?php endif; ?>
            </p>
        </div>
        <div class="flex gap-2">
            <a href="?status=" class="px-3 py-1.5 rounded-lg text-xs font-semibold transition-colors <?= !$statusFilter ? 'bg-indigo-100 text-indigo-700' : 'bg-slate-100 text-slate-500 hover:bg-slate-200' ?>">ทั้งหมด</a>
            <a href="?status=pending" class="px-3 py-1.5 rounded-lg text-xs font-semibold transition-colors <?= $statusFilter === 'pending' ? 'bg-amber-100 text-amber-700' : 'bg-slate-100 text-slate-500 hover:bg-slate-200' ?>">รอดำเนินการ</a>
            <a href="?status=contacted" class="px-3 py-1.5 rounded-lg text-xs font-semibold transition-colors <?= $statusFilter === 'contacted' ? 'bg-blue-100 text-blue-700' : 'bg-slate-100 text-slate-500 hover:bg-slate-200' ?>">ติดต่อแล้ว</a>
            <a href="?status=resolved" class="px-3 py-1.5 rounded-lg text-xs font-semibold transition-colors <?= $statusFilter === 'resolved' ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-500 hover:bg-slate-200' ?>">เสร็จสิ้น</a>
        </div>
    </div>

    <?php if (empty($requests)): ?>
    <div class="bg-white rounded-3xl p-12 text-center border border-slate-100 shadow-sm">
        <div class="w-16 h-16 bg-slate-50 text-slate-400 rounded-full flex items-center justify-center mx-auto mb-4">
            <svg class="w-8 h-8" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
            </svg>
        </div>
        <h3 class="text-lg font-semibold text-slate-800">ไม่มีคำขอ</h3>
        <p class="text-sm text-slate-400 mt-1">ยังไม่มีผู้ใช้ขอคุยกับเจ้าหน้าที่</p>
    </div>
    <?php else: ?>
    <div class="bg-white rounded-2xl border border-slate-100 overflow-hidden">
        <table class="w-full text-sm">
            <thead class="bg-slate-50 border-b border-slate-100">
                <tr>
                    <th class="px-5 py-3 text-left text-xs font-semibold text-slate-500 uppercase">เวลา</th>
                    <th class="px-5 py-3 text-left text-xs font-semibold text-slate-500 uppercase">ช่องทาง</th>
                    <th class="px-5 py-3 text-left text-xs font-semibold text-slate-500 uppercase">Contact ID</th>
                    <th class="px-5 py-3 text-left text-xs font-semibold text-slate-500 uppercase">ข้อความ</th>
                    <th class="px-5 py-3 text-center text-xs font-semibold text-slate-500 uppercase">สถานะ</th>
                    <th class="px-5 py-3 text-center text-xs font-semibold text-slate-500 uppercase">จัดการ</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-50">
                <?php foreach ($requests as $req): ?>
                <tr class="hover:bg-slate-50 transition-colors" id="row-<?= $req['id'] ?>">
                    <td class="px-5 py-3.5 text-xs text-slate-500"><?= date('d/m/Y H:i', strtotime($req['created_at'])) ?></td>
                    <td class="px-5 py-3.5">
                        <span class="px-2 py-0.5 rounded-lg text-[10px] font-bold uppercase tracking-wider <?= match($req['contact_type']) { 'line' => 'bg-emerald-100 text-emerald-700', 'phone' => 'bg-violet-100 text-violet-700', default => 'bg-blue-100 text-blue-700' } ?>">
                            <?= $req['contact_type'] === 'phone' ? 'PHONE' : $req['contact_type'] ?>
                        </span>
                    </td>
                    <td class="px-5 py-3.5 font-mono text-xs text-slate-700"><?= htmlspecialchars($req['contact_id']) ?></td>
                    <td class="px-5 py-3.5 text-xs text-slate-600 max-w-[250px] truncate"><?= htmlspecialchars($req['user_message'] ?? '—') ?></td>
                    <td class="px-5 py-3.5 text-center">
                        <span class="px-2 py-1 rounded-lg text-xs font-medium <?= $statusColors[$req['status']] ?>">
                            <?= $statusLabels[$req['status']] ?>
                        </span>
                    </td>
                    <td class="px-5 py-3.5 text-center">
                        <?php if ($req['status'] === 'pending'): ?>
                        <button @click="updateStatus(<?= $req['id'] ?>, 'contacted')"
                                class="px-3 py-1 bg-blue-50 text-blue-600 rounded-lg text-xs font-semibold hover:bg-blue-600 hover:text-white transition-all">
                            ติดต่อแล้ว
                        </button>
                        <?php elseif ($req['status'] === 'contacted'): ?>
                        <button @click="updateStatus(<?= $req['id'] ?>, 'resolved')"
                                class="px-3 py-1 bg-emerald-50 text-emerald-600 rounded-lg text-xs font-semibold hover:bg-emerald-600 hover:text-white transition-all">
                            เสร็จสิ้น
                        </button>
                        <?php else: ?>
                        <span class="text-xs text-slate-400">—</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if ($totalPages > 1): ?>
    <div class="flex justify-center mt-5 gap-1">
        <?php for ($i = 1; $i <= $totalPages; $i++): ?>
        <a href="?page=<?= $i ?>&status=<?= urlencode($statusFilter) ?>"
           class="px-3 py-1.5 rounded-lg text-sm <?= $i === $page ? 'bg-indigo-600 text-white' : 'bg-slate-100 text-slate-600 hover:bg-slate-200' ?>">
            <?= $i ?>
        </a>
        <?php endfor; ?>
    </div>
    <?php endif; ?>
    <?php endif; ?>
</div>

<script>
const CSRF_TOKEN = '<?= Auth::csrfToken() ?>';

function handoffManager() {
    return {
        async updateStatus(id, status) {
            const fd = new FormData();
            fd.append('action', 'update_status');
            fd.append('id', id);
            fd.append('status', status);
            fd.append('_csrf_token', CSRF_TOKEN);
            try {
                const res = await fetch('', { method: 'POST', body: fd });
                const data = await res.json();
                if (data.success) {
                    Noti.success('อัปเดตสถานะสำเร็จ');
                    setTimeout(() => location.reload(), 1000);
                } else {
                    Noti.error(data.message || 'เกิดข้อผิดพลาด');
                }
            } catch (e) { Noti.error('Error: ' + e.message); }
        }
    };
}
</script>
<?php require __DIR__ . '/layouts/footer.php'; ?>
