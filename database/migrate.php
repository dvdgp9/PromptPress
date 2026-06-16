<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/config/constants.php';
require_once PP_ROOT . '/database/Migrator.php';

use PromptPress\Database\Migrator;

$opts = getopt('', ['host::', 'port::', 'db::', 'user::', 'pass::', 'dry-run']);

$config = is_file(PP_CONFIG_FILE) ? require PP_CONFIG_FILE : [];
$db = $config['db'] ?? [];

$host = (string) ($opts['host'] ?? $db['host'] ?? '127.0.0.1');
$port = (int) ($opts['port'] ?? $db['port'] ?? 3306);
$name = (string) ($opts['db'] ?? $db['name'] ?? '');
$user = (string) ($opts['user'] ?? $db['user'] ?? '');
$pass = (string) ($opts['pass'] ?? $db['pass'] ?? '');
$dryRun = array_key_exists('dry-run', $opts);

if ($name === '' || $user === '') {
    fwrite(STDERR, "Uso: php database/migrate.php [--host=127.0.0.1 --port=3306 --db=DB --user=USER --pass=PASS] [--dry-run]\n");
    exit(2);
}

try {
    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $name),
        $user,
        $pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    $result = (new Migrator($pdo, PP_ROOT . '/database/migrations'))->run($dryRun);
} catch (Throwable $e) {
    fwrite(STDERR, "Migración fallida: " . $e->getMessage() . "\n");
    exit(1);
}

echo ($dryRun ? "Dry-run" : "Migración") . " completada.\n";
echo "Aplicadas: " . count($result['applied']) . "\n";
foreach ($result['applied'] as $name) {
    echo "  - {$name}\n";
}

echo "Ya aplicadas: " . count($result['skipped']) . "\n";
foreach ($result['skipped'] as $name) {
    echo "  - {$name}\n";
}

if (!empty($result['errors'])) {
    echo "Errores: " . count($result['errors']) . "\n";
    foreach ($result['errors'] as $error) {
        echo "  - {$error['name']}: {$error['error']}\n";
    }
    exit(1);
}
