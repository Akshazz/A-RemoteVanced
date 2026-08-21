<?php
declare(strict_types=1);

return [
    'version' => '002_app_settings',
    'up' => [
        "CREATE TABLE IF NOT EXISTS app_settings (
            setting_key VARCHAR(100) NOT NULL PRIMARY KEY,
            setting_value TEXT NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ],
];
