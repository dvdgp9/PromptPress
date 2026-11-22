<?php

declare(strict_types=1);

namespace App\Modules\Analytics;

/**
 * ANL-ADMIN — Que las visitas del propio equipo no cuenten en la analítica.
 *
 * La analítica no identifica a nadie (hash diario de IP+UA), así que la única
 * forma de reconocer a una admin cuando visita la web pública es marcar SU
 * navegador. Cookie técnica `pp_notrack`, un año, HttpOnly:
 *   '1' = este dispositivo no cuenta (se pone sola al entrar al panel);
 *   '0' = la admin pidió que sí cuente (no se vuelve a poner sola).
 *
 * Va por dispositivo: hay que haber entrado al panel una vez desde cada
 * navegador. Lo ya registrado no se toca.
 */
final class DeviceOptOut
{
    public const COOKIE = 'pp_notrack';
    private const LIFETIME_DAYS = 365;

    /** ¿Las visitas de este navegador se descartan? */
    public static function isExcluded(): bool
    {
        return ($_COOKIE[self::COOKIE] ?? null) === '1';
    }

    /** ¿La admin decidió que este dispositivo SÍ cuente? */
    public static function isExplicitlyCounted(): bool
    {
        return ($_COOKIE[self::COOKIE] ?? null) === '0';
    }

    /**
     * Al pasar por el panel: marca el dispositivo salvo que la admin haya
     * pedido expresamente que cuente. Si ya está marcado, renueva la fecha
     * una vez al mes como mucho (no en cada petición).
     */
    public static function markAdminDevice(): void
    {
        if (self::isExplicitlyCounted()) {
            return;
        }
        if (self::isExcluded() && !self::dueForRenewal()) {
            return;
        }
        self::put('1');
    }

    public static function exclude(): void
    {
        self::put('1');
    }

    public static function count(): void
    {
        self::put('0');
    }

    private static function dueForRenewal(): bool
    {
        $stamp = (int) ($_COOKIE[self::COOKIE . '_t'] ?? 0);
        return $stamp < time() - 30 * 86400;
    }

    private static function put(string $value): void
    {
        $_COOKIE[self::COOKIE] = $value;
        if (headers_sent()) {
            return;
        }
        $opts = [
            'expires'  => time() + self::LIFETIME_DAYS * 86400,
            'path'     => '/',
            'domain'   => '',
            'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
            'httponly' => true,
            // Lax: la visita a la web llega como navegación normal y el
            // beacon de /_analytics/collect es del mismo sitio.
            'samesite' => 'Lax',
        ];
        setcookie(self::COOKIE, $value, $opts);
        setcookie(self::COOKIE . '_t', (string) time(), $opts);
    }
}
