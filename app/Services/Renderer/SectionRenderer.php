<?php

namespace App\Services\Renderer;

use App\Services\SectionSchemas;
use Core\CSRF;
use Core\Database;
use Core\Request;
use Core\Session;

/**
 * Renderiza una sección tipada (row de `page_sections`) a HTML semántico.
 *
 * Uso:
 *   $html = SectionRenderer::render($sectionRow);
 *
 * $sectionRow debe tener al menos:
 *   - id (int)
 *   - section_type (string)
 *   - content (string JSON) o content_json (ya decodificado)
 *   - style (string JSON) opcional → puede contener `variant`
 *
 * Variantes (T18.1):
 *   La columna `style` JSON puede incluir `{"variant": "split"}`. Si la
 *   variante es válida para el tipo (ver SectionSchemas::variantsFor), se
 *   añade la clase `pp-section--{type}--{variant}` al `<section>` y se elige
 *   un layout interno acorde. Si no es válida, se hace fallback a `default`
 *   sin romper páginas pre-existentes.
 */
final class SectionRenderer
{
    /**
     * Mapa URL → ['name','url'] de atribuciones cargadas para el sitio actual.
     * Se rellena con setSiteContext() antes de renderizar.
     * @var array<string,array{name:string,url:string}>
     */
    private static array $attributions = [];

    /** Sitio activo. F21.T21.3 lo usa para listar entradas publicadas. */
    private static int $siteId = 0;

    /**
     * T18.4 — establece el sitio activo para que el renderer pueda inyectar
     * atribución a las imágenes provenientes de Unsplash. Llamar una vez antes
     * de `renderMany()`. Si nunca se llama, no se renderizan atribuciones (las
     * páginas que no usan banco no pagan coste extra de consulta).
     */
    public static function setSiteContext(int $siteId): void
    {
        self::$siteId = $siteId;
        self::$attributions = [];
        if ($siteId <= 0) return;

        try {
            $rows = Database::select(
                "SELECT path, attribution_name, attribution_url
                 FROM media
                 WHERE site_id = ? AND source = 'unsplash'
                   AND attribution_name IS NOT NULL AND attribution_name <> ''",
                [$siteId]
            );
        } catch (\Throwable $e) {
            // Schema antiguo sin las columnas T18.4: ignorar silenciosamente.
            return;
        }
        foreach ($rows as $r) {
            $path = (string) $r['path'];
            self::$attributions['/' . ltrim($path, '/')] = [
                'name' => (string) ($r['attribution_name'] ?? ''),
                'url'  => (string) ($r['attribution_url'] ?? ''),
            ];
        }
    }

    /** Renderiza una sola sección. */
    public static function render(array $section): string
    {
        $type = (string) ($section['section_type'] ?? 'generic');
        $id   = (int) ($section['id'] ?? 0);

        $content = self::decodeContent($section);
        $style   = self::decodeStyle($section);
        $variant = SectionSchemas::normalizeVariant($type, (string) ($style['variant'] ?? ''));

        $inner = match ($type) {
            'hero'         => self::renderHero($content, $variant),
            'text_image'   => self::renderTextImage($content, $variant),
            'benefits'     => self::renderBenefits($content, $variant),
            'faq'          => self::renderFaq($content, $variant),
            'cta'          => self::renderCta($content, $variant),
            'form'         => self::renderForm($content, $id, $variant),
            'testimonials' => self::renderTestimonials($content, $variant),
            'stats'        => self::renderStats($content, $variant),
            'gallery'      => self::renderGallery($content, $variant),
            'steps'        => self::renderSteps($content, $variant),
            'logos_strip'  => self::renderLogosStrip($content, $variant),
            'pricing'      => self::renderPricing($content, $variant),
            'article_body' => self::renderArticleBody($content, $variant),
            'posts_listing'=> self::renderPostsListing($content, $variant),
            'custom_block' => self::renderCustomBlock($content, $section),
            default        => self::renderGeneric($content),
        };

        $anchor = $id > 0 ? ' id="sec-' . $id . '"' : '';
        $classes = 'pp-section pp-section--' . self::cssSafe($type)
            . ' pp-section--' . self::cssSafe($type) . '--' . self::cssSafe($variant);

        // D-MB2 R2 — dirección de arte de los custom_block: el theme/pad vive en
        // content.art (extraído por el sanitizer) y se aplica al wrapper para que
        // el fondo ocupe todo el ancho de la página.
        if ($type === 'custom_block') {
            $art = is_array($content['art'] ?? null) ? $content['art'] : [];
            $theme = (string) ($art['theme'] ?? '');
            $pad = (string) ($art['pad'] ?? '');
            if (in_array($theme, ['surface', 'tint', 'primary', 'dark', 'image'], true)) {
                $classes .= ' pp-section--ppbt-' . $theme;
            }
            if (in_array($pad, ['sm', 'md', 'lg', 'xl'], true)) {
                $classes .= ' pp-section--ppbp-' . $pad;
            }
        }

        return '<section' . $anchor . ' class="' . $classes . '" data-variant="' . self::e($variant) . '">' . $inner . '</section>';
    }

    /** Renderiza una lista ordenada de secciones. */
    public static function renderMany(array $sections): string
    {
        $html = '';
        foreach ($sections as $s) {
            $html .= self::render($s) . "\n";
        }
        return $html;
    }

    // ======================================================================
    // Renderers por tipo
    // ======================================================================

