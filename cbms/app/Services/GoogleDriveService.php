<?php
/**
 * Google Drive Service
 * AI Chatbot System — CBMS
 *
 * Handles:
 * - Listing files from a Google Drive folder
 * - Downloading & extracting text from files
 * - Syncing to knowledge_sources table
 * - Triggering EmbeddingService to chunk & embed
 */

namespace App\Services;

use App\Helpers\Database;
use Google\Client as GoogleClient;
use Google\Service\Drive as DriveService;

class GoogleDriveService
{
    private Database $db;
    private EmbeddingService $embedding;
    private LogService $logger;
    private ?DriveService $drive = null;
    private string $folderId;
    private ?int $botId = null;

    public function __construct(?int $botId = null)
    {
        $this->db        = Database::getInstance();
        $this->embedding = new EmbeddingService();
        $this->logger    = new LogService();
        $this->botId     = $botId;

        // Load folder ID from bot_settings if bot is specified
        $this->folderId = '';
        if ($botId) {
            $fid = $this->db->fetchColumn(
                "SELECT setting_value FROM bot_settings WHERE bot_id = ? AND setting_key = 'google_drive_folder_id'",
                [$botId]
            );
            if ($fid) $this->folderId = $fid;
        }
        if (empty($this->folderId)) {
            $this->folderId = $_ENV['GOOGLE_DRIVE_FOLDER_ID'] ?? '';
        }
    }

    // ----------------------------------------------------------------
    // 1. Initialize Google Client
    // ----------------------------------------------------------------

    private function getClient(): DriveService
    {
        if ($this->drive === null) {
            $credPath = $_ENV['GOOGLE_APPLICATION_CREDENTIALS'] ?? '';

            if (!file_exists($credPath)) {
                // Support relative paths from the project root
                if (defined('BASE_PATH') && !preg_match('/^([a-zA-Z]:\\\\|\/)/', $credPath)) {
                    $absolutePath = rtrim(BASE_PATH, '\\/') . '/' . ltrim($credPath, '\\/');
                    if (file_exists($absolutePath)) {
                        $credPath = $absolutePath;
                    }
                }
            }

            if (!file_exists($credPath)) {
                throw new \RuntimeException(
                    'Google credentials file not found: ' . $credPath
                );
            }

            $client = new GoogleClient();
            $client->setAuthConfig($credPath);
            $client->addScope(DriveService::DRIVE_READONLY);
            $client->setApplicationName('AI Chatbot CBMS');

            $this->drive = new DriveService($client);
        }

        return $this->drive;
    }

    // ----------------------------------------------------------------
    // 2. List Files in Folder
    // ----------------------------------------------------------------

    /**
     * List all supported files in the configured Google Drive folder.
     *
     * @return array<int, array{id: string, name: string, mimeType: string, modifiedTime: string}>
     */
    public function listFiles(): array
    {
        $drive = $this->getClient();

        $supportedMimeTypes = [
            'application/vnd.google-apps.document',       // Google Docs
            'application/pdf',
            'text/plain',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document', // DOCX
            'application/vnd.openxmlformats-officedocument.presentationml.presentation', // PPTX
        ];

        $mimeQuery = implode(' or ', array_map(
            fn($m) => "mimeType='{$m}'",
            $supportedMimeTypes
        ));

        $query = "'{$this->folderId}' in parents and ({$mimeQuery}) and trashed=false";

        $results = $drive->files->listFiles([
            'q'       => $query,
            'fields'  => 'files(id,name,mimeType,modifiedTime,webViewLink)',
            'orderBy' => 'name',
        ]);

        return array_map(fn($f) => [
            'id'           => $f->getId(),
            'name'         => $f->getName(),
            'mimeType'     => $f->getMimeType(),
            'modifiedTime' => $f->getModifiedTime(),
            'webViewLink'  => $f->getWebViewLink(),
        ], $results->getFiles());
    }

