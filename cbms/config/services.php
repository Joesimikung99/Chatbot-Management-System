<?php
/**
 * External Services Configuration
 * AI Chatbot System — CBMS
 */

return [

    /*
    |------------------------------------------------------------------
    | OpenRouter AI
    |------------------------------------------------------------------
    */
    'openrouter' => [
        'api_key'        => $_ENV['OPENROUTER_API_KEY']        ?? '',
        'base_url'       => $_ENV['OPENROUTER_BASE_URL']       ?? 'https://openrouter.ai/api/v1',
        'site_url'       => $_ENV['OPENROUTER_SITE_URL']       ?? '',
        'site_name'      => $_ENV['OPENROUTER_SITE_NAME']      ?? 'AI Chatbot System',
        'default_model'  => $_ENV['OPENROUTER_DEFAULT_MODEL']  ?? 'openai/gpt-4o-mini',
        'embedding_model'=> $_ENV['OPENROUTER_EMBEDDING_MODEL']?? 'openai/text-embedding-3-small',
    ],

    /*
    |------------------------------------------------------------------
    | Microsoft Office 365 / Azure AD
    |------------------------------------------------------------------
    */
    'microsoft' => [
        'client_id'        => $_ENV['MICROSOFT_CLIENT_ID']       ?? '',
        'client_secret'    => $_ENV['MICROSOFT_CLIENT_SECRET']   ?? '',
        'tenant_id'        => $_ENV['MICROSOFT_TENANT_ID']       ?? 'common',
        'redirect_uri'     => $_ENV['MICROSOFT_REDIRECT_URI']    ?? '',
        'allowed_domains'  => array_filter(
            explode(',', $_ENV['MICROSOFT_ALLOWED_DOMAINS'] ?? ''),
            fn($d) => !empty(trim($d))
        ),
        'scopes'           => ['openid', 'email', 'profile', 'offline_access', 'User.Read'],
        'graph_url'        => 'https://graph.microsoft.com/v1.0',
    ],

    /*
    |------------------------------------------------------------------
    | CAMS API (Permission Verification — ม.พะเยา)
    |------------------------------------------------------------------
    */
    'cams' => [
        'api_url'      => $_ENV['CAMS_API_URL']      ?? 'https://appupili.up.ac.th/cams/api/v1',
        'api_key'      => $_ENV['CAMS_API_KEY']      ?? '',
        'program_code' => $_ENV['CAMS_PROGRAM_CODE'] ?? 'CBMS',
    ],

    /*
    |------------------------------------------------------------------
    | Google Drive
    |------------------------------------------------------------------
    */
    'google_drive' => [
        'credentials_path' => $_ENV['GOOGLE_APPLICATION_CREDENTIALS'] ?? '',
        'folder_id'        => $_ENV['GOOGLE_DRIVE_FOLDER_ID']         ?? '',
    ],

    /*
    |------------------------------------------------------------------
    | Facebook Messenger
    |------------------------------------------------------------------
    */
    'facebook' => [
        'app_id'              => $_ENV['FACEBOOK_APP_ID']              ?? '',
        'app_secret'          => $_ENV['FACEBOOK_APP_SECRET']          ?? '',
        'page_access_token'   => $_ENV['FACEBOOK_PAGE_ACCESS_TOKEN']   ?? '',
        'verify_token'        => $_ENV['FACEBOOK_VERIFY_TOKEN']        ?? '',
        'graph_api_url'       => 'https://graph.facebook.com/v19.0',
    ],

    /*
    |------------------------------------------------------------------
    | LINE Official Account
    |------------------------------------------------------------------
    */
    'line' => [
        'channel_access_token' => $_ENV['LINE_CHANNEL_ACCESS_TOKEN'] ?? '',
        'channel_secret'       => $_ENV['LINE_CHANNEL_SECRET']       ?? '',
        'messaging_api_url'    => 'https://api.line.me/v2/bot',
    ],
];