    private static function renderHero(array $c, string $variant): string
    {
        $heading    = self::str($c, 'heading');
        $eyebrow    = self::str($c, 'eyebrow');
        $subheading = self::str($c, 'subheading');
        $ctaText    = self::str($c, 'cta_text');
        $ctaUrl     = self::cleanUrl(self::str($c, 'cta_url'));
        $ctaText2   = self::str($c, 'cta_text_secondary');
        $ctaUrl2    = self::cleanUrl(self::str($c, 'cta_url_secondary'));
        $img        = self::cleanImage(self::str($c, 'image_url'));
        $bg         = self::cleanImage(self::str($c, 'background_image'));

        // Fallback elegante: si la variante exige imagen y no la hay, degradar a default.
        if ($variant === 'with-image-bg' && $bg === '') $variant = 'default';
        if ($variant === 'split' && $img === '' && $bg === '') $variant = 'default';

        $textBlock  = '';
        if ($eyebrow !== '')    $textBlock .= '<p class="pp-hero__eyebrow">' . self::e($eyebrow) . '</p>';
        if ($heading !== '')    $textBlock .= '<h1 class="pp-hero__heading">' . self::e($heading) . '</h1>';
        if ($subheading !== '') $textBlock .= '<p class="pp-hero__subheading">' . self::nl2br(self::e($subheading)) . '</p>';

        if ($ctaText !== '' && $ctaUrl !== '') {
            $textBlock .= '<p class="pp-hero__cta">';
            $textBlock .= '<a class="pp-btn pp-btn--primary pp-btn--lg" href="' . self::e($ctaUrl) . '">' . self::e($ctaText) . '</a>';
            if ($ctaText2 !== '' && $ctaUrl2 !== '') {
                $textBlock .= ' <a class="pp-btn pp-btn--ghost pp-btn--lg" href="' . self::e($ctaUrl2) . '">' . self::e($ctaText2) . '</a>';
            }
            $textBlock .= '</p>';
        }

        if ($variant === 'split') {
            $mediaSrc = $img !== '' ? $img : $bg;
            $html  = '<div class="pp-hero__inner pp-hero__inner--split container">';
            $html .= '<div class="pp-hero__text">' . $textBlock . '</div>';
            $html .= '<div class="pp-hero__media">' . self::mediaInner($mediaSrc, $heading, 'eager') . '</div>';
            $html .= '</div>';
            return $html;
        }

        if ($variant === 'with-image-bg') {
            $html  = '<div class="pp-hero__inner pp-hero__inner--bg container" style="background-image:url(\'' . self::e($bg) . '\')">';
            $html .= '<div class="pp-hero__overlay"></div>';
            $html .= '<div class="pp-hero__text pp-hero__text--on-bg">' . $textBlock . '</div>';
            $html .= self::imageAttribution($bg);
            $html .= '</div>';
            return $html;
        }

        // default: centrado limpio
        $html  = '<div class="pp-hero__inner pp-hero__inner--default container">';
        $html .= '<div class="pp-hero__text">' . $textBlock . '</div>';
        $html .= '</div>';
        return $html;
    }

    private static function renderTextImage(array $c, string $variant): string
    {
        $heading = self::str($c, 'heading');
        $body    = self::str($c, 'body');
        $img     = self::cleanImage(self::str($c, 'image_url'));
        $side    = self::str($c, 'image_side', 'right') === 'left' ? 'left' : 'right';
        $ctaText = self::str($c, 'cta_text');
        $ctaUrl  = self::cleanUrl(self::str($c, 'cta_url'));

        $hasImg = $img !== '';
        // wide-media y card pierden su carácter sin imagen → fallback a default.
        if (!$hasImg && in_array($variant, ['wide-media', 'card'], true)) $variant = 'default';

        $cls = 'pp-ti container pp-ti--' . $side
             . ' pp-ti--v-' . self::cssSafe($variant)
             . ($hasImg ? '' : ' pp-ti--no-media');

        $html  = '<div class="' . $cls . '">';
        $html .= '<div class="pp-ti__text">';
        if ($heading !== '') $html .= '<h2 class="pp-ti__heading">' . self::e($heading) . '</h2>';
        if ($body !== '')    $html .= '<div class="pp-ti__body">' . self::paragraphs($body) . '</div>';
        if ($ctaText !== '' && $ctaUrl !== '') {
            $html .= '<p><a class="pp-btn pp-btn--primary" href="' . self::e($ctaUrl) . '">' . self::e($ctaText) . '</a></p>';
        }
        $html .= '</div>';
        if ($hasImg) {
            $html .= '<div class="pp-ti__media">' . self::mediaInner($img, $heading, 'lazy') . '</div>';
        }
        $html .= '</div>';
        return $html;
    }

    private static function renderBenefits(array $c, string $variant): string
    {
        $heading    = self::str($c, 'heading');
        $subheading = self::str($c, 'subheading');
        $items      = is_array($c['items'] ?? null) ? $c['items'] : [];

        $html = '<div class="pp-benefits pp-benefits--v-' . self::cssSafe($variant) . ' container">';
        if ($heading !== '')    $html .= '<h2 class="pp-benefits__heading">' . self::e($heading) . '</h2>';
        if ($subheading !== '') $html .= '<p class="pp-benefits__subheading">' . self::nl2br(self::e($subheading)) . '</p>';

        $validItems = [];
        foreach ($items as $it) {
            if (!is_array($it)) continue;
            $title = self::str($it, 'title');
            $desc  = self::str($it, 'description');
            if ($title === '' && $desc === '') continue;
            $validItems[] = $it;
        }

        if (!empty($validItems)) {
            $html .= '<ul class="pp-benefits__grid">';
            $i = 0;
            foreach ($validItems as $it) {
                $i++;
                $icon  = self::str($it, 'icon');
                $title = self::str($it, 'title');
                $desc  = self::str($it, 'description');
                $html .= '<li class="pp-benefit" style="--pp-stagger:' . ($i - 1) . '">';

                if ($variant === 'numbered') {
                    $html .= '<span class="pp-benefit__num" aria-hidden="true">' . str_pad((string) $i, 2, '0', STR_PAD_LEFT) . '</span>';
                } elseif ($icon !== '') {
                    $svg = Icons::render($icon);
                    if ($svg !== '') {
                        $html .= '<span class="pp-benefit__icon" aria-hidden="true">' . $svg . '</span>';
                    }
                }

                if ($title !== '') $html .= '<h3 class="pp-benefit__title">' . self::e($title) . '</h3>';
                if ($desc !== '')  $html .= '<p class="pp-benefit__desc">' . self::nl2br(self::e($desc)) . '</p>';
                $html .= '</li>';
            }
            $html .= '</ul>';
        }
        $html .= '</div>';
        return $html;
    }

    private static function renderFaq(array $c, string $variant): string
    {
        $heading = self::str($c, 'heading');
        $items   = is_array($c['items'] ?? null) ? $c['items'] : [];

        $html = '<div class="pp-faq pp-faq--v-' . self::cssSafe($variant) . ' container">';
        if ($heading !== '') $html .= '<h2 class="pp-faq__heading">' . self::e($heading) . '</h2>';
        if (!empty($items)) {
            $html .= '<div class="pp-faq__list">';
            foreach ($items as $it) {
                if (!is_array($it)) continue;
                $q = self::str($it, 'question');
                $a = self::str($it, 'answer');
                if ($q === '' && $a === '') continue;
                $html .= '<details class="pp-faq__item">';
                $html .= '<summary class="pp-faq__q">' . self::e($q) . '</summary>';
                if ($a !== '') $html .= '<div class="pp-faq__a">' . self::paragraphs($a) . '</div>';
                $html .= '</details>';
            }
            $html .= '</div>';
        }
        $html .= '</div>';
        return $html;
    }

