<?php
declare(strict_types=1);

return [
    'app' => [
        'name' => 'RemoteBridge',
        'version' => '2.0.0',
    ],
    'database' => [
        'driver' => 'mysqli',
        'host' => getenv('RB_DB_HOST') ?: '127.0.0.1',
        'port' => (int)(getenv('RB_DB_PORT') ?: 3306),
        'database' => getenv('RB_DB_NAME') ?: 'a_remote',
        'username' => getenv('RB_DB_USER') ?: 'root',
        'password' => getenv('RB_DB_PASSWORD') ?: '',
        'charset' => 'utf8mb4',
    ],
    'session_ttl' => 300,
    'max_signal_bytes' => 262144,
];
