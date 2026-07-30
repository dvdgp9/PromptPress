<?php

declare(strict_types=1);

namespace App\Services;

use PromptPress\Database\Migrator;
use RuntimeException;

final class UpdateInstallerService
{
    /** Tamaño máximo del paquete subido a mano. */
    public const MAX_PACKAGE_BYTES = 200 * 1024 * 1024;

    /**
     * Archivos/carpetas que TIENEN que estar en el zip para considerarlo un
     * paquete de PromptPress. Es la diferencia entre actualizar y volcar
     * cualquier cosa sobre la raíz del proyecto.
     */
    private const PACKAGE_FINGERPRINT = ['index.php', 'app', 'core', 'config/constants.php', 'database/migrations'];

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
        self::installPackage($packagePath, $extractDir);

        return ['backup' => $backupPath, 'package' => $packagePath, 'version' => $version !== '' ? $version : null];
    }

    /**
     * UPD — Actualiza desde un ZIP subido a mano desde el panel.
     *
     * Misma tubería que `apply()` a partir de la verificación: lo único que
     * cambia es de dónde sale el paquete. El checksum es OPCIONAL y lo pega el
     * usuario; sirve para detectar un zip corrupto o cambiado por el camino, no
     * para autenticar el origen (quien sube el archivo ya tiene acceso al panel).
     *
     * @param array<string,mixed> $file entrada de $_FILES
     * @return array{backup:string, package:string, version:?string}
     */
    public static function applyFromUpload(array $file, string $expectedChecksum = ''): array
    {
        self::ensureRequirements();
        self::ensureDirs();

        $error = self::validateUploadedPackage($file);
        if ($error !== null) {
            throw new RuntimeException($error);
        }

        $stamp = date('Ymd_His');
        $base = "update_{$stamp}_manual";
        $backupPath = PP_STORAGE . '/updates/backups/' . $base . '.zip';
        $packagePath = PP_STORAGE . '/updates/packages/' . $base . '.zip';
        $extractDir = PP_STORAGE . '/updates/extracted/' . $base;

        $tmp = (string) $file['tmp_name'];
        $moved = is_uploaded_file($tmp) ? move_uploaded_file($tmp, $packagePath) : rename($tmp, $packagePath);
        if (!$moved || !is_file($packagePath)) {
            throw new RuntimeException('No se pudo guardar el paquete subido.');
        }

        // Checksum antes de tocar nada: si el archivo llegó mal, no hay ni backup
        // ni despliegue a medias que deshacer.
        self::verifyPackage($packagePath, $expectedChecksum, '', '');

        self::createBackup($backupPath);
        $version = self::installPackage($packagePath, $extractDir);

        return ['backup' => $backupPath, 'package' => $packagePath, 'version' => $version];
    }

    /**
     * Extrae, comprueba que es PromptPress, despliega y migra — con el sitio en
     * mantenimiento mientras dura el copiado.
     *
     * @return string|null versión declarada por el paquete, si la trae
     */
    private static function installPackage(string $packagePath, string $extractDir): ?string
    {
        self::extractZip($packagePath, $extractDir);

        $root = self::resolveExtractRoot($extractDir);
        self::assertLooksLikePromptPress($root);
        $version = self::packageVersion($root);

        MaintenanceMode::enable('Actualizando PromptPress');
        try {
            self::deploy($extractDir);
            self::runMigrations();
        } finally {
            // Pase lo que pase, el sitio vuelve a estar en línea: dejarlo caído
            // por un error de despliegue sería el peor final posible.
            MaintenanceMode::disable();
        }

        return $version;
    }

    /**
     * UPD — Copias de seguridad disponibles, de la más reciente a la más vieja.
     *
     * @return array<int,array{name:string,size:int,size_human:string,created_at:string}>
     */
    public static function backups(): array
    {
        $dir = PP_STORAGE . '/updates/backups';
        if (!is_dir($dir)) return [];

        $out = [];
        foreach (glob($dir . '/*.zip') ?: [] as $path) {
            $out[] = [
                'name'       => basename($path),
                'size'       => (int) filesize($path),
                'size_human' => self::humanBytes((int) filesize($path)),
                'created_at' => date('Y-m-d H:i', (int) filemtime($path)),
            ];
        }
        usort($out, static fn ($a, $b) => strcmp($b['name'], $a['name']));
        return $out;
    }

    /**
     * UPD — Vuelve a una copia de seguridad.
     *
     * Restaura ARCHIVOS, no base de datos: las migraciones ya aplicadas siguen
     * aplicadas. Es la vuelta atrás de una actualización que rompió el código,
     * no un viaje en el tiempo completo.
     */
    public static function restore(string $backupName): array
    {
        self::ensureRequirements();
        self::ensureDirs();

        if (preg_match('/^update_[0-9]{8}_[0-9]{6}_[A-Za-z0-9._-]+\.zip$/', $backupName) !== 1) {
            throw new RuntimeException('Nombre de copia no válido.');
        }
        $path = PP_STORAGE . '/updates/backups/' . $backupName;
        if (!is_file($path)) {
            throw new RuntimeException('Esa copia de seguridad ya no está en el servidor.');
        }

        // Antes de restaurar, una copia del estado ACTUAL: si la copia elegida
        // resulta ser peor, todavía se puede volver.
        $safety = PP_STORAGE . '/updates/backups/update_' . date('Ymd_His') . '_prerestore.zip';
        self::createBackup($safety);

        $extractDir = PP_STORAGE . '/updates/extracted/restore_' . date('Ymd_His');
        self::extractZip($path, $extractDir);

        $root = self::resolveExtractRoot($extractDir);
        self::assertLooksLikePromptPress($root);

        MaintenanceMode::enable('Restaurando una copia de seguridad');
        try {
            self::deploy($extractDir);
        } finally {
            MaintenanceMode::disable();
        }

        self::deleteDir($extractDir);

        return ['restored' => $backupName, 'safety_backup' => basename($safety)];
    }

    /**
     * @return string|null mensaje de error, o null si el archivo subido sirve
     */
    private static function validateUploadedPackage(mixed $file): ?string
    {
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return 'Selecciona el archivo ZIP de la actualización.';
        }
        if ((int) ($file['error'] ?? UPLOAD_ERR_OK) === UPLOAD_ERR_INI_SIZE) {
            return 'El ZIP supera el tamaño máximo que admite este servidor (revisa upload_max_filesize).';
        }
        if ((int) ($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            return 'La subida no se completó. Vuelve a intentarlo.';
        }
        $size = (int) ($file['size'] ?? 0);
        if ($size <= 0) return 'El archivo está vacío.';
        if ($size > self::MAX_PACKAGE_BYTES) {
            return 'El paquete supera los ' . (int) (self::MAX_PACKAGE_BYTES / 1024 / 1024) . ' MB.';
        }
        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_file($tmp)) return 'El archivo recibido no es válido.';

        // Cabecera real de ZIP: "PK\x03\x04".
        $fh = @fopen($tmp, 'rb');
        $head = $fh !== false ? (string) fread($fh, 4) : '';
        if ($fh !== false) fclose($fh);
        if (strncmp($head, "PK\x03\x04", 4) !== 0) {
            return 'Ese archivo no es un ZIP.';
        }
        return null;
    }

    /**
     * El paquete tiene que parecer PromptPress. Sin esto, un zip cualquiera se
     * volcaría sobre la raíz del proyecto y dejaría la instalación inservible.
     */
    private static function assertLooksLikePromptPress(string $root): void
    {
        $missing = [];
        foreach (self::PACKAGE_FINGERPRINT as $needle) {
            if (!file_exists($root . '/' . $needle)) $missing[] = $needle;
        }
        if ($missing !== []) {
            throw new RuntimeException(
                'El ZIP no parece un paquete de PromptPress: falta ' . implode(', ', $missing)
                . '. No se ha tocado nada.'
            );
        }
    }

    /** Versión declarada en `config/constants.php` del paquete, si se puede leer. */
    private static function packageVersion(string $root): ?string
    {
        $file = $root . '/config/constants.php';
        if (!is_file($file)) return null;
        $src = (string) @file_get_contents($file);
        if (preg_match("/PP_VERSION'\s*,\s*'([^']+)'/", $src, $m) === 1) {
            return $m[1];
        }
        return null;
    }

    private static function humanBytes(int $bytes): string
    {
        if ($bytes >= 1024 * 1024) return number_format($bytes / (1024 * 1024), 1, ',', '.') . ' MB';
        return max(1, (int) round($bytes / 1024)) . ' KB';
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
            '/storage/maintenance.flag',
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
        // El Migrator vive fuera del autoload (namespace `PromptPress\Database`,
        // carpeta `database/`), así que hay que cargarlo a mano igual que hacen
        // `database/migrate.php` y `Core\App`. Sin esto, la actualización
        // desplegaba los archivos y moría justo después, al migrar: código nuevo
        // con base de datos vieja y un error fatal en pantalla.
        require_once PP_ROOT . '/database/Migrator.php';

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
