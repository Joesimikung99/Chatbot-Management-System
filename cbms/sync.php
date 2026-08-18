#!/usr/bin/env php
<?php
/**
 * CLI Sync Script — Knowledge Base
 * AI Chatbot System — CBMS
 *
 * Usage:
 *   php sync.php               → sync + process all pending
 *   php sync.php --list        → list Drive files only
 *   php sync.php --sync-only   → sync file list (no embedding)
 *   php sync.php --source=5    → process single source by ID
 *   php sync.php --aggregate   → run daily token aggregation
 *   php sync.php --test-ai     → test default AI model
 *   php sync.php --build-wiki  → build wiki drafts from synced documents
 *   php sync.php --publish-wiki=ID|all → publish wiki draft(s)
 *   php sync.php --wiki-gaps   → report topics users ask about but we lack
 */

// ── Bootstrap ────────────────────────────────────────────────────────
define('BASE_PATH', __DIR__);

require_once BASE_PATH . '/vendor/autoload.php';

use Dotenv\Dotenv;
use App\Services\GoogleDriveService;
use App\Services\OpenRouterService;
use App\Services\TokenAnalyticsService;
use App\Services\WikiService;
use App\Helpers\Database;

// Load .env
$dotenv = Dotenv::createImmutable(BASE_PATH);
$dotenv->safeLoad();

date_default_timezone_set($_ENV['APP_TIMEZONE'] ?? 'Asia/Bangkok');

// ── CLI Argument Parsing ─────────────────────────────────────────────
$options = getopt('', [
    'list',
    'sync-only',
    'source:',
    'aggregate',
    'aggregate-date:',
    'sync-models',
    'test-ai',
    // LLM wiki knowledge layer
    'build-wiki',
    'publish-wiki:',
    'wiki-gaps',
    'wiki-days:',
    'bot:',
    // Embedding maintenance
    're-embed-all',
    'help',
]);

// ── Help ─────────────────────────────────────────────────────────────
if (isset($options['help'])) {
    echo <<<HELP
AI Chatbot System — Knowledge Base Sync Tool
============================================
Usage:
  php sync.php [options]

Options:
  --list              List all files from Google Drive (no DB changes)
  --sync-only         Sync file list to DB without processing embeddings
  --source=ID         Process (re-embed) a single knowledge source by ID
  --aggregate         Run daily token usage aggregation (yesterday)
  --aggregate-date=D  Run aggregation for a specific date (YYYY-MM-DD)
  --sync-models       Sync AI models from OpenRouter to DB
  --test-ai           Test the default AI model with a sample message
  --help              Show this help message

LLM Wiki (knowledge pages written by the LLM from your documents):
  --build-wiki        Build draft wiki pages from documents that changed
                      (combine with --source=ID to rebuild one document)
  --publish-wiki=ID   Publish one draft page → embeddings the bot can retrieve
  --publish-wiki=all  Publish every draft (initial seed only — in production
                      pages should be approved in the admin UI)
  --wiki-gaps         List topics users asked about that have no wiki page
  --wiki-days=N       Look-back window for --wiki-gaps (default 30)
  --bot=ID            Restrict a wiki command to one bot (default: all bots)

Embedding maintenance:
  --re-embed-all      Re-embed every stored chunk + wiki question with the
                      CURRENT embedding model, then clear answer/query caches.
                      Run this after changing EMBEDDING_MODEL in .env —
                      old vectors have a different dimension and score 0.
                      (combine with --bot=ID to limit to one bot)

Examples:
  php sync.php                     # Full sync + embed all pending
  php sync.php --source=3          # Re-process source ID 3
  php sync.php --aggregate         # Aggregate yesterday's stats
  php sync.php --sync-models       # Refresh model list from OpenRouter
  php sync.php --build-wiki        # Draft wiki pages from synced documents
  php sync.php --publish-wiki=12   # Publish page 12 after reviewing it
  php sync.php --wiki-gaps --wiki-days=7

HELP;
    exit(0);
}

// ── Helpers ──────────────────────────────────────────────────────────
function out(string $msg, string $color = 'white'): void
{
    $colors = [
        'green'  => "\033[32m",
        'yellow' => "\033[33m",
        'red'    => "\033[31m",
        'cyan'   => "\033[36m",
        'white'  => "\033[0m",
        'bold'   => "\033[1m",
    ];
    $reset = "\033[0m";
    $isCli = PHP_SAPI === 'cli';

    if ($isCli) {
        echo ($colors[$color] ?? '') . $msg . $reset . "\n";
    } else {
        echo htmlspecialchars($msg) . "\n";
    }
}

function separator(): void
{
    out(str_repeat('─', 60), 'cyan');
}