    // ----------------------------------------------------------------
    // 3. Download & Extract Text
    // ----------------------------------------------------------------

    /**
     * Extract plain text from a Google Drive file.
     *
     * @param  string $fileId    Google Drive file ID
     * @param  string $mimeType  Original file MIME type
     * @return string            Extracted plain text
     */
    public function extractText(string $fileId, string $mimeType, ?string $resourceKey = null): string
    {
        $client = $this->getClient()->getClient();
        
        if ($resourceKey) {
            $guzzle = new \GuzzleHttp\Client([
                'headers' => [
                    'X-Goog-Drive-Resource-Keys' => "{$fileId}/{$resourceKey}"
                ]
            ]);
            $client->setHttpClient($guzzle);
        }

        $drive = new \Google\Service\Drive($client);
        $text = '';

        try {
            if ($mimeType === 'application/vnd.google-apps.document') {
                $response = $drive->files->export($fileId, 'text/plain', ['alt' => 'media']);
                $text = (string)$response->getBody();
            } elseif ($mimeType === 'text/plain') {
                $response = $drive->files->get($fileId, ['alt' => 'media']);
                $text = (string)$response->getBody();
            } elseif ($mimeType === 'application/pdf') {
                $response = $drive->files->export($fileId, 'text/plain', ['alt' => 'media']);
                if ($response) {
                    $text = (string)$response->getBody();
                } else {
                    $response = $drive->files->get($fileId, ['alt' => 'media']);
                    $text = $this->extractPdfText((string)$response->getBody());
                }
            } elseif ($mimeType === 'application/vnd.openxmlformats-officedocument.wordprocessingml.document') {
                $response = $drive->files->get($fileId, ['alt' => 'media']);
                $text = $this->extractDocxText((string)$response->getBody());
            } elseif ($mimeType === 'application/vnd.openxmlformats-officedocument.presentationml.presentation') {
                $response = $drive->files->get($fileId, ['alt' => 'media']);
                $text = $this->extractPptxText((string)$response->getBody());
            }

            return $this->sanitizeUtf8($text);

        } catch (\Throwable $e) {
            $this->logger->logError('GoogleDriveService', 'extractText failed: ' . $e->getMessage(), [
                'file_id' => $fileId,
            ]);
            throw $e;
        }
    }

    // ----------------------------------------------------------------
    // 4. Sync All Files from Drive to DB
    // ----------------------------------------------------------------

