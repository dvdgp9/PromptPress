<?php

declare(strict_types=1);

namespace IaiaAnalytics;

/**
 * EventRecorder — ingesta de eventos de analítica.
 *
 * Portado de PromptPress (app/Modules/Analytics/EventRecorder.php) con las
 * mismas reglas de privacidad:
 *
 *  - NO se persiste la IP ni el User-Agent. Solo se usan en memoria para
 *    calcular el hash de visitante y derivar dispositivo/navegador.
 *  - visitor_hash = primeros 16 bytes (hex) de sha256(salt_del_día·site·IP·UA).
 *  - El salt es aleatorio, distinto cada día, y se purga a los 2 días
 *    (purgeOldSalts) → pasado ese plazo re-identificar es inviable.
 *
 * Cambios respecto al origen: $wpdb en lugar de Core\Database, hash/salt en
 * hex, y la auto-referencia se compara contra home_url() (no HTTP_HOST).
 * Nunca lanza hacia el llamador: los errores se tragan para no romper nada.
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
        string $eventType,
        string $path,
        ?string $referrer,
        string $ip,
        string $userAgent
    ): bool {
        global $wpdb;
        try {
            if (self::isBot($userAgent)) {
                return false;
            }

            $eventType = self::sanitizeEventType($eventType);
            $path      = self::sanitizePath($path);
            $refHost   = self::referrerHost($referrer);
            $device    = self::deviceFromUa($userAgent);
            $browser   = self::browserFromUa($userAgent);
            $hash      = self::visitorHash(self::anonymizeIp($ip), $userAgent);

            $inserted = $wpdb->insert(Schema::events(), [
                'site_id'       => Schema::SITE_ID,
                'created_at'    => current_time('mysql'),
                'event_type'    => $eventType,
                'path'          => $path,
                'referrer_host' => $refHost,
                'device'        => $device,
                'browser'       => $browser,
                'visitor_hash'  => $hash,
            ]);
            return $inserted !== false;
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
        if ($type === '') {
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
     * si el navegador no lo envía. Así "Directo" = referrer_host NULL.
     */
    private static function referrerHost(?string $referrer): ?string
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
        // Descartar auto-referencias (mismo host que esta web, con o sin www.).
        $ownHost = strtolower((string) parse_url(home_url(), PHP_URL_HOST));
        $ownHost = preg_replace('/^www\./', '', $ownHost) ?? $ownHost;
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
     * Trunca la IP ANTES de usarla en el hash (anonimización, no solo
     * seudonimización): IPv4 pierde el último octeto (/24) e IPv6 se queda
     * con los 3 primeros grupos (/48). Así ni siquiera con la sal del día
     * en la mano puede recomputarse el hash de una IP concreta.
     */
    private static function anonymizeIp(string $ip): string
    {
        if (str_contains($ip, ':')) {
            $parts = explode(':', $ip);
            return implode(':', array_slice($parts, 0, 3)) . '::';
        }
        $parts = explode('.', $ip);
        if (count($parts) === 4) {
            $parts[3] = '0';
            return implode('.', $parts);
        }
        return $ip;
    }

    /**
     * Hash de visitante del día: 16 bytes (hex) de sha256(salt·site·ip·ua),
     * con la IP ya truncada. El salt diario (que solo vive el día en curso,
     * ver purgeOldSalts) hace imposible reconstruir el hash a posteriori.
     */
    private static function visitorHash(string $ip, string $ua): string
    {
        $salt = self::saltForToday();
        return substr(hash('sha256', $salt . '|' . Schema::SITE_ID . '|' . $ip . '|' . $ua), 0, 32);
    }

    /** Obtiene (o crea) el salt aleatorio de hoy (hex), tolerando concurrencia. */
    private static function saltForToday(): string
    {
        global $wpdb;
        $table = Schema::salts();
        $today = current_time('Y-m-d');

        $salt = $wpdb->get_var($wpdb->prepare(
            "SELECT salt FROM {$table} WHERE day = %s LIMIT 1",
            $today
        ));
        if (is_string($salt) && $salt !== '') {
            return $salt;
        }

        $new = bin2hex(random_bytes(32));
        // Día nuevo: la sal de ayer muere ya, sin esperar al rollup.
        self::purgeOldSalts();
        // INSERT IGNORE: si otra request lo creó a la vez, no pisamos.
        $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO {$table} (day, salt) VALUES (%s, %s)",
            $today,
            $new
        ));
        // Releer el valor efectivo (puede ser el de la otra request).
        $salt = $wpdb->get_var($wpdb->prepare(
            "SELECT salt FROM {$table} WHERE day = %s LIMIT 1",
            $today
        ));
        return is_string($salt) && $salt !== '' ? $salt : $new;
    }

    /**
     * Purga toda sal que no sea la del día en curso (privacidad): en cuanto
     * empieza un día nuevo, la sal de ayer desaparece y los hashes pasados
     * son irrecuperables. Se llama desde el rollup y al crear la sal del día.
     */
    public static function purgeOldSalts(): void
    {
        global $wpdb;
        try {
            $table = Schema::salts();
            $wpdb->query($wpdb->prepare(
                "DELETE FROM {$table} WHERE day < %s",
                current_time('Y-m-d')
            ));
        } catch (\Throwable $e) {
            // no-op
        }
    }
}
