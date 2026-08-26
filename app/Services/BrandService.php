<?php

namespace App\Services;

use App\Services\Compliance\ComplianceService;
use App\Services\Compliance\CookieBanner;
use App\Services\Compliance\TrackingCatalog;
use App\Services\LanguageService;
use App\Services\Microcopy;
use Core\Database;

final class BrandService
{
    /**
     * Idioma del sitio que se está renderizando. Lo fijan `publicHeader()` y
     * `publicFooter()`; los helpers privados de columna no reciben `$siteId`,
     * así que se comparte por aquí (mismo patrón que
     * SectionRenderer::setSiteContext).
     */
    private static string $lang = LanguageService::DEFAULT;

    /**
     * Página que se está sirviendo (para el selector de idioma). Vacía cuando
     * se pinta el chrome fuera del contexto de una página.
     *
     * @var array<string,mixed>
     */
    private static array $currentPage = [];

    /** Texto de microcopy ya escapado para meter en un atributo HTML. */
    private static function aria(string $key): string
    {
        return htmlspecialchars(Microcopy::t($key, self::$lang), ENT_QUOTES, 'UTF-8');
    }

    /** Glyphs de marca (Simple Icons, viewBox 24, currentColor) para el footer. */
    private const SOCIAL_ICONS = [
        'instagram' => 'M7.0301.084c-1.2768.0602-2.1487.264-2.911.5634-.7888.3075-1.4575.72-2.1228 1.3877-.6652.6677-1.075 1.3368-1.3802 2.127-.2954.7638-.4956 1.6365-.552 2.914-.0564 1.2775-.0689 1.6882-.0626 4.947.0062 3.2586.0206 3.6671.0825 4.9473.061 1.2765.264 2.1482.5635 2.9107.308.7889.72 1.4573 1.388 2.1228.6679.6655 1.3365 1.0743 2.1285 1.38.7632.295 1.6361.4961 2.9134.552 1.2773.056 1.6884.069 4.9462.0627 3.2578-.0062 3.668-.0207 4.9478-.0814 1.28-.0607 2.147-.2652 2.9098-.5633.7889-.3086 1.4578-.72 2.1228-1.3881.665-.6682 1.0745-1.3378 1.3795-2.1284.2957-.7632.4966-1.636.552-2.9124.056-1.2809.0692-1.6898.063-4.948-.0063-3.2583-.021-3.6668-.0817-4.9465-.0607-1.2797-.264-2.1487-.5633-2.9117-.3084-.7889-.72-1.4568-1.3876-2.1228C21.2982 1.33 20.628.9208 19.8378.6165 19.074.321 18.2017.1197 16.9244.0645 15.6471.0093 15.236-.005 11.977.0014 8.718.0076 8.31.0215 7.0301.0839m.1402 21.6932c-1.17-.0509-1.8053-.2453-2.2287-.408-.5606-.216-.96-.4771-1.3819-.895-.422-.4178-.6811-.8186-.9-1.378-.1644-.4234-.3624-1.058-.4171-2.228-.0595-1.2645-.072-1.6442-.079-4.848-.007-3.2037.0053-3.583.0607-4.848.05-1.169.2456-1.805.408-2.2282.216-.5613.4762-.96.895-1.3816.4188-.4217.8184-.6814 1.3783-.9003.423-.1651 1.0575-.3614 2.227-.4171 1.2655-.06 1.6447-.072 4.848-.079 3.2033-.007 3.5835.005 4.8495.0608 1.169.0508 1.8053.2445 2.228.408.5608.216.96.4754 1.3816.895.4217.4194.6816.8176.9005 1.3787.1653.4217.3617 1.056.4169 2.2263.0602 1.2655.0739 1.645.0796 4.848.0058 3.203-.0055 3.5834-.061 4.848-.051 1.17-.245 1.8055-.408 2.2294-.216.5604-.4763.96-.8954 1.3814-.419.4215-.8181.6811-1.3783.9-.4224.1649-1.0577.3617-2.2262.4174-1.2656.0595-1.6448.072-4.8493.079-3.2045.007-3.5825-.006-4.848-.0608M16.953 5.5864A1.44 1.44 0 1 0 18.39 4.144a1.44 1.44 0 0 0-1.437 1.4424M5.8385 12.012c.0067 3.4032 2.7706 6.1557 6.173 6.1493 3.4026-.0065 6.157-2.7701 6.1506-6.1733-.0065-3.4032-2.771-6.1565-6.174-6.1498-3.403.0067-6.156 2.771-6.1496 6.1738M8 12.0077a4 4 0 1 1 4.008 3.9921A3.9996 3.9996 0 0 1 8 12.0077',
        'facebook' => 'M9.101 23.691v-7.98H6.627v-3.667h2.474v-1.58c0-4.085 1.848-5.978 5.858-5.978.401 0 .955.042 1.468.103a8.68 8.68 0 0 1 1.141.195v3.325a8.623 8.623 0 0 0-.653-.036 26.805 26.805 0 0 0-.733-.009c-.707 0-1.259.096-1.675.309a1.686 1.686 0 0 0-.679.622c-.258.42-.374.995-.374 1.752v1.297h3.919l-.386 2.103-.287 1.564h-3.246v8.245C19.396 23.238 24 18.179 24 12.044c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.628 3.874 10.35 9.101 11.647Z',
        'x' => 'M14.234 10.162 22.977 0h-2.072l-7.591 8.824L7.251 0H.258l9.168 13.343L.258 24H2.33l8.016-9.318L16.749 24h6.993zm-2.837 3.299-.929-1.329L3.076 1.56h3.182l5.965 8.532.929 1.329 7.754 11.09h-3.182z',
        'linkedin' => 'M20.447 20.452h-3.554v-5.569c0-1.328-.027-3.037-1.852-3.037-1.853 0-2.136 1.445-2.136 2.939v5.667H9.351V9h3.414v1.561h.046c.477-.9 1.637-1.85 3.37-1.85 3.601 0 4.267 2.37 4.267 5.455v6.286zM5.337 7.433c-1.144 0-2.063-.926-2.063-2.065 0-1.138.92-2.063 2.063-2.063 1.14 0 2.064.925 2.064 2.063 0 1.139-.925 2.065-2.064 2.065zm1.782 13.019H3.555V9h3.564v11.452zM22.225 0H1.771C.792 0 0 .774 0 1.729v20.542C0 23.227.792 24 1.771 24h20.451C23.2 24 24 23.227 24 22.271V1.729C24 .774 23.2 0 22.222 0h.003z',
        'youtube' => 'M23.498 6.186a3.016 3.016 0 0 0-2.122-2.136C19.505 3.545 12 3.545 12 3.545s-7.505 0-9.377.505A3.017 3.017 0 0 0 .502 6.186C0 8.07 0 12 0 12s0 3.93.502 5.814a3.016 3.016 0 0 0 2.122 2.136c1.871.505 9.376.505 9.376.505s7.505 0 9.377-.505a3.015 3.015 0 0 0 2.122-2.136C24 15.93 24 12 24 12s0-3.93-.502-5.814zM9.545 15.568V8.432L15.818 12l-6.273 3.568z',
        'tiktok' => 'M12.525.02c1.31-.02 2.61-.01 3.91-.02.08 1.53.63 3.09 1.75 4.17 1.12 1.11 2.7 1.62 4.24 1.79v4.03c-1.44-.05-2.89-.35-4.2-.97-.57-.26-1.1-.59-1.62-.93-.01 2.92.01 5.84-.02 8.75-.08 1.4-.54 2.79-1.35 3.94-1.31 1.92-3.58 3.17-5.91 3.21-1.43.08-2.86-.31-4.08-1.03-2.02-1.19-3.44-3.37-3.65-5.71-.02-.5-.03-1-.01-1.49.18-1.9 1.12-3.72 2.58-4.96 1.66-1.44 3.98-2.13 6.15-1.72.02 1.48-.04 2.96-.04 4.44-.99-.32-2.15-.23-3.02.37-.63.41-1.11 1.04-1.36 1.75-.21.51-.15 1.07-.14 1.61.24 1.64 1.82 3.02 3.5 2.87 1.12-.01 2.19-.66 2.77-1.61.19-.33.4-.67.41-1.06.1-1.79.06-3.57.07-5.36.01-4.03-.01-8.05.02-12.07z',
        'whatsapp' => 'M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413Z',
        'pinterest' => 'M12.017 0C5.396 0 .029 5.367.029 11.987c0 5.079 3.158 9.417 7.618 11.162-.105-.949-.199-2.403.041-3.439.219-.937 1.406-5.957 1.406-5.957s-.359-.72-.359-1.781c0-1.663.967-2.911 2.168-2.911 1.024 0 1.518.769 1.518 1.688 0 1.029-.653 2.567-.992 3.992-.285 1.193.6 2.165 1.775 2.165 2.128 0 3.768-2.245 3.768-5.487 0-2.861-2.063-4.869-5.008-4.869-3.41 0-5.409 2.562-5.409 5.199 0 1.033.394 2.143.889 2.741.099.12.112.225.085.345-.09.375-.293 1.199-.334 1.363-.053.225-.172.271-.401.165-1.495-.69-2.433-2.878-2.433-4.646 0-3.776 2.748-7.252 7.92-7.252 4.158 0 7.392 2.967 7.392 6.923 0 4.135-2.607 7.462-6.233 7.462-1.214 0-2.354-.629-2.758-1.379l-.749 2.848c-.269 1.045-1.004 2.352-1.498 3.146 1.123.345 2.306.535 3.55.535 6.607 0 11.985-5.365 11.985-11.987C23.97 5.39 18.592.026 11.985.026L12.017 0z',
    ];

