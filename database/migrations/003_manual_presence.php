<?php
declare(strict_types=1);

return [
    'version' => '003_manual_presence',
    'up' => [
        "ALTER TABLE devices ADD COLUMN IF NOT EXISTS is_online TINYINT(1) NOT NULL DEFAULT 0 AFTER last_seen_at",
        "UPDATE devices SET is_online = CASE WHEN last_seen_at IS NOT NULL AND last_seen_at > (NOW() - INTERVAL 2 MINUTE) THEN 1 ELSE 0 END",
    ],
];
