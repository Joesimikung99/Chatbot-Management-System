<?php
/**
 * Microsoft Auth Service (Office 365 / Azure AD)
 * AI Chatbot System — CBMS
 *
 * OAuth2 flow ผ่าน thenetworg/oauth2-azure
 * + ตรวจสอบสิทธิ์กับ CAMS API ของ มหาวิทยาลัยพะเยา
 */

namespace App\Services;

use App\Helpers\Database;
use App\Services\LogService;
use GuzzleHttp\Client;
use TheNetworg\OAuth2\Client\Provider\Azure;

class MicrosoftAuthService
{
    private Database   $db;
    private LogService $logger;
    private Azure      $provider;
    private Client     $http;

    private string $clientId;
    private string $clientSecret;
    private string $tenantId;
    private string $redirectUri;
    private array  $allowedDomains;

    public function __construct()
    {
        $this->db     = Database::getInstance();
        $this->logger = new LogService();
        $this->http   = new Client(['timeout' => 15]);

        $this->clientId       = $_ENV['MICROSOFT_CLIENT_ID']     ?? '';
        $this->clientSecret   = $_ENV['MICROSOFT_CLIENT_SECRET'] ?? '';
        $this->tenantId       = $_ENV['MICROSOFT_TENANT_ID']     ?? 'common';
        $this->redirectUri    = $_ENV['MICROSOFT_REDIRECT_URI']
                              ?? 'https://appupili.up.ac.th/cbms/admin/auth-callback.php';

        $rawDomains = $_ENV['MICROSOFT_ALLOWED_DOMAINS'] ?? '';
        $this->allowedDomains = array_filter(
            array_map('trim', explode(',', $rawDomains)),
            fn($d) => $d !== ''
        );

        $this->provider = new Azure([
            'clientId'                => $this->clientId,
            'clientSecret'            => $this->clientSecret,
            'redirectUri'             => $this->redirectUri,
            'tenant'                  => $this->tenantId,
            'defaultEndPointVersion'  => '2.0',
        ]);
    }

    // ----------------------------------------------------------------
    // 1. Get Authorization URL
    // ----------------------------------------------------------------

    /**
     * Generate the Microsoft login redirect URL.
     * Stores a random state in session to prevent CSRF.
     *
     * @return string  URL to redirect the user to
     */
    public function getAuthorizationUrl(): string
    {
        $this->startSession();

        $authUrl = $this->provider->getAuthorizationUrl([
            'scope' => ['openid', 'email', 'profile', 'offline_access', 'User.Read'],
        ]);

        // Store OAuth2 state in session for CSRF protection
        $_SESSION['oauth2_state']    = $this->provider->getState();
        $_SESSION['oauth2_provider'] = 'microsoft';

        return $authUrl;
    }

    // ----------------------------------------------------------------
    // 2. Handle Callback
    // ----------------------------------------------------------------