    /** Alias comunes → slug del icono. */
    private const SOCIAL_ALIASES = [
        'twitter' => 'x', 'ig' => 'instagram', 'insta' => 'instagram',
        'fb' => 'facebook', 'meta' => 'facebook', 'yt' => 'youtube',
        'wa' => 'whatsapp', 'wsp' => 'whatsapp', 'in' => 'linkedin',
        'pinteres' => 'pinterest', 'tik' => 'tiktok',
    ];

    /**
     * LOGO2 — Claves de ajuste de cada variante. Se nombran por el FONDO donde
     * va el logo, no por su color: "logo oscuro" es ambiguo (¿de tinta oscura o
     * para fondo oscuro?) y ahí es donde el cliente se equivoca al subirlo.
     */
    /**
     * Variantes de logo. `label` es una CLAVE de traducción (se resuelve con
     * `variantLabel()`): la constante se evalúa antes de saber el idioma del
     * gestor, así que no puede llevar el texto ya hecho.
     */
    public const LOGO_VARIANTS = [
        'light' => ['setting' => 'site_logo_path',      'label' => 'brand.logo_light'],
        'dark'  => ['setting' => 'site_logo_dark_path', 'label' => 'brand.logo_dark'],
    ];

    /** Etiqueta traducida de una variante de logo. */
    public static function variantLabel(string $variant): string
    {
        return __(self::LOGO_VARIANTS[$variant]['label'] ?? 'brand.logo_light');
    }

