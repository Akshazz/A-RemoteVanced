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
        'version' => '2.2.2',
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

    /* Security Lab / VMware integration. All actions are admin-only and
     * limited to explicitly authorized scopes. Configure via environment
     * variables; do not commit guest passwords or private keys. */
    'security_lab' => [
        'enabled' => filter_var(getenv('RB_SECURITY_LAB_ENABLED') ?: '0', FILTER_VALIDATE_BOOLEAN),
        'vmrun' => getenv('RB_VMWARE_VMRUN') ?: '',
        'vmx' => getenv('RB_KALI_VMX') ?: '',
        'guest_user' => getenv('RB_KALI_GUEST_USER') ?: 'kali',
        'guest_password' => getenv('RB_KALI_GUEST_PASSWORD') ?: '',
        'ssh_host' => getenv('RB_KALI_SSH_HOST') ?: '127.0.0.1',
        'ssh_port' => (int)(getenv('RB_KALI_SSH_PORT') ?: 22),
        'ssh_user' => getenv('RB_KALI_SSH_USER') ?: 'kali',
        'ssh_key' => getenv('RB_KALI_SSH_KEY') ?: '',
        'nmap' => getenv('RB_KALI_NMAP') ?: '/usr/bin/nmap',
        'nikto' => getenv('RB_KALI_NIKTO') ?: '/usr/bin/nikto',
        'timeout' => (int)(getenv('RB_SECURITY_LAB_TIMEOUT') ?: 45),
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

];
