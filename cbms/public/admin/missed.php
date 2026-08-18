<?php
if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__, 2));
}
require_once BASE_PATH . '/vendor/autoload.php';
use App\Helpers\Auth;
use App\Helpers\Database;
use App\Helpers\Response;
use App\Services\EmbeddingService;
use App\Services\OpenRouterService;

$dotenv = \Dotenv\Dotenv::createImmutable(BASE_PATH);
$dotenv->safeLoad();
date_default_timezone_set($_ENV['APP_TIMEZONE'] ?? 'Asia/Bangkok');

$pageTitle  = 'Unanswered Questions';
$breadcrumb = ['Admin', 'Unanswered Questions'];
Auth::requirePermission('view_conversations');

$db    = Database::getInstance();
$botId = Auth::requireBot();

// ── AJAX ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Auth::validateCsrf()) Response::error('CSRF failed', 403);
    $action = $_POST['action'] ?? '';
    if ($action === 'mark_reviewed') {
        $id = (int)($_POST['id'] ?? 0);
        // BUG-12 fix: verify message belongs to current bot before updating
        $db->query(
            "UPDATE messages m JOIN conversations c ON c.id = m.conversation_id
             SET m.is_reviewed = 1
             WHERE m.id = ? AND c.bot_id = ?",
            [$id, $botId]
        );
        Response::success(null, 'Marked as reviewed');
    }
    if ($action === 'mark_all_reviewed') {
        $db->query(
            "UPDATE messages m JOIN conversations c ON c.id = m.conversation_id
             SET m.is_reviewed = 1
             WHERE m.is_fallback = 1 AND m.is_reviewed = 0 AND m.role = 'assistant' AND c.bot_id = ?",
            [$botId]
        );
        Response::success(null, 'All marked as reviewed');
    }

    if ($action === 'add_answer') {
        // Writes to the knowledge base — viewing conversations is not enough.
        // Without this gate a viewer-level user could teach the bot anything.
        if (!Auth::can('manage_knowledge')) {
            Response::error('ต้องมีสิทธิ์จัดการ Knowledge Base จึงจะเพิ่มคำตอบได้', 403);
        }

        $msgId    = (int)($_POST['message_id'] ?? 0);
        $question = trim($_POST['question'] ?? '');
        $answer   = trim($_POST['answer'] ?? '');
        if (!$question || !$answer) Response::error('กรุณากรอกทั้งคำถามและคำตอบ', 422);

        try {
            $source = $db->fetch(
                "SELECT id FROM knowledge_sources WHERE bot_id = ? AND file_name = 'Manual Q&A'",
                [$botId]
            );
            if (!$source) {
                $db->insert('knowledge_sources', [
                    'bot_id'              => $botId,
                    'google_drive_file_id'=> 'manual_qa_bot_' . $botId,
                    'file_name'           => 'Manual Q&A',
                    'file_type'           => 'manual',
                    'sync_status'         => 'synced',
                    'is_active'           => 1,
                    'chunk_count'         => 0,
                ]);
                $sourceId = (int)$db->lastInsertId();
            } else {
                $sourceId = (int)$source['id'];
            }

            $chunkContent = "คำถาม: {$question}\nคำตอบ: {$answer}";
            $embedding = null;
            try {
                $or = new OpenRouterService();
                // Title-prefixed embedding input, matching how storeChunks()
                // embeds regular chunks (contextual embeddings) — the stored
                // content stays clean.
                $embResult = $or->createEmbedding(["Manual Q&A\n" . $chunkContent]);
                $embedding = $embResult[0]['embedding'] ?? null;
            } catch (\Throwable $e) {
                // Embedding failed — still save the chunk without embedding
            }

            $currentMax = (int)$db->fetchColumn(
                "SELECT COALESCE(MAX(chunk_index), -1) FROM knowledge_chunks WHERE source_id = ?",
                [$sourceId]
            );

            $db->insert('knowledge_chunks', [
                'source_id'   => $sourceId,
                'chunk_index'  => $currentMax + 1,
                'content'      => $chunkContent,
                'embedding'    => $embedding ? json_encode($embedding) : null,
                'token_count'  => (int)(mb_strlen($chunkContent) * 0.5),
                'metadata'     => json_encode(['type' => 'manual_qa', 'added_by' => Auth::get('id')]),
            ]);

            $db->query(
                "UPDATE knowledge_sources SET chunk_count = chunk_count + 1 WHERE id = ?",
                [$sourceId]
            );

            // KB changed → similar questions answered from cache would keep
            // serving the old (wrong/missing) reply for up to the cache TTL.
            try {
                $db->query('DELETE FROM answer_cache WHERE bot_id <=> ?', [$botId]);
            } catch (\Throwable) {
                // answer_cache table may not exist yet (migration 007)
            }

            if ($msgId) {
                $db->query(
                    "UPDATE messages m JOIN conversations c ON c.id = m.conversation_id
                     SET m.is_reviewed = 1 WHERE m.id = ? AND c.bot_id = ?",
                    [$msgId, $botId]
                );
            }

            Response::success(null, 'เพิ่มคำตอบลง Knowledge Base สำเร็จ' . ($embedding ? '' : ' (ไม่สามารถสร้���ง embedding ได้ จะสร้างภายหลัง)'));
        } catch (\Throwable $e) {
            Response::error('เกิดข้อผิดพลาด: ' . $e->getMessage(), 500);
        }
    }
}

