<?php

declare(strict_types=1);

namespace App\Modules\Booking;

use App\Modules\ModuleRegistry;
use Core\Database;

/**
 * BookingEmbedRenderer — el calendario de reservas dentro de las páginas del
 * PROPIO sitio (MODULOS M2).
 *
 * Hasta ahora el calendario solo existía como snippet `<script>` para pegar en
 * webs ajenas: para ponerlo en una página de tu web tenías que copiar HTML a
 * mano, con clave de API incluida. Aquí vive el mismo calendario, pero servido
 * por PromptPress y sin que el gestor vea nunca un snippet:
 *
 *   - sección "Calendario de reservas" del editor de páginas
 *     (`SectionRenderer::renderBooking()`),
 *   - placeholder `{{booking:N}}` de las páginas canvas
 *     (`CanvasService::expandPlaceholders()`).
 *
 * Ambos caminos acaban en `render()`, que emite el contenedor que rellena
 * `public/js/pp-booking-widget.js` en su modo B. Como es el mismo origen, no
 * hace falta API key ni lista de orígenes permitidos: eso solo aplica fuera.
 *
 * Si algo no cuadra (módulo apagado, sin servicios activos, servicio borrado)
 * NO se pinta un hueco roto: se devuelve cadena vacía o un aviso comentado en
 * el HTML, y el resto de la página sigue igual.
 */
final class BookingEmbedRenderer
{
    /** Días de agenda que muestra el calendario si nadie dice otra cosa. */
    public const DEFAULT_DAYS = 14;

    /**
     * Servicios que se pueden embeber: activos, del sitio, ordenados por nombre.
     *
     * @return array<int, array{id:int, name:string, duration_min:int, price_label:string}>
     */
    public static function embeddableServices(int $siteId): array
    {
        if ($siteId <= 0) {
            return [];
        }
        $rows = Database::select(
            'SELECT id, name, duration_min, price_label
               FROM booking_services
              WHERE site_id = ? AND active = 1
              ORDER BY name ASC',
            [$siteId]
        );
        return array_map(static fn (array $r): array => [
            'id'           => (int) $r['id'],
            'name'         => (string) $r['name'],
            'duration_min' => (int) $r['duration_min'],
            'price_label'  => (string) ($r['price_label'] ?? ''),
        ], $rows);
    }

    /**
     * Resuelve qué servicio hay que pintar.
     *
     * Un valor vacío (o 0) significa "el primero activo": así la sección recién
     * añadida YA funciona sin tocar nada, que es lo que espera quien no es
     * técnico. Un id que no existe, no es del sitio o está desactivado devuelve
     * null en vez de caer al primero: si el gestor eligió un servicio concreto y
     * lo desactiva, es mejor que el calendario desaparezca que enseñar otro.
     */
    public static function resolveServiceId(int $siteId, int|string|null $raw): ?int
    {
        $wanted = (int) $raw;
        $services = self::embeddableServices($siteId);
        if ($services === []) {
            return null;
        }
        if ($wanted <= 0) {
            return $services[0]['id'];
        }
        foreach ($services as $s) {
            if ($s['id'] === $wanted) {
                return $wanted;
            }
        }
        return null;
    }

    /**
     * HTML del calendario embebido.
     *
     * `lang` es el idioma de la PÁGINA que se está pintando, y viaja al widget
     * para que sus textos ("Cargando disponibilidad…", "Tu nombre", "Reservar a
     * las…") salgan en ese idioma. Sin esto, el widget preguntaba a la API sin
     * más y recibía el idioma del SERVICIO, que nace en castellano: un
     * calendario en una página francesa hablaba en español.
     *
     * @param array{service_id?:int|string|null, days?:int|string|null, lang?:string|null} $opts
     * @return string cadena vacía si no hay nada que pintar
     */
    public static function render(int $siteId, array $opts = []): string
    {
        if ($siteId <= 0 || !ModuleRegistry::isEnabled($siteId, 'booking')) {
            return '';
        }
        $serviceId = self::resolveServiceId($siteId, $opts['service_id'] ?? null);
        if ($serviceId === null) {
            return '';
        }

        $days = (int) ($opts['days'] ?? self::DEFAULT_DAYS);
        $days = max(1, min(31, $days > 0 ? $days : self::DEFAULT_DAYS));

        $widgetUrl = base_url('public/js/pp-booking-widget.js');
        $js = PP_ROOT . '/public/js/pp-booking-widget.js';
        $ver = is_file($js) ? (string) filemtime($js) : PP_VERSION;

        // El contenedor NO va vacío: lleva el nombre del servicio y su duración.
        // El widget lo borra al montar (`root.innerHTML = ''`), así que solo se
        // ve mientras carga… y en los dos sitios donde el JS no corre nunca:
        // la previsualización del editor de secciones (su iframe es
        // `sandbox="allow-same-origin"`, sin scripts) y un visitante sin JS.
        // Sin esto, el gestor añadía un calendario y su vista previa decía
        // "necesitas activar JavaScript", que parece un error.
        $lang = isset($opts['lang']) && trim((string) $opts['lang']) !== ''
            ? \App\Services\LanguageService::normalize((string) $opts['lang'])
            : self::lang($siteId);
        $service = Database::selectOne(
            'SELECT name, duration_min, price_label FROM booking_services WHERE id = ? LIMIT 1',
            [$serviceId]
        );
        $name = (string) ($service['name'] ?? '');
        $sub  = (int) ($service['duration_min'] ?? 0) . ' min';
        if (trim((string) ($service['price_label'] ?? '')) !== '') {
            $sub .= ' · ' . (string) $service['price_label'];
        }

        // Clases propias, no las del widget: el CSS de `.ppbk` lo inyecta el JS
        // y aquí puede no haber JS nunca. `.pp-booking-embed` vive en el CSS
        // público (DesignSystem), que la previsualización sí carga.
        $h  = '<div class="pp-booking-embed" data-pp-booking';
        $h .= ' data-service="' . $serviceId . '"';
        $h .= ' data-lang="' . e($lang) . '"';
        $h .= ' data-days="' . $days . '">';
        $h .= '<p class="pp-booking-embed__name">' . e($name) . '</p>';
        $h .= '<p class="pp-booking-embed__meta">' . e($sub) . '</p>';
        $h .= '<p class="pp-booking-embed__meta">' . e(\App\Services\Microcopy::t('booking.loading', $lang)) . '</p>';
        $h .= '</div>';
        $h .= '<noscript><p class="pp-booking-embed__noscript">'
            . e(\App\Services\Microcopy::t('booking.noscript', $lang))
            . '</p></noscript>';
        $h .= '<script src="' . e($widgetUrl) . '?v=' . e($ver) . '" defer></script>';

        return $h;
    }

    /** Idioma en el que se pinta el texto sin JS. */
    private static function lang(int $siteId): string
    {
        return \App\Services\LanguageService::codeFor($siteId);
    }
}
