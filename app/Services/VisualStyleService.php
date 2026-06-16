<?php

namespace App\Services;

use Core\Database;

final class VisualStyleService
{
    public const SETTING_KEY = 'site_visual_style_slug';

    public static function all(): array
    {
        return [
            'editorial-impact' => [
                'label' => 'Editorial Impacto',
                'description' => 'Titulares con presencia, bloques editoriales y contraste de campaña sin perder claridad.',
                'heading' => 'Archivo Black',
                'body' => 'Space Grotesk',
                'mode' => 'dark',
                'template_slug' => 'home-default',
            ],
            'signal-clean' => [
                'label' => 'Señal Limpia',
                'description' => 'Preciso, luminoso y muy legible. Ideal para una web que necesita transmitir confianza inmediata.',
                'heading' => 'Outfit',
                'body' => 'Manrope',
                'mode' => 'light',
                'template_slug' => 'home-default',
            ],
            'campaign-contrast' => [
                'label' => 'Campaña Contraste',
                'description' => 'Composición más atrevida, CTAs nítidos y secciones con ritmo de landing de alto impacto.',
                'heading' => 'Space Grotesk',
                'body' => 'DM Sans',
                'mode' => 'contrast',
                'template_slug' => 'landing-product',
            ],
            'precision-grid' => [
                'label' => 'Grid Preciso',
                'description' => 'Retícula visible, bordes finos y sensación técnica. Funciona muy bien para servicios B2B.',
                'heading' => 'IBM Plex Sans',
                'body' => 'IBM Plex Sans',
                'mode' => 'grid',
                'template_slug' => 'service-pro',
            ],
            'atelier-soft' => [
                'label' => 'Atelier Suave',
                'description' => 'Más cálido y editorial, con superficies suaves y un punto premium para marcas cercanas.',
                'heading' => 'Fraunces',
                'body' => 'DM Sans',
                'mode' => 'soft',
                'template_slug' => 'home-default',
            ],
            'magazine-bold' => [
                'label' => 'Magazine Bold',
                'description' => 'Portada visual, jerarquía fuerte y módulos de contenido con aire de publicación contemporánea.',
                'heading' => 'Space Grotesk',
                'body' => 'Plus Jakarta Sans',
                'mode' => 'magazine',
                'template_slug' => 'portfolio',
            ],
            'editorial-xl' => [
                'label' => 'Editorial XL',
                'description' => 'Titulares enormes en cursiva, cuadrícula editorial y aire generoso. Para portfolios y marcas que buscan presencia.',
                'heading' => 'Fraunces',
                'body' => 'Inter',
                'mode' => 'editorial-xl',
                'template_slug' => 'portfolio',
            ],
            'brutalist-mono' => [
                'label' => 'Brutalist Mono',
                'description' => 'Bordes gruesos, sombras duras desplazadas, ritmo técnico. Producto, comunidad y campañas atrevidas.',
                'heading' => 'Space Grotesk',
                'body' => 'JetBrains Mono',
                'mode' => 'brutalist',
                'template_slug' => 'landing-product',
            ],
            'studio-warm' => [
                'label' => 'Studio Warm',
                'description' => 'Cálido, redondeado y editorial. Para marcas cercanas, gastronomía o lifestyle premium sin caer en el cliché.',
                'heading' => 'Fraunces',
                'body' => 'Manrope',
                'mode' => 'warm',
                'template_slug' => 'restaurant',
            ],
        ];
    }
    public static function get(string $slug): ?array
    {
        $styles = self::all();
        return $styles[$slug] ?? null;
    }

    public static function defaultSlug(): string
    {
        return 'signal-clean';
    }

    public static function normalizeSlug(?string $slug): string
    {
        $slug = trim((string) $slug);
        return self::get($slug) ? $slug : self::defaultSlug();
    }

    public static function selectedForSite(int $siteId): string
    {
        $row = Database::selectOne(
            'SELECT setting_value FROM settings WHERE site_id = ? AND setting_key = ? LIMIT 1',
            [$siteId, self::SETTING_KEY]
        );
        return self::normalizeSlug((string) ($row['setting_value'] ?? ''));
    }

