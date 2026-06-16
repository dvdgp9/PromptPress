<?php

declare(strict_types=1);

namespace App\Services;

use PromptPress\Database\Migrator;
use RuntimeException;

final class UpdateInstallerService
{
    /**
     * @return array{backup:string, package:string, version:?string}
     */
    public static function apply(int $siteId): array
    {
        $status = UpdateService::status($siteId);
        $downloadUrl = trim((string) ($status['download_url'] ?? ''));
        if ($downloadUrl === '') {
            throw new RuntimeException('No hay paquete de actualización disponible. Ejecuta "Comprobar ahora".');
        }
        $expectedChecksum = trim((string) ($status['checksum_sha256'] ?? ''));
        $signature = trim((string) ($status['signature'] ?? ''));
        $signatureAlg = trim((string) ($status['signature_alg'] ?? ''));

        self::ensureRequirements();
        self::ensureDirs();

        $stamp = date('Ymd_His');
        $version = trim((string) ($status['latest_version'] ?? ''));
        $versionSafe = $version !== '' ? preg_replace('/[^a-zA-Z0-9._-]/', '_', $version) : 'unknown';
        $base = "update_{$stamp}_{$versionSafe}";

        $backupPath = PP_STORAGE . '/updates/backups/' . $base . '.zip';
        $packagePath = PP_STORAGE . '/updates/packages/' . $base . '.zip';
        $extractDir = PP_STORAGE . '/updates/extracted/' . $base;

        self::createBackup($backupPath);
        self::download($downloadUrl, $packagePath);
        self::verifyPackage($packagePath, $expectedChecksum, $signature, $signatureAlg);
        self::extractZip($packagePath, $extractDir);
        self::deploy($extractDir);
        self::runMigrations();

        return ['backup' => $backupPath, 'package' => $packagePath, 'version' => $version !== '' ? $version : null];
    }

    private static function ensureRequirements(): void
    {
        if (!class_exists('ZipArchive')) {
            throw new RuntimeException('La extensión ZIP no está disponible en PHP.');
        }
        if (!in_array('sha256', hash_algos(), true)) {
            throw new RuntimeException('SHA-256 no está disponible en este runtime.');
        }
    }

