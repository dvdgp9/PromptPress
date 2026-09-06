<?php

declare(strict_types=1);

namespace App\Services;

use Core\Database;

final class UpdateService
{
    private const KEY_LAST_CHECK = 'updates_last_check';
    private const KEY_LAST_RESULT = 'updates_last_result';

    /**
     * @return array{
     *   current_version:string,
     *   latest_version:?string,
     *   has_update:bool,
     *   checked_at:?string,
     *   source:string,
     *   status:string,
     *   message:string,
     *   changelog_url:?string,
     *   download_url:?string,
     *   checksum_sha256:?string,
     *   signature:?string,
     *   signature_alg:?string
     * }
     */
    public static function status(int $siteId): array
    {
        $current = defined('PP_VERSION') ? (string) PP_VERSION : '0.0.0';
        $raw = self::getSetting($siteId, self::KEY_LAST_RESULT);
        $checkedAt = self::getSetting($siteId, self::KEY_LAST_CHECK);

        $base = [
            'current_version' => $current,
            'latest_version' => null,
            'has_update' => false,
            'checked_at' => $checkedAt !== '' ? $checkedAt : null,
            'source' => 'none',
            'status' => 'idle',
            'message' => __('upd.never_checked'),
            'changelog_url' => null,
            'download_url' => null,
            'checksum_sha256' => null,
            'signature' => null,
            'signature_alg' => null,
        ];

        if ($raw === '') {
            return $base;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            $base['status'] = 'error';
            $base['message'] = __('upd.bad_cached');
            return $base;
        }

        return array_merge($base, [
            'latest_version' => self::strOrNull($decoded['latest_version'] ?? null),
            'has_update' => (bool) ($decoded['has_update'] ?? false),
            'source' => (string) ($decoded['source'] ?? 'remote'),
            'status' => (string) ($decoded['status'] ?? 'ok'),
            'message' => (string) ($decoded['message'] ?? __('upd.check_done')),
            'changelog_url' => self::strOrNull($decoded['changelog_url'] ?? null),
            'download_url' => self::strOrNull($decoded['download_url'] ?? null),
            'checksum_sha256' => self::strOrNull($decoded['checksum_sha256'] ?? null),
            'signature' => self::strOrNull($decoded['signature'] ?? null),
            'signature_alg' => self::strOrNull($decoded['signature_alg'] ?? null),
        ]);
    }

    public static function checkNow(int $siteId): array
    {
        $current = defined('PP_VERSION') ? (string) PP_VERSION : '0.0.0';

        // UPD-GH — Orden de precedencia, de más mandón a menos:
        //   1. `updates.version_check_url` — endpoint propio. Es el camino del
        //      día que las descargas pidan licencia, y por eso gana: quien lo
        //      configura lo hace a conciencia.
        //   2. `updates.github_repo` — este sitio mira otro repo.
        //   3. `PP_UPDATES_GITHUB_REPO` — el repo del producto, que viaja en el
        //      paquete: por eso una instalación actualizada ya sabe dónde mirar
        //      sin que nadie edite `config.php`.
        $endpoint = trim((string) config('updates.version_check_url', ''));
        if ($endpoint !== '') {
            $result = self::callRemote($endpoint, $siteId, $current);
            self::storeResult($siteId, $result);
            return self::status($siteId);
        }

        $repo = self::githubRepo();
        if ($repo !== '') {
            $result = self::callGithub($repo, $current);
            self::storeResult($siteId, $result);
            return self::status($siteId);
        }

        // Hay dos maneras de quedarse sin canal y NO son la misma: no haber
        // configurado nada (normal, informativo) o haber escrito algo que no
        // vale (una errata que hay que ver, o el sitio se queda sin updates sin
        // que nadie se entere).
        $raw = self::rawGithubRepo();
        $result = $raw !== ''
            ? self::failure('mock', __('upd.err.github_bad_repo', ['repo' => $raw]), $current)
            : self::failure('mock', __('upd.err.no_channel'), $current, 'ok');
        self::storeResult($siteId, $result);
        return self::status($siteId);
    }

    /** El repo tal cual está configurado, sin validar. Solo para poder avisar. */
    private static function rawGithubRepo(): string
    {
        $repo = trim((string) config('updates.github_repo', ''));
        if ($repo === '' && defined('PP_UPDATES_GITHUB_REPO')) {
            $repo = trim((string) PP_UPDATES_GITHUB_REPO);
        }
        return $repo;
    }

    /**
     * Repo `owner/nombre` del que salen las releases, o cadena vacía si no hay
     * ninguno válido. Se valida el formato porque acaba dentro de una URL: un
     * valor con barras o espacios construiría una petición a saber dónde.
     */
    public static function githubRepo(): string
    {
        $repo = self::rawGithubRepo();
        // Un valor mal escrito NO cae al repo del producto: preferimos que el
        // panel avise a que el sitio esté mirando, calladito, un repo distinto
        // del que su dueño cree haber configurado.
        return preg_match('~^[A-Za-z0-9._-]{1,100}/[A-Za-z0-9._-]{1,100}$~', $repo) === 1 ? $repo : '';
    }