    /**
     * Compare Drive files with knowledge_sources and sync:
     * - Insert new files
     * - Update modified files
     * - Deactivate deleted files
     *
     * @return array{processed: int, new: int, updated: int, skipped: int, errors: int}
     */
    public function syncFilesFromDrive(): array
    {
        $stats = ['processed' => 0, 'new' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => 0];

        $driveFiles = $this->listFiles();
        $driveIds   = array_column($driveFiles, 'id');

        // Existing sources keyed by google_drive_file_id.
        // Only real Drive files take part in this sync: wiki pages
        // (source_type='wiki') and Manual Q&A (file_type='manual', which
        // predates the source_type column) have placeholder drive ids that are
        // never in the folder listing — including them here made the
        // deactivation pass below silently kill them on every sync.
        $sql = "SELECT * FROM knowledge_sources
                WHERE source_type = 'drive'
                  AND COALESCE(file_type, '') <> 'manual'";
        $params = [];
        if ($this->botId) {
            $sql     .= ' AND bot_id = ?';
            $params[] = $this->botId;
        }
        $existing    = $this->db->fetchAll($sql, $params);
        $existingMap = array_column($existing, null, 'google_drive_file_id');

        foreach ($driveFiles as $file) {
            $fileId = $file['id'];
            $stats['processed']++;

            $dbRecord = $existingMap[$fileId] ?? null;
            $modifiedTime = $file['modifiedTime']
                ? date('Y-m-d H:i:s', strtotime($file['modifiedTime']))
                : null;

            if (!$dbRecord) {
                // New file
                $this->db->insert('knowledge_sources', [
                    'bot_id'               => $this->botId,
                    'google_drive_file_id' => $fileId,
                    'file_name'            => $file['name'],
                    'file_type'            => $this->getFileType($file['mimeType']),
                    'mime_type'            => $file['mimeType'],
                    'google_drive_url'     => $file['webViewLink'] ?? null,
                    'last_modified'        => $modifiedTime,
                    'sync_status'          => 'pending',
                    'is_active'            => 1,
                ]);
                $stats['new']++;
            } else {
                // Check if file was modified
                if ($modifiedTime && $dbRecord['last_modified'] !== $modifiedTime) {
                    // Update by row id: the same Drive file may exist under
                    // another bot, and a file-id match would overwrite theirs.
                    $this->db->update('knowledge_sources', [
                        'file_name'     => $file['name'],
                        'last_modified' => $modifiedTime,
                        'sync_status'   => 'pending',
                        'is_active'     => 1,
                    ], ['id' => $dbRecord['id']]);
                    $stats['updated']++;
                } else {
                    $stats['skipped']++;
                }
            }
        }

        // Deactivate sources no longer in Drive — only when syncing a specific
        // bot. An unscoped run compares EVERY bot's sources against a single
        // folder, so anything living in another bot's folder would be wrongly
        // deactivated; being conservative here beats losing knowledge.
        if ($this->botId !== null) {
            foreach ($existingMap as $fileId => $record) {
                if (!in_array($fileId, $driveIds, true) && $record['is_active']) {
                    $this->db->update('knowledge_sources',
                        ['is_active' => 0],
                        ['id' => $record['id']]
                    );
                }
            }
        }

        return $stats;
    }

    // ----------------------------------------------------------------
    // 4.5. Add Single File by URL
    // ----------------------------------------------------------------

