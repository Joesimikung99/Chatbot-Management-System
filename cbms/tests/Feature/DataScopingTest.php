<?php
/**
 * Feature tests for Data Scoping (bot_id isolation)
 * Tests: conversations, knowledge_sources, messages scope by bot_id
 * Bug regression: pages showing data from wrong bot
 */

namespace Tests\Feature;

use Tests\DatabaseTestCase;
use App\Helpers\Database;

class DataScopingTest extends DatabaseTestCase
{
    private int $bot1Id;
    private int $bot2Id;

    protected function setUp(): void
    {
        parent::setUp();
        $db = Database::getInstance();

        $this->bot1Id = (int)$db->insert('bots', [
            'slug' => 'scope-bot1-' . time(),
            'name' => 'Scope Bot 1',
            'is_active' => 1,
            'created_by' => 1,
        ]);
        $this->bot2Id = (int)$db->insert('bots', [
            'slug' => 'scope-bot2-' . time(),
            'name' => 'Scope Bot 2',
            'is_active' => 1,
            'created_by' => 1,
        ]);
    }

    // ────────────────────────────────────────────────────────────────
    // Conversations Scoping
    // ────────────────────────────────────────────────────────────────

    public function test_conversations_scoped_by_bot_id(): void
    {
        $db = Database::getInstance();

        // Insert conversations for each bot
        $db->insert('conversations', [
            'bot_id'     => $this->bot1Id,
            'session_id' => 'sess-bot1-001',
            'platform'   => 'web',
        ]);
        $db->insert('conversations', [
            'bot_id'     => $this->bot1Id,
            'session_id' => 'sess-bot1-002',
            'platform'   => 'web',
        ]);
        $db->insert('conversations', [
            'bot_id'     => $this->bot2Id,
            'session_id' => 'sess-bot2-001',
            'platform'   => 'facebook',
        ]);

        // Query scoped to bot1
        $bot1Count = (int)$db->fetchColumn(
            'SELECT COUNT(*) FROM conversations WHERE bot_id = ?',
            [$this->bot1Id]
        );
        $this->assertSame(2, $bot1Count);

        // Query scoped to bot2
        $bot2Count = (int)$db->fetchColumn(
            'SELECT COUNT(*) FROM conversations WHERE bot_id = ?',
            [$this->bot2Id]
        );
        $this->assertSame(1, $bot2Count);
    }

    // ────────────────────────────────────────────────────────────────
    // Knowledge Sources Scoping
    // ────────────────────────────────────────────────────────────────

    public function test_knowledge_sources_scoped_by_bot_id(): void
    {
        $db = Database::getInstance();

        $db->insert('knowledge_sources', [
            'bot_id'      => $this->bot1Id,
            'source_type' => 'file',
            'name'        => 'bot1-doc.pdf',
            'status'      => 'ready',
        ]);
        $db->insert('knowledge_sources', [
            'bot_id'      => $this->bot2Id,
            'source_type' => 'file',
            'name'        => 'bot2-doc.pdf',
            'status'      => 'ready',
        ]);

        $bot1Sources = $db->fetchAll(
            'SELECT * FROM knowledge_sources WHERE bot_id = ?',
            [$this->bot1Id]
        );
        $bot2Sources = $db->fetchAll(
            'SELECT * FROM knowledge_sources WHERE bot_id = ?',
            [$this->bot2Id]
        );

        $this->assertCount(1, $bot1Sources);
        $this->assertCount(1, $bot2Sources);
        $this->assertSame('bot1-doc.pdf', $bot1Sources[0]['name']);
        $this->assertSame('bot2-doc.pdf', $bot2Sources[0]['name']);
    }

    // ────────────────────────────────────────────────────────────────
    // Unanswered (Missed) Messages Scoping
    // ────────────────────────────────────────────────────────────────

