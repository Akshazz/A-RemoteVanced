<?php
declare(strict_types=1);

/* How long (seconds) to wait for the initial MySQL/MariaDB TCP handshake
 * before giving up. `new mysqli(...)` has no timeout of its own — if the
 * DB host/port is unreachable (MySQL service stopped, wrong host/port, a
 * firewall silently dropping the connection instead of refusing it), the
 * connection attempt can block for minutes. Without a timeout that eats
 * straight through PHP's max_execution_time (120s by default) and crashes
 * the whole page with an opaque "Maximum execution time exceeded" fatal
 * error instead of a clear, catchable one. */
const RB_DB_CONNECT_TIMEOUT_SECONDS = 5;

function dbConfig(): array {
    static $config;
    return $config ??= (require __DIR__ . '/config.php')['database'];
}

/** Shared connect helper. Uses mysqli_init()+real_connect() instead of
 * `new mysqli(...)` because only that form lets us set a connect timeout
 * before the connection attempt starts. */
function rb_db_connect(string $database): mysqli {
    $c = dbConfig();
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $db = mysqli_init();
    if ($db === false) {
        throw new RuntimeException('mysqli_init() failed');
    }
    $db->options(MYSQLI_OPT_CONNECT_TIMEOUT, RB_DB_CONNECT_TIMEOUT_SECONDS);
    $db->real_connect($c['host'], $c['username'], $c['password'], $database, $c['port']);
    $db->set_charset($c['charset']);
    return $db;
}

function dbServer(): mysqli {
    return rb_db_connect('');
}

function db(): mysqli {
    static $db;
    if ($db instanceof mysqli) return $db;
    $db = rb_db_connect(dbConfig()['database']);
    return $db;
}
