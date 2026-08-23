<?php
declare(strict_types=1);

return [
    'version' => '004_remove_network_access_code',
    'up' => [
        "DELETE FROM app_settings WHERE setting_key = 'network_access_code'",
    ],
];