// ── DATA ──────────────────────────────────────────────────────────────
$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;
$offset  = ($page - 1) * $perPage;

$total = (int)$db->fetchColumn(
    "SELECT COUNT(*) FROM messages m JOIN conversations c ON c.id = m.conversation_id
     WHERE m.is_fallback = 1 AND m.is_reviewed = 0 AND m.role = 'assistant' AND c.bot_id = ?",
    [$botId]
);
$totalPages = ceil($total / $perPage);

$sql = "SELECT m.id as assistant_msg_id, m.conversation_id, m.content as bot_reply, m.created_at, c.platform, c.session_id,
        (SELECT content FROM messages WHERE conversation_id = m.conversation_id AND id < m.id AND role = 'user' ORDER BY id DESC LIMIT 1) as user_question
        FROM messages m JOIN conversations c ON c.id = m.conversation_id
        WHERE m.is_fallback = 1 AND m.is_reviewed = 0 AND m.role = 'assistant' AND c.bot_id = ?
        ORDER BY m.created_at DESC LIMIT " . (int)$perPage . " OFFSET " . (int)$offset;

$unanswered = $db->fetchAll($sql, [$botId]);

require __DIR__ . '/layouts/header.php';
?>

<div x-data="unansweredManager()">
    <div class="flex flex-wrap items-center justify-between gap-4 mb-6">
        <div>
            <h1 class="text-2xl font-bold text-slate-800">คำถามที่ AI ตอบไม่ได้</h1>
            <p class="text-sm text-slate-500 mt-1">รายการคำถามที่บอทหาข้อมูลไม่พบ และยังไม่ได้ตรวจสอบ</p>
        </div>
        <button x-show="remaining > 0" x-transition
                @click="markAllReviewed()"
                class="px-4 py-2 bg-slate-100 text-slate-600 rounded-xl text-sm font-medium hover:bg-slate-200 transition-colors">
            ทำเครื่องหมายว่าอ่านแล้วทั้งหมด (<span x-text="remaining"></span>)
        </button>
    </div>

    <!-- Empty state -->
    <div x-show="remaining === 0" x-transition class="bg-white rounded-3xl p-12 text-center border border-slate-100 shadow-sm">
        <div class="w-16 h-16 bg-emerald-50 text-emerald-500 rounded-full flex items-center justify-center mx-auto mb-4">
            <svg class="w-8 h-8" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>
        </div>
        <h3 class="text-lg font-semibold text-slate-800">ไม่มีคำถามค้างคา</h3>
    </div>

    <!-- Items list -->
    <div x-show="remaining > 0" class="space-y-4">
        <?php foreach ($unanswered as $item): ?>
        <div class="bg-white rounded-2xl border border-slate-100 shadow-sm overflow-hidden"
             x-show="!reviewed.includes(<?= $item['assistant_msg_id'] ?>)" x-transition
             id="row-<?= $item['assistant_msg_id'] ?>">
            <div class="p-5 flex flex-col md:flex-row gap-5 items-start">
                <div class="w-full md:w-48 shrink-0">
                    <span class="px-2 py-0.5 rounded-lg text-[10px] font-bold uppercase tracking-wider bg-slate-100 text-slate-700"><?= $item['platform'] ?></span>
                    <p class="text-xs text-slate-400 font-mono mt-2"><?= substr($item['session_id'] ?? '', 0, 10) ?>...</p>
                    <p class="text-xs text-slate-500 mt-1"><?= date('d/m/Y H:i', strtotime($item['created_at'])) ?></p>
                </div>
                <div class="flex-1 space-y-3">
                    <div class="bg-slate-50 rounded-2xl p-4 border border-slate-100">
                        <p class="text-sm text-slate-800 font-medium"><?= htmlspecialchars($item['user_question'] ?? '(ไม่พบคำถาม)') ?></p>
                    </div>
                    <div class="bg-white rounded-2xl p-4 border border-red-50 text-sm text-slate-500 italic">
                        <?= htmlspecialchars($item['bot_reply']) ?>
                    </div>
                </div>
                <div class="flex flex-col gap-2 shrink-0">
                    <button @click="openAddAnswer(<?= $item['assistant_msg_id'] ?>, <?= htmlspecialchars(json_encode($item['user_question'] ?? ''), ENT_QUOTES) ?>)"
                            class="px-4 py-2 bg-emerald-50 text-emerald-600 rounded-xl text-sm font-semibold hover:bg-emerald-600 hover:text-white transition-all">
                        เพิ่มคำตอบ
                    </button>
                    <button @click="markReviewed(<?= $item['assistant_msg_id'] ?>)"
                            class="px-4 py-2 bg-indigo-50 text-indigo-600 rounded-xl text-sm font-semibold hover:bg-indigo-600 hover:text-white transition-all">
                        อ่านแล้ว
                    </button>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Add Answer Modal -->
    <div x-show="addModal" x-transition class="fixed inset-0 z-50 flex items-center justify-center p-4">
        <div class="absolute inset-0 bg-slate-900/50 backdrop-blur-sm" @click="addModal=false"></div>
        <div class="relative bg-white rounded-2xl shadow-2xl w-full max-w-lg p-6" @click.stop>
            <div class="flex items-center justify-between mb-5">
                <h2 class="text-lg font-bold text-slate-800">เพิ่มคำตอบลง Knowledge Base</h2>
                <button @click="addModal=false" class="text-slate-400 hover:text-slate-600">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>
            <div class="space-y-4">
                <div>
                    <label class="block text-sm font-semibold text-slate-700 mb-1">คำถาม</label>
                    <textarea x-model="addForm.question" rows="2"
                              class="w-full px-4 py-2.5 rounded-xl border border-slate-200 text-sm focus:ring-2 focus:ring-indigo-500 outline-none resize-none"></textarea>
                </div>
                <div>
                    <label class="block text-sm font-semibold text-slate-700 mb-1">คำตอบที่ถูกต้อง</label>
                    <textarea x-model="addForm.answer" rows="5" placeholder="พิมพ์คำตอบที่ต้องการให้บอทตอบในครั้งถัดไป..."
                              class="w-full px-4 py-2.5 rounded-xl border border-slate-200 text-sm focus:ring-2 focus:ring-indigo-500 outline-none resize-y"></textarea>
                </div>
            </div>
            <div class="flex gap-3 mt-6">
                <button @click="addModal=false" class="flex-1 py-2.5 rounded-xl border border-slate-200 text-sm font-medium text-slate-600 hover:bg-slate-50">ยกเลิก</button>
                <button @click="submitAnswer()" :disabled="addSaving || !addForm.answer.trim()"
                        class="flex-1 py-2.5 rounded-xl bg-emerald-600 text-white text-sm font-semibold hover:bg-emerald-700 disabled:opacity-60 transition-colors">
                    <span x-text="addSaving ? 'กำลังบันทึก...' : 'บันทึกลง Knowledge Base'"></span>
                </button>
            </div>
        </div>
    </div>
