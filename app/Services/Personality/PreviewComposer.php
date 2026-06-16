<?php

declare(strict_types=1);

namespace App\Services\Personality;

use Core\Database;

/**
 * D-Slice 1 (S1.13) — Composición del preview de home del wizard de onboarding.
 *
 * En vez de un HTML hardcodeado, genera una composición REAL:
 *   1. Define el esqueleto típico de home (hero · benefits · text_image · testimonials · cta).
 *   2. Rellena el contenido con datos reales del `site_memory` del usuario.
 *   3. Pide a `LayoutSelector` la variante óptima por sección según el vector
 *      de personalidad + el skin compuesto (D-Slice 2/5 ya en producción).
 *   4. Devuelve las secciones listas para `SectionRenderer::renderMany`.
 *
 * El resultado es un preview que VARÍA tanto en skin como en estructura cuando
 * el usuario hace nudges o cuando cambia los datos del onboarding.
 */
final class PreviewComposer
{
    /**
     * Construye las secciones de la home del preview.
     *
     * @return array<int, array{section_type:string, content:array, style:array}>
     */
    public static function buildHomeSections(int $siteId): array
    {
        $memory = self::loadMemory($siteId);
        $brand  = self::loadBrandName($siteId);

        // Esqueleto base de home. El tipo de página es 'home' para que el
        // LayoutSelector aplique los priors correctos (hero como protagonista,
        // cta cerrando, etc.).
        $structure = [
            ['type' => 'hero',         'content' => self::buildHero($memory, $brand)],
            ['type' => 'benefits',     'content' => self::buildBenefits($memory)],
            ['type' => 'text_image',   'content' => self::buildTextImage($memory, $brand)],
            ['type' => 'testimonials', 'content' => self::buildTestimonials($brand)],
            ['type' => 'cta',          'content' => self::buildCta($memory)],
        ];

        // Pedir variantes al selector. Mismas secciones, mismo formato que en
        // la generación real de páginas.
        $forSelector = array_map(
            static fn ($s) => ['type' => $s['type'], 'content' => $s['content']],
            $structure
        );

        try {
            $selections = LayoutSelector::selectForPage($siteId, $forSelector, 'home');
        } catch (\Throwable $e) {
            // Selector falla → fallback a 'default' variant en cada sección.
            error_log('[PreviewComposer] LayoutSelector failed: ' . $e->getMessage());
            $selections = [];
        }

        // Mergear la variante elegida en `style` para el renderer.
        $renderable = [];
        foreach ($structure as $i => $s) {
            $variant = (string) ($selections[$i]['variant'] ?? 'default');
            $renderable[] = [
                'section_type' => $s['type'],
                'content'      => $s['content'],
                'style'        => ['variant' => $variant],
            ];
        }
        return $renderable;
    }

    // ==========================================================
    // Loaders
    // ==========================================================

    private static function loadMemory(int $siteId): array
    {
        try {
            $rows = Database::select(
                'SELECT field_key, field_value FROM site_memory WHERE site_id = ?',
                [$siteId]
            );
        } catch (\Throwable $e) {
            return [];
        }
        $out = [];
        foreach ($rows as $r) {
            $out[(string) $r['field_key']] = (string) $r['field_value'];
        }
        return $out;
    }

    private static function loadBrandName(int $siteId): string
    {
        try {
            $r = Database::selectOne('SELECT name FROM sites WHERE id = ?', [$siteId]);
            $n = trim((string) ($r['name'] ?? ''));
            return $n !== '' ? $n : 'Tu marca';
        } catch (\Throwable $e) {
            return 'Tu marca';
        }
    }

    // ==========================================================
    // Content builders — usan signals del onboarding cuando hay
    // ==========================================================

    private static function buildHero(array $m, string $brand): array
    {
        $heading    = self::pickHeading($m, $brand);
        $subheading = self::pickSubheading($m);

        return [
            'eyebrow'             => self::firstWords($m['target_audience'] ?? '', 8, 'Para tu negocio'),
            'heading'             => $heading,
            'subheading'          => $subheading,
            'cta_text'            => 'Pedir información',
            'cta_url'             => '#contacto',
            'cta_text_secondary'  => 'Ver servicios',
            'cta_url_secondary'   => '#servicios',
        ];
    }

    private static function buildBenefits(array $m): array
    {
        $items = self::extractListItems($m['unique_selling_points'] ?? '', 3);
        if (count($items) < 3) {
            $items = array_merge($items, self::extractListItems($m['services'] ?? '', 3 - count($items)));
        }
        if (empty($items)) {
            $items = [
                ['title' => 'Proceso claro', 'description' => 'Comunicación cercana y entregas frecuentes.'],
                ['title' => 'Resultados',    'description' => 'Cada decisión orientada al impacto medible.'],
                ['title' => 'Acompañamiento','description' => 'Seguimos a tu lado después de la entrega.'],
            ];
        }

        $iconPool = ['rocket', 'shield', 'chart', 'heart', 'star', 'compass'];
        $withIcons = [];
        foreach (array_slice($items, 0, 3) as $i => $it) {
            $withIcons[] = [
                'icon'        => $iconPool[$i % count($iconPool)],
                'title'       => (string) $it['title'],
                'description' => (string) ($it['description'] ?? ''),
            ];
        }

        return [
            'heading'    => '¿Por qué elegirnos?',
            'subheading' => self::firstWords($m['value_proposition'] ?? '', 18, ''),
            'items'      => $withIcons,
        ];
    }