    private static function renderCta(array $c, string $variant): string
    {
        $heading = self::str($c, 'heading');
        $desc    = self::str($c, 'description');
        $ctaText = self::str($c, 'cta_text');
        $ctaUrl  = self::cleanUrl(self::str($c, 'cta_url'));

        $cls = 'pp-cta pp-cta--v-' . self::cssSafe($variant) . ' container';
        $html = '<div class="' . $cls . '">';

        if ($variant === 'split') {
            $html .= '<div class="pp-cta__text">';
            if ($heading !== '') $html .= '<h2 class="pp-cta__heading">' . self::e($heading) . '</h2>';
            if ($desc !== '')    $html .= '<p class="pp-cta__desc">' . self::nl2br(self::e($desc)) . '</p>';
            $html .= '</div>';
            if ($ctaText !== '' && $ctaUrl !== '') {
                $html .= '<div class="pp-cta__action"><a class="pp-btn pp-btn--primary pp-btn--lg" href="' . self::e($ctaUrl) . '">' . self::e($ctaText) . '</a></div>';
            }
        } else {
            // default & card → markup idéntico, CSS distinto.
            if ($heading !== '') $html .= '<h2 class="pp-cta__heading">' . self::e($heading) . '</h2>';
            if ($desc !== '')    $html .= '<p class="pp-cta__desc">' . self::nl2br(self::e($desc)) . '</p>';
            if ($ctaText !== '' && $ctaUrl !== '') {
                $html .= '<p class="pp-cta__cta"><a class="pp-btn pp-btn--primary pp-btn--lg" href="' . self::e($ctaUrl) . '">' . self::e($ctaText) . '</a></p>';
            }
        }

        $html .= '</div>';
        return $html;
    }

    private static function renderForm(array $c, int $sectionId, string $variant): string
    {
        $heading = self::str($c, 'heading');
        $desc    = self::str($c, 'description');
        $submit  = self::str($c, 'submit_text', 'Enviar');
        $img     = self::cleanImage(self::str($c, 'image_url'));
        $fields  = is_array($c['fields'] ?? null) ? $c['fields'] : [];

        if ($variant === 'with-side-image' && $img === '') $variant = 'default';

        $validFields = array_values(array_filter($fields, fn($f) => is_array($f) && self::str($f, 'label') . self::str($f, 'name') . self::str($f, 'placeholder') !== ''));
        $hasFile = false;
        foreach ($validFields as $f) {
            if (is_array($f) && self::str($f, 'field_type', 'text') === 'file') {
                $hasFile = true;
                break;
            }
        }

        $cls = 'pp-form pp-form--v-' . self::cssSafe($variant) . ' container';
        $html = '<div class="' . $cls . '">';

        if ($variant === 'with-side-image') {
            $html .= '<div class="pp-form__media">' . self::mediaInner($img, $heading, 'lazy') . '</div>';
        }

        $html .= '<div class="pp-form__panel">';
        if ($heading !== '') $html .= '<h2 class="pp-form__heading">' . self::e($heading) . '</h2>';
        if ($desc !== '')    $html .= '<p class="pp-form__desc">' . self::nl2br(self::e($desc)) . '</p>';

        if (empty($validFields)) {
            $html .= '</div></div>';
            return $html;
        }

        $status = (string) Request::get('form_status', '');
        $statusSection = (int) Request::get('form_section', 0);
        if ($status !== '' && $statusSection === $sectionId) {
            $messages = [
                'ok' => self::str($c, 'success_message', 'Gracias, hemos recibido tu mensaje.'),
                'error' => 'No se pudo enviar el formulario. Revisa los campos e inténtalo de nuevo.',
                'rate_limited' => 'Hemos recibido varios envíos seguidos. Espera unos minutos antes de volver a intentarlo.',
            ];
            $kind = $status === 'ok' ? 'success' : 'error';
            $msg = $messages[$status] ?? $messages['error'];
            if ($status === 'error') {
                $detail = Session::flash('form_error_' . $sectionId);
                if (is_string($detail) && trim($detail) !== '') {
                    $msg = $detail;
                }
            }
            $html .= '<div class="pp-form__notice pp-form__notice--' . $kind . '">' . self::e($msg) . '</div>';
        }

        $html .= '<form class="pp-form__form" method="post" action="' . self::e(base_url('forms/' . $sectionId)) . '#sec-' . $sectionId . '"' . ($hasFile ? ' enctype="multipart/form-data"' : '') . '>';
        $html .= '<input type="hidden" name="_csrf" value="' . self::e(CSRF::token()) . '">';
        $html .= '<input type="hidden" name="_return" value="' . self::e(Request::path()) . '">';
        $html .= '<div class="pp-form__hp" aria-hidden="true"><label>Web<input type="text" name="company_url" tabindex="-1" autocomplete="off"></label></div>';
        foreach ($validFields as $idx => $f) {
            if (!is_array($f)) continue;
            $label       = self::str($f, 'label');
            $name        = self::str($f, 'name', 'field_' . $idx);
            $ftype       = self::str($f, 'field_type', 'text');
            $required    = self::str($f, 'required', '0') === '1';
            $placeholder = self::str($f, 'placeholder');
            $options     = is_array($f['options'] ?? null) ? array_values(array_filter(array_map('strval', $f['options']))) : [];

            $nameSafe = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $name) ?? ('field_' . $idx);
            $id = 'f_' . $sectionId . '_' . $nameSafe;
            $req = $required ? ' required' : '';

            $html .= '<div class="pp-form__row">';
            if ($label !== '') {
                $html .= '<label class="pp-form__label" for="' . self::e($id) . '">' . self::e($label)
                      . ($required ? ' <span class="pp-form__req" aria-hidden="true">*</span>' : '') . '</label>';
            }
            if ($ftype === 'textarea') {
                $html .= '<textarea class="pp-form__control" id="' . self::e($id) . '" name="' . self::e($nameSafe) . '" rows="5" placeholder="' . self::e($placeholder) . '"' . $req . '></textarea>';
            } elseif ($ftype === 'select') {
                $firstOption = $placeholder !== '' ? $placeholder : 'Selecciona una opción';
                $html .= '<select class="pp-form__control" id="' . self::e($id) . '" name="' . self::e($nameSafe) . '"' . $req . '><option value="">' . self::e($firstOption) . '</option>';
                foreach ($options as $option) {
                    $html .= '<option value="' . self::e($option) . '">' . self::e($option) . '</option>';
                }
                $html .= '</select>';
            } elseif ($ftype === 'checkbox') {
                $html .= '<label class="pp-form__check"><input type="checkbox" name="' . self::e($nameSafe) . '" value="1"' . $req . '> ' . self::e($placeholder) . '</label>';
            } elseif ($ftype === 'file') {
                $accept = \App\Services\FormSubmissionService::acceptAttributeForField($f);
                $maxMb = \App\Services\FormSubmissionService::maxMbForField($f);
                $help = $placeholder !== '' ? $placeholder : \App\Services\FormSubmissionService::fileHelpForField($f);
                $html .= '<label class="pp-form__file" data-pp-file-field data-max-bytes="' . (int) ($maxMb * 1024 * 1024) . '">'
                      . '<input id="' . self::e($id) . '" name="' . self::e($nameSafe) . '" type="file" accept="' . self::e($accept) . '"' . $req . '>'
                      . '<span class="pp-form__file-button">Seleccionar archivo</span>'
                      . '<span class="pp-form__file-name" data-pp-file-name>Ningún archivo seleccionado</span>'
                      . '</label>'
                      . '<small class="pp-form__help" data-pp-file-help="' . self::e(\App\Services\FormSubmissionService::fileHelpForField($f)) . '">' . self::e($help) . '</small>';
            } else {
                $inputType = in_array($ftype, ['email', 'tel', 'text', 'number', 'date', 'url'], true) ? $ftype : 'text';
                $html .= '<input class="pp-form__control" type="' . $inputType . '" id="' . self::e($id) . '" name="' . self::e($nameSafe) . '" placeholder="' . self::e($placeholder) . '"' . $req . '>';
            }
            $html .= '</div>';
        }
        // E-GDPR G5 — checkbox separado de marketing si está habilitado (no premarcado).
        $marketingOptIn = self::str($c, 'marketing_opt_in', '0') === '1';
        if ($marketingOptIn) {
            $html .= '<div class="pp-form__row pp-form__row--consent">';
            $html .= '<label class="pp-form__check">'
                   . '<input type="checkbox" name="_marketing_consent" value="1">'
                   . ' Acepto recibir comunicaciones comerciales y novedades por email. Puedo darme de baja en cualquier momento.'
                   . '</label>';
            $html .= '</div>';
        }