    /**
     * Extract File ID from Google Drive URL and add to DB
     *
     * @param string $url
     * @return array{success: bool, id?: int, message?: string}
     */
    public function addFileByUrl(string $url): array
    {
        // Extract ID from URL
        if (preg_match('/\/d\/([a-zA-Z0-9_-]{15,})/', $url, $matches)) {
            $fileId = $matches[1];
        } elseif (preg_match('/id=([a-zA-Z0-9_-]{15,})/', $url, $matches)) {
            $fileId = $matches[1];
        } elseif (preg_match('/\/folders\/([a-zA-Z0-9_-]{15,})/', $url, $matches)) {
            $fileId = $matches[1];
        } else {
            return ['success' => false, 'message' => 'ไม่พบ File/Folder ID ในลิงก์ที่ระบุ'];
        }

        // Extract Resource Key if present
        $resourceKey = null;
        if (preg_match('/[?&]resourcekey=([a-zA-Z0-9_-]+)/', $url, $rkMatches)) {
            $resourceKey = $rkMatches[1];
        }

        try {
            $client = $this->getClient()->getClient();
            
            // If resource key is present, inject it into HTTP client headers
            if ($resourceKey) {
                $guzzle = new \GuzzleHttp\Client([
                    'headers' => [
                        'X-Goog-Drive-Resource-Keys' => "{$fileId}/{$resourceKey}"
                    ]
                ]);
                $client->setHttpClient($guzzle);
            }

            $drive = new \Google\Service\Drive($client);
            $file = $drive->files->get($fileId, ['fields' => 'id,name,mimeType,modifiedTime,webViewLink']);
            
            // If it's a folder, fetch all files inside it
            if ($file->getMimeType() === 'application/vnd.google-apps.folder') {
                $supportedMimeTypes = [
                    'application/vnd.google-apps.document',
                    'application/pdf',
                    'text/plain',
                    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                    'application/vnd.openxmlformats-officedocument.presentationml.presentation',
                ];
                $mimeQuery = implode(' or ', array_map(fn($m) => "mimeType='{$m}'", $supportedMimeTypes));
                $query = "'{$fileId}' in parents and ({$mimeQuery}) and trashed=false";
                
                $results = $drive->files->listFiles([
                    'q'       => $query,
                    'fields'  => 'files(id,name,mimeType,modifiedTime,webViewLink)',
                    'orderBy' => 'name',
                ]);
                
                $files = $results->getFiles();
                if (empty($files)) {
                    return ['success' => false, 'message' => 'ไม่พบไฟล์ที่รองรับในโฟลเดอร์นี้'];
                }
                
                $added = 0;
                $updated = 0;
                foreach ($files as $f) {
                    $existing = $this->db->fetch(
                        'SELECT id, last_modified FROM knowledge_sources WHERE google_drive_file_id = ? AND bot_id <=> ?',
                        [$f->getId(), $this->botId]
                    );
                    $modifiedTime = $f->getModifiedTime() ? date('Y-m-d H:i:s', strtotime($f->getModifiedTime())) : null;

                    if (!$existing) {
                        $this->db->insert('knowledge_sources', [
                            'bot_id'               => $this->botId,
                            'google_drive_file_id' => $f->getId(),
                            'file_name'            => $f->getName(),
                            'file_type'            => $this->getFileType($f->getMimeType()),
                            'mime_type'            => $f->getMimeType(),
                            'google_drive_url'     => $f->getWebViewLink(),
                            'last_modified'        => $modifiedTime,
                            'sync_status'          => 'pending',
                            'is_active'            => 1,
                        ]);
                        $added++;
                    } else {
                        // If file exists but was modified on Google Drive, mark as pending to be re-embedded
                        if ($modifiedTime && $existing['last_modified'] !== $modifiedTime) {
                            $this->db->update('knowledge_sources', [
                                'file_name'     => $f->getName(),
                                'last_modified' => $modifiedTime,
                                'sync_status'   => 'pending',
                                'is_active'     => 1,
                            ], ['id' => $existing['id']]);
                            $updated++;
                        }
                    }
                }
                
                $msg = "พบไฟล์ในโฟลเดอร์ เพิ่มใหม่ {$added} ไฟล์";
                if ($updated > 0) $msg .= ", อัปเดตข้อมูล {$updated} ไฟล์";
                return ['success' => true, 'id' => null, 'message' => $msg];
            }

            // Single file logic — duplicate check is per-bot: the same Drive
            // file may legitimately serve another bot's knowledge base.
            $existing = $this->db->fetch(
                'SELECT id FROM knowledge_sources WHERE google_drive_file_id = ? AND bot_id <=> ?',
                [$fileId, $this->botId]
            );
            if ($existing) {
                return ['success' => false, 'message' => 'ไฟล์นี้มีอยู่ในระบบแล้ว'];
            }

            $modifiedTime = $file->getModifiedTime()
                ? date('Y-m-d H:i:s', strtotime($file->getModifiedTime()))
                : null;

            $insertId = $this->db->insert('knowledge_sources', [
                'bot_id'               => $this->botId,
                'google_drive_file_id' => $file->getId(),
                'file_name'            => $file->getName(),
                'file_type'            => $this->getFileType($file->getMimeType()),
                'mime_type'            => $file->getMimeType(),
                'google_drive_url'     => $file->getWebViewLink() ?? $url,
                'last_modified'        => $modifiedTime,
                'sync_status'          => 'pending',
                'is_active'            => 1,
            ]);

            return ['success' => true, 'id' => $insertId, 'message' => 'เพิ่มไฟล์เรียบร้อยแล้ว: ' . $file->getName()];
        } catch (\Throwable $e) {
            $this->logger->logError('GoogleDriveService', 'addFileByUrl failed: ' . $e->getMessage(), ['url' => $url]);
            
            $msg = $e->getMessage();
            if (str_contains($msg, 'Google credentials file not found')) {
                return ['success' => false, 'message' => 'ระบบยังไม่ได้ตั้งค่า Google Service Account (ไม่พบไฟล์ Credentials)'];
            }
            if (str_contains($msg, 'does not exist') || str_contains($msg, 'credentials')) {
                return ['success' => false, 'message' => 'ระบบยังไม่ได้ตั้งค่า Google Service Account ให้ถูกต้อง'];
            }

            return ['success' => false, 'message' => 'ไม่สามารถดึงข้อมูลได้: ' . $msg];
        }
    }