    private static function buildTextImage(array $m, string $brand): array
    {
        $body = trim((string) ($m['business_description'] ?? ''));
        if ($body === '') {
            $body = 'Acompañamos a nuestros clientes desde el primer día con un proceso transparente y una atención cuidada al detalle.';
        }

        return [
            'heading'    => 'Sobre ' . $brand,
            'body'       => self::trimToChars($body, 320),
            'image_url'  => '',
            'image_side' => 'right',
            'cta_text'   => 'Conoce al equipo',
            'cta_url'    => '#equipo',
        ];
    }

    private static function buildTestimonials(string $brand): array
    {
        return [
            'heading'    => 'Lo que dicen nuestros clientes',
            'subheading' => 'Hemos ayudado a equipos como el tuyo a despegar.',
            'items' => [
                [
                    'quote'  => 'Trabajar con ' . $brand . ' ha sido la mejor decisión del año. Resultados claros, cero fricciones.',
                    'author' => 'María Álvarez',
                    'role'   => 'Directora · Pyme cliente',
                ],
                [
                    'quote'  => 'Resolvieron en una semana lo que llevábamos meses sin abordar. Recomendados.',
                    'author' => 'Jorge Ramos',
                    'role'   => 'Fundador · Estudio Norte',
                ],
            ],
        ];
    }

    private static function buildCta(array $m): array
    {
        return [
            'heading'     => '¿Listo para empezar?',
            'description' => 'Cuéntanos qué necesitas y te respondemos en menos de 24 horas. Sin compromiso.',
            'cta_text'    => 'Empezar ahora',
            'cta_url'     => '#contacto',
        ];
    }

    // ==========================================================
    // Helpers de extracción
    // ==========================================================

    /**
     * Headline corta y punchy. Estrategia:
     *   1. Si el texto tiene ":" en los primeros 90 chars, usar la parte
     *      antes del colon (suelen ser frases tipo "X: el cómo lo hacemos").
     *   2. Si no, primera frase trimada a 70 chars.
     */
    private static function pickHeading(array $m, string $brand): string
    {
        $candidates = [
            $m['value_proposition']     ?? '',
            $m['business_description']  ?? '',
        ];
        foreach ($candidates as $text) {
            $text = trim((string) $text);
            if ($text === '') continue;

            // Atajo por ":" — suele dejar la parte más punchy.
            $colon = mb_strpos($text, ':');
            if ($colon !== false && $colon > 6 && $colon < 90) {
                $head = trim(mb_substr($text, 0, $colon));
                if ($head !== '') return self::trimToChars($head, 70);
            }

            $sentence = self::firstSentence($text);
            if ($sentence !== '') {
                return self::trimToChars($sentence, 70);
            }
        }
        return 'Hacemos que tu trabajo se vea';
    }

    private static function pickSubheading(array $m): string
    {
        $candidates = [
            $m['business_description']  ?? '',
            $m['value_proposition']     ?? '',
        ];
        foreach ($candidates as $text) {
            $sentence = self::firstSentence((string) $text, /*skipFirst*/ true);
            if ($sentence !== '') {
                return self::trimToChars($sentence, 180);
            }
        }
        return 'Una propuesta clara, una llamada a la acción visible y la confianza necesaria para que el visitante sepa qué hacer después.';
    }

    /**
     * Devuelve la primera frase (o la segunda si skipFirst=true) de un texto.
     */
    private static function firstSentence(string $text, bool $skipFirst = false): string
    {
        $text = trim($text);
        if ($text === '') return '';
        // Divide por puntos seguido de espacio o final.
        $parts = preg_split('/(?<=[\.!?])\s+/u', $text) ?: [];
        $parts = array_values(array_filter(array_map('trim', $parts), fn ($p) => $p !== ''));
        if ($skipFirst) {
            return (string) ($parts[1] ?? '');
        }
        return (string) ($parts[0] ?? $text);
    }

    private static function trimToChars(string $text, int $max): string
    {
        $text = trim($text);
        if (mb_strlen($text) <= $max) return $text;
        $cut = mb_substr($text, 0, $max - 1);
        $sp  = mb_strrpos($cut, ' ');
        if ($sp !== false && $sp > $max - 30) $cut = mb_substr($cut, 0, $sp);
        return rtrim($cut, " ,.;:") . '…';
    }

    private static function firstWords(string $text, int $maxWords, string $fallback): string
    {
        $text = trim($text);
        if ($text === '') return $fallback;
        $words = preg_split('/\s+/u', $text) ?: [];
        if (count($words) <= $maxWords) return $text;
        return implode(' ', array_slice($words, 0, $maxWords)) . '…';
    }

    /**
     * Extrae N items de una lista (separados por \n o ;).
     * Cada item se descompone en title + description si tiene ':'.
     *
     * @return array<int,array{title:string, description?:string}>
     */
    private static function extractListItems(string $text, int $max): array
    {
        $text = trim($text);
        if ($text === '') return [];
        // Permitir separación por nueva línea, ; o •
        $parts = preg_split('/[\n;\r•]+/u', $text) ?: [];
        $parts = array_values(array_filter(array_map('trim', $parts), fn ($p) => $p !== ''));
        $out = [];
        foreach ($parts as $p) {
            if (count($out) >= $max) break;
            $colon = mb_strpos($p, ':');
            if ($colon !== false && $colon > 0 && $colon < 80) {
                $title = trim(mb_substr($p, 0, $colon));
                $desc  = trim(mb_substr($p, $colon + 1));
                $out[] = ['title' => $title, 'description' => $desc];
            } else {
                // Sin separador → todo es título, descripción genérica corta.
                $out[] = ['title' => self::trimToChars($p, 60), 'description' => ''];
            }
        }
        return $out;
    }
}