    public static function data(int $siteId): array
    {
        $site = Database::selectOne('SELECT name FROM sites WHERE id = ? LIMIT 1', [$siteId]) ?: [];

        $rows = Database::select(
            "SELECT setting_key, setting_value FROM settings
             WHERE site_id = ? AND setting_key IN ('site_logo_path', 'site_logo_dark_path', 'site_logo_primary')",
            [$siteId]
        );
        $settings = [];
        foreach ($rows as $r) $settings[(string) $r['setting_key']] = trim((string) $r['setting_value']);

        $primary = ($settings['site_logo_primary'] ?? 'light') === 'dark' ? 'dark' : 'light';

        return [
            'site_id' => $siteId,
            'name' => trim((string) ($site['name'] ?? 'PromptPress')) ?: 'PromptPress',
            'logo_path' => (string) ($settings['site_logo_path'] ?? ''),
            'logo_dark_path' => (string) ($settings['site_logo_dark_path'] ?? ''),
            'logo_primary' => $primary,
        ];
    }

    /**
     * LOGO2 — Ruta del logo adecuada para un fondo.
     *
     * `$background`: 'light' | 'dark' | 'auto' (el principal). Si la variante
     * pedida no existe, se devuelve la otra: mejor un logo con menos contraste
     * que ningún logo.
     */
    public static function logoPathFor(int $siteId, string $background = 'auto', ?array $data = null): string
    {
        $data = $data ?? self::data($siteId);
        if ($background === 'auto' || !isset(self::LOGO_VARIANTS[$background])) {
            $background = (string) $data['logo_primary'];
        }

        $paths = [
            'light' => (string) $data['logo_path'],
            'dark'  => (string) $data['logo_dark_path'],
        ];
        $other = $background === 'dark' ? 'light' : 'dark';

        foreach ([$background, $other] as $candidate) {
            $path = ltrim($paths[$candidate], '/');
            if ($path !== '' && is_file(PP_ROOT . '/' . $path)) return $path;
        }
        return '';
    }

    /**
     * LOGO2 — Bloque de contexto para la IA con los logos disponibles.
     * Cadena vacía si el sitio no tiene ninguno: así no se le sugiere insertar
     * un `<img>` con una ruta que no existe.
     */
    public static function logoHintForAi(int $siteId): string
    {
        $data = self::data($siteId);
        $lines = [];

        foreach (self::LOGO_VARIANTS as $variant => $meta) {
            $path = $variant === 'dark' ? (string) $data['logo_dark_path'] : (string) $data['logo_path'];
            $path = ltrim($path, '/');
            if ($path === '' || !is_file(PP_ROOT . '/' . $path)) continue;
            $lines[] = '- ' . ($variant === 'dark' ? 'Sobre fondo OSCURO' : 'Sobre fondo CLARO')
                . ': ' . self::publicLogoUrl($siteId, $path, $variant);
        }

        if ($lines === []) return '';

        // i18n-ignore: cabecera del bloque de logos que viaja al prompt.
        return "LOGOS DE LA MARCA (rutas reales, no inventes otras):\n"
            . implode("\n", $lines) . "\n"
            // i18n-ignore-start: instrucciones de logo para el modelo.
            . "- Insértalos con `<img src=\"…\" alt=\"" . str_replace('"', '', (string) $data['name']) . "\">`.\n"
            . "- Elige SIEMPRE la variante que contraste con el fondo de esa sección. Si solo hay una, úsala solo donde se lea bien.\n"
            . "- No pongas el logo en la cabecera ni en el pie: la plataforma ya los pinta.";
    }

    /** LOGO2 — Variante realmente disponible para un fondo ('' si no hay logo). */
    public static function logoVariantFor(int $siteId, string $background = 'auto', ?array $data = null): string
    {
        $data = $data ?? self::data($siteId);
        $path = self::logoPathFor($siteId, $background, $data);
        if ($path === '') return '';
        return $path === ltrim((string) $data['logo_dark_path'], '/') ? 'dark' : 'light';
    }

    public static function logoUrl(int $siteId, string $background = 'auto'): string
    {
        $data = self::data($siteId);
        $variant = self::logoVariantFor($siteId, $background, $data);
        if ($variant === '') return '';
        return self::publicLogoUrl($siteId, self::logoPathFor($siteId, $background, $data), $variant);
    }

    public static function publicLogoUrl(int $siteId, string $path, string $variant = 'light'): string
    {
        $path = ltrim(trim($path), '/');
        $prefix = 'storage/uploads/' . $siteId . '/brand/';
        if ($path === '' || !str_starts_with($path, $prefix) || !is_file(PP_ROOT . '/' . $path)) return '';
        return base_url('brand-assets/' . $siteId . '/logo' . ($variant === 'dark' ? '/dark' : ''));
    }

    /**
     * D-MB2 R4 — Logo solo si el archivo existe en disco (un path huérfano en
     * settings producía un <img> roto + alt duplicando el nombre del sitio).
     */
    private static function brandMark(array $data, string $background = 'auto'): string
    {
        $name = htmlspecialchars($data['name'], ENT_QUOTES, 'UTF-8');
        $siteId = (int) ($data['site_id'] ?? 0);
        $path = self::logoPathFor($siteId, $background, $data);
        if ($path !== '') {
            $variant = self::logoVariantFor($siteId, $background, $data);
            return '<img class="pp-site-header__logo-img" src="' . htmlspecialchars(self::publicLogoUrl($siteId, $path, $variant), ENT_QUOTES, 'UTF-8') . '" alt="' . $name . '">';
        }
        return '<span class="pp-site-header__logo-fallback" aria-hidden="true">'
             . htmlspecialchars(mb_strtoupper(mb_substr($data['name'], 0, 1)), ENT_QUOTES, 'UTF-8')
             . '</span><span>' . $name . '</span>';
    }

