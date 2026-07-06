<?php

declare(strict_types=1);

namespace App\Modules\Analytics;

use Core\Database;

/**
 * EventRecorder — ingesta de eventos de analítica (FEAT-3 A3).
 *
 * Registra un evento (pageview o evento con nombre) en `analytics_events`
 * respetando las reglas de privacidad del diseño (cursor/analytics-design.md):
 *
 *  - NO se persiste la IP ni el User-Agent. Solo se usan en memoria para
 *    calcular el hash de visitante y derivar dispositivo/navegador.
 *  - visitor_hash = primeros 16 bytes de sha256(salt_del_día · site · IP · UA).
 *  - El salt es aleatorio, distinto cada día, y se purga a los 2 días
 *    (purgeOldSalts) → pasado ese plazo re-identificar es inviable.
 *
 * Se usa desde el endpoint público /_analytics/collect y, server-side, desde
 * el envío de formularios (A6). Nunca debe lanzar hacia el llamador: los
 * errores se tragan para no romper ni el pageview ni el submit del formulario.
 */
final class EventRecorder
{
    /** Patrones de User-Agent que descartamos como bots/automatismos. */
    private const BOT_PATTERN = '/bot|crawl|spider|slurp|headless|lighthouse|preview|monitor|curl|wget|python-|axios|http-client|facebookexternalhit|embedly|whatsapp|telegram/i';

    /**
     * Registra un evento. Devuelve true si se insertó, false si se descartó
     * (bot, datos inválidos) o falló silenciosamente.
     */
    public static function record(
        int $siteId,
        string $eventType,
        string $path,
        ?string $referrer,
        string $ip,
        string $userAgent
    ): bool {
        try {
            if (self::isBot($userAgent)) {
                return false;
            }

            $eventType = self::sanitizeEventType($eventType);
            $path      = self::sanitizePath($path);
            $refHost   = self::referrerHost($referrer, $siteId);
            $device    = self::deviceFromUa($userAgent);
            $browser   = self::browserFromUa($userAgent);
            $hash      = self::visitorHash($siteId, $ip, $userAgent);

            Database::execute(
                'INSERT INTO analytics_events
                    (site_id, created_at, event_type, path, referrer_host, device, browser, visitor_hash)
                 VALUES (?, NOW(), ?, ?, ?, ?, ?, ?)',
                [$siteId, $eventType, $path, $refHost, $device, $browser, $hash]
            );
            return true;
        } catch (\Throwable $e) {
            // La analítica jamás debe romper la petición que la origina.
            return false;
        }
    }

    private static function isBot(string $ua): bool
    {
        return $ua === '' || preg_match(self::BOT_PATTERN, $ua) === 1;
    }

    private static function sanitizeEventType(string $type): string
    {
        $type = strtolower(trim($type));
        if ($type === '' ) {
            return 'pageview';
        }
        // Solo minúsculas, dígitos y guion bajo; colapsar y recortar guiones.
        $type = preg_replace('/[^a-z0-9_]+/', '_', $type) ?? '';
        $type = trim($type, '_');
        return $type === '' ? 'pageview' : substr($type, 0, 50);
    }

    private static function sanitizePath(string $path): string
    {
        // Quedarnos solo con el path (sin query ni fragmento) para no explotar
        // la cardinalidad ni guardar datos sensibles en la URL.
        $path = (string) parse_url($path, PHP_URL_PATH);
        if ($path === '') {
            $path = '/';
        }
        if ($path[0] !== '/') {
            $path = '/' . $path;
        }
        return substr($path, 0, 255);
    }

    /**
     * Reduce el referrer a su host, y lo descarta (null) si es interno o
     * si el sitio no lo envía. Así "Directo" = referrer_host NULL.
     */
    private static function referrerHost(?string $referrer, int $siteId): ?string
    {
        if ($referrer === null || trim($referrer) === '') {
            return null;
        }
        $host = parse_url(trim($referrer), PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return null;
        }
        $host = strtolower($host);
        // Normalizar "www." para agrupar www.google.com con google.com.
        $host = preg_replace('/^www\./', '', $host) ?? $host;
        // Descartar auto-referencias (mismo host que el sitio, con o sin www.).
        $ownHost = preg_replace('/^www\./', '', strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''))) ?? '';
        if ($ownHost !== '' && $host === $ownHost) {
            return null;
        }
        return substr($host, 0, 120);
    }

    private static function deviceFromUa(string $ua): string
    {
        if (preg_match('/iPad|Tablet|PlayBook|Silk|(?=.*Android)(?!.*Mobile)/i', $ua)) {
            return 'tablet';
        }
        if (preg_match('/Mobi|Android.*Mobile|iPhone|iPod|Windows Phone|BlackBerry|Opera Mini/i', $ua)) {
            return 'mobile';
        }
        return 'desktop';
    }

    private static function browserFromUa(string $ua): ?string
    {
        // Orden importa: Edge/Chrome se identifican como otros; comprobar antes.
        if (preg_match('/Edg[eA]?\//i', $ua))                 return 'edge';
        if (preg_match('/OPR\/|Opera/i', $ua))                return 'opera';
        if (preg_match('/Firefox\//i', $ua))                  return 'firefox';
        if (preg_match('/Chrome\/|CriOS/i', $ua))             return 'chrome';
        if (preg_match('/Safari\//i', $ua))                   return 'safari';
        return 'other';
    }

    /**
     * Hash de visitante del día: 16 bytes de sha256(salt · site · ip · ua).
     * El salt diario hace imposible reconstruir el hash una vez purgado.
     */
    private static function visitorHash(int $siteId, string $ip, string $ua): string
    {
        $salt = self::saltForToday();
        return substr(hash('sha256', $salt . '|' . $siteId . '|' . $ip . '|' . $ua, true), 0, 16);
    }

    /** Obtiene (o crea) el salt aleatorio de hoy, tolerando concurrencia. */
    private static function saltForToday(): string
    {
        $today = date('Y-m-d');
        $row = Database::selectOne(
            'SELECT salt FROM analytics_salts WHERE day = ? LIMIT 1',
            [$today]
        );
        if ($row !== null) {
            return (string) $row['salt'];
        }

        $salt = random_bytes(32);
        // INSERT IGNORE: si otra request lo creó a la vez, no pisamos.
        Database::execute(
            'INSERT IGNORE INTO analytics_salts (day, salt) VALUES (?, ?)',
            [$today, $salt]
        );
        // Releer el valor efectivo (puede ser el de la otra request).
        $row = Database::selectOne(
            'SELECT salt FROM analytics_salts WHERE day = ? LIMIT 1',
            [$today]
        );
        return $row !== null ? (string) $row['salt'] : $salt;
    }

    /** Purga salts con más de 2 días (privacidad). Barato; llamar en el rollup. */
    public static function purgeOldSalts(): void
    {
        try {
            Database::execute('DELETE FROM analytics_salts WHERE day < (CURRENT_DATE - INTERVAL 2 DAY)');
        } catch (\Throwable $e) {
            // no-op
        }
    }
}
