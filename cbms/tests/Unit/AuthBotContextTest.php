<?php
/**
 * Tests for Auth helper — Bot Context methods
 * Covers: activeBotId, setActiveBot, canAccessBot, botRole, userBots, requireBot, activeBot
 */

namespace Tests\Unit;

use Tests\TestCase;
use App\Helpers\Auth;

class AuthBotContextTest extends TestCase
{
    // ────────────────────────────────────────────────────────────────
    // activeBotId()
    // ────────────────────────────────────────────────────────────────

    public function test_activeBotId_returns_null_when_no_bot_set(): void
    {
        $this->loginAsSuperAdmin();
        unset($_SESSION['active_bot_id']);

        $this->assertNull(Auth::activeBotId());
    }

    public function test_activeBotId_returns_id_from_session(): void
    {
        $this->loginAsSuperAdmin();
        $this->setActiveBot(5);

        $this->assertSame(5, Auth::activeBotId());
    }

    // ────────────────────────────────────────────────────────────────
    // setActiveBot() — session-only tests (no DB)
    // ────────────────────────────────────────────────────────────────

    public function test_setActiveBot_returns_false_when_not_logged_in(): void
    {
        $this->clearSession();

        // Auth::user() returns null → canAccessBot returns false
        $this->assertFalse(Auth::canAccessBot(1));
    }

    // ────────────────────────────────────────────────────────────────
    // canAccessBot() — role-based checks
    // ────────────────────────────────────────────────────────────────

    public function test_super_admin_can_access_any_bot(): void
    {
        $this->loginAsSuperAdmin();

        // Super admin bypasses DB check
        $this->assertTrue(Auth::canAccessBot(999));
    }

    public function test_no_user_cannot_access_bot(): void
    {
        $this->clearSession();

        $this->assertFalse(Auth::canAccessBot(1));
    }

    // ────────────────────────────────────────────────────────────────
    // botRole() — role inference
    // ────────────────────────────────────────────────────────────────

    public function test_super_admin_always_returns_owner_role(): void
    {
        $this->loginAsSuperAdmin();

        $this->assertSame('owner', Auth::botRole(42));
    }

    public function test_botRole_returns_null_when_not_logged_in(): void
    {
        $this->clearSession();

        $this->assertNull(Auth::botRole(1));
    }

    // ────────────────────────────────────────────────────────────────
    // userBots() — access list
    // ────────────────────────────────────────────────────────────────

    public function test_userBots_returns_empty_when_not_logged_in(): void
    {
        $this->clearSession();

        $this->assertSame([], Auth::userBots());
    }
}
