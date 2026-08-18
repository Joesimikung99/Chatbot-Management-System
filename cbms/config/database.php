<?php
/**
 * Database Configuration
 * AI Chatbot System — CBMS
 */

return [
    'default' => 'mysql',

    'connections' => [
        'mysql' => [
            'driver'    => 'mysql',
            'host'      => $_ENV['DB_HOST']     ?? 'localhost',
            'port'      => (int)($_ENV['DB_PORT'] ?? 3306),
            'database'  => $_ENV['DB_DATABASE'] ?? 'chatbot_db',
            'username'  => $_ENV['DB_USERNAME'] ?? 'chatbot_user',
            'password'  => $_ENV['DB_PASSWORD'] ?? '',
            'charset'   => $_ENV['DB_CHARSET']  ?? 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'options'   => [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
            ],
        ],
    ],
];
