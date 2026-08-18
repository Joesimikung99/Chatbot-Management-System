<?php
/**
 * Feature tests for Switch Bot functionality
 * Tests: switch-bot.php endpoint logic, session state
 * Bug regression: sidebar expanding wrong bot, variable collision
 */

namespace Tests\Feature;

use Tests\DatabaseTestCase;
use App\Helpers\Auth;
use App\Helpers\Database;

class SwitchBotTest extends DatabaseTestCase
{
    private int $bot1Id;
    private int $bot2Id;

    protected function setUp(): void
    {
        parent::setUp();

        $db = Database::getInstance();

        $this->bot1Id = (int)$db->insert('bots', [
            'slug' => 'switch-bot1-' . time(),
            'name' => 'Switch Bot 1',
            'is_active' => 1,
            'created_by' => 1,
        ]);

        $this->bot2Id = (int)$db->insert('bots', [
            'slug' => 'switch-bot2-' . time(),
            'name' => 'Switch Bot 2',
            'is_active' => 1,
            'created_by' => 1,
        ]);
    }

    // ────────────────────────────────────────────────────────────────
    // setActiveBot()
    // ────────────────────────────────────────────────────────────────

    public function test_switch_bot_updates_session(): void
    {
        $this->loginAsSuperAdmin();

        $this->assertTrue(Auth::setActiveBot($this->bot1Id));
        $this->assertSame($this->bot1Id, Auth::activeBotId());

        $this->assertTrue(Auth::setActiveBot($this->bot2Id));
        $this->assertSame($this->bot2Id, Auth::activeBotId());
    }

    public function test_switch_bot_rejects_unauthorized_bot(): void
    {
        $this->loginAsAdmin(2);

        // Admin has no access to these bots
        $this->assertFalse(Auth::setActiveBot($this->bot1Id));
        $this->assertNull(Auth::activeBotId()); // unchanged
    }

    public function test_switch_bot_allows_authorized_admin(): void
    {
        $db = Database::getInstance();
        $db->insert('bot_users', ['bot_id' => $this->bot1Id, 'user_id' => 2, 'role' => 'editor']);

        $this->loginAsAdmin(2);

        $this->assertTrue(Auth::setActiveBot($this->bot1Id));
        $this->assertSame($this->bot1Id, Auth::activeBotId());

        // But cannot switch to bot2 (no access)
        $this->assertFalse(Auth::setActiveBot($this->bot2Id));
        $this->assertSame($this->bot1Id, Auth::activeBotId()); // remains on bot1
    }

    // ────────────────────────────────────────────────────────────────
    // requireBot() — auto-select first bot
    // ────────────────────────────────────────────────────────────────

    public function test_requireBot_auto_selects_first_bot_for_super_admin(): void
    {
        $this->loginAsSuperAdmin();
        // No active bot set
        unset($_SESSION['active_bot_id']);

        $selectedId = Auth::requireBot();

        $this->assertGreaterThan(0, $selectedId);
        $this->assertSame($selectedId, Auth::activeBotId());
    }

    // ────────────────────────────────────────────────────────────────
    // activeBot() — cached record
    // ────────────────────────────────────────────────────────────────

    public function test_activeBot_returns_full_record(): void
    {
        $this->loginAsSuperAdmin();
        Auth::setActiveBot($this->bot1Id);

        $bot = Auth::activeBot();

        $this->assertIsArray($bot);
        $this->assertSame($this->bot1Id, (int)$bot['id']);
        $this->assertSame('Switch Bot 1', $bot['name']);
    }

    public function test_activeBot_returns_null_when_no_bot_set(): void
    {
        $this->loginAsSuperAdmin();
        unset($_SESSION['active_bot_id']);

        $this->assertNull(Auth::activeBot());
    }
}