// ── Main ─────────────────────────────────────────────────────────────
out('', 'white');
separator();
out('  AI Chatbot System — Knowledge Base Sync', 'bold');
out('  ' . date('Y-m-d H:i:s'), 'cyan');
separator();

// ── List Files ───────────────────────────────────────────────────────
if (isset($options['list'])) {
    out("\n📂 Listing Google Drive files...\n", 'cyan');
    try {
        $driveService = new GoogleDriveService();
        $files = $driveService->listFiles();
        if (empty($files)) {
            out('  No files found in the configured folder.', 'yellow');
        } else {
            out(sprintf("  %-40s %-15s %s", 'Name', 'Type', 'Modified'), 'bold');
            separator();
            foreach ($files as $f) {
                out(sprintf("  %-40s %-15s %s",
                    substr($f['name'], 0, 39),
                    $f['mimeType'],
                    $f['modifiedTime'] ?? '-'
                ));
            }
            out("\n  Total: " . count($files) . ' files', 'green');
        }
    } catch (\Throwable $e) {
        out('  ERROR: ' . $e->getMessage(), 'red');
    }
    exit(0);
}

// ── Sync Models from OpenRouter ───────────────────────────────────────
if (isset($options['sync-models'])) {
    out("\n🤖 Syncing AI models from OpenRouter...\n", 'cyan');
    try {
        $or    = new OpenRouterService();
        $stats = $or->syncModelsToDatabase();
        out("  ✅ Added:       {$stats['added']}", 'green');
        out("  🔄 Updated:     {$stats['updated']}", 'yellow');
        out("  ❌ Deactivated: {$stats['deactivated']}", 'red');
        if ($stats['errors'] > 0) {
            out("  ⚠️  Errors:      {$stats['errors']}", 'red');
        }
    } catch (\Throwable $e) {
        out('  ERROR: ' . $e->getMessage(), 'red');
    }
    exit(0);
}

// ── Test AI ───────────────────────────────────────────────────────────
if (isset($options['test-ai'])) {
    out("\n🧪 Testing default AI model...\n", 'cyan');
    try {
        $or    = new OpenRouterService();
        $model = $or->getDefaultModel();
        if (!$model) {
            out('  No active model found in DB.', 'red');
            exit(1);
        }
        out("  Model: {$model['display_name']} ({$model['openrouter_model_id']})", 'yellow');
        $result = $or->testModel($model['openrouter_model_id']);
        if ($result['success']) {
            out("  ✅ Response: {$result['response']}", 'green');
            out("  ⏱  Response time: {$result['response_time_ms']}ms", 'cyan');
        } else {
            out("  ❌ Failed: {$result['error']}", 'red');
        }
    } catch (\Throwable $e) {
        out('  ERROR: ' . $e->getMessage(), 'red');
    }
    exit(0);
}