    // ----------------------------------------------------------------
    // 5. Process a Single Source (extract + chunk + embed)
    // ----------------------------------------------------------------

    /**
     * Extract text, chunk, and embed a single knowledge source.
     * Updates sync_status in the DB.
     *
     * @param  int  $sourceId  knowledge_sources.id
     * @return array{success: bool, chunks: int, error?: string}
     */
    public function processSource(int $sourceId): array
    {
        // BUG-15 fix: scope by bot_id to prevent cross-bot processing
        $query  = 'SELECT * FROM knowledge_sources WHERE id = ?';
        $params = [$sourceId];
        if ($this->botId) {
            $query   .= ' AND bot_id = ?';
            $params[] = $this->botId;
        }
        $source = $this->db->fetch($query, $params);
        if (!$source) {
            return ['success' => false, 'chunks' => 0, 'error' => 'Source not found'];
        }

        // Mark as processing
        $this->db->update('knowledge_sources', ['sync_status' => 'processing'], ['id' => $sourceId]);

        try {
            if (($source['file_type'] ?? '') === 'manual') {
                // Manual sources have no external file (their google_drive_file_id
                // is a placeholder) — rebuild from the stored chunk content so
                // "process" re-chunks + re-embeds instead of 404ing on Drive.
                $rows = $this->db->fetchAll(
                    'SELECT content FROM knowledge_chunks WHERE source_id = ? ORDER BY chunk_index',
                    [$sourceId]
                );
                $text = implode("\n\n", array_column($rows, 'content'));
            } else {
                // Extract resourceKey from URL if present
                $resourceKey = null;
                if (!empty($source['google_drive_url']) && preg_match('/[?&]resourcekey=([a-zA-Z0-9_-]+)/', $source['google_drive_url'], $matches)) {
                    $resourceKey = $matches[1];
                }

                $text = $this->extractText($source['google_drive_file_id'], $source['mime_type'] ?? 'text/plain', $resourceKey);
            }

            if (empty(trim($text))) {
                throw new \RuntimeException('No text could be extracted from the file');
            }

            // Chunk the text
            $chunks = $this->embedding->chunkText($text);

            // Store chunks with embeddings
            $storedCount = $this->embedding->storeChunks($sourceId, $chunks, withEmbedding: true);

            // Mark as synced
            $this->db->update('knowledge_sources', [
                'sync_status'   => 'synced',
                'last_synced_at'=> date('Y-m-d H:i:s'),
                'chunk_count'   => $storedCount,
                'error_message' => null,
            ], ['id' => $sourceId]);

            return ['success' => true, 'chunks' => $storedCount];

        } catch (\Throwable $e) {
            $errorMsg = $e->getMessage();

            $this->db->update('knowledge_sources', [
                'sync_status'   => 'error',
                'error_message' => $errorMsg,
            ], ['id' => $sourceId]);

            $this->logger->logError('GoogleDriveService', 'processSource failed', [
                'source_id' => $sourceId,
                'error'     => $errorMsg,
            ]);

            return ['success' => false, 'chunks' => 0, 'error' => $errorMsg];
        }
    }

    // ----------------------------------------------------------------
    // 6. Process All Pending Sources
    // ----------------------------------------------------------------