    /**
     * D-MB2 R4 — Páginas publicadas de primer nivel para la navegación
     * (excluye legales y la home, que ya es el enlace de marca).
     *
     * @return array<int,array{title:string,slug:string,page_type:string}>
     */
    private static function navPages(int $siteId, int $limit = 6): array
    {
        try {
            // El menú es el del idioma que se está sirviendo: en `/fr/` no
            // pueden colarse las páginas castellanas. Se excluye además la home
            // del propio idioma, cuyo slug es el prefijo (`fr`).
            $rows = Database::select(
                "SELECT title, slug, page_type FROM pages
                 WHERE site_id = ? AND status = 'published'
                   AND language = ?
                   AND page_type NOT IN ('legal', 'article')
                   AND (parent_id IS NULL OR parent_id = 0)
                   AND slug NOT IN ('', 'inicio', 'home', ?)
                 ORDER BY tree_sort_order ASC, sort_order ASC, id ASC
                 LIMIT " . (int) $limit,
                [$siteId, self::$lang, self::$lang]
            );
        } catch (\Throwable $e) {
            return [];
        }
        return array_map(static fn(array $r) => [
            'title' => (string) $r['title'],
            'slug' => (string) $r['slug'],
            'page_type' => (string) $r['page_type'],
        ], $rows);
    }

    public static function publicHeader(int $siteId, ?array $config = null, ?string $lang = null): string
    {
        // El idioma de render lo manda quien sirve la página (puede no ser el
        // del sitio: en `/fr/contact` es el francés). Si no se indica, el del sitio.
        self::$lang = $lang !== null ? LanguageService::normalize($lang) : LanguageService::codeFor($siteId);
        $data = self::data($siteId);
        // I18N-FULL T5.1 — capa de texto del idioma que se está sirviendo.
        $config = ChromeService::localized($config ?? ChromeService::load($siteId), self::$lang);
        [$links, $cta] = self::headerNav($siteId, $config);

        // CHROME-EDITOR — clases de estilo SOLO cuando difieren del defecto, para
        // que un sitio sin configurar quede con la clase `pp-site-header` exacta.
        $layout = (array) ($config['header']['layout'] ?? []);
        $style = (array) ($config['header']['style'] ?? []);
        $mods = '';
        if (($layout['sticky'] ?? true) === false)        $mods .= ' pp-site-header--static';
        if (!empty($layout['transparent_over_hero']))     $mods .= ' pp-site-header--transparent';
        $background = (string) ($style['background'] ?? 'auto');
        if (in_array($background, ['light', 'dark', 'brand', 'transparent'], true)) $mods .= ' pp-site-header--bg-' . $background;
        $density = (string) ($layout['density'] ?? 'regular');
        if ($density === 'compact' || $density === 'tall') $mods .= ' pp-site-header--density-' . $density;
        if ((string) ($layout['logo_position'] ?? 'left') === 'center') $mods .= ' pp-site-header--logo-center';
        if ((string) ($layout['width'] ?? 'contained') === 'full') $mods .= ' pp-site-header--full';
        $navAlignment = (string) ($layout['nav_alignment'] ?? 'right');
        if ($navAlignment === 'left' || $navAlignment === 'center') $mods .= ' pp-site-header--nav-' . $navAlignment;

        $brandUrl = trim((string) ($config['header']['brand']['url'] ?? ''));
        $brandHref = $brandUrl !== '' ? self::href($brandUrl) : htmlspecialchars(base_url('/'), ENT_QUOTES, 'UTF-8');
        $borderStyle = self::borderStyle((array) ($style['border'] ?? []));
        if ($borderStyle !== '') $mods .= ' pp-site-header--custom-border';

        return '<header class="pp-site-header' . $mods . '"' . ($borderStyle !== '' ? ' style="' . $borderStyle . '"' : '') . '>'
             . '<div class="pp-site-header__inner">'
             . '<a class="pp-site-header__brand" href="' . $brandHref . '">'
             . self::brandMark($data)
             . '</a>'
             . ($links !== '' ? '<nav class="pp-site-header__nav" aria-label="' . self::aria('nav.primary_aria') . '">' . $links . '</nav>' : '')
             . self::languageSwitcher($siteId)
             . $cta
             . '</div>'
             . '</header>';
    }

    /**
     * Selector de idioma (I18N-FULL T3.2).
     *
     * Solo aparece si el sitio sirve más de un idioma: una web monolingüe no ve
     * ni rastro. Los idiomas se nombran con su ENDÓNIMO («Français», no
     * «Francés»): quien busca la versión francesa reconoce su idioma escrito
     * como lo escribe él.
     *
     * El destino de cada enlace lo resuelve `languageSwitchTarget()` sobre la
     * página que se está sirviendo; aquí solo se conoce el idioma, así que los
     * enlaces apuntan a la home de cada idioma salvo que el renderizador haya
     * fijado la página actual con `setCurrentPage()`.
     */
    private static function languageSwitcher(int $siteId): string
    {
        if (!LanguageService::isMultilingual($siteId)) {
            return '';
        }

        $items = '';
        foreach (LanguageService::activeFor($siteId) as $code) {
            $isCurrent = $code === self::$lang;
            $href = htmlspecialchars(
                self::languageSwitchTarget($siteId, self::$currentPage, $code),
                ENT_QUOTES,
                'UTF-8'
            );
            $items .= '<a class="pp-site-header__lang-item' . ($isCurrent ? ' is-current' : '') . '"'
                . ' href="' . $href . '" hreflang="' . htmlspecialchars($code, ENT_QUOTES, 'UTF-8') . '"'
                . ($isCurrent ? ' aria-current="true"' : '') . '>'
                . htmlspecialchars(LanguageService::label($code), ENT_QUOTES, 'UTF-8')
                . '</a>';
        }

        return '<nav class="pp-site-header__lang" aria-label="'
             . self::aria('nav.language_aria') . '">' . $items . '</nav>';
    }

