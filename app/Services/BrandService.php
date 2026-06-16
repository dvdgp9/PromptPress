<?php

namespace App\Services;

use App\Services\Compliance\ComplianceService;
use App\Services\Compliance\CookieBanner;
use App\Services\Compliance\TrackingCatalog;
use Core\Database;

final class BrandService
{
    public static function data(int $siteId): array
    {
        $site = Database::selectOne('SELECT name FROM sites WHERE id = ? LIMIT 1', [$siteId]) ?: [];
        $logo = Database::selectOne(
            'SELECT setting_value FROM settings WHERE site_id = ? AND setting_key = ? LIMIT 1',
            [$siteId, 'site_logo_path']
        );

        return [
            'name' => trim((string) ($site['name'] ?? 'PromptPress')) ?: 'PromptPress',
            'logo_path' => trim((string) ($logo['setting_value'] ?? '')),
        ];
    }

    public static function logoUrl(int $siteId): string
    {
        $data = self::data($siteId);
        return $data['logo_path'] !== '' ? base_url($data['logo_path']) : '';
    }

    /**
     * D-MB2 R4 — Logo solo si el archivo existe en disco (un path huérfano en
     * settings producía un <img> roto + alt duplicando el nombre del sitio).
     */
    private static function brandMark(array $data): string
    {
        $name = htmlspecialchars($data['name'], ENT_QUOTES, 'UTF-8');
        $path = ltrim($data['logo_path'], '/');
        if ($path !== '' && is_file(PP_ROOT . '/' . $path)) {
            return '<img class="pp-site-header__logo-img" src="' . htmlspecialchars(base_url($path), ENT_QUOTES, 'UTF-8') . '" alt="' . $name . '">';
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
            $rows = Database::select(
                "SELECT title, slug, page_type FROM pages
                 WHERE site_id = ? AND status = 'published'
                   AND page_type NOT IN ('legal', 'article')
                   AND (parent_id IS NULL OR parent_id = 0)
                   AND slug NOT IN ('', 'inicio', 'home')
                 ORDER BY tree_sort_order ASC, sort_order ASC, id ASC
                 LIMIT " . (int) $limit,
                [$siteId]
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

    public static function publicHeader(int $siteId, ?array $config = null): string
    {
        $data = self::data($siteId);
        $config = $config ?? ChromeService::load($siteId);
        [$links, $cta] = self::headerNav($siteId, $config);

        // CHROME-EDITOR — clases de estilo SOLO cuando difieren del defecto, para
        // que un sitio sin configurar quede con la clase `pp-site-header` exacta.
        $layout = (array) ($config['header']['layout'] ?? []);
        $mods = '';
        if (($layout['sticky'] ?? true) === false)        $mods .= ' pp-site-header--static';
        if (!empty($layout['transparent_over_hero']))     $mods .= ' pp-site-header--transparent';
        $density = (string) ($layout['density'] ?? 'regular');
        if ($density === 'compact' || $density === 'tall') $mods .= ' pp-site-header--density-' . $density;
        if ((string) ($layout['logo_position'] ?? 'left') === 'center') $mods .= ' pp-site-header--logo-center';

        return '<header class="pp-site-header' . $mods . '">'
             . '<div class="pp-site-header__inner">'
             . '<a class="pp-site-header__brand" href="' . htmlspecialchars(base_url('/'), ENT_QUOTES, 'UTF-8') . '">'
             . self::brandMark($data)
             . '</a>'
             . ($links !== '' ? '<nav class="pp-site-header__nav" aria-label="Navegación principal">' . $links . '</nav>' : '')
             . $cta
             . '</div>'
             . '</header>';
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
                    $autoCta = '<a class="pp-btn pp-btn--primary pp-site-header__cta" href="' . $href . '">' . $title . '</a>';
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
            $cta = '<a class="pp-btn ' . $style . ' pp-site-header__cta" href="' . self::href((string) $ctaCfg['url'])
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
    public static function publicFooter(int $siteId, ?array $config = null): string
    {
        $name = htmlspecialchars(self::data($siteId)['name'], ENT_QUOTES, 'UTF-8');
        $year = date('Y');
        $config = $config ?? ChromeService::load($siteId);
        try {
            $legal = Database::select(
                "SELECT title, slug FROM pages
                 WHERE site_id = ? AND page_type = 'legal' AND status = 'published'
                 ORDER BY title ASC",
                [$siteId]
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
            ? '<a class="pp-site-footer__link" href="#" data-cb-reopen>Configurar cookies</a>'
            : '';
        $bannerHtml = CookieBanner::render($manifest);

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
        $brandCol = '<div class="pp-site-footer__brandcol">'
              . '<span class="pp-site-footer__name">' . $name . '</span>'
              . ($tagline !== '' ? '<p class="pp-site-footer__tagline">' . htmlspecialchars($tagline, ENT_QUOTES, 'UTF-8') . '</p>' : '')
              . '</div>';
        $navCol = $navLinks !== ''
            ? '<nav class="pp-site-footer__col" aria-label="Navegación"><span class="pp-site-footer__col-title">Explora</span>' . $navLinks . '</nav>'
            : '';
        $legalCol = ($links !== '' || $reopenLink !== '')
            ? '<nav class="pp-site-footer__col" aria-label="Enlaces legales"><span class="pp-site-footer__col-title">Legal</span>' . $links . $reopenLink . '</nav>'
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
        $footerClass = 'pp-site-footer'
            . ($bg === 'light' ? ' pp-site-footer--light' : ($bg === 'brand' ? ' pp-site-footer--brand' : ''));

        return '<footer class="' . $footerClass . '">'
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
        return '<div class="pp-site-footer__col pp-site-footer__contact" aria-label="Contacto"><span class="pp-site-footer__col-title">Contacto</span>' . $items . '</div>';
    }

    /** Columna de redes sociales. '' si no hay. */
    private static function footerSocialCol(array $config): string
    {
        $links = '';
        foreach ((array) ($config['footer']['social'] ?? []) as $s) {
            if (!is_array($s)) continue;
            $url = trim((string) ($s['url'] ?? ''));
            $net = trim((string) ($s['network'] ?? ''));
            if ($url === '' || $net === '') continue;
            $links .= '<a class="pp-site-footer__link pp-site-footer__social-link" href="' . self::href($url)
                . '" target="_blank" rel="noopener">' . htmlspecialchars(ucfirst($net), ENT_QUOTES, 'UTF-8') . '</a>';
        }
        if ($links === '') return '';
        return '<div class="pp-site-footer__col pp-site-footer__social" aria-label="Redes sociales"><span class="pp-site-footer__col-title">Síguenos</span>' . $links . '</div>';
    }

    /** Columna de newsletter (titular + CTA). '' si no está activada. */
    private static function footerNewsletterCol(array $config): string
    {
        $n = (array) ($config['footer']['newsletter'] ?? []);
        if (empty($n['enabled'])) return '';
        $heading = trim((string) ($n['heading'] ?? '')) ?: 'Suscríbete a nuestra newsletter';
        $url = trim((string) ($n['form_ref'] ?? '')) !== '' ? self::href((string) $n['form_ref']) : self::href('/contacto');
        return '<div class="pp-site-footer__col pp-site-footer__newsletter" aria-label="Newsletter">'
             . '<span class="pp-site-footer__col-title">Newsletter</span>'
             . '<p class="pp-site-footer__newsletter-text">' . htmlspecialchars($heading, ENT_QUOTES, 'UTF-8') . '</p>'
             . '<a class="pp-btn pp-btn--primary pp-btn--sm pp-site-footer__newsletter-cta" href="' . $url . '">Suscribirme</a>'
             . '</div>';
    }
}
