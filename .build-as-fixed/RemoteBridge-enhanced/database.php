<?php
declare(strict_types=1);

function dbConfig(): array {
    static $config;
    return $config ??= (require __DIR__ . '/config.php')['database'];
}

function dbServer(): mysqli {
    $c = dbConfig();
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $db = new mysqli($c['host'], $c['username'], $c['password'], '', $c['port']);
    $db->set_charset($c['charset']);
    return $db;
}

function db(): mysqli {
    static $db;
    if ($db instanceof mysqli) return $db;
    $c = dbConfig();
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $db = new mysqli($c['host'], $c['username'], $c['password'], $c['database'], $c['port']);
    $db->set_charset($c['charset']);
    return $db;
}