    public function test_unanswered_messages_scoped_by_bot_id(): void
    {
        $db = Database::getInstance();

        // Create conversations
        $conv1Id = $db->insert('conversations', [
            'bot_id' => $this->bot1Id, 'session_id' => 'missed-s1', 'platform' => 'web',
        ]);
        $conv2Id = $db->insert('conversations', [
            'bot_id' => $this->bot2Id, 'session_id' => 'missed-s2', 'platform' => 'web',
        ]);

        // Create fallback messages
        $db->insert('messages', [
            'conversation_id' => $conv1Id,
            'role'            => 'assistant',
            'content'         => 'Fallback for bot1',
            'is_fallback'     => 1,
            'is_reviewed'     => 0,
        ]);
        $db->insert('messages', [
            'conversation_id' => $conv1Id,
            'role'            => 'assistant',
            'content'         => 'Another fallback for bot1',
            'is_fallback'     => 1,
            'is_reviewed'     => 0,
        ]);
        $db->insert('messages', [
            'conversation_id' => $conv2Id,
            'role'            => 'assistant',
            'content'         => 'Fallback for bot2',
            'is_fallback'     => 1,
            'is_reviewed'     => 0,
        ]);

        // Count unanswered for bot1 (same query as missed.php)
        $bot1Unanswered = (int)$db->fetchColumn(
            "SELECT COUNT(*) FROM messages m
             JOIN conversations c ON c.id = m.conversation_id
             WHERE m.is_fallback = 1 AND m.is_reviewed = 0 AND m.role = 'assistant' AND c.bot_id = ?",
            [$this->bot1Id]
        );
        $this->assertSame(2, $bot1Unanswered);

        $bot2Unanswered = (int)$db->fetchColumn(
            "SELECT COUNT(*) FROM messages m
             JOIN conversations c ON c.id = m.conversation_id
             WHERE m.is_fallback = 1 AND m.is_reviewed = 0 AND m.role = 'assistant' AND c.bot_id = ?",
            [$this->bot2Id]
        );
        $this->assertSame(1, $bot2Unanswered);
    }

    // ────────────────────────────────────────────────────────────────
    // Mark Reviewed — Bot Scope
    // ────────────────────────────────────────────────────────────────

    public function test_mark_all_reviewed_only_affects_current_bot(): void
    {
        $db = Database::getInstance();

        $conv1Id = $db->insert('conversations', [
            'bot_id' => $this->bot1Id, 'session_id' => 'mark-s1', 'platform' => 'web',
        ]);
        $conv2Id = $db->insert('conversations', [
            'bot_id' => $this->bot2Id, 'session_id' => 'mark-s2', 'platform' => 'web',
        ]);

        $db->insert('messages', [
            'conversation_id' => $conv1Id, 'role' => 'assistant',
            'content' => 'Bot1 fallback', 'is_fallback' => 1, 'is_reviewed' => 0,
        ]);
        $db->insert('messages', [
            'conversation_id' => $conv2Id, 'role' => 'assistant',
            'content' => 'Bot2 fallback', 'is_fallback' => 1, 'is_reviewed' => 0,
        ]);

        // Mark all reviewed for bot1 only (mimics missed.php mark_all_reviewed)
        $db->query(
            "UPDATE messages m JOIN conversations c ON c.id = m.conversation_id
             SET m.is_reviewed = 1
             WHERE m.is_fallback = 1 AND m.is_reviewed = 0 AND m.role = 'assistant' AND c.bot_id = ?",
            [$this->bot1Id]
        );

        // Bot1 should have 0 unanswered
        $bot1Remaining = (int)$db->fetchColumn(
            "SELECT COUNT(*) FROM messages m JOIN conversations c ON c.id = m.conversation_id
             WHERE m.is_fallback = 1 AND m.is_reviewed = 0 AND m.role = 'assistant' AND c.bot_id = ?",
            [$this->bot1Id]
        );
        $this->assertSame(0, $bot1Remaining);

        // Bot2 should still have 1 unanswered
        $bot2Remaining = (int)$db->fetchColumn(
            "SELECT COUNT(*) FROM messages m JOIN conversations c ON c.id = m.conversation_id
             WHERE m.is_fallback = 1 AND m.is_reviewed = 0 AND m.role = 'assistant' AND c.bot_id = ?",
            [$this->bot2Id]
        );
        $this->assertSame(1, $bot2Remaining);
    }
}
