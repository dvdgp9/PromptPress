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
     * RSV-UI — Anchos del calendario dentro de la página.
     *
     * El widget nació con `max-width:420px` clavado, así que un calendario en
     * una página ancha salía como una tarjetita perdida en el centro y no había
     * forma de cambiarlo sin tocar código. Ahora el ancho es una opción más del
     * embed, y viaja por los tres caminos: sección clásica, placeholder canvas
     * y panel del Studio.
     *
     * - `card` — la tarjeta de siempre (420px).
     * - `wide` — hasta 760px; calendario y horas en dos columnas.
     * - `full` — todo el ancho del contenedor.
     */
    public const WIDTHS = ['card', 'wide', 'full'];
    public const DEFAULT_WIDTH = 'card';

    /** Normaliza el ancho pedido; cualquier valor raro vuelve a la tarjeta. */
    public static function normalizeWidth(int|string|null $raw): string
    {
        $w = strtolower(trim((string) $raw));
        return in_array($w, self::WIDTHS, true) ? $w : self::DEFAULT_WIDTH;
    }

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
     * RSV-TABS — Lista ordenada de servicios para un embed con pestañas.
     *
     * El ORDEN es el que eligió el gestor, porque es el orden de las pestañas:
     * aquí no se ordena ni se reordena nada. Se quitan los repetidos y los que
     * no existen, no son del sitio o están desactivados, con el mismo criterio
     * que `resolveServiceId()`: mejor una pestaña menos que una pestaña que
     * lleva a un calendario que no debería estar ahí.
     *
     * @param array<int,int|string>|string|null $raw ids, o la lista "3,7" tal
     *        y como viene del placeholder
     * @return int[]
     */
    public static function resolveServiceIds(int $siteId, array|string|null $raw): array
    {
        $wanted = is_array($raw) ? $raw : explode(',', (string) $raw);
        $valid = array_column(self::embeddableServices($siteId), 'id');

        $out = [];
        foreach ($wanted as $candidate) {
            $id = (int) trim((string) $candidate);
            if ($id <= 0 || in_array($id, $out, true) || !in_array($id, $valid, true)) continue;
            $out[] = $id;
        }
        return $out;
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
     * RSV-TABS — `service_ids` (dos o más) pinta una pestaña por servicio y UN
     * solo calendario, que cambia de servicio al pulsar. No son N calendarios
     * escondidos: así la página solo pide la disponibilidad del que se ve.
     *
     * @param array{service_id?:int|string|null, service_ids?:array<int,int|string>|string|null, days?:int|string|null, lang?:string|null, width?:string|null} $opts
     * @return string cadena vacía si no hay nada que pintar
     */
    public static function render(int $siteId, array $opts = []): string
    {
        if ($siteId <= 0 || !ModuleRegistry::isEnabled($siteId, 'booking')) {
            return '';
        }

        // Con varios servicios manda la lista; si de ella no sobrevive ninguno
        // (todos borrados o desactivados) se cae al camino de siempre, que es
        // el que sabe devolver vacío sin dejar un hueco roto.
        $tabIds = self::resolveServiceIds($siteId, $opts['service_ids'] ?? null);
        $serviceId = $tabIds !== []
            ? $tabIds[0]
            : self::resolveServiceId($siteId, $opts['service_id'] ?? null);
        if ($serviceId === null) {
            return '';
        }
        if (count($tabIds) < 2) {
            $tabIds = [];   // una sola pestaña no es una pestaña
        }

        $days = (int) ($opts['days'] ?? self::DEFAULT_DAYS);
        $days = max(1, min(31, $days > 0 ? $days : self::DEFAULT_DAYS));
        $width = self::normalizeWidth($opts['width'] ?? null);

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
        $uid = 'pp-bk-' . (++self::$seq);
        $tabs = $tabIds !== [] ? self::renderTabBar($siteId, $tabIds, $uid, $width, $lang) : '';

        $h  = $tabs !== '' ? '<div class="pp-booking-tabs pp-booking-tabs--w-' . $width . '" data-pp-booking-tabs>' . $tabs : '';
        $h .= '<div class="pp-booking-embed pp-booking-embed--w-' . $width . '" data-pp-booking';
        $h .= ' data-service="' . $serviceId . '"';
        $h .= ' data-lang="' . e($lang) . '"';
        $h .= ' data-width="' . $width . '"';
        $h .= ' data-days="' . $days . '"';
        if ($tabs !== '') {
            // Un solo panel para todas las pestañas: el `aria-labelledby` dice
            // cuál lo está mandando, y el JS lo mueve al cambiar.
            $h .= ' id="' . $uid . '-panel" role="tabpanel" aria-labelledby="' . $uid . '-tab-' . $tabIds[0] . '"';
        }
        $h .= '>';
        $h .= '<p class="pp-booking-embed__name">' . e($name) . '</p>';
        $h .= '<p class="pp-booking-embed__meta">' . e($sub) . '</p>';
        $h .= '<p class="pp-booking-embed__meta">' . e(\App\Services\Microcopy::t('booking.loading', $lang)) . '</p>';
        $h .= '</div>';
        if ($tabs !== '') {
            $h .= '</div>';
        }
        $h .= '<noscript><p class="pp-booking-embed__noscript">'
            . e(\App\Services\Microcopy::t('booking.noscript', $lang))
            . '</p></noscript>';
        $h .= '<script src="' . e($widgetUrl) . '?v=' . e($ver) . '" defer></script>';

        return $h;
    }

    /**
     * Barra de pestañas. Una por servicio, con su nombre y su duración: elegir
     * entre «15 min gratis» y «1 h» es justo lo que hay que poder leer sin
     * abrir nada.
     *
     * @param int[] $ids
     */
    private static function renderTabBar(int $siteId, array $ids, string $uid, string $width, string $lang): string
    {
        $byId = [];
        foreach (self::embeddableServices($siteId) as $s) {
            $byId[$s['id']] = $s;
        }

        $h = '<div class="pp-booking-tabs__bar" role="tablist" aria-label="'
            . e(\App\Services\Microcopy::t('booking.tabs_label', $lang)) . '">';
        foreach ($ids as $i => $id) {
            $s = $byId[$id] ?? null;
            if ($s === null) continue;
            $meta = $s['duration_min'] . ' min';
            if (trim($s['price_label']) !== '') {
                $meta .= ' · ' . $s['price_label'];
            }
            $on = $i === 0;
            $h .= '<button type="button" class="pp-booking-tabs__tab' . ($on ? ' is-on' : '') . '"'
                . ' id="' . $uid . '-tab-' . $id . '"'
                . ' role="tab" data-service="' . $id . '"'
                . ' aria-controls="' . $uid . '-panel"'
                . ' aria-selected="' . ($on ? 'true' : 'false') . '"'
                . ' tabindex="' . ($on ? '0' : '-1') . '">'
                . '<span class="pp-booking-tabs__name">' . e($s['name']) . '</span>'
                . '<span class="pp-booking-tabs__meta">' . e($meta) . '</span>'
                . '</button>';
        }
        return $h . '</div>';
    }

    /** Distingue las pestañas de dos embeds en la misma página. */
    private static int $seq = 0;

    /** Idioma en el que se pinta el texto sin JS. */
    private static function lang(int $siteId): string
    {
        return \App\Services\LanguageService::codeFor($siteId);
    }
}
