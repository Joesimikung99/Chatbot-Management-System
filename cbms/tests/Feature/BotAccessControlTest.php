<?php
/**
 * Feature tests for Bot Access Control
 * Tests: bot_users roles, member management, cross-bot access prevention
 */

namespace Tests\Feature;

use Tests\DatabaseTestCase;
use App\Helpers\Auth;
use App\Helpers\Database;

class BotAccessControlTest extends DatabaseTestCase
{
    private int $botId;

    protected function setUp(): void
    {
        parent::setUp();

        $db = Database::getInstance();
        $this->botId = (int)$db->insert('bots', [
            'slug'       => 'acl-test-' . time(),
            'name'       => 'ACL Test Bot',
            'is_active'  => 1,
            'created_by' => 1,
        ]);
    }

    // ────────────────────────────────────────────────────────────────
    // Bot User Roles
    // ────────────────────────────────────────────────────────────────

    public function test_add_member_with_owner_role(): void
    {
        $db = Database::getInstance();

        $db->insert('bot_users', ['bot_id' => $this->botId, 'user_id' => 1, 'role' => 'owner']);

        $role = $db->fetchColumn(
            'SELECT role FROM bot_users WHERE bot_id = ? AND user_id = ?',
            [$this->botId, 1]
        );
        $this->assertSame('owner', $role);
    }

    public function test_add_member_with_editor_role(): void
    {
        $db = Database::getInstance();

        $db->insert('bot_users', ['bot_id' => $this->botId, 'user_id' => 2, 'role' => 'editor']);

        $role = $db->fetchColumn(
            'SELECT role FROM bot_users WHERE bot_id = ? AND user_id = ?',
            [$this->botId, 2]
        );
        $this->assertSame('editor', $role);
    }

    public function test_add_member_with_viewer_role(): void
    {
        $db = Database::getInstance();

        $db->insert('bot_users', ['bot_id' => $this->botId, 'user_id' => 3, 'role' => 'viewer']);

        $role = $db->fetchColumn(
            'SELECT role FROM bot_users WHERE bot_id = ? AND user_id = ?',
            [$this->botId, 3]
        );
        $this->assertSame('viewer', $role);
    }

    // ────────────────────────────────────────────────────────────────
    // Update Member Role
    // ────────────────────────────────────────────────────────────────

    public function test_update_member_role(): void
    {
        $db = Database::getInstance();

        $db->insert('bot_users', ['bot_id' => $this->botId, 'user_id' => 2, 'role' => 'viewer']);

        // Promote to editor
        $db->update('bot_users', ['role' => 'editor'], ['bot_id' => $this->botId, 'user_id' => 2]);

        $role = $db->fetchColumn(
            'SELECT role FROM bot_users WHERE bot_id = ? AND user_id = ?',
            [$this->botId, 2]
        );
        $this->assertSame('editor', $role);
    }

    // ────────────────────────────────────────────────────────────────
    // Remove Member
    // ────────────────────────────────────────────────────────────────

    public function test_remove_member(): void
    {
        $db = Database::getInstance();

        $db->insert('bot_users', ['bot_id' => $this->botId, 'user_id' => 2, 'role' => 'editor']);

        $deleted = $db->delete('bot_users', ['bot_id' => $this->botId, 'user_id' => 2]);

        $this->assertSame(1, $deleted);
        $this->assertNull(
            $db->fetch('SELECT * FROM bot_users WHERE bot_id = ? AND user_id = ?', [$this->botId, 2])
        );
    }

    // ────────────────────────────────────────────────────────────────
    // Duplicate Member Prevention
    // ────────────────────────────────────────────────────────────────

    public function test_duplicate_member_detected(): void
    {
        $db = Database::getInstance();

        $db->insert('bot_users', ['bot_id' => $this->botId, 'user_id' => 1, 'role' => 'owner']);

        // Mimics bot-edit.php check before insert
        $exists = $db->fetch(
            'SELECT id FROM bot_users WHERE bot_id = ? AND user_id = ?',
            [$this->botId, 1]
        );
        $this->assertNotNull($exists, 'Duplicate member should be detected');
    }

    // ────────────────────────────────────────────────────────────────
    // Super Admin Access (session-only, no DB needed)
    // ────────────────────────────────────────────────────────────────

    public function test_super_admin_can_access_any_bot_without_bot_users(): void
    {
        $this->loginAsSuperAdmin();

        // Super admin bypasses bot_users table check
        $this->assertTrue(Auth::canAccessBot($this->botId));
        $this->assertTrue(Auth::canAccessBot(99999)); // Non-existent bot
    }

    // ────────────────────────────────────────────────────────────────
    // Admin Cannot Access Unassigned Bot
    // ────────────────────────────────────────────────────────────────

    public function test_admin_cannot_access_bot_without_assignment(): void
    {
        $this->loginAsAdmin(2);

        // Admin user 2 has no bot_users entry for this bot
        $this->assertFalse(Auth::canAccessBot($this->botId));
    }

    public function test_admin_can_access_assigned_bot(): void
    {
        $db = Database::getInstance();
        $db->insert('bot_users', ['bot_id' => $this->botId, 'user_id' => 2, 'role' => 'editor']);

        $this->loginAsAdmin(2);
        $this->assertTrue(Auth::canAccessBot($this->botId));
    }
}
