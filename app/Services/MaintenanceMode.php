<?php

declare(strict_types=1);

namespace App\Services;

/**
 * UPD — Modo mantenimiento durante una actualización.
 *
 * Mientras se copian los archivos nuevos, el sitio está a medias: una visita
 * puede cargar una página cuyo PHP ya se ha sustituido y cuya dependencia
 * todavía no. Durante ese rato el público ve una página de "volvemos enseguida"
 * con HTTP 503.
 *
 * El PANEL sigue accesible a propósito: quien está actualizando no puede
 * quedarse fuera, y si algo falla necesita llegar al botón de restaurar.
 *
 * La marca es un fichero, no un ajuste en base de datos: tiene que funcionar
 * aunque la actualización deje el código a medias o la BD no responda.
 */
final class MaintenanceMode
{
    /** Si la marca es más vieja que esto, se ignora: algo se quedó a medias. */
    private const STALE_SECONDS = 900;

    public static function enable(string $reason = ''): void
    {
        @file_put_contents(self::flagPath(), json_encode([
            'since'  => date('c'),
            'reason' => $reason,
        ], JSON_UNESCAPED_UNICODE), LOCK_EX);
    }

    public static function disable(): void
    {
        $path = self::flagPath();
        if (is_file($path)) @unlink($path);
    }

    public static function isActive(): bool
    {
        $path = self::flagPath();
        if (!is_file($path)) return false;

        // Una marca vieja significa que una actualización se cortó a mitad (un
        // fatal, un timeout). Dejar el sitio caído para siempre por eso sería
        // peor que el riesgo de servirlo: se ignora y se limpia.
        if (@filemtime($path) < time() - self::STALE_SECONDS) {
            @unlink($path);
            return false;
        }
        return true;
    }

    /** @return array{since:?string,reason:string} */
    public static function info(): array
    {
        $raw = @file_get_contents(self::flagPath());
        $data = is_string($raw) ? json_decode($raw, true) : null;
        return [
            'since'  => is_array($data) ? (string) ($data['since'] ?? '') : null,
            'reason' => is_array($data) ? (string) ($data['reason'] ?? '') : '',
        ];
    }

    /** Página que ve el visitante. Sin dependencias: puede faltar medio código. */
    public static function render(): string
    {
        return '<!doctype html><html lang="es"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>Volvemos enseguida</title><style>'
            . 'body{margin:0;min-height:100vh;display:grid;place-items:center;background:#f8fafc;'
            . 'font-family:system-ui,-apple-system,"Segoe UI",Helvetica,Arial,sans-serif;color:#1f2937}'
            . '.box{max-width:420px;padding:32px;text-align:center}'
            . 'h1{margin:0 0 12px;font-size:1.35rem}p{margin:0;color:#6b7280;line-height:1.6}'
            . '</style></head><body><div class="box">'
            . '<h1>Volvemos enseguida</h1>'
            . '<p>Estamos aplicando una actualización. En un par de minutos la web estará disponible otra vez.</p>'
            . '</div></body></html>';
    }

    public static function flagPath(): string
    {
        return PP_STORAGE . '/maintenance.flag';
    }
}