    private static function ensureDirs(): void
    {
        foreach ([
            PP_STORAGE . '/updates',
            PP_STORAGE . '/updates/backups',
            PP_STORAGE . '/updates/packages',
            PP_STORAGE . '/updates/extracted',
        ] as $dir) {
            if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
                throw new RuntimeException('No se pudo crear el directorio de updates: ' . $dir);
            }
        }
    }

    private static function createBackup(string $targetZip): void
    {
        $zip = new \ZipArchive();
        if ($zip->open($targetZip, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('No se pudo crear el backup previo a la actualización.');
        }

        $exclude = [
            '/vendor',
            '/storage/cache',
            '/storage/logs',
            '/storage/updates',
            '/.git',
        ];

        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(PP_ROOT, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($it as $file) {
            /** @var \SplFileInfo $file */
            if (!$file->isFile()) {
                continue;
            }
            $abs = $file->getPathname();
            $rel = str_replace('\\', '/', substr($abs, strlen(PP_ROOT)));
            if (self::isExcluded($rel, $exclude)) {
                continue;
            }
            $zip->addFile($abs, ltrim($rel, '/'));
        }

        $zip->close();
    }

    private static function download(string $url, string $targetPath): void
    {
        $ch = curl_init($url);
        $fp = fopen($targetPath, 'wb');
        if ($ch === false || $fp === false) {
            throw new RuntimeException('No se pudo iniciar la descarga del paquete.');
        }

        curl_setopt_array($ch, [
            CURLOPT_FILE => $fp,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_FAILONERROR => false,
            CURLOPT_USERAGENT => 'PromptPress/' . (defined('PP_VERSION') ? PP_VERSION : 'dev'),
        ]);
        $ok = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        fclose($fp);

        if ($ok !== true || $errno !== 0 || $http < 200 || $http >= 300) {
            @unlink($targetPath);
            throw new RuntimeException('Fallo descargando update (HTTP ' . $http . ', errno ' . $errno . '): ' . $error);
        }
    }

    private static function extractZip(string $zipPath, string $targetDir): void
    {
        if (is_dir($targetDir)) {
            self::deleteDir($targetDir);
        }
        if (!@mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
            throw new RuntimeException('No se pudo preparar directorio temporal para extracción.');
        }

        $zip = new \ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new RuntimeException('No se pudo abrir el ZIP descargado.');
        }
        if (!$zip->extractTo($targetDir)) {
            $zip->close();
            throw new RuntimeException('No se pudo extraer el ZIP de actualización.');
        }
        $zip->close();
    }

    private static function verifyPackage(
        string $packagePath,
        string $expectedChecksum,
        string $signature,
        string $signatureAlg
    ): void {
        $actualChecksum = strtolower((string) hash_file('sha256', $packagePath));
        if ($actualChecksum === '') {
            throw new RuntimeException('No se pudo calcular checksum SHA-256 del paquete.');
        }

        if ($expectedChecksum !== '') {
            $normalizedExpected = strtolower(preg_replace('/[^a-f0-9]/i', '', $expectedChecksum) ?? '');
            if ($normalizedExpected !== $actualChecksum) {
                throw new RuntimeException('Checksum SHA-256 inválido: el paquete no coincide con el esperado.');
            }
        }

        // Verificación HMAC opcional (firma en hex del checksum) para autenticidad básica.
        // Configuración esperada: updates.signature_key (secreto compartido).
        if ($signature !== '') {
            $alg = $signatureAlg !== '' ? strtolower($signatureAlg) : 'hmac-sha256';
            if ($alg !== 'hmac-sha256') {
                throw new RuntimeException('Algoritmo de firma no soportado: ' . $alg);
            }
            $key = trim((string) config('updates.signature_key', ''));
            if ($key === '') {
                throw new RuntimeException('Falta `updates.signature_key` para validar firma del paquete.');
            }

            $expectedSig = strtolower(trim($signature));
            $actualSig = strtolower(hash_hmac('sha256', $actualChecksum, $key));
            if (!hash_equals($expectedSig, $actualSig)) {
                throw new RuntimeException('Firma del paquete inválida.');
            }
        }
    }

    private static function deploy(string $extractDir): void
    {
        $root = self::resolveExtractRoot($extractDir);
        $exclude = [
            '/config/config.php',
            '/storage/uploads',
            '/storage/documents',
            '/storage/logs',
            '/storage/cache',
            '/storage/updates',
        ];

        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($it as $item) {
            /** @var \SplFileInfo $item */
            $abs = $item->getPathname();
            $rel = str_replace('\\', '/', substr($abs, strlen($root)));
            $rel = '/' . ltrim($rel, '/');

            if (self::isExcluded($rel, $exclude)) {
                continue;
            }

            $dest = PP_ROOT . $rel;
            if ($item->isDir()) {
                if (!is_dir($dest) && !@mkdir($dest, 0775, true) && !is_dir($dest)) {
                    throw new RuntimeException('No se pudo crear directorio destino: ' . $rel);
                }
                continue;
            }

            $parent = dirname($dest);
            if (!is_dir($parent) && !@mkdir($parent, 0775, true) && !is_dir($parent)) {
                throw new RuntimeException('No se pudo crear directorio padre: ' . $parent);
            }
            if (!@copy($abs, $dest)) {
                throw new RuntimeException('No se pudo copiar archivo: ' . $rel);
            }
        }
    }

    private static function runMigrations(): void
    {
        $migrator = new Migrator(\Core\Database::connection(), PP_ROOT . '/database/migrations');
        $result = $migrator->run();
        if (!empty($result['errors'])) {
            $first = $result['errors'][0];
            throw new RuntimeException('Migración fallida tras update: ' . $first['name'] . ' — ' . $first['error']);
        }
    }

    private static function resolveExtractRoot(string $extractDir): string
    {
        $entries = array_values(array_filter(scandir($extractDir) ?: [], static fn($v) => $v !== '.' && $v !== '..'));
        if (count($entries) === 1) {
            $only = $extractDir . '/' . $entries[0];
            if (is_dir($only)) {
                return $only;
            }
        }
        return $extractDir;
    }

    /** @param string[] $exclusions */
    private static function isExcluded(string $relativePath, array $exclusions): bool
    {
        $p = str_replace('\\', '/', $relativePath);
        foreach ($exclusions as $ex) {
            $ex = rtrim($ex, '/');
            if ($p === $ex || str_starts_with($p, $ex . '/')) {
                return true;
            }
        }
        return false;
    }

    private static function deleteDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }
        @rmdir($dir);
    }
}