    public static function saveSelectedForSite(int $siteId, string $slug): void
    {
        $slug = self::normalizeSlug($slug);
        Database::execute(
            'INSERT INTO settings (site_id, setting_key, setting_value, is_encrypted)
             VALUES (?, ?, ?, 0)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), is_encrypted = 0',
            [$siteId, self::SETTING_KEY, $slug]
        );
    }

    public static function bodyClass(string $slug): string
    {
        return 'pp-visual-style pp-visual-style--' . self::cssSafe(self::normalizeSlug($slug));
    }

    public static function cardsForSite(int $siteId): array
    {
        $cards = [];
        foreach (self::all() as $slug => $style) {
            $tpl = (string) ($style['template_slug'] ?? 'home-default');
            $cards[] = [
                'slug' => $slug,
                'label' => (string) $style['label'],
                'description' => (string) $style['description'],
                'heading_font' => (string) $style['heading'],
                'body_font' => (string) $style['body'],
                'template_slug' => $tpl,
                'preview_url' => base_url('admin/pages/ai/templates/' . $tpl . '/preview?style=' . rawurlencode($slug)),
                'palette' => self::paletteForSite($siteId, $slug),
            ];
        }
        return $cards;
    }

    public static function previewVariant(string $styleSlug, string $sectionType, int $index, int $siteId = 0): string
    {
        $styleSlug = self::normalizeSlug($styleSlug);
        $mode = (string) (self::get($styleSlug)['mode'] ?? 'light');
        $map = [
            'dark' => [
                'hero' => ['poster-stack', 'metric-led'],
                'benefits' => ['manifesto', 'offset-grid'],
                'stats' => ['scoreboard'],
                'pricing' => ['split-value', 'editorial-list'],
                'gallery' => ['editorial-strip', 'mosaic'],
                'cta' => ['poster-close', 'quiet-inline'],
                'testimonials' => ['quote-wall', 'featured-quote'],
            ],
            'light' => [
                'hero' => ['statement-left', 'split'],
                'benefits' => ['proof-strip', 'offset-grid'],
                'stats' => ['inline-bar', 'default'],
                'pricing' => ['comparison', 'editorial-list'],
                'gallery' => ['mosaic', 'editorial-strip'],
                'cta' => ['quiet-inline', 'split'],
                'testimonials' => ['featured-quote', 'default'],
            ],
            'contrast' => [
                'hero' => ['metric-led', 'poster-stack'],
                'benefits' => ['offset-grid', 'manifesto'],
                'stats' => ['scoreboard'],
                'pricing' => ['split-value'],
                'gallery' => ['editorial-strip'],
                'cta' => ['poster-close'],
                'testimonials' => ['quote-wall'],
            ],
            'grid' => [
                'hero' => ['statement-left', 'metric-led'],
                'benefits' => ['manifesto', 'proof-strip'],
                'stats' => ['inline-bar', 'scoreboard'],
                'pricing' => ['editorial-list', 'comparison'],
                'gallery' => ['mosaic'],
                'cta' => ['quiet-inline'],
                'testimonials' => ['quote-wall', 'default'],
            ],
            'soft' => [
                'hero' => ['split', 'statement-left'],
                'benefits' => ['offset-grid', 'cards-icon-top'],
                'stats' => ['default', 'inline-bar'],
                'pricing' => ['comparison', 'split-value'],
                'gallery' => ['mosaic', 'editorial-strip'],
                'cta' => ['card', 'quiet-inline'],
                'testimonials' => ['featured-quote'],
            ],
            'magazine' => [
                'hero' => ['poster-stack', 'statement-left'],
                'benefits' => ['manifesto', 'proof-strip'],
                'stats' => ['scoreboard'],
                'pricing' => ['editorial-list'],
                'gallery' => ['editorial-strip'],
                'cta' => ['poster-close', 'quiet-inline'],
                'testimonials' => ['quote-wall'],
            ],
            'editorial-xl' => [
                'hero' => ['statement-left', 'poster-stack'],
                'benefits' => ['manifesto', 'offset-grid'],
                'stats' => ['scoreboard'],
                'pricing' => ['editorial-list'],
                'gallery' => ['editorial-strip', 'mosaic'],
                'cta' => ['poster-close', 'quiet-inline'],
                'testimonials' => ['featured-quote', 'quote-wall'],
            ],
            'brutalist' => [
                'hero' => ['metric-led', 'statement-left'],
                'benefits' => ['numbered', 'manifesto'],
                'stats' => ['scoreboard', 'inline-bar'],
                'pricing' => ['comparison', 'split-value'],
                'gallery' => ['mosaic'],
                'cta' => ['poster-close'],
                'testimonials' => ['quote-wall'],
            ],
            'warm' => [
                'hero' => ['split', 'default'],
                'benefits' => ['cards-icon-top', 'default'],
                'stats' => ['default', 'inline-bar'],
                'pricing' => ['comparison', 'default'],
                'gallery' => ['mosaic', 'default'],
                'cta' => ['card', 'quiet-inline'],
                'testimonials' => ['featured-quote'],
            ],
        ];

        $choices = $map[$mode][$sectionType] ?? [];
        if ($choices === []) return 'default';
        $seed = crc32($siteId . '|' . $styleSlug . '|' . $sectionType . '|' . $index);
        return $choices[$seed % count($choices)];
    }

    public static function fontsLink(string $slug): string
    {
        $style = self::get($slug);
        if (!$style) return '';
        $fonts = array_values(array_unique(array_filter([
            (string) ($style['heading'] ?? ''),
            (string) ($style['body'] ?? ''),
        ])));
        if (empty($fonts)) return '';

        $families = implode('&family=', array_map(
            fn($f) => str_replace(' ', '+', $f) . ':ital,wght@0,300;0,400;0,500;0,600;0,700;0,800;0,900;1,300;1,400;1,500;1,600;1,700;1,800;1,900',
            $fonts
        ));
        $href = 'https://fonts.googleapis.com/css2?family=' . $families . '&display=swap';
        return '<link rel="stylesheet" href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '">';
    }

    public static function renderCss(int $siteId, string $slug, ?string $paletteOverride = null): string
    {
        $slug = self::normalizeSlug($slug);
        $style = self::get($slug) ?? self::get(self::defaultSlug());
        $palette = self::paletteForSite($siteId, $slug, $paletteOverride);
        $heading = DesignSystem::fontCssValue((string) ($style['heading'] ?? 'Outfit'));
        $body = DesignSystem::fontCssValue((string) ($style['body'] ?? 'Manrope'));
        $mode = (string) ($style['mode'] ?? 'light');

        $vars = [
            '--pp-bg' => $palette['bg'],
            '--pp-surface' => $palette['surface'],
            '--pp-text' => $palette['text'],
            '--pp-text-muted' => $palette['muted'],
            '--pp-border' => $palette['line'],
            '--pp-primary' => $palette['accent'],
            '--pp-primary-dark' => $palette['accent_dark'],
            '--pp-accent' => $palette['accent_2'],
            '--pp-font-heading' => $heading,
            '--pp-font-body' => $body,
        ];
        $varLines = [];
        foreach ($vars as $name => $value) {
            $varLines[] = $name . ':' . $value . ';';
        }

        $class = '.pp-visual-style--' . self::cssSafe($slug);
        $css = $class . '{' . implode('', $varLines) . "}\n";
        $css .= self::baseVisualCss($class, $mode);
        return '<style id="pp-visual-style-css">' . $css . '</style>';
    }

    public static function paletteForSite(int $siteId, string $slug, ?string $paletteOverride = null): array
    {
        $tokens = DesignSystem::load($siteId);
        $colors = $tokens['colors'] ?? [];
        $primary = self::validHex((string) ($colors['primary'] ?? '#ea580c'), '#ea580c');

        // 1) Override explícito (preview de plantilla, etc.).
        if ($paletteOverride !== null && PalettePresets::get($paletteOverride)) {
            return PalettePresets::tokens($paletteOverride, $primary);
        }
        // 2) Si el sitio declara un palette_preset persistido, usar esa paleta
        //    curada y saltar la generación automática derivada del primary.
        $presetSlug = PalettePresets::selectedForSite($siteId);
        if ($presetSlug !== null) {
            return PalettePresets::tokens($presetSlug, $primary);
        }
        $accent = self::desaturate($primary, 0.82);
        $style = self::get($slug) ?? [];
        $mode = (string) ($style['mode'] ?? 'light');

        if ($mode === 'dark' || $mode === 'contrast') {
            return [
                'bg' => self::mix('#11100f', $primary, $mode === 'dark' ? 0.08 : 0.04),
                'surface' => self::mix('#1b1917', $primary, 0.08),
                'text' => '#f7f3ec',
                'muted' => '#c8beb2',
                'line' => self::mix('#302c28', $primary, 0.14),
                'accent' => $accent,
                'accent_dark' => self::mix($accent, '#11100f', 0.25),
                'accent_2' => self::mix('#f6f0df', $accent, 0.26),
            ];
        }

        if ($mode === 'soft') {
            return [
                'bg' => self::mix('#fbf7ef', $primary, 0.04),
                'surface' => self::mix('#fffdf8', $primary, 0.06),
                'text' => '#211c18',
                'muted' => '#756b62',
                'line' => self::mix('#eadfd3', $primary, 0.10),
                'accent' => $accent,
                'accent_dark' => self::mix($accent, '#211c18', 0.18),
                'accent_2' => self::mix('#d7ede2', $accent, 0.18),
            ];
        }

        if ($mode === 'grid') {
            return [
                'bg' => '#f7f8f6',
                'surface' => '#ffffff',
                'text' => '#161a17',
                'muted' => '#5f6860',
                'line' => self::mix('#d9ded8', $primary, 0.10),
                'accent' => $accent,
                'accent_dark' => self::mix($accent, '#161a17', 0.22),
                'accent_2' => self::mix('#eef6d9', $accent, 0.20),
            ];
        }

        if ($mode === 'magazine') {
            return [
                'bg' => self::mix('#f3f6ed', $primary, 0.08),
                'surface' => '#fffefa',
                'text' => '#141411',
                'muted' => '#65665d',
                'line' => self::mix('#d6dacb', $primary, 0.12),
                'accent' => $accent,
                'accent_dark' => self::mix($accent, '#141411', 0.18),
                'accent_2' => self::mix('#11100f', $accent, 0.04),
            ];
        }

        return [
            'bg' => '#fbfbfa',
            'surface' => '#ffffff',
            'text' => '#171717',
            'muted' => '#666666',
            'line' => self::mix('#dededb', $primary, 0.08),
            'accent' => $accent,
            'accent_dark' => self::mix($accent, '#171717', 0.20),
            'accent_2' => self::mix('#e9f2ed', $accent, 0.20),
        ];
    }

    private static function baseVisualCss(string $class, string $mode): string
    {
        $css = <<<CSS
{$class}{letter-spacing:0}
{$class} .pp-site-header{background:color-mix(in srgb,var(--pp-bg) 88%,transparent);border-bottom:1px solid color-mix(in srgb,var(--pp-text) 13%,transparent)}
{$class} h1,{$class} h2,{$class} h3{letter-spacing:0}
{$class} .pp-section--hero{overflow:hidden}
{$class} .pp-section--hero:not(.pp-section--hero--poster-stack):not(.pp-section--hero--with-image-bg){background:var(--pp-bg)}
{$class} .pp-hero__inner--default{align-items:flex-start;text-align:left;padding-top:clamp(54px,8vw,110px);padding-bottom:clamp(42px,6vw,86px)}
{$class} .pp-hero__inner--default .pp-hero__subheading,{$class} .pp-hero__inner--default .pp-hero__cta{margin-left:0;margin-right:0;text-align:left;justify-content:flex-start}
{$class} .pp-hero__heading{font-size:clamp(2.7rem,7.4vw,6.8rem);line-height:.92;max-width:11ch;text-wrap:balance}
{$class} .pp-hero__subheading{font-size:clamp(1rem,1.45vw,1.28rem);max-width:48rem}
{$class} .pp-hero__eyebrow{border-radius:0;background:transparent;border:1px solid color-mix(in srgb,var(--pp-primary) 55%,transparent);color:var(--pp-primary);padding:7px 10px}
{$class} .pp-btn{border-radius:calc(var(--pp-btn-radius) * .75);box-shadow:none;transition:transform .22s cubic-bezier(.16,1,.3,1),background .22s cubic-bezier(.16,1,.3,1)}
{$class} .pp-btn:hover{transform:translateY(-2px)}
{$class} .pp-benefits__grid,{$class} .pp-pricing__grid{grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:1px;background:color-mix(in srgb,var(--pp-text) 12%,transparent);border:1px solid color-mix(in srgb,var(--pp-text) 12%,transparent)}
{$class} .pp-benefit,{$class} .pp-plan{border:0;border-radius:0;background:var(--pp-surface);box-shadow:none}
{$class} .pp-ti{gap:clamp(28px,6vw,86px)}
{$class} .pp-ti__media img,{$class} .pp-gallery__figure{border-radius:calc(var(--pp-radius-card) * .8)}
{$class} .pp-stats__grid{border-top:1px solid color-mix(in srgb,var(--pp-text) 14%,transparent);border-bottom:1px solid color-mix(in srgb,var(--pp-text) 14%,transparent);gap:0}
{$class} .pp-stat{padding:26px 18px}
{$class} .pp-cta--v-card,{$class} .pp-cta--v-default{border-radius:0;background:var(--pp-text);color:var(--pp-bg)}
{$class} .pp-cta--v-card .pp-cta__heading,{$class} .pp-cta--v-default .pp-cta__heading{color:var(--pp-bg)}
{$class} .pp-cta--v-card .pp-cta__desc,{$class} .pp-cta--v-default .pp-cta__desc{color:color-mix(in srgb,var(--pp-bg) 78%,transparent)}
@media (max-width:760px){{$class} .pp-hero__heading{font-size:clamp(2.2rem,14vw,4rem)}{$class} .pp-benefits__grid,{$class} .pp-pricing__grid{display:block;background:transparent;border:0}{$class} .pp-benefit,{$class} .pp-plan{border:1px solid color-mix(in srgb,var(--pp-text) 12%,transparent);margin-bottom:12px}}
CSS;

        if ($mode === 'dark' || $mode === 'contrast') {
            $css .= <<<CSS
{$class} .pp-section--hero::before{content:"";position:absolute;inset:auto 0 0 42%;height:62%;background:linear-gradient(90deg,transparent,color-mix(in srgb,var(--pp-primary) 16%,transparent));pointer-events:none}
{$class} .pp-hero__heading{color:var(--pp-text);text-transform:uppercase;max-width:12ch}
{$class} .pp-btn--primary{color:#15120f}
{$class} .pp-btn--ghost{color:var(--pp-text)}
CSS;
        } elseif ($mode === 'grid') {
            $css .= <<<CSS
{$class} body,{$class}{background-image:linear-gradient(color-mix(in srgb,var(--pp-text) 5%,transparent) 1px,transparent 1px),linear-gradient(90deg,color-mix(in srgb,var(--pp-text) 5%,transparent) 1px,transparent 1px);background-size:32px 32px}
{$class} .pp-hero__heading{text-transform:uppercase}
CSS;
        } elseif ($mode === 'soft') {
            $css .= <<<CSS
{$class} .pp-hero__heading{font-size:clamp(2.6rem,6.2vw,5.8rem);line-height:.98;max-width:13ch}
{$class} .pp-benefit,{$class} .pp-plan{border-radius:calc(var(--pp-radius-card) * 1.4)}
{$class} .pp-benefits__grid,{$class} .pp-pricing__grid{background:transparent;border:0;gap:18px}
CSS;
        } elseif ($mode === 'magazine') {
            $css .= <<<CSS
{$class} .pp-hero__heading{text-transform:uppercase;max-width:10ch}
{$class} .pp-hero__inner--default{border-bottom:1px solid color-mix(in srgb,var(--pp-text) 16%,transparent)}
{$class} .pp-section--hero--poster-stack{background:var(--pp-text);color:var(--pp-bg)}
{$class} .pp-section--hero--poster-stack .pp-hero__heading{color:var(--pp-bg)}
{$class} .pp-section--hero--poster-stack .pp-hero__subheading{color:color-mix(in srgb,var(--pp-bg) 78%,transparent)}
CSS;
        } elseif ($mode === 'editorial-xl') {
            $css .= <<<CSS
/* ============================================================
   EDITORIAL XL — Magazine-grade typography + intentional drama.
   ============================================================ */
{$class}{
    --pp-disp: var(--pp-font-heading);
    --pp-rule: 1px solid color-mix(in srgb, var(--pp-text) 18%, transparent);
    --pp-rule-strong: 1px solid var(--pp-text);
    counter-reset: pp-section;
    background-color: var(--pp-bg);
    background-image: radial-gradient(color-mix(in srgb,var(--pp-text) 4%,transparent) 1px,transparent 1.4px);
    background-size: 22px 22px;
    background-attachment: fixed;
}
{$class} .pp-section{padding:clamp(72px, 9vw, 140px) 0;position:relative;counter-increment:pp-section}
{$class} .pp-section + .pp-section{padding-top:clamp(72px, 9vw, 140px)}
{$class} .pp-section::before{
    content:counter(pp-section,decimal-leading-zero) " / Capítulo";
    position:absolute;top:32px;left:clamp(20px, 5vw, 64px);
    font-family:var(--pp-font-body);font-weight:500;font-size:.7rem;letter-spacing:.24em;
    color:color-mix(in srgb,var(--pp-text) 50%,transparent);text-transform:uppercase;
}
{$class} .pp-section--hero::before{content:none}
{$class} .pp-section--cta::before{color:color-mix(in srgb,var(--pp-bg) 70%,transparent)}
{$class} .container{max-width:min(1320px,calc(100% - clamp(40px,8vw,120px)))}

/* H2 editorial con regla larga */
{$class} h2{
    font-family:var(--pp-disp);font-style:italic;font-weight:400;
    font-size:clamp(2.4rem,5.6vw,4.6rem);line-height:.96;letter-spacing:-.02em;
    max-width:18ch;margin:0 0 clamp(28px,3vw,48px)
}
{$class} h3{font-family:var(--pp-disp);letter-spacing:-.01em}

/* ============================================================
   HEADER — masthead-like band
   ============================================================ */
{$class} .pp-site-header{
    background:transparent;border-bottom:var(--pp-rule-strong);
    padding:18px clamp(24px,5vw,64px);min-height:auto;
    backdrop-filter:none
}
{$class} .pp-site-header__brand{font-family:var(--pp-disp);font-style:italic;font-weight:500;letter-spacing:-.01em;font-size:1.1rem}

/* ============================================================
   HERO — magazine cover + huge italic display
   ============================================================ */
{$class} .pp-section--hero{
    border-bottom:var(--pp-rule-strong);background:var(--pp-bg);overflow:hidden;
    padding:0
}
{$class} .pp-section--hero .container{
    max-width:var(--pp-container-max);
    margin:0 auto;
    padding:0 clamp(24px,5vw,64px);
}
{$class} .pp-hero__inner{
    display:grid;grid-template-columns:1fr;gap:0;
    padding:clamp(72px,8vw,120px) clamp(40px,8vw,96px) clamp(40px,5vw,72px);
    min-height:clamp(560px,86vh,820px);position:relative
}
/* Guard-rail contra reglas base del hero/poster-stack que pegaban el contenido a borde */
{$class} .pp-section--hero .pp-hero__inner,
{$class} .pp-section--hero .pp-hero__inner--default,
{$class} .pp-section--hero--poster-stack .pp-hero__inner,
{$class} .pp-section--hero--poster-stack .pp-hero__inner--default{
    padding-left:clamp(36px,7vw,88px) !important;
    padding-right:clamp(36px,7vw,88px) !important;
}
/* Tag inferior — única anotación editorial del hero */
{$class} .pp-section--hero .pp-hero__inner::after{
    content:"Vol. 03 \\00a0\\00a0\\00b7\\00a0\\00a0 Edici\\00f3n 2026";
    position:absolute;bottom:22px;right:clamp(28px,5vw,72px);
    font-family:var(--pp-font-body);font-weight:500;font-size:.7rem;
    letter-spacing:.28em;text-transform:uppercase;
    color:color-mix(in srgb,var(--pp-text) 60%,transparent)
}
{$class} .pp-hero__inner--default{align-items:flex-end;text-align:left}
{$class} .pp-hero__heading{
    font-family:var(--pp-disp);font-style:italic;font-weight:400;
    font-size:clamp(3rem,9vw,8rem);line-height:.92;letter-spacing:-.025em;
    color:var(--pp-text);max-width:16ch;margin:0 0 .4em;text-wrap:balance
}
{$class} .pp-hero__subheading{
    font-family:var(--pp-font-body);font-style:normal;
    font-size:clamp(1.05rem,1.6vw,1.35rem);max-width:46rem;
    color:color-mix(in srgb,var(--pp-text) 76%,transparent);margin:0 0 1.4em
}
{$class} .pp-hero__eyebrow{
    display:inline-flex;align-items:center;gap:14px;
    background:transparent;border:0;border-radius:0;color:var(--pp-text);
    padding:0;margin:0 0 1.6em;
    font-family:var(--pp-font-body);font-weight:600;font-size:.78rem;
    letter-spacing:.26em;text-transform:uppercase
}
{$class} .pp-hero__eyebrow::before{
    content:"";display:inline-block;width:48px;height:1px;background:var(--pp-text)
}
{$class} .pp-hero__cta{display:flex;gap:14px;flex-wrap:wrap;margin-top:.6em}

/* poster-stack reescrito */
{$class} .pp-section--hero--poster-stack{background:var(--pp-bg);color:var(--pp-text)}
{$class} .pp-section--hero--poster-stack .pp-hero__inner{align-items:flex-end}
{$class} .pp-section--hero--poster-stack .pp-hero__heading{color:var(--pp-text);text-transform:none}
{$class} .pp-section--hero--poster-stack .pp-hero__inner::after{color:color-mix(in srgb,var(--pp-text) 70%,transparent)}
{$class} .pp-section--hero--poster-stack .pp-hero__inner--default::after{display:none}

/* ============================================================
   BUTTONS
   ============================================================ */
{$class} .pp-btn{
    border-radius:999px;padding:14px 24px;font-family:var(--pp-font-body);
    font-weight:600;font-size:.92rem;letter-spacing:.02em;text-transform:none;
    border:1px solid var(--pp-text);background:var(--pp-text);color:var(--pp-bg);
    box-shadow:none;position:relative;overflow:hidden;
    transition:transform .35s var(--pp-ease-out),background .35s,color .35s
}
{$class} .pp-btn:hover{background:var(--pp-primary);border-color:var(--pp-primary);color:#fff;transform:translateY(-1px)}
{$class} .pp-btn--ghost{background:transparent;color:var(--pp-text);box-shadow:none}
{$class} .pp-btn--lg{padding:16px 30px;font-size:1rem}

/* ============================================================
   TEXT + IMAGE — drop cap + italic header + caption
   ============================================================ */
{$class} .pp-ti{gap:clamp(40px,6vw,90px);align-items:start}
{$class} .pp-ti__heading{font-family:var(--pp-disp);font-style:italic;font-weight:400;font-size:clamp(2.2rem,4.2vw,3.6rem);line-height:1;letter-spacing:-.02em;max-width:18ch;margin:0 0 .6em}
{$class} .pp-ti__body{font-size:1.05rem;line-height:1.7;max-width:38em}
{$class} .pp-ti__body p:first-of-type{margin-top:0}
{$class} .pp-ti__body p:first-of-type::first-letter{
    font-family:var(--pp-disp);font-style:italic;font-weight:400;
    font-size:5.2em;line-height:.86;float:left;
    margin:.04em .12em -.06em 0;color:var(--pp-primary)
}
{$class} .pp-ti__media{position:relative}
{$class} .pp-ti__media img{border-radius:0;border:1px solid var(--pp-text);box-shadow:none}
{$class} .pp-ti__media::after{
    content:"Fig. 01 — De cerca";
    display:block;margin-top:14px;
    font-family:var(--pp-disp);font-style:italic;font-size:.95rem;
    color:color-mix(in srgb,var(--pp-text) 65%,transparent)
}
{$class} .pp-ti--v-card{background:var(--pp-surface);border:1px solid var(--pp-text);border-radius:0;box-shadow:none;padding:clamp(36px,4.5vw,72px)}
{$class} .pp-ti--v-card .pp-ti__media img{border:0}

/* ============================================================
   GALLERY — editorial strip with indexed captions
   ============================================================ */
{$class} .pp-gallery__heading{margin-bottom:clamp(36px,5vw,72px)}
{$class} .pp-gallery__grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:clamp(28px,3vw,56px) clamp(20px,2vw,40px);counter-reset:pp-gallery;list-style:none;padding:0;margin:0}
{$class} .pp-gallery--v-editorial-strip .pp-gallery__grid{grid-template-columns:repeat(auto-fit,minmax(300px,1fr))}
{$class} .pp-gallery__item{counter-increment:pp-gallery;position:relative}
{$class} .pp-gallery__figure{margin:0;position:relative;display:flex;flex-direction:column;gap:14px;transition:transform .5s var(--pp-ease-out)}
{$class} .pp-gallery__figure:hover{transform:translateY(-4px)}
{$class} .pp-gallery__img{border-radius:0;width:100%;height:auto;display:block;filter:saturate(.96);transition:filter .45s,transform .8s var(--pp-ease-out)}
{$class} .pp-gallery__figure:hover .pp-gallery__img{filter:saturate(1.05);transform:scale(1.012)}
{$class} .pp-gallery__caption{
    font-family:var(--pp-disp);font-style:italic;font-weight:400;font-size:1.05rem;
    color:var(--pp-text);margin:0;padding-top:8px;border-top:var(--pp-rule);
    display:flex;align-items:baseline;gap:14px
}
{$class} .pp-gallery__caption::before{
    content:counter(pp-gallery,decimal-leading-zero);
    font-family:var(--pp-font-body);font-style:normal;font-weight:600;
    font-size:.74rem;letter-spacing:.18em;text-transform:uppercase;
    color:color-mix(in srgb,var(--pp-text) 60%,transparent);
    flex-shrink:0
}

/* ============================================================
   TESTIMONIALS — featured-quote
   ============================================================ */
{$class} .pp-testimonials--v-featured-quote{position:relative;text-align:left}
{$class} .pp-testimonials__heading{display:none}
{$class} .pp-testimonial--featured{
    position:relative;max-width:none;padding:clamp(48px,6vw,90px) clamp(32px,5vw,80px);
    border-top:var(--pp-rule-strong);border-bottom:var(--pp-rule-strong);
    margin:0;background:var(--pp-bg)
}
{$class} .pp-testimonial--featured::before{
    content:"\\201C";position:absolute;top:-.18em;left:clamp(20px,3vw,52px);
    font-family:var(--pp-disp);font-style:italic;font-weight:400;
    font-size:clamp(8rem,18vw,16rem);line-height:1;color:var(--pp-primary);
    opacity:.16;pointer-events:none
}
{$class} .pp-testimonial--featured .pp-testimonial__quote{
    font-family:var(--pp-disp);font-style:italic;font-weight:400;
    font-size:clamp(1.6rem,3.5vw,2.8rem);line-height:1.18;letter-spacing:-.01em;
    color:var(--pp-text);max-width:28ch;margin:0 0 28px;text-wrap:balance
}
/* Reset defensivo: evitar pseudo-elementos accidentales con '<' en quotes/figures */
{$class} .pp-testimonial--featured .pp-testimonial__quote::before,
{$class} .pp-testimonial--featured .pp-testimonial__quote::after,
{$class} .pp-testimonial--featured figure::before,
{$class} .pp-testimonial--featured figure::after{content:none!important}
{$class} .pp-testimonial--featured .pp-testimonial__caption{
    display:flex;align-items:center;gap:18px;
    font-family:var(--pp-font-body);font-size:.78rem;letter-spacing:.22em;
    text-transform:uppercase;color:color-mix(in srgb,var(--pp-text) 70%,transparent)
}
{$class} .pp-testimonial--featured .pp-testimonial__avatar{width:48px;height:48px;border-radius:0;border:1px solid var(--pp-text);object-fit:cover}
{$class} .pp-testimonial--featured .pp-testimonial__person{display:flex;flex-direction:column;gap:2px}
{$class} .pp-testimonial--featured .pp-testimonial__person strong{font-style:italic;font-family:var(--pp-disp);font-weight:400;font-size:1.15rem;letter-spacing:0;text-transform:none;color:var(--pp-text)}
{$class} .pp-testimonial--featured .pp-testimonial__role{color:color-mix(in srgb,var(--pp-text) 60%,transparent)}

/* ============================================================
   LOGOS STRIP — banda editorial con divisores
   ============================================================ */
{$class} .pp-logos{padding:clamp(44px,5vw,72px) 0;border-top:var(--pp-rule);border-bottom:var(--pp-rule)}
{$class} .pp-logos__heading{
    font-family:var(--pp-font-body);font-weight:600;font-size:.78rem;
    letter-spacing:.28em;text-transform:uppercase;text-align:center;
    color:color-mix(in srgb,var(--pp-text) 65%,transparent);margin:0 0 28px
}
{$class} .pp-logos__track-wrap{overflow:hidden}
{$class} .pp-logos__track{display:flex;flex-wrap:wrap;justify-content:center;align-items:center;gap:0;list-style:none;padding:0;margin:0}
{$class} .pp-logos__item{padding:0 clamp(20px,3vw,40px);border-right:var(--pp-rule)}
{$class} .pp-logos__item:last-child{border-right:0}
{$class} .pp-logos__cell{display:inline-flex;align-items:center;justify-content:center;height:56px;opacity:.78;transition:opacity .25s,filter .25s;filter:grayscale(1) contrast(.95)}
{$class} .pp-logos__cell:hover{opacity:1;filter:grayscale(0) contrast(1)}
{$class} .pp-logos__img{max-height:32px;width:auto}

/* ============================================================
   CTA — poster-close fullbleed
   ============================================================ */
{$class} .pp-section--cta{background:var(--pp-text);color:var(--pp-bg);border:0;padding:clamp(80px,10vw,160px) 0;position:relative}
{$class} .pp-section--cta::after{
    content:"\\00a7 Hablemos";
    position:absolute;top:34px;right:clamp(24px,5vw,72px);
    font-family:var(--pp-font-body);font-weight:500;font-size:.74rem;
    letter-spacing:.28em;text-transform:uppercase;
    color:color-mix(in srgb,var(--pp-bg) 70%,transparent)
}
{$class} .pp-cta{text-align:left;max-width:none}
{$class} .pp-cta--v-poster-close,{$class} .pp-cta--v-default,{$class} .pp-cta--v-card{background:transparent;color:var(--pp-bg);border-radius:0;box-shadow:none;padding:0 clamp(32px,5vw,80px)}
{$class} .pp-cta__heading{
    font-family:var(--pp-disp);font-style:italic;font-weight:400;
    font-size:clamp(2.6rem,8vw,7rem);line-height:.92;letter-spacing:-.025em;
    color:var(--pp-bg);max-width:16ch;margin:0 0 .4em;text-wrap:balance
}
{$class} .pp-cta__desc{font-family:var(--pp-font-body);color:color-mix(in srgb,var(--pp-bg) 78%,transparent);max-width:48ch;font-size:1.1rem;line-height:1.6;margin:0 0 2em}
{$class} .pp-cta__cta,{$class} .pp-cta__action{margin:0}
{$class} .pp-section--cta .pp-btn{background:var(--pp-bg);color:var(--pp-text);border-color:var(--pp-bg)}
{$class} .pp-section--cta .pp-btn:hover{background:var(--pp-primary);color:#fff;border-color:var(--pp-primary)}

/* ============================================================
   FORM
   ============================================================ */
{$class} .pp-form{display:grid;grid-template-columns:1fr 1.4fr;gap:clamp(36px,5vw,80px);align-items:start}
{$class} .pp-form--v-default{grid-template-columns:1fr;max-width:48em}
{$class} .pp-form__heading{font-family:var(--pp-disp);font-style:italic;font-weight:400;font-size:clamp(2rem,3.6vw,3rem);line-height:1;letter-spacing:-.02em;margin:0 0 .5em}
{$class} .pp-form__desc{color:color-mix(in srgb,var(--pp-text) 72%,transparent);max-width:42ch}
{$class} .pp-form__form{display:grid;gap:18px}
{$class} .pp-form__row{display:flex;flex-direction:column;gap:6px}
{$class} .pp-form__label{font-family:var(--pp-font-body);font-weight:600;font-size:.74rem;letter-spacing:.22em;text-transform:uppercase;color:color-mix(in srgb,var(--pp-text) 65%,transparent)}
{$class} .pp-form__control{background:transparent;border:0;border-bottom:var(--pp-rule-strong);border-radius:0;padding:14px 0;font-family:var(--pp-font-body);font-size:1.05rem;color:var(--pp-text);transition:border-color .2s}
{$class} .pp-form__control:focus{outline:0;border-bottom-color:var(--pp-primary)}

/* ============================================================
   STATS / BENEFITS — overrides previos refinados
   ============================================================ */
{$class} .pp-stats__grid{grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:0;border-top:var(--pp-rule-strong);border-bottom:var(--pp-rule-strong);background:transparent;border-left:0;border-right:0}
{$class} .pp-stat{padding:clamp(36px,5vw,72px) 24px;border-right:var(--pp-rule);background:transparent}
{$class} .pp-stat:last-child{border-right:0}
{$class} .pp-stat__value{font-family:var(--pp-disp);font-style:italic;font-weight:400;font-size:clamp(3rem,7vw,6rem);line-height:1;letter-spacing:-.04em;color:var(--pp-text);display:flex;align-items:baseline;gap:6px}
{$class} .pp-stat__suffix{font-size:.4em;font-style:normal;font-weight:600;color:var(--pp-primary)}
{$class} .pp-stat__label{font-family:var(--pp-font-body);font-size:.78rem;letter-spacing:.22em;text-transform:uppercase;color:color-mix(in srgb,var(--pp-text) 65%,transparent);margin-top:14px}

{$class} .pp-benefits__grid{grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:0;background:transparent;border-top:var(--pp-rule-strong);border-bottom:var(--pp-rule-strong)}
{$class} .pp-benefit{background:transparent;border:0;border-right:var(--pp-rule);border-radius:0;padding:clamp(36px,4vw,56px) 28px;box-shadow:none;counter-increment:pp-benefit}
{$class} .pp-benefit:last-child{border-right:0}
{$class} .pp-benefit__icon{display:none}
{$class} .pp-benefit__title{font-family:var(--pp-disp);font-style:italic;font-weight:400;font-size:clamp(1.4rem,2.2vw,2rem);line-height:1.05;margin:0 0 14px;letter-spacing:-.01em}
{$class} .pp-benefit__title::before{
    content:counter(pp-benefit,upper-roman);display:block;
    font-family:var(--pp-font-body);font-style:normal;font-weight:600;font-size:.74rem;
    letter-spacing:.28em;text-transform:uppercase;
    color:color-mix(in srgb,var(--pp-text) 56%,transparent);margin-bottom:18px
}
{$class} .pp-benefit__desc{color:color-mix(in srgb,var(--pp-text) 72%,transparent);max-width:30ch;line-height:1.55}
{$class} .pp-benefits__grid{counter-reset:pp-benefit}

@media (max-width:760px){
    {$class} .pp-form{grid-template-columns:1fr}
    {$class} .pp-section--hero .pp-hero__inner::before{font-size:.62rem;flex-direction:column;gap:4px;text-align:left}
    {$class} .pp-stat,{$class} .pp-benefit{border-right:0;border-bottom:var(--pp-rule)}
    {$class} .pp-stats__grid,{$class} .pp-benefits__grid{grid-template-columns:1fr}
    {$class} .pp-section::before{position:static;display:block;margin-bottom:24px}
}
CSS;
        } elseif ($mode === 'brutalist') {
            $css .= <<<CSS
{$class} h1,{$class} h2,{$class} h3{text-transform:uppercase;letter-spacing:-.01em}
{$class} .pp-hero__heading{font-size:clamp(2.6rem,9vw,7rem);line-height:.92;max-width:13ch;font-weight:900}
{$class} .pp-hero__eyebrow{background:var(--pp-text);color:var(--pp-bg);border-radius:0;padding:6px 12px;font-family:var(--pp-font-body);text-transform:uppercase;letter-spacing:.1em}
{$class} .pp-btn{border-radius:0;border:2px solid var(--pp-text);box-shadow:4px 4px 0 var(--pp-text);transition:transform .12s linear,box-shadow .12s linear}
{$class} .pp-btn:hover{transform:translate(-2px,-2px);box-shadow:6px 6px 0 var(--pp-text);filter:none}
{$class} .pp-btn:active{transform:translate(2px,2px);box-shadow:2px 2px 0 var(--pp-text)}
{$class} .pp-btn--ghost{background:var(--pp-bg);color:var(--pp-text)}
{$class} .pp-benefits__grid,{$class} .pp-pricing__grid{grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:18px;background:transparent;border:0}
{$class} .pp-benefit,{$class} .pp-plan{border:2px solid var(--pp-text);border-radius:0;background:var(--pp-surface);box-shadow:6px 6px 0 var(--pp-text);padding:28px}
{$class} .pp-benefit:hover{transform:translate(-2px,-2px);box-shadow:8px 8px 0 var(--pp-text)}
{$class} .pp-stats__grid{grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:0;border:2px solid var(--pp-text)}
{$class} .pp-stat{border-right:2px solid var(--pp-text);padding:32px 16px}
{$class} .pp-stat:last-child{border-right:0}
{$class} .pp-stat__value{font-size:clamp(2.4rem,5.4vw,4.4rem);font-weight:900}
{$class} .pp-cta--v-default,{$class} .pp-cta--v-card{background:var(--pp-text);color:var(--pp-bg);border-radius:0;border:0;box-shadow:8px 8px 0 var(--pp-primary)}
{$class} .pp-cta--v-default .pp-cta__heading,{$class} .pp-cta--v-card .pp-cta__heading{color:var(--pp-bg);text-transform:uppercase}
{$class} .pp-cta--v-default .pp-btn,{$class} .pp-cta--v-card .pp-btn{background:var(--pp-bg);color:var(--pp-text);border-color:var(--pp-bg);box-shadow:4px 4px 0 var(--pp-primary)}
{$class} .pp-ti__media img{border-radius:0;border:2px solid var(--pp-text);box-shadow:8px 8px 0 var(--pp-text)}
CSS;
        } elseif ($mode === 'warm') {
            $css .= <<<CSS
{$class} .pp-hero__heading{font-size:clamp(2.4rem,6.2vw,5.8rem);line-height:1.02;letter-spacing:-.02em;max-width:14ch}
{$class} .pp-hero__eyebrow{background:transparent;color:var(--pp-primary);border:0;font-family:var(--pp-font-heading);font-style:italic;font-weight:500;font-size:1rem;letter-spacing:0;text-transform:none;padding:0}
{$class} .pp-hero__eyebrow::before{content:"\2014\00a0";opacity:.6}
{$class} .pp-hero__subheading{font-family:var(--pp-font-heading);font-style:italic;font-size:clamp(1.1rem,1.6vw,1.4rem);max-width:42rem}
{$class} .pp-btn{border-radius:999px;padding:14px 28px}
{$class} .pp-benefits__grid,{$class} .pp-pricing__grid{grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:24px;background:transparent;border:0}
{$class} .pp-benefit,{$class} .pp-plan{border:0;border-radius:calc(var(--pp-radius-card) * 2);background:var(--pp-surface);padding:36px 32px;box-shadow:0 24px 48px -28px color-mix(in srgb,var(--pp-text) 22%,transparent)}
{$class} .pp-benefit__title{font-style:italic;letter-spacing:0}
{$class} .pp-ti__media img{border-radius:calc(var(--pp-radius-card) * 1.4)}
{$class} .pp-cta--v-default,{$class} .pp-cta--v-card{border-radius:calc(var(--pp-radius-card) * 1.4);background:var(--pp-surface);color:var(--pp-text);box-shadow:0 30px 60px -32px color-mix(in srgb,var(--pp-text) 28%,transparent)}
{$class} .pp-cta--v-default .pp-cta__heading,{$class} .pp-cta--v-card .pp-cta__heading{color:var(--pp-text);font-style:italic;font-size:clamp(1.8rem,4vw,3.2rem);max-width:18ch;margin-left:auto;margin-right:auto}
{$class} .pp-stats__grid{gap:0;border-top:1px solid var(--pp-line);border-bottom:1px solid var(--pp-line)}
{$class} .pp-stat__value{font-style:italic;font-size:clamp(2.4rem,5.4vw,4.4rem);font-weight:600;color:var(--pp-primary)}
CSS;
        }

        return $css;
    }

    private static function validHex(string $value, string $fallback): string
    {
        return preg_match('/^#[0-9a-f]{6}$/i', $value) ? strtolower($value) : $fallback;
    }

    private static function desaturate(string $hex, float $amount): string
    {
        return self::mix($hex, '#8a8176', 1 - $amount);
    }

    private static function mix(string $a, string $b, float $weightB): string
    {
        $weightB = max(0, min(1, $weightB));
        [$ar, $ag, $ab] = self::rgb($a);
        [$br, $bg, $bb] = self::rgb($b);
        $r = (int) round($ar * (1 - $weightB) + $br * $weightB);
        $g = (int) round($ag * (1 - $weightB) + $bg * $weightB);
        $b2 = (int) round($ab * (1 - $weightB) + $bb * $weightB);
        return sprintf('#%02x%02x%02x', $r, $g, $b2);
    }

    private static function rgb(string $hex): array
    {
        $hex = ltrim(self::validHex($hex, '#000000'), '#');
        return [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
    }

    private static function cssSafe(string $value): string
    {
        return preg_replace('/[^a-z0-9_-]+/i', '-', strtolower($value)) ?: 'style';
    }
}