    /**
     * Handle the OAuth2 callback from Microsoft.
     *
     * @param  string $code   Authorization code from Microsoft
     * @param  string $state  State parameter (must match session)
     * @return array{
     *   microsoft_id: string,
     *   email: string,
     *   display_name: string,
     *   avatar_url: string|null,
     *   tenant_id: string,
     *   job_title: string|null,
     *   tokens: array
     * }
     * @throws \RuntimeException on any failure
     */
    public function handleCallback(string $code, string $state): array
    {
        $this->startSession();

        // CSRF state validation
        $expectedState = $_SESSION['oauth2_state'] ?? null;
        if (!$expectedState || !hash_equals($expectedState, $state)) {
            throw new \RuntimeException('Invalid OAuth2 state parameter. Possible CSRF attack.');
        }
        unset($_SESSION['oauth2_state'], $_SESSION['oauth2_provider']);

        // Exchange code for tokens
        try {
            $token = $this->provider->getAccessToken('authorization_code', [
                'code' => $code,
            ]);
        } catch (\Throwable $e) {
            $this->logger->logError('MicrosoftAuth', 'Token exchange failed: ' . $e->getMessage());
            throw new \RuntimeException('Microsoft authentication failed. Please try again.');
        }

        // Fetch user profile from Microsoft Graph API
        try {
            $graphUser = $this->provider->get(
                $this->provider->getRootMicrosoftGraphUri($token) . '/v1.0/me',
                $token
            );
        } catch (\Throwable $e) {
            $this->logger->logError('MicrosoftAuth', 'Graph API call failed: ' . $e->getMessage());
            throw new \RuntimeException('Failed to retrieve user profile from Microsoft.');
        }

        $email       = $graphUser['mail'] ?? $graphUser['userPrincipalName'] ?? '';
        $microsoftId = $graphUser['id']   ?? '';

        if (empty($email) || empty($microsoftId)) {
            throw new \RuntimeException('Microsoft account does not have a valid email address.');
        }

        // Generate lightweight avatar instead of fetching base64 image from Microsoft
        $displayName = $graphUser['displayName'] ?? $email;
        $avatarUrl = "https://ui-avatars.com/api/?name=" . urlencode($displayName) . "&background=6366f1&color=fff";

        return [
            'microsoft_id'  => $microsoftId,
            'email'         => strtolower(trim($email)),
            'display_name'  => $graphUser['displayName']       ?? $email,
            'avatar_url'    => $avatarUrl,
            'tenant_id'     => $graphUser['tenantId']          ?? $this->tenantId,
            'job_title'     => $graphUser['jobTitle']          ?? null,
            'tokens'        => [
                'access_token'  => $token->getToken(),
                'refresh_token' => $token->getRefreshToken(),
                'expires'       => $token->getExpires(),
            ],
        ];
    }

    // ----------------------------------------------------------------
    // 3. Validate Allowed Domain
    // ----------------------------------------------------------------

    /**
     * Check whether an email domain is in the allowed list.
     * If MICROSOFT_ALLOWED_DOMAINS is empty, all domains pass.
     *
     * @param  string $email
     * @return bool
     */
    public function validateAllowedDomain(string $email): bool
    {
        if (empty($this->allowedDomains)) {
            return true; // Open to all domains
        }

        $domain = strtolower(substr($email, strrpos($email, '@') + 1));
        return in_array($domain, array_map('strtolower', $this->allowedDomains), true);
    }

    // ----------------------------------------------------------------
    // 4. Verify with CAMS API (ม.พะเยา)
    // ----------------------------------------------------------------

    /**
     * Verify the logged-in user against the CAMS central permission system.
     * Returns CAMS user data and permission level, or null if not authorized.
     *
     * @param  string $email   Email from Microsoft
     * @return array|null      CAMS user + permission data, or null if not authorized
     */
    public function verifyCams(string $email): ?array
    {
        $camsUrl     = rtrim($_ENV['CAMS_API_URL']      ?? 'https://appupili.up.ac.th/cams/api/v1', '/');
        $apiKey      = $_ENV['CAMS_API_KEY']             ?? '';
        $programCode = $_ENV['CAMS_PROGRAM_CODE']        ?? 'CBMS';

        try {
            $response = $this->http->post("{$camsUrl}/auth/verify", [
                'headers' => [
                    'Authorization' => 'Bearer ' . $apiKey,
                    'Content-Type'  => 'application/json',
                    'Accept'        => 'application/json',
                ],
                'json' => [
                    'email'        => $email,
                    'program_code' => $programCode,
                ],
                'http_errors' => false,
            ]);

            $body   = json_decode($response->getBody()->getContents(), true);
            $status = $response->getStatusCode();

            if ($status !== 200 || !($body['success'] ?? false) || !($body['authorized'] ?? false)) {
                $this->logger->logError('MicrosoftAuth', "CAMS unauthorized for {$email}", [
                    'status' => $status,
                    'body'   => $body,
                ]);
                return null;
            }

            return [
                'user'       => $body['user']       ?? [],
                'permission' => $body['permission'] ?? [],
            ];

        } catch (\Throwable $e) {
            $this->logger->logError('MicrosoftAuth', 'CAMS API error: ' . $e->getMessage());
            // If CAMS is unreachable, allow fallback (adjust based on policy)
            return null;
        }
    }