    /**
     * URL equivalente de una página en otro idioma.
     *
     * Si existe la traducción (misma `translation_group`), se enlaza a ella.
     * Si no, a la HOME de ese idioma: un selector que lleva a un 404 es peor
     * que uno que lleva a la portada.
     *
     * @param array<string,mixed> $page
     */
    public static function languageSwitchTarget(int $siteId, array $page, string $targetLang): string
    {
        $targetLang = LanguageService::normalize($targetLang);
        $group = trim((string) ($page['translation_group'] ?? ''));

        if ($group !== '') {
            try {
                $row = Database::selectOne(
                    "SELECT slug FROM pages
                     WHERE site_id = ? AND translation_group = ? AND language = ? AND status = 'published'
                     LIMIT 1",
                    [$siteId, $group, $targetLang]
                );
                if ($row !== null) {
                    return base_url(ltrim((string) $row['slug'], '/'));
                }
            } catch (\Throwable $e) {
                // sin traducción localizable: caemos a la home
            }
        }

        $prefix = LanguageService::prefixFor($siteId, $targetLang);
        return base_url($prefix === '' ? '/' : $prefix);
    }

    /**
     * Página que se está sirviendo, para que el selector pueda enlazar a su
     * traducción. La fija el renderizador antes de pintar el header.
     *
     * @param array<string,mixed> $page
     */
    public static function setCurrentPage(array $page): void
    {
        self::$currentPage = $page;
    }

    /**
     * CHROME-EDITOR — Resuelve los enlaces del menú y el CTA del header.
     * Si no hay menú configurado, replica EXACTAMENTE el comportamiento
     * automático histórico (páginas publicadas; la de contacto pasa a CTA).
     *
     * @return array{0:string,1:string} [linksHtml, ctaHtml]
     */
    private static function headerNav(int $siteId, array $config): array
    {
        $menu = $config['header']['menu'] ?? [];
        $ctaCfg = $config['header']['cta'] ?? ['mode' => 'auto'];

        $links = '';
        $autoCta = '';
        $layout = (array) ($config['header']['layout'] ?? []);
        $ctaMobileClass = ((string) ($layout['mobile_cta'] ?? 'show')) === 'hide' ? ' pp-site-header__cta--mobile-hidden' : '';

        if (is_array($menu) && $menu !== []) {
            foreach ($menu as $item) {
                if (!is_array($item) || (($item['visible'] ?? true) === false)) continue;
                $links .= self::headerItemHtml($siteId, $item);
            }
        } else {
            // AUTO (histórico): páginas publicadas; la de contacto se vuelve CTA.
            foreach (self::navPages($siteId) as $p) {
                $href = htmlspecialchars(base_url(ltrim($p['slug'], '/')), ENT_QUOTES, 'UTF-8');
                $title = htmlspecialchars($p['title'], ENT_QUOTES, 'UTF-8');
                if ($autoCta === '' && $p['page_type'] === 'contact') {
                    $autoCta = '<a class="pp-btn pp-btn--primary pp-site-header__cta' . $ctaMobileClass . '" href="' . $href . '">' . $title . '</a>';
                    continue;
                }
                $links .= '<a class="pp-site-header__link" href="' . $href . '">' . $title . '</a>';
            }
        }

        $mode = (string) ($ctaCfg['mode'] ?? 'auto');
        $cta = '';
        if ($mode === 'custom'
            && trim((string) ($ctaCfg['label'] ?? '')) !== ''
            && trim((string) ($ctaCfg['url'] ?? '')) !== ''
        ) {
            $style = ((string) ($ctaCfg['style'] ?? 'primary')) === 'ghost' ? 'pp-btn--ghost' : 'pp-btn--primary';
            $cta = '<a class="pp-btn ' . $style . ' pp-site-header__cta' . $ctaMobileClass . '" href="' . self::href((string) $ctaCfg['url'])
                 . '">' . htmlspecialchars((string) $ctaCfg['label'], ENT_QUOTES, 'UTF-8') . '</a>';
        } elseif ($mode === 'auto') {
            $cta = $autoCta; // vacío si se usó un menú personalizado
        }
        // mode 'off' => sin CTA

        return [$links, $cta];
    }

    /** Render de un ítem de menú: página, enlace o desplegable. */
    private static function headerItemHtml(int $siteId, array $item): string
    {
        if ((string) ($item['type'] ?? 'page') === 'dropdown') {
            $label = htmlspecialchars((string) ($item['label'] ?? ''), ENT_QUOTES, 'UTF-8');
            $children = '';
            foreach ((array) ($item['children'] ?? []) as $c) {
                if (is_array($c) && (($c['visible'] ?? true) !== false)) {
                    $children .= self::headerItemHtml($siteId, $c);
                }
            }
            if ($label === '' || $children === '') return '';
            return '<div class="pp-site-header__dropdown">'
                 . '<span class="pp-site-header__link pp-site-header__dropdown-toggle">' . $label . '</span>'
                 . '<div class="pp-site-header__dropdown-menu">' . $children . '</div>'
                 . '</div>';
        }

        [$href, $label, $target] = self::resolveItem($siteId, $item);
        if ($href === '' || $label === '') return '';
        $t = $target === '_blank' ? ' target="_blank" rel="noopener"' : '';
        return '<a class="pp-site-header__link" href="' . $href . '"' . $t . '>' . $label . '</a>';
    }