    /**
     * Consulta la API pública de GitHub Releases.
     *
     * El canal `stable` usa `/releases/latest`, que ya se salta borradores y
     * prelanzamientos. Cualquier otro canal mira la lista entera y coge la
     * primera publicación no borrador, prelanzamientos incluidos: así se puede
     * probar una beta sin tocar el canal estable de nadie.
     *
     * @return array<string,mixed>
     */
    private static function callGithub(string $repo, string $current): array
    {
        $channel = strtolower(trim((string) config('updates.channel', 'stable')));
        $url = $channel === 'stable'
            ? 'https://api.github.com/repos/' . $repo . '/releases/latest'
            : 'https://api.github.com/repos/' . $repo . '/releases?per_page=10';

        [$body, $errno, $error, $http] = self::httpGet($url);

        if ($errno !== 0 || !is_string($body)) {
            return self::failure('github', __('upd.err.request', ['detalle' => $error]), $current);
        }
        // 404 en `/releases/latest` significa "este repo todavía no ha publicado
        // nada", no que algo esté roto. Enseñarlo como error asustaría sin motivo.
        if ($http === 404) {
            return self::failure('github', __('upd.err.github_none', ['repo' => $repo]), $current, 'ok');
        }
        if ($http === 403 || $http === 429) {
            return self::failure('github', __('upd.err.github_rate'), $current);
        }
        if ($http < 200 || $http >= 300) {
            return self::failure('github', __('upd.err.github_http', ['status' => (string) $http]), $current);
        }

        $json = json_decode($body, true);
        if (!is_array($json)) {
            return self::failure('github', __('upd.err.bad_response'), $current);
        }
        // La lista devuelve un array de releases; `latest`, una sola. Se mira la
        // clave 0 y no `array_is_list()` porque composer.json admite PHP 8.0 y
        // esa función es de 8.1: en un hosting viejo esto sería un fatal.
        if (array_key_exists(0, $json)) {
            $json = self::firstPublishedRelease($json);
            if ($json === null) {
                return self::failure('github', __('upd.err.github_none', ['repo' => $repo]), $current, 'ok');
            }
        }

        $parsed = self::parseGithubRelease($json, $current);
        // El SHA-256 no lo publica GitHub: lo subimos nosotros como segundo
        // asset y se lee ahora, para que `verifyPackage()` tenga con qué
        // comparar antes de desplegar nada.
        if ($parsed['status'] === 'ok' && $parsed['checksum_url'] !== null) {
            $parsed['checksum_sha256'] = self::fetchChecksum((string) $parsed['checksum_url']);
        }
        unset($parsed['checksum_url']);
        return $parsed;
    }

    /**
     * Traduce una release de GitHub al formato que guarda `storeResult()`.
     *
     * Separado de la llamada HTTP para poder probarlo con una respuesta real
     * guardada, sin depender de la red ni del límite de la API.
     *
     * @param array<string,mixed> $release
     * @return array<string,mixed>
     */
    public static function parseGithubRelease(array $release, string $current): array
    {
        $tag = trim((string) ($release['tag_name'] ?? ''));
        // Las etiquetas suelen llevar `v` delante; la versión instalada no.
        $latest = ltrim($tag, 'vV');
        if ($latest === '') {
            return self::failure('github', __('upd.err.bad_response'), $current) + ['checksum_url' => null];
        }

        $assets = is_array($release['assets'] ?? null) ? $release['assets'] : [];
        $package = null;
        $checksumUrl = null;
        foreach ($assets as $asset) {
            if (!is_array($asset)) continue;
            $name = strtolower(trim((string) ($asset['name'] ?? '')));
            $url = self::strOrNull($asset['browser_download_url'] ?? null);
            if ($url === null || $name === '') continue;
            if ($package === null && str_ends_with($name, '.zip')) {
                $package = $url;
            } elseif ($checksumUrl === null && str_ends_with($name, '.sha256')) {
                $checksumUrl = $url;
            }
        }

        if ($package === null) {
            return self::failure('github', __('upd.err.github_no_zip', ['version' => $latest]), $current)
                + ['checksum_url' => null];
        }

        $hasUpdate = self::isNewer($latest, $current);
        return [
            'source' => 'github',
            'status' => 'ok',
            'latest_version' => $latest,
            'has_update' => $hasUpdate,
            'message' => __($hasUpdate ? 'upd.available' : 'upd.up_to_date'),
            'changelog_url' => self::strOrNull($release['html_url'] ?? null),
            'download_url' => $package,
            'checksum_sha256' => null,
            'signature' => null,
            'signature_alg' => null,
            'checksum_url' => $checksumUrl,
        ];
    }

    /**
     * Primera release publicada de la lista (ni borrador). Los prelanzamientos
     * SÍ cuentan aquí: a esta rama solo se llega con un canal que no es estable.
     *
     * @param array<int,mixed> $releases
     * @return array<string,mixed>|null
     */
    private static function firstPublishedRelease(array $releases): ?array
    {
        foreach ($releases as $release) {
            if (is_array($release) && empty($release['draft'])) {
                return $release;
            }
        }
        return null;
    }

