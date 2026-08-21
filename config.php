<?php
declare(strict_types=1);

/*
 * RemoteBridge application configuration
 * ---------------------------------------
 * Keep deployment-specific settings in this file so you do not need to
 * search through the project source code when moving the application to a
 * different MySQL/MariaDB server.
 *
 * Environment variables still take priority. If you are using XAMPP/local
 * development, the values below are the easiest place to configure the DB.
 */

return [
    'app' => [
        'name' => 'RemoteBridge',
        'version' => '2.1.0',
    ],

    /* ================================================================
     * DATABASE CONNECTION
     * Change ONLY this block for a normal XAMPP/manual installation.
     * ================================================================ */
    'database' => [
        'driver' => 'mysqli',
        'host' => getenv('RB_DB_HOST') ?: '127.0.0.1',
        'port' => (int)(getenv('RB_DB_PORT') ?: 3306),
        'database' => getenv('RB_DB_NAME') ?: 'remote_bridge',
        'username' => getenv('RB_DB_USER') ?: 'root',
        'password' => getenv('RB_DB_PASSWORD') ?: '',
        'charset' => 'utf8mb4',
    ],

    /* Native control agent. Keep 127.0.0.1 for same-PC use. Set host to
     * 0.0.0.0 when you intentionally want LAN browsers to reach the agent;
     * the token is still required before any OS input is accepted. */
    'agent' => [
        'host' => getenv('RB_AGENT_HOST') ?: '127.0.0.1',
        'port' => (int)(getenv('RB_AGENT_PORT') ?: 8791),
    ],

    'session_ttl' => 300,
    'max_signal_bytes' => 262144,

    // Optional TURN relay. Leave empty for the built-in free fallback.
    'turn' => [
        'url' => getenv('RB_TURN_URL') ?: '',
        'username' => getenv('RB_TURN_USERNAME') ?: '',
        'credential' => getenv('RB_TURN_CREDENTIAL') ?: '',
        'use_free_fallback' => !getenv('RB_DISABLE_FREE_TURN'),
    ],

    // Access control for the "Devices on this network" sidebar.
    'network' => [
        'access_code' => getenv('RB_NETWORK_ACCESS_CODE') ?: null,
    ],
];