    /**
     * Resuelve un ítem (página o enlace) a [href, labelHtml, target].
     * @return array{0:string,1:string,2:string}
     */
    private static function resolveItem(int $siteId, array $item): array
    {
        $target = ((string) ($item['target'] ?? '_self')) === '_blank' ? '_blank' : '_self';

        if ((string) ($item['type'] ?? 'page') === 'link') {
            $url = trim((string) ($item['url'] ?? ''));
            $label = trim((string) ($item['label'] ?? ''));
            return [$url !== '' ? self::href($url) : '', htmlspecialchars($label, ENT_QUOTES, 'UTF-8'), $target];
        }

        $page = self::pageById($siteId, (int) ($item['page_id'] ?? 0));
        if ($page === null) return ['', '', $target];
        $label = trim((string) ($item['label'] ?? '')) ?: (string) $page['title'];
        $href = htmlspecialchars(base_url(ltrim((string) $page['slug'], '/')), ENT_QUOTES, 'UTF-8');
        return [$href, htmlspecialchars($label, ENT_QUOTES, 'UTF-8'), $target];
    }

    /** Sanea/normaliza un href de enlace personalizado. */
    private static function href(string $url): string
    {
        $url = trim($url);
        if ($url === '') return '#';
        if (preg_match('~^(https?://|mailto:|tel:|#|/)~i', $url)) {
            return htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
        }
        return htmlspecialchars(base_url(ltrim($url, '/')), ENT_QUOTES, 'UTF-8');
    }

    /** @return array{title:string,slug:string,status:string}|null */
    private static function pageById(int $siteId, int $id): ?array
    {
        if ($id <= 0) return null;
        try {
            $r = Database::selectOne(
                'SELECT title, slug, status FROM pages WHERE id = ? AND site_id = ? LIMIT 1',
                [$id, $siteId]
            );
        } catch (\Throwable $e) {
            return null;
        }
        return $r ?: null;
    }

