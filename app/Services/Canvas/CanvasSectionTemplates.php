<?php

declare(strict_types=1);

namespace App\Services\Canvas;

use App\Services\Microcopy;

/**
 * Plantillas manuales mínimas de Studio.
 *
 * El catálogo es una whitelist cerrada y el contenido inicial se resuelve con
 * el idioma de la página. Las clases pertenecen a la gramática pública `ppb`
 * de DesignSystem: no se persiste CSS inline ni se duplica CSS por inserción.
 */
final class CanvasSectionTemplates
{
    /** @return array<string,array{label_key:string}> */
    public static function catalog(): array
    {
        return [
            'text' => ['label_key' => 'studio_block.text.label'],
            'text_image' => ['label_key' => 'studio_block.text_image.label'],
            'cta' => ['label_key' => 'studio_block.cta.label'],
        ];
    }

    /**
     * @return array{id:string,label:string,html:string}|null
     */
    public static function render(
        string $template,
        string $lang,
        ?string $suffix = null,
        string $placeholderUrl = ''
    ): ?array {
        $catalog = self::catalog();
        if (!isset($catalog[$template])) return null;

        $suffix = preg_replace('/[^a-z0-9]/i', '', (string) $suffix) ?: substr(bin2hex(random_bytes(5)), 0, 10);
        $prefix = str_replace('_', '-', $template);
        $id = 'manual-' . $prefix . '-' . strtolower($suffix);
        $label = Microcopy::t($catalog[$template]['label_key'], $lang);

        $t = static fn(string $key): string => self::escape(Microcopy::t('studio_block.' . $template . '.' . $key, $lang));
        $section = static fn(string $body, string $classes = 'pp-section pp-section--ppbp-md'): string =>
            '<section data-pp-section="' . self::escape($id) . '" data-pp-label="' . self::escape($label)
            . '" class="' . $classes . '">' . $body . '</section>';

        if ($template === 'text') {
            $html = $section('<div class="ppb-container"><div class="ppb-stack ppb-stack--tight ppb-measure-md">'
                . '<span class="ppb-eyebrow">' . $t('eyebrow') . '</span>'
                . '<h2 class="ppb-heading-lg">' . $t('title') . '</h2>'
                . '<p class="ppb-lead">' . $t('body') . '</p>'
                . '</div></div>');
        } elseif ($template === 'text_image') {
            $src = self::escape($placeholderUrl);
            $html = $section('<div class="ppb-container"><div class="ppb-split ppb-split--text-heavy ppb-split--media-right">'
                . '<div class="ppb-stack ppb-stack--tight"><span class="ppb-eyebrow">' . $t('eyebrow') . '</span>'
                . '<h2 class="ppb-heading-lg">' . $t('title') . '</h2>'
                . '<p class="ppb-lead">' . $t('body') . '</p></div>'
                . '<figure class="ppb-media ppb-media--frame ppb-media--landscape">'
                . '<img src="' . $src . '" alt="' . $t('alt') . '"></figure>'
                . '</div></div>', 'pp-section pp-section--ppbt-surface pp-section--ppbp-md');
        } else {
            $html = $section('<div class="ppb-container"><div class="ppb-strip">'
                . '<div class="ppb-stack ppb-stack--tight ppb-measure-md">'
                . '<h2 class="ppb-heading-md">' . $t('title') . '</h2>'
                . '<p class="ppb-copy">' . $t('body') . '</p></div>'
                . '<div class="ppb-actions ppb-actions--stack-mobile"><a href="#" class="pp-btn pp-btn--primary">'
                . $t('button') . '</a></div></div></div>',
                'pp-section pp-section--ppbt-primary pp-section--ppbp-md'
            );
        }

        return ['id' => $id, 'label' => $label, 'html' => $html];
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