        $html .= '<p><button type="submit" class="pp-btn pp-btn--primary pp-btn--lg">' . self::e($submit) . '</button></p>';

        // E-GDPR G5 — nota de privacidad debajo del form, con enlace a la política.
        $html .= self::renderFormPrivacyNotice($c);

        $html .= '</form>';
        $html .= '</div></div>';
        return $html;
    }

    /**
     * E-GDPR G5 — Disclosure legal auto-generado debajo del formulario.
     * Lee lawful_basis + retention_period de la sección y el enlace a la
     * política de privacidad del manifest del sitio activo.
     */
    private static function renderFormPrivacyNotice(array $c): string
    {
        $basisKey = self::str($c, 'lawful_basis', 'legitimate_interest');
        $basis = match ($basisKey) {
            'consent'  => 'tras tu consentimiento explícito',
            'contract' => 'para gestionar tu solicitud o servicio contratado',
            default    => 'en base a nuestro interés legítimo de atender consultas',
        };
        $retention = trim(self::str($c, 'retention_period', '12 meses tras la última comunicación'));
        $policyUrl = self::privacyPolicyUrl();

        $text = 'Tus datos se tratarán ' . $basis;
        if ($retention !== '') $text .= ' y se conservarán durante ' . mb_strtolower($retention);
        $text .= '.';

        $html = '<p class="pp-form__privacy">';
        $html .= self::e($text);
        if ($policyUrl !== '') {
            $html .= ' <a href="' . self::e($policyUrl) . '">Más información en nuestra política de privacidad</a>.';
        }
        $html .= '</p>';
        return $html;
    }

    /**
     * Resuelve la URL pública a la política de privacidad del sitio actual.
     * Devuelve '' si no existe o no se puede resolver.
     */
    private static function privacyPolicyUrl(): string
    {
        if (self::$siteId <= 0) return '';
        try {
            $manifest = \App\Services\Compliance\ComplianceService::manifest(self::$siteId);
            $legal = (array) ($manifest['legal_pages'] ?? []);
            $id = $legal['privacy_policy'] ?? null;
            if (!$id) return '';
            $row = Database::selectOne(
                "SELECT slug FROM pages WHERE id = ? AND status = 'published' LIMIT 1",
                [(int) $id]
            );
            if (!$row) return '';
            return base_url(ltrim((string) $row['slug'], '/'));
        } catch (\Throwable $e) {
            return '';
        }
    }

    private static function renderTestimonials(array $c, string $variant): string
    {
        $heading    = self::str($c, 'heading');
        $subheading = self::str($c, 'subheading');
        $items      = is_array($c['items'] ?? null) ? $c['items'] : [];

        $valid = [];
        foreach ($items as $it) {
            if (!is_array($it)) continue;
            if (self::str($it, 'quote') === '') continue;
            $valid[] = $it;
        }

        $cls = 'pp-testimonials pp-testimonials--v-' . self::cssSafe($variant) . ' container';
        $html = '<div class="' . $cls . '">';
        if ($heading !== '')    $html .= '<h2 class="pp-testimonials__heading">' . self::e($heading) . '</h2>';
        if ($subheading !== '') $html .= '<p class="pp-testimonials__subheading">' . self::nl2br(self::e($subheading)) . '</p>';

        if (empty($valid)) { $html .= '</div>'; return $html; }

        if ($variant === 'featured-quote') {
            $first = $valid[0];
            $quote = self::str($first, 'quote');
            $author = self::str($first, 'author');
            $role = self::str($first, 'role');
            $avatar = self::cleanImage(self::str($first, 'avatar_url'));
            $html .= '<figure class="pp-testimonial pp-testimonial--featured">';
            $html .= '<blockquote class="pp-testimonial__quote">' . self::nl2br(self::e($quote)) . '</blockquote>';
            $html .= '<figcaption class="pp-testimonial__caption">';
            if ($avatar !== '') $html .= '<img class="pp-testimonial__avatar" src="' . self::e($avatar) . '" alt="' . self::e($author) . '" loading="lazy" decoding="async">';
            $html .= '<span class="pp-testimonial__person">';
            if ($author !== '') $html .= '<strong>' . self::e($author) . '</strong>';
            if ($role !== '')   $html .= '<span class="pp-testimonial__role">' . self::e($role) . '</span>';
            $html .= '</span>';
            $html .= '</figcaption>';
            $html .= '</figure>';
        } else {
            $html .= '<ul class="pp-testimonials__grid">';
            $i = 0;
            foreach ($valid as $it) {
                $i++;
                $quote  = self::str($it, 'quote');
                $author = self::str($it, 'author');
                $role   = self::str($it, 'role');
                $avatar = self::cleanImage(self::str($it, 'avatar_url'));
                $html .= '<li class="pp-testimonial" style="--pp-stagger:' . ($i - 1) . '">';
                $html .= '<blockquote class="pp-testimonial__quote">' . self::nl2br(self::e($quote)) . '</blockquote>';
                $html .= '<div class="pp-testimonial__caption">';
                if ($avatar !== '') $html .= '<img class="pp-testimonial__avatar" src="' . self::e($avatar) . '" alt="' . self::e($author) . '" loading="lazy" decoding="async">';
                $html .= '<span class="pp-testimonial__person">';
                if ($author !== '') $html .= '<strong>' . self::e($author) . '</strong>';
                if ($role !== '')   $html .= '<span class="pp-testimonial__role">' . self::e($role) . '</span>';
                $html .= '</span>';
                $html .= '</div>';
                $html .= '</li>';
            }
            $html .= '</ul>';
        }

        $html .= '</div>';
        return $html;
    }

    private static function renderStats(array $c, string $variant): string
    {
        $heading    = self::str($c, 'heading');
        $subheading = self::str($c, 'subheading');
        $items      = is_array($c['items'] ?? null) ? $c['items'] : [];

        $valid = [];
        foreach ($items as $it) {
            if (!is_array($it)) continue;
            if (self::str($it, 'value') === '' && self::str($it, 'label') === '') continue;
            $valid[] = $it;
        }

        $cls = 'pp-stats pp-stats--v-' . self::cssSafe($variant) . ' container';
        $html = '<div class="' . $cls . '">';
        if ($heading !== '')    $html .= '<h2 class="pp-stats__heading">' . self::e($heading) . '</h2>';
        if ($subheading !== '') $html .= '<p class="pp-stats__subheading">' . self::nl2br(self::e($subheading)) . '</p>';

        if (!empty($valid)) {
            $html .= '<dl class="pp-stats__grid">';
            $i = 0;
            foreach ($valid as $it) {
                $i++;
                $value  = self::str($it, 'value');
                $suffix = self::str($it, 'suffix');
                $label  = self::str($it, 'label');
                $html .= '<div class="pp-stat" style="--pp-stagger:' . ($i - 1) . '">';
                $html .= '<dt class="pp-stat__value">';
                if ($value !== '')  $html .= '<span class="pp-stat__num">' . self::e($value) . '</span>';
                if ($suffix !== '') $html .= '<span class="pp-stat__suffix">' . self::e($suffix) . '</span>';
                $html .= '</dt>';
                if ($label !== '')  $html .= '<dd class="pp-stat__label">' . self::e($label) . '</dd>';
                $html .= '</div>';
            }
            $html .= '</dl>';
        }

        $html .= '</div>';
        return $html;
    }

    private static function renderGallery(array $c, string $variant): string
    {
        $heading    = self::str($c, 'heading');
        $subheading = self::str($c, 'subheading');
        $items      = is_array($c['items'] ?? null) ? $c['items'] : [];

        $valid = [];
        foreach ($items as $it) {
            if (!is_array($it)) continue;
            $img = self::cleanImage(self::str($it, 'image_url'));
            if ($img === '') continue;
            $it['_image'] = $img;
            $valid[] = $it;
        }

        $cls = 'pp-gallery pp-gallery--v-' . self::cssSafe($variant) . ' container';
        $html = '<div class="' . $cls . '">';
        if ($heading !== '')    $html .= '<h2 class="pp-gallery__heading">' . self::e($heading) . '</h2>';
        if ($subheading !== '') $html .= '<p class="pp-gallery__subheading">' . self::nl2br(self::e($subheading)) . '</p>';

        if (!empty($valid)) {
            $html .= '<ul class="pp-gallery__grid">';
            $i = 0;
            foreach ($valid as $it) {
                $i++;
                $img     = $it['_image'];
                $caption = self::str($it, 'caption');
                $alt     = self::str($it, 'alt');
                if ($alt === '') $alt = $caption;
                $html .= '<li class="pp-gallery__item" style="--pp-stagger:' . ($i - 1) . '">';
                $html .= '<figure class="pp-gallery__figure">';
                $html .= '<img class="pp-gallery__img" src="' . self::e($img) . '" alt="' . self::e($alt) . '" loading="lazy" decoding="async">';
                if ($caption !== '') $html .= '<figcaption class="pp-gallery__caption">' . self::e($caption) . '</figcaption>';
                $html .= self::imageAttribution($img);
                $html .= '</figure>';
                $html .= '</li>';
            }
            $html .= '</ul>';
        }

        $html .= '</div>';
        return $html;
    }

    private static function renderSteps(array $c, string $variant): string
    {
        $heading    = self::str($c, 'heading');
        $subheading = self::str($c, 'subheading');
        $items      = is_array($c['items'] ?? null) ? $c['items'] : [];

        $valid = [];
        foreach ($items as $it) {
            if (!is_array($it)) continue;
            if (self::str($it, 'title') === '' && self::str($it, 'description') === '') continue;
            $valid[] = $it;
        }

        $cls = 'pp-steps pp-steps--v-' . self::cssSafe($variant) . ' container';
        $html = '<div class="' . $cls . '">';
        if ($heading !== '')    $html .= '<h2 class="pp-steps__heading">' . self::e($heading) . '</h2>';
        if ($subheading !== '') $html .= '<p class="pp-steps__subheading">' . self::nl2br(self::e($subheading)) . '</p>';

        if (!empty($valid)) {
            $html .= '<ol class="pp-steps__list">';
            $i = 0;
            foreach ($valid as $it) {
                $i++;
                $title = self::str($it, 'title');
                $desc  = self::str($it, 'description');
                $html .= '<li class="pp-step" style="--pp-stagger:' . ($i - 1) . '">';
                $html .= '<span class="pp-step__num" aria-hidden="true">' . str_pad((string) $i, 2, '0', STR_PAD_LEFT) . '</span>';
                $html .= '<div class="pp-step__body">';
                if ($title !== '') $html .= '<h3 class="pp-step__title">' . self::e($title) . '</h3>';
                if ($desc !== '')  $html .= '<p class="pp-step__desc">' . self::nl2br(self::e($desc)) . '</p>';
                $html .= '</div>';
                $html .= '</li>';
            }
            $html .= '</ol>';
        }

        $html .= '</div>';
        return $html;
    }

    private static function renderLogosStrip(array $c, string $variant): string
    {
        $heading = self::str($c, 'heading');
        $items   = is_array($c['items'] ?? null) ? $c['items'] : [];

        $valid = [];
        foreach ($items as $it) {
            if (!is_array($it)) continue;
            $logo = self::cleanImage(self::str($it, 'logo_url'));
            if ($logo === '') continue;
            $it['_logo'] = $logo;
            $valid[] = $it;
        }

        $cls = 'pp-logos pp-logos--v-' . self::cssSafe($variant) . ' container';
        $html = '<div class="' . $cls . '">';
        if ($heading !== '') $html .= '<p class="pp-logos__heading">' . self::e($heading) . '</p>';

        if (!empty($valid)) {
            // Para marquee duplicamos el set para conseguir bucle continuo sin saltos.
            $sets = $variant === 'marquee' ? 2 : 1;
            $html .= '<div class="pp-logos__track-wrap">';
            $html .= '<ul class="pp-logos__track" aria-hidden="' . ($variant === 'marquee' ? 'false' : 'false') . '">';
            for ($s = 0; $s < $sets; $s++) {
                foreach ($valid as $it) {
                    $logo = $it['_logo'];
                    $name = self::str($it, 'name');
                    $url  = self::cleanUrl(self::str($it, 'link_url'));
                    $img  = '<img class="pp-logos__img" src="' . self::e($logo) . '" alt="' . self::e($name) . '" loading="lazy" decoding="async">';
                    $cell = $url !== ''
                        ? '<a class="pp-logos__cell" href="' . self::e($url) . '" rel="noopener">' . $img . '</a>'
                        : '<span class="pp-logos__cell">' . $img . '</span>';
                    $html .= '<li class="pp-logos__item"' . ($s === 1 ? ' aria-hidden="true"' : '') . '>' . $cell . '</li>';
                }
            }
            $html .= '</ul>';
            $html .= '</div>';
        }

        $html .= '</div>';
        return $html;
    }

    private static function renderPricing(array $c, string $variant): string
    {
        $heading    = self::str($c, 'heading');
        $subheading = self::str($c, 'subheading');
        $items      = is_array($c['items'] ?? null) ? $c['items'] : [];

        $valid = [];
        foreach ($items as $it) {
            if (!is_array($it)) continue;
            if (self::str($it, 'plan_name') === '' && self::str($it, 'price') === '') continue;
            $valid[] = $it;
        }

        $cls = 'pp-pricing pp-pricing--v-' . self::cssSafe($variant) . ' container';
        $html = '<div class="' . $cls . '">';
        if ($heading !== '')    $html .= '<h2 class="pp-pricing__heading">' . self::e($heading) . '</h2>';
        if ($subheading !== '') $html .= '<p class="pp-pricing__subheading">' . self::nl2br(self::e($subheading)) . '</p>';

        if (!empty($valid)) {
            $html .= '<ul class="pp-pricing__grid">';
            $i = 0;
            foreach ($valid as $it) {
                $i++;
                $name        = self::str($it, 'plan_name');
                $price       = self::str($it, 'price');
                $period      = self::str($it, 'period');
                $description = self::str($it, 'description');
                $featuresRaw = self::str($it, 'features');
                $ctaText     = self::str($it, 'cta_text');
                $ctaUrl      = self::cleanUrl(self::str($it, 'cta_url'));
                $highlighted = self::str($it, 'highlighted', '0') === '1';

                $features = [];
                if ($featuresRaw !== '') {
                    foreach (preg_split('/\R+/u', $featuresRaw) ?: [] as $line) {
                        $line = trim($line);
                        if ($line !== '') $features[] = $line;
                    }
                }

                $cardCls = 'pp-plan' . ($highlighted ? ' pp-plan--featured' : '');
                $html .= '<li class="' . $cardCls . '" style="--pp-stagger:' . ($i - 1) . '">';
                if ($highlighted) $html .= '<span class="pp-plan__badge">Recomendado</span>';
                if ($name !== '')        $html .= '<h3 class="pp-plan__name">' . self::e($name) . '</h3>';
                if ($description !== '') $html .= '<p class="pp-plan__desc">' . self::e($description) . '</p>';
                if ($price !== '' || $period !== '') {
                    $html .= '<p class="pp-plan__price">';
                    if ($price !== '')  $html .= '<span class="pp-plan__amount">' . self::e($price) . '</span>';
                    if ($period !== '') $html .= '<span class="pp-plan__period">' . self::e($period) . '</span>';
                    $html .= '</p>';
                }
                if (!empty($features)) {
                    $html .= '<ul class="pp-plan__features">';
                    foreach ($features as $f) {
                        $html .= '<li>' . self::e($f) . '</li>';
                    }
                    $html .= '</ul>';
                }
                if ($ctaText !== '' && $ctaUrl !== '') {
                    $btnCls = $highlighted ? 'pp-btn pp-btn--primary' : 'pp-btn pp-btn--ghost';
                    $html .= '<p class="pp-plan__cta"><a class="' . $btnCls . '" href="' . self::e($ctaUrl) . '">' . self::e($ctaText) . '</a></p>';
                }
                $html .= '</li>';
            }
            $html .= '</ul>';
        }

        $html .= '</div>';
        return $html;
    }

    /**
     * F21.T21.2.d — Render del cuerpo de un artículo.
     * El content trae `blocks: [...]` con tipos: paragraph, heading, image,
     * list, quote, divider. Cada bloque se pinta con tipografía editorial.
     */
    private static function renderArticleBody(array $c, string $variant): string
    {
        $blocks = is_array($c['blocks'] ?? null) ? $c['blocks'] : [];

        $widthClass = 'pp-article-body--' . self::cssSafe($variant);
        $html = '<div class="pp-article-body ' . $widthClass . ' container">';
        $html .= '<article class="pp-article-body__content">';

        foreach ($blocks as $b) {
            if (!is_array($b)) continue;
            $type = (string) ($b['type'] ?? '');
            switch ($type) {
                case 'paragraph':
                    $text = trim((string) ($b['text'] ?? ''));
                    if ($text === '') break;
                    $html .= '<p class="pp-article-body__p">' . self::nl2br(self::e($text)) . '</p>';
                    break;

                case 'heading':
                    $level = ((int) ($b['level'] ?? 2) === 3) ? 3 : 2;
                    $text = trim((string) ($b['text'] ?? ''));
                    if ($text === '') break;
                    $tag = 'h' . $level;
                    $html .= '<' . $tag . ' class="pp-article-body__h' . $level . '">' . self::e($text) . '</' . $tag . '>';
                    break;

                case 'image':
                    $src = self::cleanImage((string) ($b['src'] ?? ''));
                    if ($src === '') break;
                    $alt = (string) ($b['alt'] ?? '');
                    $caption = trim((string) ($b['caption'] ?? ''));
                    $html .= '<figure class="pp-article-body__figure">';
                    $html .= self::mediaInner($src, $alt !== '' ? $alt : $caption, 'lazy');
                    if ($caption !== '') {
                        $html .= '<figcaption>' . self::e($caption) . '</figcaption>';
                    }
                    $html .= '</figure>';
                    break;

                case 'list':
                    $items = is_array($b['items'] ?? null) ? $b['items'] : [];
                    $valid = array_values(array_filter(array_map(
                        fn($i) => trim((string) $i),
                        $items
                    ), fn($i) => $i !== ''));
                    if (empty($valid)) break;
                    $tag = (($b['style'] ?? 'unordered') === 'ordered') ? 'ol' : 'ul';
                    $html .= '<' . $tag . ' class="pp-article-body__list pp-article-body__list--' . $tag . '">';
                    foreach ($valid as $it) {
                        $html .= '<li>' . self::e($it) . '</li>';
                    }
                    $html .= '</' . $tag . '>';
                    break;

                case 'quote':
                    $text = trim((string) ($b['text'] ?? ''));
                    if ($text === '') break;
                    $attribution = trim((string) ($b['attribution'] ?? ''));
                    $html .= '<blockquote class="pp-article-body__quote">';
                    $html .= '<p>' . self::nl2br(self::e($text)) . '</p>';
                    if ($attribution !== '') {
                        $html .= '<cite>— ' . self::e($attribution) . '</cite>';
                    }
                    $html .= '</blockquote>';
                    break;

                case 'divider':
                    $html .= '<hr class="pp-article-body__divider">';
                    break;
            }
        }

        $html .= '</article>';
        $html .= '</div>';
        return $html;
    }

    /**
     * F21.T21.3 — Listado dinámico de entradas (índice de blog).
     *
     * Consulta las últimas N entradas publicadas (`page_type='article'`,
     * `status='published'`) del sitio activo, ordenadas por `published_at` DESC.
     * Pinta cards según variante. Si el sitio no tiene entradas, no renderiza
     * nada (mejor que un empty state confuso para el visitante).
     */
    private static function renderPostsListing(array $c, string $variant): string
    {
        $heading    = self::str($c, 'heading');
        $subheading = self::str($c, 'subheading');
        $limit = max(1, min(20, (int) self::str($c, 'limit', '6')));

        $siteId = self::$siteId;
        if ($siteId <= 0) return '';

        try {
            $posts = Database::select(
                "SELECT p.id, p.title, p.slug, p.published_at,
                        pm.excerpt, pm.featured_image_path, pm.featured_image_alt,
                        pm.author_name, pm.reading_minutes
                 FROM pages p
                 LEFT JOIN post_meta pm ON pm.page_id = p.id
                 WHERE p.site_id = ? AND p.page_type = 'article' AND p.status = 'published'
                 ORDER BY p.published_at DESC, p.id DESC
                 LIMIT " . $limit,
                [$siteId]
            );
        } catch (\Throwable $e) {
            error_log('[renderPostsListing] DB error: ' . $e->getMessage());
            return '';
        }

        if (empty($posts)) return '';

        $cls = 'pp-posts-listing pp-posts-listing--v-' . self::cssSafe($variant) . ' container';
        $html = '<div class="' . $cls . '">';
        if ($heading !== '')    $html .= '<h2 class="pp-posts-listing__heading">' . self::e($heading) . '</h2>';
        if ($subheading !== '') $html .= '<p class="pp-posts-listing__subheading">' . self::nl2br(self::e($subheading)) . '</p>';

        if ($variant === 'featured-first' && count($posts) >= 1) {
            $featured = array_shift($posts);
            $html .= '<div class="pp-posts-listing__featured">' . self::renderPostCard($featured, 'featured') . '</div>';
            if (!empty($posts)) {
                $html .= '<ul class="pp-posts-listing__rest">';
                $i = 0;
                foreach ($posts as $p) {
                    $i++;
                    $html .= '<li style="--pp-stagger:' . ($i - 1) . '">' . self::renderPostCard($p, 'compact') . '</li>';
                }
                $html .= '</ul>';
            }
        } elseif ($variant === 'editorial-list') {
            $html .= '<ul class="pp-posts-listing__list">';
            $i = 0;
            foreach ($posts as $p) {
                $i++;
                $html .= '<li style="--pp-stagger:' . ($i - 1) . '">' . self::renderPostCard($p, 'row') . '</li>';
            }
            $html .= '</ul>';
        } else {
            // default — grid de tarjetas
            $html .= '<ul class="pp-posts-listing__grid">';
            $i = 0;
            foreach ($posts as $p) {
                $i++;
                $html .= '<li style="--pp-stagger:' . ($i - 1) . '">' . self::renderPostCard($p, 'card') . '</li>';
            }
            $html .= '</ul>';
        }

        $html .= '</div>';
        return $html;
    }

    /**
     * Helper: pinta una card de entrada según `$shape`:
     *   - 'card'     → grid default (thumb arriba + texto debajo)
     *   - 'row'      → lista editorial (thumb lateral + texto)
     *   - 'featured' → destacada grande (variant featured-first, primera)
     *   - 'compact'  → small card (variant featured-first, las restantes)
     */
    private static function renderPostCard(array $post, string $shape): string
    {
        $title  = (string) ($post['title'] ?? '');
        $slug   = ltrim((string) ($post['slug'] ?? ''), '/');
        $url    = base_url($slug);
        $excerpt = trim((string) ($post['excerpt'] ?? ''));
        $img    = trim((string) ($post['featured_image_path'] ?? ''));
        $alt    = (string) ($post['featured_image_alt'] ?? '');
        $author = trim((string) ($post['author_name'] ?? ''));
        $reading = (int) ($post['reading_minutes'] ?? 0);
        $pubAt  = (string) ($post['published_at'] ?? '');

        $imgSrc = '';
        if ($img !== '') {
            $imgSrc = preg_match('#^https?://#i', $img) ? $img : base_url(ltrim($img, '/'));
        }

        // Meta line (fecha · autor · reading)
        $metaParts = [];
        if ($pubAt !== '') {
            $ts = strtotime($pubAt);
            if ($ts) {
                $months = ['ene','feb','mar','abr','may','jun','jul','ago','sep','oct','nov','dic'];
                $metaParts[] = '<time datetime="' . self::e(date('c', $ts)) . '">' . (int) date('j', $ts) . ' ' . $months[(int) date('n', $ts) - 1] . ' ' . date('Y', $ts) . '</time>';
            }
        }
        if ($author !== '')  $metaParts[] = '<span>' . self::e($author) . '</span>';
        if ($reading > 0 && $shape !== 'compact') $metaParts[] = '<span>' . (int) $reading . ' min</span>';
        $meta = !empty($metaParts) ? '<p class="pp-post-card__meta">' . implode(' · ', $metaParts) . '</p>' : '';

        $h  = '<a class="pp-post-card pp-post-card--' . self::cssSafe($shape) . '" href="' . self::e($url) . '">';
        if ($imgSrc !== '') {
            $h .= '<figure class="pp-post-card__media">';
            $h .= '<img src="' . self::e($imgSrc) . '" alt="' . self::e($alt !== '' ? $alt : $title) . '" loading="lazy" decoding="async">';
            $h .= '</figure>';
        } else {
            // Placeholder si no hay imagen
            $h .= '<div class="pp-post-card__media pp-post-card__media--empty" aria-hidden="true"></div>';
        }
        $h .= '<div class="pp-post-card__body">';
        if ($meta !== '') $h .= $meta;
        if ($title !== '') $h .= '<h3 class="pp-post-card__title">' . self::e($title) . '</h3>';
        if ($excerpt !== '' && $shape !== 'compact') {
            $h .= '<p class="pp-post-card__excerpt">' . self::e($excerpt) . '</p>';
        }
        $h .= '</div>';
        $h .= '</a>';
        return $h;
    }

    private static function renderGeneric(array $c): string
    {
        $heading = self::str($c, 'heading');
        $body    = self::str($c, 'body');
        $html = '<div class="pp-generic container">';
        if ($heading !== '') $html .= '<h2>' . self::e($heading) . '</h2>';
        if ($body !== '')    $html .= self::paragraphs($body);
        $html .= '</div>';
        return $html;
    }

    private static function renderCustomBlock(array $c, array $section): string
    {
        if (($c['version'] ?? '') !== 'ppb:1') {
            return '<!-- custom_block invalid: unsupported version -->';
        }
        $raw = is_string($c['html'] ?? null) ? (string) $c['html'] : '';
        if (trim($raw) === '') {
            return '<!-- custom_block invalid: empty -->';
        }

        $result = CustomBlockSanitizer::sanitize($raw, [
            'site_id' => self::$siteId,
            'section_id' => (int) ($section['id'] ?? 0),
            'section_index' => (int) ($section['sort_order'] ?? 0),
            'is_first_section' => (int) ($section['sort_order'] ?? 0) === 0,
        ]);
        if (empty($result['ok'])) {
            return '<!-- custom_block invalid: sanitizer rejected -->';
        }

        // D-MB2 — librería de iconos de confianza: el sanitizer garantiza spans
        // VACÍOS con data-ppb-icon validado; aquí se inyecta el SVG real.
        return preg_replace_callback(
            '/<span([^>]*?)data-ppb-icon="([a-z0-9-]+)"([^>]*)><\/span>/',
            static fn(array $m) => '<span' . $m[1] . 'data-ppb-icon="' . $m[2] . '"' . $m[3] . '>' . Icons::render($m[2]) . '</span>',
            (string) $result['html']
        ) ?? (string) $result['html'];
    }

    // ======================================================================
    // Helpers
    // ======================================================================

    private static function decodeContent(array $section): array
    {
        if (isset($section['content_json']) && is_array($section['content_json'])) {
            return $section['content_json'];
        }
        $raw = $section['content'] ?? null;
        if (is_array($raw)) return $raw;
        if (!is_string($raw) || $raw === '') return [];
        $d = json_decode($raw, true);
        return is_array($d) ? $d : [];
    }

    private static function decodeStyle(array $section): array
    {
        if (isset($section['style_json']) && is_array($section['style_json'])) {
            return $section['style_json'];
        }
        $raw = $section['style'] ?? null;
        if (is_array($raw)) return $raw;
        if (!is_string($raw) || $raw === '') return [];
        $d = json_decode($raw, true);
        return is_array($d) ? $d : [];
    }

    private static function str(array $arr, string $key, string $default = ''): string
    {
        $v = $arr[$key] ?? $default;
        if (is_string($v)) return trim($v);
        if (is_scalar($v)) return (string) $v;
        return $default;
    }

    private static function e(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    private static function nl2br(string $s): string
    {
        return nl2br($s, false);
    }

    /** Convierte texto con doble salto en <p>, respetando saltos simples como <br>. */
    private static function paragraphs(string $s): string
    {
        $s = trim($s);
        if ($s === '') return '';
        $blocks = preg_split('/\R{2,}/u', $s) ?: [$s];
        $html = '';
        foreach ($blocks as $b) {
            $b = trim($b);
            if ($b === '') continue;
            $html .= '<p>' . self::nl2br(self::e($b)) . '</p>';
        }
        return $html;
    }

    /**
     * T18.4 — Atribución HTML para una URL si proviene del banco. Devuelve '' si no.
     * Se inyecta tras la imagen correspondiente.
     */
    /** Variante pública: usable desde otros controllers/services (T21.2.d hero del artículo). */
    public static function publicImageAttribution(string $url): string
    {
        return self::imageAttribution($url);
    }

    private static function imageAttribution(string $url): string
    {
        if ($url === '' || empty(self::$attributions)) return '';
        // Normalizar a path-relativo: '/storage/uploads/…'
        $key = $url;
        if (preg_match('#^https?://[^/]+(/.*)$#i', $url, $m)) $key = $m[1];
        if (!str_starts_with($key, '/')) $key = '/' . $key;

        $attr = self::$attributions[$key] ?? null;
        if (!$attr || ($attr['name'] ?? '') === '') return '';

        $unsplashUrl = 'https://unsplash.com/?utm_source=promptpress&utm_medium=referral';
        $photogUrl   = $attr['url'] !== '' ? $attr['url'] : $unsplashUrl;
        return '<small class="pp-image-attr">Foto de <a href="' . self::e($photogUrl) . '" rel="noopener">' . self::e($attr['name']) . '</a> en <a href="' . self::e($unsplashUrl) . '" rel="noopener">Unsplash</a></small>';
    }

    private static function cssSafe(string $s): string
    {
        return preg_replace('/[^a-zA-Z0-9_-]/', '-', $s) ?? 'section';
    }

    /**
     * Normaliza URLs producidas por la IA. Devuelve '' si la URL no es usable
     * (vacía, anchor solo, placeholder tipo TODO/PENDING, javascript:, etc.).
     */
    private static function cleanUrl(string $url): string
    {
        $u = trim($url);
        if ($u === '' || $u === '#') return '';
        $low = strtolower($u);
        if (str_starts_with($low, 'javascript:')) return '';
        $placeholders = ['todo', 'tbd', 'pending', 'pendiente', 'none', 'null', 'undefined', '#tbd', '#todo'];
        if (in_array($low, $placeholders, true)) return '';
        // anchors válidos (#foo) y rutas relativas/absolutas pasan
        return $u;
    }

    /**
     * E-GDPR G4b — Renderiza el contenido interno de un slot multimedia.
     * Si la URL es de YouTube/Vimeo, devuelve el placeholder click-to-load.
     * En caso contrario, un `<img>` con la atribución correspondiente.
     */
    private static function mediaInner(string $url, string $alt, string $loading = 'lazy', string $imgClass = ''): string
    {
        $emb = \App\Services\Compliance\VideoEmbedDetector::renderPlaceholder($url, $alt);
        if ($emb !== null) return $emb;
        $cls = $imgClass !== '' ? ' class="' . self::e($imgClass) . '"' : '';
        return '<img' . $cls . ' src="' . self::e($url) . '" alt="' . self::e($alt) . '" loading="' . self::e($loading) . '" decoding="async">'
             . self::imageAttribution($url);
    }

    /**
     * Normaliza URLs de imagen. Igual que cleanUrl pero más estricta: un anchor
     * o una ruta sin pinta de imagen/URL se descartan.
     */
    private static function cleanImage(string $url): string
    {
        $u = self::cleanUrl($url);
        if ($u === '') return '';
        if ($u[0] === '#') return '';
        // Aceptar http(s)://, //, /, data:image, o rutas relativas con extensión de imagen
        if (preg_match('#^(https?:)?//#i', $u)) return $u;
        if (str_starts_with($u, '/')) return $u;
        if (str_starts_with(strtolower($u), 'data:image/')) return $u;
        if (preg_match('/\.(jpe?g|png|gif|webp|avif|svg)(\?|$)/i', $u)) return $u;
        return '';
    }
}
