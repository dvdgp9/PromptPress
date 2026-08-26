<?php

declare(strict_types=1);

/**
 * Empaqueta PromptPress en un ZIP listo para el actualizador del panel
 * (Ajustes → Actualizaciones → subir paquete).
 *
 * Uso:  php scripts/build_package.php [--out=ruta.zip]
 *
 * Qué NO entra, y por qué:
 *   - Secretos de ESTA instalación (`config/config.php`, `config/image_bank.php`).
 *     Llevan credenciales de base de datos, la app_key y claves de API: subirlos
 *     a otro servidor sería filtrarlos, y además el instalador los respeta,
 *     así que ni siquiera se sobrescribirían.
 *   - Datos de ESTA instalación (subidas, documentos, logs, caché, backups).
 *   - `iaia-analytics/`: es otro proyecto que vive en el mismo repo, 110 MB que
 *     no pintan nada en el paquete.
 *   - Herramientas de desarrollo (.git, .cursor, .claude, deliverables…).
 *
 * `vendor/` SÍ entra: está versionado y es lo que da soporte a PDF y DOCX en
 * hostings sin Composer.
 */

$root = dirname(__DIR__);

/** Prefijos relativos a la raíz que nunca se empaquetan. */
$exclude = [
    '/.git',
    '/.github',
    '/.claude',
    '/.cursor',
    '/cursor',
    '/deliverables',
    '/skill-web-compliance',
    '/iaia-analytics',
    '/node_modules',
    '/config/config.php',
    '/config/image_bank.php',
    '/install/.installed',
    '/storage/uploads',
    '/storage/documents',
    '/storage/resources',
    '/storage/logs',
    '/storage/cache',
    '/storage/updates',
    '/storage/maintenance.flag',
];

/** Nombres de fichero que se caen estén donde estén. */
$excludeNames = ['.DS_Store', 'Thumbs.db', '.t13-test.sh'];

// El instalador rechaza cualquier ZIP al que le falte algo de esto.
$fingerprint = ['index.php', 'app', 'core', 'config/constants.php', 'database/migrations'];

// --- versión, para el nombre del archivo ---
$version = '0.0.0';
$constants = (string) @file_get_contents($root . '/config/constants.php');
if (preg_match("/define\(\s*'PP_VERSION'\s*,\s*'([^']+)'/", $constants, $m)) {
    $version = $m[1];
}

$opts = getopt('', ['out::']);
$out  = (string) ($opts['out'] ?? $root . '/deliverables/promptpress-' . $version . '-' . date('Ymd-Hi') . '.zip');
@mkdir(dirname($out), 0775, true);

$isExcluded = static function (string $rel) use ($exclude, $excludeNames): bool {
    foreach ($excludeNames as $name) {
        if (basename($rel) === $name) {
            return true;
        }
    }
    foreach ($exclude as $ex) {
        if ($rel === $ex || str_starts_with($rel, $ex . '/')) {
            return true;
        }
    }
    return false;
};

$zip = new ZipArchive();
if ($zip->open($out, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    fwrite(STDERR, "No se pudo crear el ZIP en {$out}\n");
    exit(1);
}

$count = 0;
$it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST
);
foreach ($it as $item) {
    /** @var SplFileInfo $item */
    $rel = str_replace('\\', '/', substr($item->getPathname(), strlen($root)));
    if ($isExcluded($rel)) {
        continue;
    }
    if ($item->isDir()) {
        // Las carpetas vacías con sentido (storage/uploads y compañía) tienen que
        // llegar creadas: si no, la instalación destino se queda sin sitio donde
        // escribir y falla en la primera subida.
        $zip->addEmptyDir(ltrim($rel, '/'));
        continue;
    }
    $zip->addFile($item->getPathname(), ltrim($rel, '/'));
    $count++;
}
$zip->close();

// --- comprobación: que el paquete pase el mismo filtro que el instalador ---
$check = new ZipArchive();
if ($check->open($out) !== true) {
    fwrite(STDERR, "El ZIP se creó pero no se puede volver a abrir.\n");
    exit(1);
}
$missing = [];
foreach ($fingerprint as $needle) {
    if ($check->locateName($needle) === false && $check->locateName($needle . '/') === false) {
        $missing[] = $needle;
    }
}
$leaked = [];
foreach (['config/config.php', 'config/image_bank.php'] as $secret) {
    if ($check->locateName($secret) !== false) {
        $leaked[] = $secret;
    }
}
$check->close();

if ($missing !== []) {
    fwrite(STDERR, "El actualizador lo rechazaría: falta " . implode(', ', $missing) . "\n");
    exit(1);
}
if ($leaked !== []) {
    fwrite(STDERR, "ABORTADO: el paquete lleva secretos dentro (" . implode(', ', $leaked) . ")\n");
    @unlink($out);
    exit(1);
}

printf(
    "OK  %s\n    %d archivos · %s · versión %s\n",
    $out,
    $count,
    number_format(filesize($out) / 1024 / 1024, 1) . ' MB',
    $version
);