    /**
     * Process all knowledge sources with sync_status = 'pending'.
     * Used by the CLI sync script.
     *
     * @return array{total: int, success: int, failed: int}
     */
    public function processPendingSources(): array
    {
        $sql = "SELECT id FROM knowledge_sources WHERE sync_status IN ('pending') AND is_active = 1";
        $params = [];
        if ($this->botId) {
            $sql .= ' AND bot_id = ?';
            $params[] = $this->botId;
        }
        $pending = $this->db->fetchAll($sql, $params);

        $stats = ['total' => count($pending), 'success' => 0, 'failed' => 0];

        foreach ($pending as $source) {
            $result = $this->processSource($source['id']);
            $result['success'] ? $stats['success']++ : $stats['failed']++;
        }

        return $stats;
    }

    // ----------------------------------------------------------------
    // Private Helpers
    // ----------------------------------------------------------------

    private function getFileType(string $mimeType): string
    {
        return match (true) {
            str_contains($mimeType, 'google-apps.document') => 'google_doc',
            str_contains($mimeType, 'pdf')                  => 'pdf',
            str_contains($mimeType, 'wordprocessingml')      => 'docx',
            str_contains($mimeType, 'presentationml')        => 'pptx',
            str_contains($mimeType, 'text/plain')            => 'txt',
            default                                          => 'unknown',
        };
    }

    private function extractPdfText(string $pdfContent): string
    {
        if (!class_exists(\Smalot\PdfParser\Parser::class)) {
            $this->logger->logError('GoogleDriveService', 'smalot/pdfparser not installed — falling back to basic extraction');
            $text = preg_replace('/[^\x20-\x7E\x0A\x0D\x09\xE0-\xFF]/', ' ', $pdfContent);
            $text = preg_replace('/\s+/', ' ', $text);
            return trim($text);
        }

        try {
            $parser = new \Smalot\PdfParser\Parser();
            $pdf    = $parser->parseContent($pdfContent);
            return trim($pdf->getText());
        } catch (\Throwable $e) {
            $this->logger->logError('GoogleDriveService', 'PDF parse failed: ' . $e->getMessage());
            return '';
        }
    }

    private function extractDocxText(string $docxContent): string
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'docx');
        file_put_contents($tmpFile, $docxContent);
        
        $text = '';
        $zip = new \ZipArchive();
        if ($zip->open($tmpFile) === true) {
            if (($index = $zip->locateName('word/document.xml')) !== false) {
                $xml = $zip->getFromIndex($index);
                $text = strip_tags(str_replace(['<w:p', '</w:p>'], ["\n<w:p", "\n"], $xml));
            }
            $zip->close();
        }
        unlink($tmpFile);
        
        return trim($text);
    }

    private function extractPptxText(string $pptxContent): string
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'pptx');
        file_put_contents($tmpFile, $pptxContent);
        
        $text = '';
        $zip = new \ZipArchive();
        if ($zip->open($tmpFile) === true) {
            for ($i = 1; $i <= 200; $i++) {
                if (($index = $zip->locateName("ppt/slides/slide{$i}.xml")) !== false) {
                    $xml = $zip->getFromIndex($index);
                    $text .= strip_tags(str_replace(['<a:p', '</a:p>'], ["\n<a:p", "\n"], $xml)) . "\n\n";
                }
            }
            $zip->close();
        }
        unlink($tmpFile);
        
        return trim($text);
    }

    private function sanitizeUtf8(string $text): string
    {
        // 1. Force strict UTF-8 using PHP's native mb_scrub (replaces invalid bytes with '?')
        if (function_exists('mb_scrub')) {
            $text = mb_scrub($text, 'UTF-8');
        } else {
            $cleaned = @iconv('UTF-8', 'UTF-8//IGNORE', $text);
            if ($cleaned !== false) {
                $text = $cleaned;
            }
        }
        
        // 2. Remove null bytes and standard control characters (except newline/tab)
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $text);
        
        return (string)$text;
    }
}
