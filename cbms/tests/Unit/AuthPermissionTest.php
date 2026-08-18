<?php
/**
 * Tests for Auth helper — Permission & Role methods
 * Covers: can, isSuperAdmin, role, user, get, check
 */

namespace Tests\Unit;

use Tests\TestCase;
use App\Helpers\Auth;

class AuthPermissionTest extends TestCase
{
    // ────────────────────────────────────────────────────────────────
    // can()
    // ────────────────────────────────────────────────────────────────

    public function test_super_admin_can_do_everything(): void
    {
        $this->loginAsSuperAdmin();

        $this->assertTrue(Auth::can('manage_models'));
        $this->assertTrue(Auth::can('manage_users'));
        $this->assertTrue(Auth::can('manage_settings'));
        $this->assertTrue(Auth::can('manage_bots'));
        $this->assertTrue(Auth::can('nonexistent_permission'));
    }

    public function test_admin_can_only_do_assigned_permissions(): void
    {
        $this->loginAsAdmin(2, [
            'view_dashboard'   => true,
            'manage_knowledge' => true,
            'manage_models'    => false,
        ]);

        $this->assertTrue(Auth::can('view_dashboard'));
        $this->assertTrue(Auth::can('manage_knowledge'));
        $this->assertFalse(Auth::can('manage_models'));
        $this->assertFalse(Auth::can('nonexistent_permission'));
    }

    public function test_can_returns_false_when_not_logged_in(): void
    {
        $this->clearSession();

        $this->assertFalse(Auth::can('view_dashboard'));
    }

    public function test_viewer_has_limited_permissions(): void
    {
        $this->loginAsViewer();

        $this->assertTrue(Auth::can('view_dashboard'));
        $this->assertTrue(Auth::can('view_conversations'));
        $this->assertFalse(Auth::can('manage_bots'));
        $this->assertFalse(Auth::can('manage_settings'));
    }

    // ────────────────────────────────────────────────────────────────
    // isSuperAdmin()
    // ────────────────────────────────────────────────────────────────

    public function test_isSuperAdmin_true_for_super_admin(): void
    {
        $this->loginAsSuperAdmin();
        $this->assertTrue(Auth::isSuperAdmin());
    }

    public function test_isSuperAdmin_false_for_admin(): void
    {
        $this->loginAsAdmin();
        $this->assertFalse(Auth::isSuperAdmin());
    }

    public function test_isSuperAdmin_false_for_viewer(): void
    {
        $this->loginAsViewer();
        $this->assertFalse(Auth::isSuperAdmin());
    }

    // ────────────────────────────────────────────────────────────────
    // role()
    // ────────────────────────────────────────────────────────────────

    public function test_role_returns_correct_value(): void
    {
        $this->loginAsSuperAdmin();
        $this->assertSame('super_admin', Auth::role());

        $this->loginAsAdmin();
        $this->assertSame('admin', Auth::role());

        $this->loginAsViewer();
        $this->assertSame('viewer', Auth::role());
    }

    public function test_role_returns_viewer_when_not_logged_in(): void
    {
        $this->clearSession();
        $this->assertSame('viewer', Auth::role());
    }

    // ────────────────────────────────────────────────────────────────
    // user(), get(), check()
    // ────────────────────────────────────────────────────────────────

    public function test_user_returns_array_when_logged_in(): void
    {
        $this->loginAsSuperAdmin(1);

        $user = Auth::user();
        $this->assertIsArray($user);
        $this->assertSame(1, $user['id']);
        $this->assertSame('super_admin', $user['role']);
    }

    public function test_user_returns_null_when_not_logged_in(): void
    {
        $this->clearSession();
        $this->assertNull(Auth::user());
    }

    public function test_get_returns_specific_field(): void
    {
        $this->loginAsAdmin(42);

        $this->assertSame(42, Auth::get('id'));
        $this->assertSame('admin@test.local', Auth::get('email'));
        $this->assertSame('default-value', Auth::get('nonexistent', 'default-value'));
    }

    public function test_check_returns_true_when_logged_in(): void
    {
        $this->loginAsAdmin();
        $this->assertTrue(Auth::check());
    }

    public function test_check_returns_false_when_not_logged_in(): void
    {
        $this->clearSession();
        $this->assertFalse(Auth::check());
    }

    // ────────────────────────────────────────────────────────────────
    // Permissions as JSON string (edge case)
    // ────────────────────────────────────────────────────────────────

    public function test_can_handles_permissions_as_json_string(): void
    {
        $this->startSession();
        $_SESSION['admin_user'] = [
            'id'            => 10,
            'email'         => 'jsonperms@test.local',
            'display_name'  => 'JSON Perms User',
            'role'          => 'admin',
            'permissions'   => '{"view_dashboard":true,"manage_bots":false}',
            'auth_provider' => 'local',
            'avatar_url'    => null,
            'login_at'      => time(),
        ];

        $this->assertTrue(Auth::can('view_dashboard'));
        $this->assertFalse(Auth::can('manage_bots'));
    }
}
