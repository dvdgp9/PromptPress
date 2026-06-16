#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/constants.php';
require_once PP_CORE . '/Autoloader.php';
\Core\Autoloader::register();
if (is_file(PP_ROOT . '/vendor/autoload.php')) {
    require_once PP_ROOT . '/vendor/autoload.php';
}
\Core\App::boot();

$opts = getopt('', ['site:', 'yes']);
$siteId = isset($opts['site']) ? (int) $opts['site'] : 0;
if ($siteId <= 0) {
    fwrite(STDERR, "Uso: php database/reset.php --site=<id> [--yes]\n");
    exit(1);
}

$site = \Core\Database::selectOne('SELECT id, name FROM sites WHERE id = ? LIMIT 1', [$siteId]);
if (!$site) {
    fwrite(STDERR, "No existe el sitio {$siteId}.\n");
    exit(1);
}

$counts = \App\Services\SiteResetService::counts($siteId);
echo "Vas a borrar contenido del sitio \"{$site['name']}\" (id={$siteId}).\n";
foreach ($counts as $label => $count) {
    echo "- {$label}: {$count}\n";
}

if (!isset($opts['yes'])) {
    echo "Escribe RESET para continuar: ";
    $answer = trim((string) fgets(STDIN));
    if ($answer !== 'RESET') {
        echo "Cancelado.\n";
        exit(0);
    }
}

$result = \App\Services\SiteResetService::reset($siteId);
echo "Sitio reiniciado.\n";
foreach (($result['deleted'] ?? []) as $label => $count) {
    echo "- {$label}: {$count}\n";
}
