<?php

namespace Tests;

use PHPUnit\Framework\TestCase as BaseTestCase;
use App\Helpers\Auth;

abstract class TestCase extends BaseTestCase
{
    /**
     * Simulate a logged-in super admin session.
     */
    protected function loginAsSuperAdmin(int $id = 1): void
    {
        $this->startSession();
        $_SESSION['admin_user'] = [
            'id'            => $id,
            'email'         => 'superadmin@test.local',
            'display_name'  => 'Super Admin',
            'role'          => 'super_admin',
            'permissions'   => [],
            'auth_provider' => 'local',
            'avatar_url'    => null,
            'login_at'      => time(),
        ];
    }

    /**
     * Simulate a logged-in admin session with specific permissions.
     */
    protected function loginAsAdmin(int $id = 2, array $permissions = []): void
    {
        $this->startSession();
        $defaultPerms = [
            'view_dashboard'     => true,
            'view_conversations' => true,
            'view_analytics'     => true,
            'view_token_usage'   => true,
            'manage_knowledge'   => true,
            'manage_bots'        => true,
        ];
        $_SESSION['admin_user'] = [
            'id'            => $id,
            'email'         => 'admin@test.local',
            'display_name'  => 'Test Admin',
            'role'          => 'admin',
            'permissions'   => array_merge($defaultPerms, $permissions),
            'auth_provider' => 'local',
            'avatar_url'    => null,
            'login_at'      => time(),
        ];
    }

    /**
     * Simulate a viewer session (limited permissions).
     */
    protected function loginAsViewer(int $id = 3): void
    {
        $this->startSession();
        $_SESSION['admin_user'] = [
            'id'            => $id,
            'email'         => 'viewer@test.local',
            'display_name'  => 'Test Viewer',
            'role'          => 'viewer',
            'permissions'   => [
                'view_dashboard'     => true,
                'view_conversations' => true,
            ],
            'auth_provider' => 'local',
            'avatar_url'    => null,
            'login_at'      => time(),
        ];
    }

    /**
     * Set active bot in session.
     */
    protected function setActiveBot(int $botId): void
    {
        $this->startSession();
        $_SESSION['active_bot_id'] = $botId;
    }

    /**
     * Clear session (simulate logout).
     */
    protected function clearSession(): void
    {
        $this->startSession();
        $_SESSION = [];
    }

    /**
     * Start session if not started.
     */
    protected function startSession(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            // Use a temporary session handler for tests
            ini_set('session.use_cookies', '0');
            ini_set('session.use_only_cookies', '0');
            ini_set('session.cache_limiter', '');
            session_start();
        }
    }

    /**
     * Set up CSRF token and return it.
     */
    protected function setupCsrf(): string
    {
        $this->startSession();
        $token = bin2hex(random_bytes(32));
        $_SESSION['csrf_token'] = $token;
        return $token;
    }

    /**
     * Simulate POST request globals.
     */
    protected function simulatePost(array $data = [], ?string $csrfToken = null): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = $data;
        if ($csrfToken !== null) {
            $_POST['_csrf_token'] = $csrfToken;
        }
    }

    /**
     * Simulate GET request globals.
     */
    protected function simulateGet(array $data = []): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_GET = $data;
    }

    protected function tearDown(): void
    {
        // Clean up superglobals
        $_POST = [];
        $_GET = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';

        // Clean session
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION = [];
        }

        parent::tearDown();
    }
}