</div>

<script>
const CSRF_TOKEN = '<?= Auth::csrfToken() ?>';

function unansweredManager() {
    return {
        total: <?= $total ?>,
        reviewed: [],
        addModal: false,
        addSaving: false,
        addForm: { messageId: 0, question: '', answer: '' },

        get remaining() {
            return this.total - this.reviewed.length;
        },

        openAddAnswer(msgId, question) {
            this.addForm = { messageId: msgId, question: question || '', answer: '' };
            this.addModal = true;
        },

        async submitAnswer() {
            if (!this.addForm.answer.trim()) return;
            this.addSaving = true;
            const fd = new FormData();
            fd.append('action', 'add_answer');
            fd.append('message_id', this.addForm.messageId);
            fd.append('question', this.addForm.question);
            fd.append('answer', this.addForm.answer);
            fd.append('_csrf_token', CSRF_TOKEN);
            try {
                const res = await fetch('', { method: 'POST', body: fd });
                const data = await res.json();
                if (data.success) {
                    this.reviewed.push(this.addForm.messageId);
                    this.addModal = false;
                    Noti.success(data.message);
                } else {
                    Noti.error(data.message || 'เกิดข้อผิดพลาด');
                }
            } catch (e) { Noti.error('Error: ' + e.message); }
            this.addSaving = false;
        },

        async markReviewed(id) {
            const fd = new FormData();
            fd.append('action', 'mark_reviewed');
            fd.append('id', id);
            fd.append('_csrf_token', CSRF_TOKEN);
            try {
                const res = await fetch('', { method: 'POST', body: fd });
                const data = await res.json();
                if (data.success) {
                    this.reviewed.push(id);
                }
            } catch (e) {}
        },

        async markAllReviewed() {
            if (!await Noti.confirm('ยืนยันทำเครื่องหมายว่าอ่านแล้วทั้งหมด?')) return;
            const fd = new FormData();
            fd.append('action', 'mark_all_reviewed');
            fd.append('_csrf_token', CSRF_TOKEN);
            try {
                const res = await fetch('', { method: 'POST', body: fd });
                const data = await res.json();
                if (data.success) {
                    this.total = 0;
                    this.reviewed = [];
                }
            } catch (e) {}
        }
    };
}
</script>
<?php require __DIR__ . '/layouts/footer.php'; ?>
