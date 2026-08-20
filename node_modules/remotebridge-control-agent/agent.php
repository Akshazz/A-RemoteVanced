<?php
declare(strict_types=1);
function detectMac(): string {
    if (PHP_OS_FAMILY === 'Windows') {
        $out = shell_exec('getmac /fo csv /nh 2>NUL') ?? '';
        if (preg_match('/"([0-9A-Fa-f]{2}(?:-[0-9A-Fa-f]{2}){5})"/', $out, $m)) return strtolower(str_replace('-', ':', $m[1]));
    } else foreach (glob('/sys/class/net/*/address') ?: [] as $file) {
        if (basename(dirname($file)) === 'lo') continue;
        $mac = trim((string)@file_get_contents($file));
        if (preg_match('/^(?:[0-9a-f]{2}:){5}[0-9a-f]{2}$/i', $mac) && strtolower($mac) !== '00:00:00:00:00:00') return strtolower($mac);
    }
    return 'unknown';
}
$mac=detectMac();
$remoteId='RB-'.strtoupper(substr(hash('sha256','RemoteBridge|'.$mac),0,16));
echo "RemoteBridge native PHP agent\nRemote ID: {$remoteId}\n";