    // ----------------------------------------------------------------
    // 5. Find or Create Admin User
    // ----------------------------------------------------------------

    /**
     * Find existing user by microsoft_id or email, or create a new one.
     * Maps CAMS permission level to system role.
     *
     * @param  array       $microsoftData  from handleCallback()
     * @param  array|null  $camsData       from verifyCams() (can be null if CAMS skipped)
     * @return array       admin_users record
     * @throws \RuntimeException if user is inactive
     */
    public function findOrCreateUser(array $microsoftData, ?array $camsData = null): array
    {
        $email       = $microsoftData['email'];
        $microsoftId = $microsoftData['microsoft_id'];

        // Determine role from CAMS level
        $role = $this->mapCamsRole($camsData['permission']['level_value'] ?? null);

        // Try to find by microsoft_id first
        $user = $this->db->fetch(
            'SELECT * FROM admin_users WHERE microsoft_id = ? LIMIT 1',
            [$microsoftId]
        );

        // Fall back to email match
        if (!$user) {
            $user = $this->db->fetch(
                'SELECT * FROM admin_users WHERE email = ? LIMIT 1',
                [$email]
            );
        }

        $tokenJson = json_encode($microsoftData['tokens'], JSON_UNESCAPED_UNICODE);
        $now       = date('Y-m-d H:i:s');
        $ip        = $this->getClientIp();

        if ($user) {
            // Update existing user
            if (!$user['is_active']) {
                throw new \RuntimeException('บัญชีผู้ใช้นี้ถูกระงับการใช้งาน กรุณาติดต่อผู้ดูแลระบบ');
            }

            $updateData = [
                'microsoft_id'        => $microsoftId,
                'microsoft_tenant_id' => $microsoftData['tenant_id'],
                'microsoft_token'     => $tokenJson,
                'display_name'        => $microsoftData['display_name'],
                'avatar_url'          => $microsoftData['avatar_url'] ?? $user['avatar_url'],
                'auth_provider'       => 'microsoft',
                'last_login_at'       => $now,
                'last_login_ip'       => $ip,
            ];

            // Sync CAMS data if available
            if ($camsData) {
                $updateData = array_merge($updateData, $this->buildCamsFields($camsData));
                // Update role only if CAMS gives higher access
                if ($role !== 'viewer') {
                    $updateData['role'] = $role;
                }
            }

            $this->db->update('admin_users', $updateData, ['id' => $user['id']]);
            return array_merge($user, $updateData);
        }

        // Create new user
        $newData = [
            'email'               => $email,
            'display_name'        => $microsoftData['display_name'],
            'avatar_url'          => $microsoftData['avatar_url'] ?? null,
            'role'                => $role,
            'auth_provider'       => 'microsoft',
            'microsoft_id'        => $microsoftId,
            'microsoft_tenant_id' => $microsoftData['tenant_id'],
            'microsoft_token'     => $tokenJson,
            'last_login_at'       => $now,
            'last_login_ip'       => $ip,
            'is_active'           => 1,
            'permissions'         => json_encode($this->getDefaultPermissions($role)),
        ];

        if ($camsData) {
            $newData = array_merge($newData, $this->buildCamsFields($camsData));
        }

        $newId = $this->db->insert('admin_users', $newData);

        $this->logger->logActivity(null, 'user.created', 'admin_user', (int)$newId, null, [
            'email'         => $email,
            'auth_provider' => 'microsoft',
            'role'          => $role,
        ]);

        return array_merge($newData, ['id' => (int)$newId]);
    }