// ── Re-embed All (after switching embedding model) ───────────────────
if (isset($options['re-embed-all'])) {
    set_time_limit(0);
    $botId = isset($options['bot']) ? (int)$options['bot'] : null;
    $db    = Database::getInstance();
    $or    = new OpenRouterService();

    $model = $_ENV['EMBEDDING_MODEL'] ?? $_ENV['OPENROUTER_EMBEDDING_MODEL'] ?? 'openai/text-embedding-3-small';
    out("\n🧬 Re-embedding everything with: {$model}\n", 'cyan');

    try {
        // 1) Knowledge chunks — batch per source, title-prefixed like storeChunks()
        $sql    = 'SELECT id, file_name, bot_id FROM knowledge_sources WHERE chunk_count > 0';
        $params = [];
        if ($botId !== null) { $sql .= ' AND bot_id = ?'; $params[] = $botId; }
        $sources = $db->fetchAll($sql, $params);

        $chunkTotal = 0;
        foreach ($sources as $src) {
            $rows = $db->fetchAll(
                'SELECT id, content FROM knowledge_chunks WHERE source_id = ? ORDER BY chunk_index',
                [$src['id']]
            );
            if (empty($rows)) continue;

            $title = trim((string)$src['file_name']);
            foreach (array_chunk($rows, 100) as $batch) {
                $inputs  = array_map(
                    fn($r) => $title === '' ? $r['content'] : $title . "\n" . $r['content'],
                    $batch
                );
                $vectors = $or->createEmbedding($inputs);

                foreach ($batch as $i => $row) {
                    $vec = $vectors[$i]['embedding'] ?? null;
                    if (is_array($vec) && $vec !== []) {
                        $db->update('knowledge_chunks', ['embedding' => json_encode($vec)], ['id' => $row['id']]);
                        $chunkTotal++;
                    } else {
                        out("  ⚠ chunk {$row['id']} (source {$src['id']}): embedding API returned nothing", 'yellow');
                    }
                }
            }
            out("  ✅ {$src['file_name']}: " . count($rows) . ' chunks', 'green');
        }

        // 2) Wiki example questions (only those already embedded = published pages)
        $qSql    = 'SELECT wq.id, wq.question FROM wiki_questions wq
                    JOIN wiki_pages wp ON wp.id = wq.page_id
                    WHERE wq.embedding IS NOT NULL';
        $qParams = [];
        if ($botId !== null) { $qSql .= ' AND wp.bot_id = ?'; $qParams[] = $botId; }
        $questions = [];
        try {
            $questions = $db->fetchAll($qSql, $qParams);
        } catch (\Throwable) {
            // wiki tables may not exist yet (migration 009)
        }

        $qTotal = 0;
        foreach (array_chunk($questions, 100) as $batch) {
            $vectors = $or->createEmbedding(array_column($batch, 'question'));
            foreach ($batch as $i => $row) {
                $vec = $vectors[$i]['embedding'] ?? null;
                if (is_array($vec) && $vec !== []) {
                    $db->update('wiki_questions', ['embedding' => json_encode($vec)], ['id' => $row['id']]);
                    $qTotal++;
                }
            }
        }

        // 3) Caches built on old-model vectors are now poison — drop them.
        try {
            if ($botId !== null) $db->query('DELETE FROM answer_cache WHERE bot_id <=> ?', [$botId]);
            else                 $db->query('DELETE FROM answer_cache');
        } catch (\Throwable) {}
        try {
            $db->query('DELETE FROM query_embeddings');
        } catch (\Throwable) {}

        separator();
        out("  Chunks re-embedded    : {$chunkTotal}", 'green');
        out("  Questions re-embedded : {$qTotal}", 'green');
        out('  answer_cache + query_embeddings cleared', 'white');
        out("\n  ทดสอบต่อด้วย: php sync.php --test-ai แล้วลองถามผ่าน bash test-api.sh", 'cyan');
    } catch (\Throwable $e) {
        out('  ERROR: ' . $e->getMessage(), 'red');
        exit(1);
    }
    exit(0);
}

// ── Wiki: Build Drafts ────────────────────────────────────────────────
// ต้องอยู่ก่อน --source เพราะ --build-wiki --source=ID หมายถึง build จาก
// เอกสารฉบับเดียว ไม่ใช่ re-embed เอกสารนั้น
if (isset($options['build-wiki'])) {
    set_time_limit(0);
    $botId    = isset($options['bot'])    ? (int)$options['bot']    : null;
    $sourceId = isset($options['source']) ? (int)$options['source'] : null;

    out("\n📝 Building wiki drafts " . ($sourceId ? "from source {$sourceId}" : 'from changed documents') . "...\n", 'cyan');
    try {
        $wiki = new WikiService($botId);
        out("  Model   : " . $wiki->builderModel(), 'yellow');
        $stats = $wiki->buildDrafts($sourceId);
        out("  Created : {$stats['created']} pages", 'green');
        out("  Updated : {$stats['updated']} pages", 'yellow');
        out("  Skipped : {$stats['skipped']} documents", 'white');
        out("  Failed  : {$stats['failed']} documents", $stats['failed'] > 0 ? 'red' : 'white');
        out("  Questions generated: {$stats['questions']}", 'white');
        if ($stats['pages'] > 0) {
            out("\n  ทุกหน้าเป็น draft — review แล้วกด publish ที่ admin/wiki.php", 'cyan');
        }
        if ($stats['failed'] > 0) {
            out('  ดูรายละเอียดที่ storage/logs/wiki_service.log', 'yellow');
        }
    } catch (\Throwable $e) {
        out('  ERROR: ' . $e->getMessage(), 'red');
        exit(1);
    }
    exit(0);
}

// ── Wiki: Publish ─────────────────────────────────────────────────────
if (isset($options['publish-wiki'])) {
    set_time_limit(0);
    $botId  = isset($options['bot']) ? (int)$options['bot'] : null;
    $target = trim((string)$options['publish-wiki']);

    try {
        $wiki  = new WikiService($botId);
        $pages = $target === 'all'
            ? $wiki->listPages('draft')
            : array_filter([$wiki->findPage((int)$target)]);

        if (empty($pages)) {
            out("\n  ไม่พบหน้า wiki ที่จะ publish", 'yellow');
            exit(0);
        }

        out("\n🚀 Publishing " . count($pages) . " wiki page(s)...\n", 'cyan');
        $ok = 0;
        foreach ($pages as $page) {
            if ($wiki->publishPage((int)$page['id'])) {
                out("  ✅ #{$page['id']} {$page['title']}", 'green');
                $ok++;
            } else {
                out("  ❌ #{$page['id']} {$page['title']}", 'red');
            }
        }
        out("\n  Published: {$ok}/" . count($pages), $ok === count($pages) ? 'green' : 'yellow');
    } catch (\Throwable $e) {
        out('  ERROR: ' . $e->getMessage(), 'red');
        exit(1);
    }
    exit(0);
}

