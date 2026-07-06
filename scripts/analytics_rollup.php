<?php

declare(strict_types=1);

/**
 * CLI opcional para consolidar la analítica de todos los sitios (FEAT-3 A4).
 *
 * El rollup normal es perezoso (se dispara al abrir el dashboard), así que este
 * script NO es obligatorio. Existe para instalaciones que prefieran un cron:
 *
 *   php scripts/analytics_rollup.php
 *
 * Fuerza el rollup (sin lock horario) para cada sitio y purga crudos/salts.
 */

require_once __DIR__ . '/../config/constants.php';
require_once PP_CORE . '/Autoloader.php';
\Core\Autoloader::register();
require_once PP_ROOT . '/vendor/autoload.php';
\Core\App::boot();

use App\Modules\Analytics\RollupService;
use App\Modules\ModuleRegistry;
use Core\Database;

$sites = Database::select('SELECT id FROM sites ORDER BY id ASC');
$total = 0;
foreach ($sites as $site) {
    $siteId = (int) $site['id'];
    if (!ModuleRegistry::isEnabled($siteId, 'analytics')) {
        continue;
    }
    $res = RollupService::run($siteId);
    $total++;
    echo "site {$siteId}: {$res['days']} días consolidados, {$res['purged_events']} eventos purgados" . PHP_EOL;
}
echo "Hecho ({$total} sitio(s) con analítica activa)." . PHP_EOL;