    // ----------------------------------------------------------------
    // 6. Refresh Token If Needed
    // ----------------------------------------------------------------

    /**
     * Refresh the Microsoft access token if it has expired.
     *
     * @param  array $user  admin_users record (needs microsoft_token JSON)
     * @return bool  true if refreshed, false if not needed or failed
     */
    public function refreshTokenIfNeeded(array $user): bool
    {
        $tokenData = json_decode($user['microsoft_token'] ?? '{}', true);
        $expires   = $tokenData['expires'] ?? 0;

        // Not expired yet (with 5-min buffer)
        if ($expires && $expires > time() + 300) {
            return false;
        }

        $refreshToken = $tokenData['refresh_token'] ?? '';
        if (empty($refreshToken)) {
            return false;
        }

        try {
            $newToken = $this->provider->getAccessToken('refresh_token', [
                'refresh_token' => $refreshToken,
            ]);

            $newTokenData = json_encode([
                'access_token'  => $newToken->getToken(),
                'refresh_token' => $newToken->getRefreshToken() ?? $refreshToken,
                'expires'       => $newToken->getExpires(),
            ]);

            $this->db->update('admin_users', ['microsoft_token' => $newTokenData], ['id' => $user['id']]);

            // Update session too
            if (isset($_SESSION['admin_user'])) {
                $_SESSION['admin_user']['token_expires'] = $newToken->getExpires();
            }

            return true;
        } catch (\Throwable $e) {
            $this->logger->logError('MicrosoftAuth', 'Token refresh failed: ' . $e->getMessage());
            return false;
        }
    }

    // ----------------------------------------------------------------
    // Private Helpers
    // ----------------------------------------------------------------



    /**
     * Map CAMS level_value to system role.
     * Super Admin = level ≥ 2, Admin = level 1, Viewer = 0 or unknown
     */
    private function mapCamsRole(?int $levelValue): string
    {
        if ($levelValue === null) {
            return 'viewer';
        }
        if ($levelValue >= 2) {
            return 'super_admin';
        }
        if ($levelValue >= 1) {
            return 'admin';
        }
        return 'viewer';
    }

    private function buildCamsFields(array $camsData): array
    {
        $user       = $camsData['user']       ?? [];
        $permission = $camsData['permission'] ?? [];

        return [
            'cams_user_id'    => $user['user_id']        ?? null,
            'cams_dept_code'  => $user['dept_code']      ?? null,
            'cams_dept_name'  => $user['dept_name_th']   ?? null,
            'cams_level_code' => $permission['level_code']  ?? null,
            'cams_level_value'=> $permission['level_value'] ?? null,
        ];
    }

    private function getDefaultPermissions(string $role): array
    {
        $base = [
            'view_dashboard'     => true,
            'view_conversations' => true,
            'view_analytics'     => false,
            'view_token_usage'   => false,
            'manage_knowledge'   => false,
            'manage_models'      => false,
            'manage_users'       => false,
            'manage_settings'    => false,
            'export_data'        => false,
        ];

        if ($role === 'admin' || $role === 'super_admin') {
            $base = array_map(fn() => true, $base);
            if ($role !== 'super_admin') {
                $base['manage_users'] = false;
            }
        }

        return $base;
    }

    private function startSession(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            $sessionName     = $_ENV['ADMIN_SESSION_NAME'] ?? 'cbms_chatbot_admin';
            $sessionLifetime = (int)($_ENV['ADMIN_SESSION_LIFETIME'] ?? 86400);
            session_name($sessionName);
            session_set_cookie_params([
                'lifetime' => $sessionLifetime,
                'path'     => '/',
                'secure'   => true,
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
            session_start();
        }
    }

    private function getClientIp(): string
    {
        return \App\Helpers\Network::getClientIp();
    }
}