    /**
     * E-GDPR G3 — Footer público con enlaces a páginas legales publicadas.
     *
     * Renderiza solo las páginas con `page_type='legal'` que estén `published`.
     * Si no hay ninguna, devuelve un footer mínimo (©/marca) sin enlaces.
     */
    public static function publicFooter(int $siteId, ?array $config = null, ?string $lang = null): string
    {
        self::$lang = $lang !== null ? LanguageService::normalize($lang) : LanguageService::codeFor($siteId);
        $config = ChromeService::localized($config ?? ChromeService::load($siteId), self::$lang);
        $siteName = self::data($siteId)['name'];
        $footerBrandName = trim((string) ($config['footer']['brand']['name'] ?? ''));
        $name = htmlspecialchars($footerBrandName !== '' ? $footerBrandName : $siteName, ENT_QUOTES, 'UTF-8');
        $year = date('Y');
        try {
            $legal = Database::select(
                "SELECT title, slug FROM pages
                 WHERE site_id = ? AND page_type = 'legal' AND status = 'published'
                   AND language = ?
                 ORDER BY title ASC",
                [$siteId, self::$lang]
            );
        } catch (\Throwable $e) {
            $legal = [];
        }

        $links = '';
        foreach ($legal as $p) {
            $href  = htmlspecialchars(base_url(ltrim((string) $p['slug'], '/')), ENT_QUOTES, 'UTF-8');
            $title = htmlspecialchars((string) $p['title'], ENT_QUOTES, 'UTF-8');
            $links .= '<a class="pp-site-footer__link" href="' . $href . '">' . $title . '</a>';
        }

        // Banner de cookies (E-GDPR G4): el JS se inyecta siempre (gestiona también
        // click-to-load de vídeos), pero el banner UI solo aparece si hay tracking activo.
        $manifest = ComplianceService::manifest($siteId);
        $needsBanner = TrackingCatalog::needsBanner($manifest);
        $reopenLink = $needsBanner
            ? '<a class="pp-site-footer__link" href="#" data-cb-reopen>' . self::aria('cookies.reopen') . '</a>'
            : '';
        $bannerHtml = CookieBanner::render($manifest, self::$lang);

        // D-MB2 R4 — footer "de agencia": banda oscura con marca + tagline,
        // navegación principal y enlaces legales; barra inferior con ©.
        $tagline = '';
        try {
            $mem = Database::selectOne(
                "SELECT field_value FROM site_memory
                 WHERE site_id = ? AND field_key IN ('value_proposition', 'business_description')
                 ORDER BY FIELD(field_key, 'value_proposition', 'business_description') LIMIT 1",
                [$siteId]
            );
            $tagline = trim((string) ($mem['field_value'] ?? ''));
            if (mb_strlen($tagline) > 160) {
                $tagline = rtrim(mb_substr($tagline, 0, 157), " \t.,;") . '…';
            }
        } catch (\Throwable $e) {
            // sin memoria → sin tagline
        }

        // CHROME-EDITOR — override del tagline si está configurado.
        $taglineOverride = trim((string) ($config['footer']['tagline'] ?? ''));
        if ($taglineOverride !== '') {
            $tagline = $taglineOverride;
        }

        // CHROME-EDITOR — navegación del pie: items propios si están configurados,
        // si no, las páginas publicadas (comportamiento histórico).
        $footerNavItems = (array) ($config['footer']['nav'] ?? []);
        if ($footerNavItems !== []) {
            $navLinks = self::footerNavFromItems($siteId, $footerNavItems);
        } else {
            $navLinks = '';
            foreach (self::navPages($siteId) as $p) {
                $href = htmlspecialchars(base_url(ltrim($p['slug'], '/')), ENT_QUOTES, 'UTF-8');
                $navLinks .= '<a class="pp-site-footer__link" href="' . $href . '">'
                    . htmlspecialchars($p['title'], ENT_QUOTES, 'UTF-8') . '</a>';
            }
        }

        // CHROME-EDITOR — columnas por bloque, en el orden configurado.
        // LOGO2 — El pie va sobre fondo oscuro: si hay logo para ese fondo, se usa
        // en lugar del nombre en texto. Si no lo hay, se mantiene el nombre (un
        // logo de tinta oscura sobre fondo oscuro no se vería).
        $footerLogo = '';
        if (BrandService::logoVariantFor($siteId, 'dark') === 'dark') {
            $footerLogo = '<img class="pp-site-footer__logo" src="'
                . htmlspecialchars(self::logoUrl($siteId, 'dark'), ENT_QUOTES, 'UTF-8')
                . '" alt="' . $name . '">';
        }

        $brandCol = '<div class="pp-site-footer__brandcol">'
              . ($footerLogo !== '' ? $footerLogo : '<span class="pp-site-footer__name">' . $name . '</span>')
              . ($tagline !== '' ? '<p class="pp-site-footer__tagline">' . htmlspecialchars($tagline, ENT_QUOTES, 'UTF-8') . '</p>' : '')
              . '</div>';
        $navCol = $navLinks !== ''
            ? '<nav class="pp-site-footer__col" aria-label="' . self::aria('nav.footer_aria') . '"><span class="pp-site-footer__col-title">' . self::footerLabel($config, 'nav', 'footer.explore') . '</span>' . $navLinks . '</nav>'
            : '';
        $legalCol = ($links !== '' || $reopenLink !== '')
            ? '<nav class="pp-site-footer__col" aria-label="' . self::aria('nav.legal_aria') . '"><span class="pp-site-footer__col-title">' . self::footerLabel($config, 'legal', 'footer.legal') . '</span>' . $links . $reopenLink . '</nav>'
            : '';

        $colMap = [
            'brand'      => $brandCol,
            'nav'        => $navCol,
            'legal'      => $legalCol,
            'contact'    => self::footerContactCol($config),
            'social'     => self::footerSocialCol($config),
            'newsletter' => self::footerNewsletterCol($config),
        ];

        $order = array_values(array_filter((array) ($config['footer']['blocks'] ?? []), 'is_string'));
        if ($order === []) {
            $order = ['brand', 'nav', 'legal']; // orden histórico (regresión cero)
        }
        $cols = '';
        foreach ($order as $b) {
            if (isset($colMap[$b]) && $colMap[$b] !== '') {
                $cols .= $colMap[$b];
            }
        }

        // CHROME-EDITOR — override del copyright si está configurado.
        $copyOverride = trim((string) ($config['footer']['copyright'] ?? ''));
        $copy = $copyOverride !== ''
            ? htmlspecialchars($copyOverride, ENT_QUOTES, 'UTF-8')
            : '© ' . $year . ' · ' . $name;

        // CHROME-EDITOR — fondo del footer (defecto 'dark' => sin modificador).
        $bg = (string) ($config['footer']['style']['background'] ?? 'dark');
        $columns = (int) ($config['footer']['style']['columns'] ?? 0);
        $borderStyle = self::borderStyle((array) ($config['footer']['style']['border'] ?? []));
        $footerClass = 'pp-site-footer'
            . ($bg === 'light' ? ' pp-site-footer--light' : ($bg === 'brand' ? ' pp-site-footer--brand' : ''))
            . (in_array($columns, [2, 3, 4], true) ? ' pp-site-footer--cols-' . $columns : '')
            . ($borderStyle !== '' ? ' pp-site-footer--custom-border' : '');

        return '<footer class="' . $footerClass . '"' . ($borderStyle !== '' ? ' style="' . $borderStyle . '"' : '') . '>'
             . '<div class="pp-site-footer__grid">' . $cols . '</div>'
             . '<div class="pp-site-footer__bottom"><span class="pp-site-footer__copy">' . $copy . '</span></div>'
             . '</footer>'
             . $bannerHtml;
    }

    /** Enlaces de navegación del pie desde items propios (página/enlace; sin desplegables). */
    private static function footerNavFromItems(int $siteId, array $items): string
    {
        $out = '';
        foreach ($items as $it) {
            if (!is_array($it) || (($it['visible'] ?? true) === false)) continue;
            if ((string) ($it['type'] ?? 'page') === 'dropdown') continue; // el pie no usa desplegables
            [$href, $label, $target] = self::resolveItem($siteId, $it);
            if ($href === '' || $label === '') continue;
            $t = $target === '_blank' ? ' target="_blank" rel="noopener"' : '';
            $out .= '<a class="pp-site-footer__link" href="' . $href . '"' . $t . '>' . $label . '</a>';
        }
        return $out;
    }

