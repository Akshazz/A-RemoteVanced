<?php
declare(strict_types=1);

$config = require __DIR__ . '/../config.php';
$c = $config['database'];
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$db = new mysqli($c['host'], $c['username'], $c['password'], '', $c['port']);
$db->set_charset($c['charset']);

$name = $c['database'];
if (!preg_match('/^[A-Za-z0-9_]+$/', $name)) throw new RuntimeException('Invalid database name.');
$db->query("CREATE DATABASE IF NOT EXISTS `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$db->select_db($name);
$db->query("CREATE TABLE IF NOT EXISTS migrations (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, version VARCHAR(100) NOT NULL UNIQUE, applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB");

$files = glob(__DIR__ . '/migrations/*.php');
sort($files, SORT_NATURAL);
foreach ($files as $file) {
    $migration = require $file;
    $version = $migration['version'];
    $check = $db->prepare('SELECT id FROM migrations WHERE version = ? LIMIT 1');
    $check->bind_param('s', $version);
    $check->execute();
    $check->store_result();
    if ($check->num_rows) { echo "SKIP {$version}\n"; $check->close(); continue; }
    $check->close();

    $db->begin_transaction();
    try {
        foreach ($migration['up'] as $sql) $db->query($sql);
        $stmt = $db->prepare('INSERT INTO migrations(version) VALUES(?)');
        $stmt->bind_param('s', $version);
        $stmt->execute();
        $stmt->close();
        $db->commit();
        echo "APPLIED {$version}\n";
    } catch (Throwable $e) {
        $db->rollback();
        throw $e;
    }
}
echo "Database ready: {$name}\n";
