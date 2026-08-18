<?php
/**
 * Application Configuration
 * AI Chatbot System — CBMS
 */

return [
    'name'     => $_ENV['APP_NAME']       ?? 'AI Chatbot System',
    'url'      => $_ENV['APP_URL']        ?? 'https://appupili.up.ac.th/cbms',
    'env'      => $_ENV['APP_ENV']        ?? 'production',
    'debug'    => filter_var($_ENV['APP_DEBUG'] ?? false, FILTER_VALIDATE_BOOLEAN),
    'secret'   => $_ENV['APP_SECRET_KEY'] ?? '',
    'timezone' => $_ENV['APP_TIMEZONE']   ?? 'Asia/Bangkok',

    /*
    |------------------------------------------------------------------
    | Session Configuration
    |------------------------------------------------------------------
    */
    'session' => [
        'name'     => $_ENV['ADMIN_SESSION_NAME']     ?? 'cbms_chatbot_admin',
        'lifetime' => (int)($_ENV['ADMIN_SESSION_LIFETIME'] ?? 86400),
        'secure'   => filter_var($_ENV['APP_ENV'] ?? 'production', FILTER_VALIDATE_BOOLEAN) === false
                        ? false
                        : true,
        'httponly' => true,
        'samesite' => 'Strict',
    ],

    /*
    |------------------------------------------------------------------
    | Rate Limiting
    |------------------------------------------------------------------
    */
    'rate_limit' => [
        'per_minute' => (int)($_ENV['RATE_LIMIT_PER_MINUTE'] ?? 20),
        'per_hour'   => (int)($_ENV['RATE_LIMIT_PER_HOUR']   ?? 100),
    ],

    /*
    |------------------------------------------------------------------
    | Authentication
    |------------------------------------------------------------------
    */
    'auth' => [
        'allow_local_login' => filter_var($_ENV['ALLOW_LOCAL_LOGIN'] ?? true, FILTER_VALIDATE_BOOLEAN),
    ],

    /*
    |------------------------------------------------------------------
    | Trusted Proxies
    |------------------------------------------------------------------
    | IP addresses of reverse proxies that are allowed to set
    | X-Forwarded-For / X-Real-IP headers. Only these IPs will be
    | trusted for client IP resolution. Empty array = trust REMOTE_ADDR only.
    */
    'trusted_proxies' => array_filter(
        array_map('trim', explode(',', $_ENV['TRUSTED_PROXIES'] ?? '')),
        fn($p) => $p !== ''
    ),
];