    /** Columna de contacto (dirección/teléfono/email/horario). '' si vacía. */
    private static function footerContactCol(array $config): string
    {
        $c = (array) ($config['footer']['contact'] ?? []);
        $addr  = trim((string) ($c['address'] ?? ''));
        $phone = trim((string) ($c['phone'] ?? ''));
        $email = trim((string) ($c['email'] ?? ''));
        $hours = trim((string) ($c['hours'] ?? ''));
        $items = '';
        if ($addr !== '')  $items .= '<span class="pp-site-footer__contact-item">' . nl2br(htmlspecialchars($addr, ENT_QUOTES, 'UTF-8')) . '</span>';
        if ($phone !== '') $items .= '<a class="pp-site-footer__link" href="tel:' . htmlspecialchars((string) preg_replace('/[^0-9+]/', '', $phone), ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($phone, ENT_QUOTES, 'UTF-8') . '</a>';
        if ($email !== '') $items .= '<a class="pp-site-footer__link" href="mailto:' . htmlspecialchars($email, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($email, ENT_QUOTES, 'UTF-8') . '</a>';
        if ($hours !== '') $items .= '<span class="pp-site-footer__contact-item">' . htmlspecialchars($hours, ENT_QUOTES, 'UTF-8') . '</span>';
        if ($items === '') return '';
        return '<div class="pp-site-footer__col pp-site-footer__contact" aria-label="' . self::aria('footer.contact') . '"><span class="pp-site-footer__col-title">' . self::footerLabel($config, 'contact', 'footer.contact') . '</span>' . $items . '</div>';
    }

    /** Columna de redes sociales (icono de marca + fallback a texto). '' si no hay. */
    private static function footerSocialCol(array $config): string
    {
        $links = '';
        foreach ((array) ($config['footer']['social'] ?? []) as $s) {
            if (!is_array($s)) continue;
            $url = trim((string) ($s['url'] ?? ''));
            $net = trim((string) ($s['network'] ?? ''));
            if ($url === '' || $net === '') continue;

            $key = strtolower((string) preg_replace('/[^a-z0-9]/i', '', $net));
            $slug = self::SOCIAL_ALIASES[$key] ?? $key;
            $label = htmlspecialchars(ucfirst($net), ENT_QUOTES, 'UTF-8');
            $href = self::href($url);

            if (isset(self::SOCIAL_ICONS[$slug])) {
                $svg = '<svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor" aria-hidden="true" focusable="false"><path d="'
                     . self::SOCIAL_ICONS[$slug] . '"/></svg>';
                $links .= '<a class="pp-site-footer__social-link" href="' . $href
                    . '" target="_blank" rel="noopener" aria-label="' . $label . '" title="' . $label . '">' . $svg . '</a>';
            } else {
                $links .= '<a class="pp-site-footer__link" href="' . $href
                    . '" target="_blank" rel="noopener">' . $label . '</a>';
            }
        }
        if ($links === '') return '';
        return '<div class="pp-site-footer__col pp-site-footer__social" aria-label="' . self::aria('footer.social_aria') . '"><span class="pp-site-footer__col-title">' . self::footerLabel($config, 'social', 'footer.social') . '</span><div class="pp-site-footer__social-row">' . $links . '</div></div>';
    }

    /** Columna de newsletter (titular + CTA). '' si no está activada. */
    private static function footerNewsletterCol(array $config): string
    {
        $n = (array) ($config['footer']['newsletter'] ?? []);
        if (empty($n['enabled'])) return '';
        $heading = Microcopy::resolve((string) ($n['heading'] ?? ''), 'footer.newsletter_heading', self::$lang);
        $label = Microcopy::resolve((string) ($n['cta_label'] ?? ''), 'footer.newsletter_cta', self::$lang);
        $url = trim((string) ($n['form_ref'] ?? '')) !== '' ? self::href((string) $n['form_ref']) : self::href('/contacto');
        return '<div class="pp-site-footer__col pp-site-footer__newsletter" aria-label="Newsletter">'
             . '<span class="pp-site-footer__col-title">' . self::footerLabel($config, 'newsletter', 'footer.newsletter') . '</span>'
             . '<p class="pp-site-footer__newsletter-text">' . htmlspecialchars($heading, ENT_QUOTES, 'UTF-8') . '</p>'
             . '<a class="pp-btn pp-btn--primary pp-btn--sm pp-site-footer__newsletter-cta" href="' . $url . '">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</a>'
             . '</div>';
    }

    /**
     * Título de columna del footer. `$microcopyKey` es la clave del texto
     * automático: se usa cuando el usuario no ha puesto etiqueta propia (o
     * cuando lo guardado es el default castellano histórico).
     */
    private static function footerLabel(array $config, string $key, string $microcopyKey): string
    {
        $stored = (string) ($config['footer']['labels'][$key] ?? '');
        return htmlspecialchars(
            Microcopy::resolve($stored, $microcopyKey, self::$lang),
            ENT_QUOTES,
            'UTF-8'
        );
    }

    private static function borderStyle(array $border): string
    {
        $mode = (string) ($border['mode'] ?? 'all');
        $rules = [];
        if ($mode === 'sides') {
            foreach (['top', 'right', 'bottom', 'left'] as $side) {
                $part = (array) ($border[$side] ?? []);
                $width = trim((string) ($part['width'] ?? ''));
                $color = trim((string) ($part['color'] ?? ''));
                if ($width === '') continue;
                $rules[] = '--pp-chrome-border-' . $side . '-width:' . ($width !== '' ? (int) $width . 'px' : '0');
                $rules[] = '--pp-chrome-border-' . $side . '-color:' . ($color !== '' ? htmlspecialchars($color, ENT_QUOTES, 'UTF-8') : 'transparent');
            }
        } else {
            $part = (array) ($border['all'] ?? []);
            $width = trim((string) ($part['width'] ?? ''));
            $color = trim((string) ($part['color'] ?? ''));
            if ($width !== '') {
                $w = $width !== '' ? (int) $width . 'px' : '0';
                $c = $color !== '' ? htmlspecialchars($color, ENT_QUOTES, 'UTF-8') : 'transparent';
                foreach (['top', 'right', 'bottom', 'left'] as $side) {
                    $rules[] = '--pp-chrome-border-' . $side . '-width:' . $w;
                    $rules[] = '--pp-chrome-border-' . $side . '-color:' . $c;
                }
            }
        }
        return $rules !== [] ? htmlspecialchars(implode(';', $rules), ENT_QUOTES, 'UTF-8') : '';
    }
}
