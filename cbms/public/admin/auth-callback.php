<?php
/**
 * Microsoft OAuth2 Callback Handler
 * AI Chatbot System — CBMS
 * URL: /admin/auth-callback.php
 *
 * Flow:
 *   Microsoft → redirect here with ?code=xxx&state=xxx
 *   → validate state
 *   → exchange code for token
 *   → get user from Graph API
 *   → validate domain
 *   → verify with CAMS API
 *   → find or create admin_user
 *   → create PHP session
 *   → redirect to dashboard
 */

define('BASE_PATH', dirname(__DIR__, 2));
require_once BASE_PATH . '/vendor/autoload.php';

use Dotenv\Dotenv;
use App\Helpers\Auth;
use App\Services\MicrosoftAuthService;
use App\Services\LogService;

$dotenv = Dotenv::createImmutable(BASE_PATH);
$dotenv->safeLoad();

date_default_timezone_set($_ENV['APP_TIMEZONE'] ?? 'Asia/Bangkok');
Auth::startSession();

$adminBase = rtrim($_ENV['APP_URL'] ?? 'https://appupili.up.ac.th/cbms', '/') . '/admin';
$logger    = new LogService();

// ── Error Redirect Helper ──────────────────────────────────────────────
function redirectError(string $reason): never
{
    global $adminBase;
    header("Location: {$adminBase}/login.php?reason=" . urlencode($reason));
    exit;
}

// ── Validate required parameters ──────────────────────────────────────
$code  = $_GET['code']  ?? '';
$state = $_GET['state'] ?? '';

if (empty($code) || empty($state)) {
    // Microsoft returned an error
    $error = $_GET['error_description'] ?? $_GET['error'] ?? 'Unknown Microsoft auth error';
    $logger->logError('AuthCallback', 'Missing code/state: ' . $error);
    redirectError('ms_error');
}

// ── Process OAuth2 Callback ────────────────────────────────────────────
try {
    $msAuth = new MicrosoftAuthService();

    // 1. Exchange code → get Microsoft user data
    $microsoftData = $msAuth->handleCallback($code, $state);
    $email         = $microsoftData['email'];

    // 2. Validate email domain
    if (!$msAuth->validateAllowedDomain($email)) {
        $logger->logError('AuthCallback', "Domain not allowed: {$email}");
        redirectError('domain');
    }

    // 3. Verify with CAMS API
    $camsData = $msAuth->verifyCams($email);

    // If CAMS check failed and CAMS is required
    $camsRequired = filter_var($_ENV['CAMS_REQUIRED'] ?? 'false', FILTER_VALIDATE_BOOLEAN);
    if ($camsRequired && $camsData === null) {
        $logger->logError('AuthCallback', "CAMS rejected: {$email}");
        redirectError('cams');
    }

    // 4. Find or create admin user
    $user = $msAuth->findOrCreateUser($microsoftData, $camsData);

    // 5. Create PHP session
    Auth::login($user);

    // 6. Log successful login
    $logger->logActivity($user['id'], 'auth.login', 'admin_user', $user['id'], null, [
        'method'     => 'microsoft',
        'email'      => $email,
        'cams_level' => $camsData['permission']['level_value'] ?? null,
    ]);

    // 7. Redirect to dashboard (or intended page)
    $redirect = $_SESSION['intended_url'] ?? ($adminBase . '/index.php');
    unset($_SESSION['intended_url']);

    header("Location: {$redirect}");
    exit;

} catch (\RuntimeException $e) {
    $message = $e->getMessage();
    $logger->logError('AuthCallback', $message);

    // Map known error messages to redirect reasons
    if (str_contains($message, 'state')) {
        redirectError('csrf');
    }
    if (str_contains($message, 'suspended') || str_contains($message, 'ระงับ')) {
        redirectError('suspended');
    }

    // Generic error — store message in session for display
    $_SESSION['auth_error'] = $message;
    redirectError('ms_error');

} catch (\Throwable $e) {
    $logger->logError('AuthCallback', 'Unexpected: ' . $e->getMessage(), [
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ]);
    redirectError('ms_error');
}