    /**
     * Lee el `.sha256` que acompaña al paquete. Formato de `shasum`:
     * `<64 hex>  <nombre de archivo>`. Si no se puede leer se devuelve null y
     * la instalación sigue: el checksum es una comprobación extra, no un
     * requisito (el zip subido a mano tampoco lo lleva).
     */
    private static function fetchChecksum(string $url): ?string
    {
        [$body, $errno, , $http] = self::httpGet($url);
        if ($errno !== 0 || !is_string($body) || $http < 200 || $http >= 300) {
            return null;
        }
        return preg_match('/\b([a-f0-9]{64})\b/i', $body, $m) === 1 ? strtolower($m[1]) : null;
    }

    /**
     * GET con las cabeceras que pide GitHub (sin User-Agent contesta 403).
     *
     * @return array{0:string|bool, 1:int, 2:string, 3:int} cuerpo, errno, error, http
     */
    private static function httpGet(string $url): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTPHEADER => [
                'Accept: application/vnd.github+json',
                'X-GitHub-Api-Version: 2022-11-28',
            ],
            CURLOPT_USERAGENT => 'PromptPress/' . (defined('PP_VERSION') ? PP_VERSION : 'dev'),
            CURLOPT_TIMEOUT => 12,
        ]);
        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return [$body, $errno, $error, $http];
    }

    /**
     * Resultado sin novedad: se conserva la versión instalada como "última"
     * para que el panel no enseñe un hueco, y nunca se marca `has_update`.
     *
     * @return array<string,mixed>
     */
    private static function failure(string $source, string $message, string $current, string $status = 'error'): array
    {
        return [
            'source' => $source,
            'status' => $status,
            'latest_version' => $current,
            'has_update' => false,
            'message' => $message,
            'changelog_url' => null,
            'download_url' => null,
            'checksum_sha256' => null,
            'signature' => null,
            'signature_alg' => null,
        ];
    }

    private static function callRemote(string $endpoint, int $siteId, string $current): array
    {
        $payload = [
            'product' => 'promptpress',
            'site_id' => $siteId,
            'current_version' => $current,
            'license_key' => trim((string) config('updates.license_key', '')),
            'channel' => trim((string) config('updates.channel', 'stable')),
        ];

        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES),
            CURLOPT_TIMEOUT => 12,
        ]);
        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($errno !== 0 || !is_string($body) || $http < 200 || $http >= 300) {
            return [
                'source' => 'remote',
                'status' => 'error',
                'latest_version' => $current,
                'has_update' => false,
                'message' => $errno !== 0 ? __('upd.err.request', ['detalle' => $error]) : __('upd.err.http', ['status' => (string) $http]),
                'changelog_url' => null,
                'download_url' => null,
                'checksum_sha256' => null,
                'signature' => null,
                'signature_alg' => null,
            ];
        }

        $json = json_decode($body, true);
        if (!is_array($json)) {
            return [
                'source' => 'remote',
                'status' => 'error',
                'latest_version' => $current,
                'has_update' => false,
                'message' => __('upd.err.bad_response'),
                'changelog_url' => null,
                'download_url' => null,
                'checksum_sha256' => null,
                'signature' => null,
                'signature_alg' => null,
            ];
        }

        $latest = trim((string) ($json['latest'] ?? $current));
        $hasUpdate = self::isNewer($latest, $current);

        return [
            'source' => 'remote',
            'status' => 'ok',
            'latest_version' => $latest,
            'has_update' => $hasUpdate,
            'message' => (string) ($json['message'] ?? __($hasUpdate ? 'upd.available' : 'upd.up_to_date')),
            'changelog_url' => self::strOrNull($json['changelog_url'] ?? null),
            'download_url' => self::strOrNull($json['download_url'] ?? null),
            'checksum_sha256' => self::strOrNull($json['checksum_sha256'] ?? null),
            'signature' => self::strOrNull($json['signature'] ?? null),
            'signature_alg' => self::strOrNull($json['signature_alg'] ?? null),
        ];
    }

    private static function storeResult(int $siteId, array $payload): void
    {
        self::upsertSetting($siteId, self::KEY_LAST_RESULT, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        self::upsertSetting($siteId, self::KEY_LAST_CHECK, date('c'));
    }

    private static function getSetting(int $siteId, string $key): string
    {
        $row = Database::selectOne(
            'SELECT setting_value FROM settings WHERE site_id = ? AND setting_key = ? LIMIT 1',
            [$siteId, $key]
        );
        return (string) ($row['setting_value'] ?? '');
    }

    private static function upsertSetting(int $siteId, string $key, string $value): void
    {
        Database::execute(
            'INSERT INTO settings (site_id, setting_key, setting_value, is_encrypted)
             VALUES (?, ?, ?, 0)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)',
            [$siteId, $key, $value]
        );
    }

    private static function isNewer(string $latest, string $current): bool
    {
        $a = preg_replace('/[^0-9.]/', '', $latest) ?: '0';
        $b = preg_replace('/[^0-9.]/', '', $current) ?: '0';
        return version_compare($a, $b, '>');
    }

    private static function strOrNull(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $v = trim($value);
        return $v === '' ? null : $v;
    }
}
