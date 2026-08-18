<?php
/**
 * Auth Helper — Session & Permission Management
 * AI Chatbot System — CBMS
 *
 * Usage:
 *   Auth::requireLogin();                  // Redirect to login if not logged in
 *   Auth::requirePermission('manage_models');
 *   Auth::user();                          // Get current user array
 *   Auth::can('export_data');              // Check permission
 *   Auth::login($user);                   // Create session after login
 *   Auth::logout();                       // Destroy session
 */

namespace App\Helpers;

class Auth
{
    private static bool $sessionStarted = false;

    // ----------------------------------------------------------------
    // Session Bootstrap
    // ----------------------------------------------------------------

    public static function startSession(): void
    {
        if (static::$sessionStarted || session_status() === PHP_SESSION_ACTIVE) {
            static::$sessionStarted = true;
            return;
        }

        $name     = $_ENV['ADMIN_SESSION_NAME']     ?? 'cbms_chatbot_admin';
        $lifetime = (int)($_ENV['ADMIN_SESSION_LIFETIME'] ?? 86400);
        // Detect HTTPS even when TLS is terminated at a reverse proxy / ARR
        // (IIS behind a load balancer leaves $_SERVER['HTTPS'] unset).
        $isHttps  = (isset($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
                 || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
                 || (($_SERVER['SERVER_PORT'] ?? '') === '443');

        session_name($name);
        session_set_cookie_params([
            'lifetime' => $lifetime,
            'path'     => '/',
            'domain'   => '',
            'secure'   => $isHttps,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        static::$sessionStarted = true;
    }

    // ----------------------------------------------------------------
    // Authentication Checks
    // ----------------------------------------------------------------

    /**
     * Require the admin to be logged in.
     * Redirects to /admin/login.php if not authenticated.
     */
    public static function requireLogin(): void
    {
        static::startSession();

        if (empty($_SESSION['admin_user'])) {
            $loginUrl = static::getAdminBaseUrl() . '/admin/login.php'
                . '?redirect=' . urlencode($_SERVER['REQUEST_URI'] ?? '');
            header('Location: ' . $loginUrl);
            exit;
        }

        // Check session validity / expiry
        $loginAt  = $_SESSION['admin_user']['login_at']       ?? 0;
        $lifetime = (int)($_ENV['ADMIN_SESSION_LIFETIME']     ?? 86400);
        if ($loginAt && (time() - $loginAt) > $lifetime) {
            static::logout();
            header('Location: ' . static::getAdminBaseUrl() . '/admin/login.php?reason=expired');
            exit;
        }
    }

    /**
     * Require a specific permission.
     * Calls requireLogin() first, then checks the permission.
     */
    public static function requirePermission(string $permission): void
    {
        static::requireLogin();

        if (!static::can($permission)) {
            http_response_code(403);
            include dirname(__DIR__, 2) . '/public/admin/403.php';
            exit;
        }
    }

    /**
     * Require minimum role.
     * 'viewer' < 'admin' < 'super_admin'
     */
    public static function requireRole(string $minimumRole): void
    {
        static::requireLogin();

        $hierarchy = ['viewer' => 0, 'admin' => 1, 'super_admin' => 2];
        $current   = $hierarchy[static::user()['role'] ?? 'viewer'] ?? 0;
        $required  = $hierarchy[$minimumRole] ?? 0;

        if ($current < $required) {
            http_response_code(403);
            include dirname(__DIR__, 2) . '/public/admin/403.php';
            exit;
        }
    }

    // ----------------------------------------------------------------
    // Session Access
    // ----------------------------------------------------------------

    /**
     * Get the current logged-in admin user array, or null.
     *
     * @return array{
     *   id: int,
     *   email: string,
     *   display_name: string,
     *   role: string,
     *   permissions: array,
     *   auth_provider: string,
     *   avatar_url: string|null,
     *   login_at: int
     * }|null
     */
    public static function user(): ?array
    {
        static::startSession();
        return $_SESSION['admin_user'] ?? null;
    }

    /**
     * Get a specific field from the current user.
     */
    public static function get(string $field, mixed $default = null): mixed
    {
        $user = static::user();
        return $user[$field] ?? $default;
    }

    /**
     * Get the current user's role.
     */
    public static function role(): string
    {
        return static::get('role', 'viewer');
    }

    /**
     * Check if the current user has a specific permission.
     */
    public static function can(string $permission): bool
    {
        $user = static::user();
        if (!$user) {
            return false;
        }

        // Super admin can do everything
        if ($user['role'] === 'super_admin') {
            return true;
        }

        $permissions = $user['permissions'] ?? [];

        // Decode JSON string if needed
        if (is_string($permissions)) {
            $permissions = json_decode($permissions, true) ?? [];
        }

        return (bool)($permissions[$permission] ?? false);
    }

    /**
     * Check if user is authenticated.
     */
    public static function check(): bool
    {
        static::startSession();
        return !empty($_SESSION['admin_user']);
    }

    /**
     * Check if user is a super admin.
     */
    public static function isSuperAdmin(): bool
    {
        return static::role() === 'super_admin';
    }

    // ----------------------------------------------------------------
    // Login / Logout
    // ----------------------------------------------------------------

    /**
     * Create a session for the given admin user.
     * Regenerates session ID to prevent session fixation.
     *
     * @param array $user  admin_users DB record
     */
    public static function login(array $user): void
    {
        static::startSession();

        // Regenerate session ID (prevent session fixation)
        session_regenerate_id(true);

        // Decode permissions
        $permissions = $user['permissions'] ?? '{}';
        if (is_string($permissions)) {
            $permissions = json_decode($permissions, true) ?? [];
        }

        $_SESSION['admin_user'] = [
            'id'            => (int)$user['id'],
            'email'         => $user['email'],
            'display_name'  => $user['display_name'],
            'role'          => $user['role'],
            'permissions'   => $permissions,
            'auth_provider' => $user['auth_provider'],
            'avatar_url'    => $user['avatar_url'] ?? null,
            'login_at'      => time(),
            // CAMS info (if available)
            'dept_name'     => $user['cams_dept_name']   ?? null,
            'dept_code'     => $user['cams_dept_code']   ?? null,
            'level_value'   => $user['cams_level_value'] ?? null,
        ];

        // Update last login in DB
        try {
            $db = Database::getInstance();
            $db->update('admin_users', [
                'last_login_at' => date('Y-m-d H:i:s'),
                'last_login_ip' => static::getClientIp(),
            ], ['id' => $user['id']]);
        } catch (\Throwable) {
            // Non-fatal
        }
    }

    /**
     * Destroy the current admin session.
     */
    public static function logout(): void
    {
        static::startSession();
        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(), '', time() - 42000,
                $params['path'], $params['domain'],
                $params['secure'], $params['httponly']
            );
        }

        session_destroy();
        static::$sessionStarted = false;
    }

    // ----------------------------------------------------------------
    // Bot Context
    // ----------------------------------------------------------------

    /**
     * Get active bot ID from session.
     */
    public static function activeBotId(): ?int
    {
        static::startSession();
        return $_SESSION['active_bot_id'] ?? null;
    }

    /**
     * Set the active bot (after verifying access).
     */
    public static function setActiveBot(int $botId): bool
    {
        if (!static::canAccessBot($botId)) {
            return false;
        }
        static::startSession();
        $_SESSION['active_bot_id'] = $botId;
        return true;
    }

    /**
     * Check if the current user can access a specific bot.
     */
    public static function canAccessBot(int $botId): bool
    {
        $user = static::user();
        if (!$user) return false;
        if ($user['role'] === 'super_admin') return true;

        $db = Database::getInstance();
        return (bool)$db->fetch(
            'SELECT 1 FROM bot_users WHERE bot_id = ? AND user_id = ?',
            [$botId, $user['id']]
        );
    }

    /**
     * Get the user's role for a specific bot (owner/editor/viewer or null).
     */
    public static function botRole(int $botId): ?string
    {
        $user = static::user();
        if (!$user) return null;
        if ($user['role'] === 'super_admin') return 'owner';

        $db = Database::getInstance();
        return $db->fetchColumn(
            'SELECT role FROM bot_users WHERE bot_id = ? AND user_id = ?',
            [$botId, $user['id']]
        ) ?: null;
    }

    /**
     * Get all bots accessible to the current user.
     */
    public static function userBots(): array
    {
        $user = static::user();
        if (!$user) return [];

        $db = Database::getInstance();

        if ($user['role'] === 'super_admin') {
            return $db->fetchAll(
                'SELECT b.*, NULL as bot_role FROM bots b ORDER BY b.name'
            );
        }

        return $db->fetchAll(
            'SELECT b.*, bu.role as bot_role
             FROM bots b
             JOIN bot_users bu ON bu.bot_id = b.id
             WHERE bu.user_id = ? AND b.is_active = 1
             ORDER BY b.name',
            [$user['id']]
        );
    }

    /**
     * Require an active bot to be selected.
     * Auto-selects the first available bot if none is set.
     * Returns the active bot ID.
     */
    public static function requireBot(): int
    {
        static::requireLogin();

        $botId = static::activeBotId();

        if ($botId && static::canAccessBot($botId)) {
            return $botId;
        }

        $bots = static::userBots();
        if (!empty($bots)) {
            $botId = (int)$bots[0]['id'];
            static::setActiveBot($botId);
            return $botId;
        }

        http_response_code(403);
        echo '<div style="text-align:center;margin-top:80px;font-family:sans-serif;">';
        echo '<h2>ไม่มีบอทที่สามารถเข้าถึงได้</h2>';
        echo '<p>กรุณาติดต่อ Super Admin เพื่อให้สิทธิ์เข้าถึงบอท</p>';
        echo '</div>';
        exit;
    }

    /**
     * Get the active bot record (cached per request).
     */
    public static function activeBot(): ?array
    {
        $botId = static::activeBotId();
        if (!$botId) return null;

        static $cache = [];
        if (isset($cache[$botId])) return $cache[$botId];

        $db = Database::getInstance();
        $cache[$botId] = $db->fetch('SELECT * FROM bots WHERE id = ?', [$botId]);
        return $cache[$botId];
    }

    // ----------------------------------------------------------------
    // CSRF Token
    // ----------------------------------------------------------------

    /**
     * Generate and store a CSRF token in session.
     */
    public static function csrfToken(): string
    {
        static::startSession();
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    /**
     * Validate a submitted CSRF token.
     * Call on any POST form that modifies state.
     */
    public static function validateCsrf(?string $token = null): bool
    {
        static::startSession();
        $token    ??= $_POST['_csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        $expected   = $_SESSION['csrf_token'] ?? '';
        return $expected && hash_equals($expected, $token);
    }

    /**
     * Return a hidden CSRF input field HTML string.
     */
    public static function csrfField(): string
    {
        $token = static::csrfToken();
        return '<input type="hidden" name="_csrf_token" value="' . htmlspecialchars($token) . '">';
    }

    // ----------------------------------------------------------------
    // Local Login (username/password)
    // ----------------------------------------------------------------

    /**
     * Attempt local login with email/username and password.
     *
     * @param  string $identifier  Email or username
     * @param  string $password    Plain text password
     * @return array|null          admin_users record or null on failure
     */
    public static function attemptLocalLogin(string $identifier, string $password): ?array
    {
        $db   = Database::getInstance();
        $user = $db->fetch(
            "SELECT * FROM admin_users
             WHERE (email = ? OR username = ?)
               AND auth_provider = 'local'
               AND is_active = 1
             LIMIT 1",
            [$identifier, $identifier]
        );

        if (!$user || !password_verify($password, $user['password'] ?? '')) {
            return null;
        }

        return $user;
    }

    // ----------------------------------------------------------------
    // Local Login Throttling (brute-force protection)
    // ----------------------------------------------------------------

    /** Max failed attempts (per IP or identifier) before lockout. */
    private const LOGIN_MAX_ATTEMPTS = 5;

    /** Rolling window, in minutes, the failed attempts are counted over. */
    private const LOGIN_WINDOW_MINUTES = 15;

    /**
     * True if this (identifier + IP) pair has too many recent failed attempts.
     *
     * Throttling is keyed on the identifier+IP *pair* (not IP alone) on purpose:
     * the university sits behind NAT, so many legitimate users share one public
     * IP. A pure per-IP lockout would let one bad actor (or one fat-fingered
     * user) lock out an entire campus. The pair still stops brute-forcing a
     * specific account from a given client.
     *
     * Fails open (returns false) if the throttle table is missing, so an
     * un-migrated deploy can still log in.
     */
    public static function isLoginThrottled(string $identifier, ?string $ip = null): bool
    {
        $ip ??= static::getClientIp();
        try {
            $count = (int) Database::getInstance()->fetchColumn(
                "SELECT COUNT(*) FROM admin_login_attempts
                 WHERE identifier = ? AND ip_address = ?
                   AND attempted_at >= DATE_SUB(NOW(), INTERVAL " . self::LOGIN_WINDOW_MINUTES . " MINUTE)",
                [$identifier, $ip]
            );
            return $count >= self::LOGIN_MAX_ATTEMPTS;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Record one failed login attempt. Non-fatal on any error.
     */
    public static function recordFailedLogin(string $identifier, ?string $ip = null): void
    {
        $ip ??= static::getClientIp();
        try {
            Database::getInstance()->insert('admin_login_attempts', [
                'identifier' => mb_substr($identifier, 0, 255),
                'ip_address' => $ip,
            ]);
        } catch (\Throwable) {
            // Non-fatal
        }
    }

    /**
     * Clear failed attempts for an identifier/IP after a successful login.
     */
    public static function clearLoginAttempts(string $identifier, ?string $ip = null): void
    {
        $ip ??= static::getClientIp();
        try {
            Database::getInstance()->query(
                "DELETE FROM admin_login_attempts WHERE identifier = ? AND ip_address = ?",
                [$identifier, $ip]
            );
        } catch (\Throwable) {
            // Non-fatal
        }
    }

    // ----------------------------------------------------------------
    // Private Helpers
    // ----------------------------------------------------------------

    private static function getAdminBaseUrl(): string
    {
        $appUrl = $_ENV['APP_URL'] ?? 'https://appupili.up.ac.th/cbms';
        return rtrim($appUrl, '/');
    }

    private static function getClientIp(): string
    {
        return Network::getClientIp();
    }
}