// ── Wiki: Gap Analysis ────────────────────────────────────────────────
if (isset($options['wiki-gaps'])) {
    $botId = isset($options['bot'])       ? (int)$options['bot']       : null;
    $days  = isset($options['wiki-days']) ? (int)$options['wiki-days'] : 30;

    out("\n🔍 Analysing questions the bot could not answer (last {$days} days)...\n", 'cyan');
    try {
        $topics = (new WikiService($botId))->analyzeGaps($days);
        if (empty($topics)) {
            out('  ไม่พบหัวข้อที่ขาด — ไม่มี fallback ในช่วงนี้ หรือทุกหัวข้อมีหน้า wiki แล้ว', 'green');
            exit(0);
        }

        out(sprintf('  %-5s %-38s %s', 'Freq', 'Topic', 'Suggested slug'), 'bold');
        separator();
        foreach ($topics as $t) {
            out(sprintf('  %-5d %-38s %s',
                $t['frequency'],
                mb_substr($t['topic'], 0, 37, 'UTF-8'),
                $t['suggested_slug']
            ));
        }
        out("\n  Total: " . count($topics) . ' topics', 'green');
        out('  สร้างร่างหน้าได้ที่ admin/wiki.php (เนื้อหาต้องเขียนเอง — fallback log ไม่ใช่แหล่งข้อมูล)', 'cyan');
    } catch (\Throwable $e) {
        out('  ERROR: ' . $e->getMessage(), 'red');
        exit(1);
    }
    exit(0);
}

// ── Daily Aggregation ─────────────────────────────────────────────────
if (isset($options['aggregate'])) {
    $date = $options['aggregate-date'] ?? null;
    $label = $date ?? 'yesterday';
    out("\n📊 Running daily token aggregation for {$label}...\n", 'cyan');
    try {
        $analytics = new TokenAnalyticsService();
        $analytics->aggregateDailyStats($date);
        out("  ✅ Aggregation complete for {$label}", 'green');
    } catch (\Throwable $e) {
        out('  ERROR: ' . $e->getMessage(), 'red');
    }
    exit(0);
}

// ── Process Single Source ─────────────────────────────────────────────
if (isset($options['source'])) {
    $sourceId = (int)$options['source'];
    out("\n⚙️  Processing source ID: {$sourceId}...\n", 'cyan');
    try {
        $driveService = new GoogleDriveService();
        $result = $driveService->processSource($sourceId);
        if ($result['success']) {
            out("  ✅ Success: {$result['chunks']} chunks stored", 'green');
        } else {
            out("  ❌ Failed: {$result['error']}", 'red');
        }
    } catch (\Throwable $e) {
        out('  ERROR: ' . $e->getMessage(), 'red');
    }
    exit(0);
}

// ── Full Sync (default) ───────────────────────────────────────────────
out("\n📂 Step 1: Syncing file list from Google Drive...\n", 'cyan');
try {
    $driveService = new GoogleDriveService();
    $syncStats = $driveService->syncFilesFromDrive();
    out("  Processed : {$syncStats['processed']}", 'white');
    out("  New       : {$syncStats['new']}", 'green');
    out("  Updated   : {$syncStats['updated']}", 'yellow');
    out("  Skipped   : {$syncStats['skipped']}", 'white');
    if ($syncStats['errors'] > 0) {
        out("  Errors    : {$syncStats['errors']}", 'red');
    }
} catch (\Throwable $e) {
    out('  ERROR: ' . $e->getMessage(), 'red');
    out('  Make sure GOOGLE_APPLICATION_CREDENTIALS and GOOGLE_DRIVE_FOLDER_ID are set in .env', 'yellow');
    if (!isset($options['sync-only'])) {
        exit(1);
    }
}

if (isset($options['sync-only'])) {
    out("\n✅ Sync-only mode — skipping embedding.", 'green');
    exit(0);
}

// Step 2: Process pending sources
out("\n⚙️  Step 2: Processing pending knowledge sources (embedding)...\n", 'cyan');
try {
    $embedStats = $driveService->processPendingSources();
    out("  Total   : {$embedStats['total']}", 'white');
    out("  Success : {$embedStats['success']}", 'green');
    out("  Failed  : {$embedStats['failed']}", $embedStats['failed'] > 0 ? 'red' : 'white');
} catch (\Throwable $e) {
    out('  ERROR: ' . $e->getMessage(), 'red');
}

separator();
out("\n✅ Sync complete: " . date('Y-m-d H:i:s'), 'green');
out('', 'white');
