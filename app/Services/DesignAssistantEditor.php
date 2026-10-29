<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\AI\Actions;
use App\Services\AI\AIActionRunner;
use Core\Database;

/**
 * ASST-V2 F4e — Ejecutor del asistente para `design.edit`.
 *
 * Colores de la paleta, tipografías de titulares y texto, tamaño base y radios
 * de botones y tarjetas. Escribe por el MISMO camino que el formulario de
 * /admin/design: los colores van a la paleta a medida (`site_palette_custom`)
 * y lo demás a los overrides manuales (`design_manual_tokens`), así la cadena
 * de precedencia (DesignSystem::effective) no cambia.
 *
 * No hay borrador: guardar es aplicarlo en toda la web. Antes se guardan los
 * dos ajustes en crudo en `versions` (entity design) para deshacerlo.
 */
final class DesignAssistantEditor
{
    public const ENTITY = 'design';

    /** Lo único que puede tocar el asistente. */
    public const EDITABLE = [
        'colors'     => ['primary', 'primary_dark', 'accent', 'bg', 'surface', 'text', 'text_muted', 'border'],
        'typography' => ['font_heading', 'font_body', 'base_size'],
        'buttons'    => ['radius'],
        'spacing'    => ['radius_card'],
    ];

    /** Token del panel => clave de la paleta (inverso de applyCustomPaletteToTokens). */
    private const PALETTE_MAP = [
        'primary' => 'accent', 'primary_dark' => 'accent_dark', 'accent' => 'accent_2', 'bg' => 'bg',
        'surface' => 'surface', 'text' => 'text', 'text_muted' => 'muted', 'border' => 'line',
    ];

    /** Lo que pinta hoy la web, recortado a lo editable. @return array<string,array<string,mixed>> */
    public static function current(int $siteId): array
    {
        return self::subset(DesignSystem::effective($siteId));
    }

    /**
     * Prepara el diseño nuevo, sin guardar nada.
     *
     * @return array{ok:bool, error?:string, before?:array, content?:array, base_hash?:string, notes?:array<int,string>}
     * @throws \App\Services\AI\AIException si falla la llamada al modelo
     */
    public static function propose(int $siteId, string $instruction): array
    {
        $current = self::current($siteId);
        $locked = self::lockedFonts($siteId);
        $fonts = [];
        foreach (DesignSystem::fontOptions($siteId) as $value => $label) {
            $fonts[] = $value . ' = ' . $label;
        }
        $result = AIActionRunner::run(Actions::EDIT_DESIGN, [
            'request'        => $instruction,
            'current_design' => json_encode($current + ($locked !== [] ? ['fijadas_por_la_marca' => $locked] : []), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'font_options'   => implode("\n", $fonts),
            'brand_colors'   => implode(', ', BrandPaletteService::brandColors($siteId)) ?: '-',
        ], $siteId);

        $draft = is_array($result['data'] ?? null) ? $result['data'] : [];
        return self::prepare($siteId, $draft);
    }

    /**
     * Aplica `$draft` (salida de la IA, solo lo que cambia) sobre el diseño
     * actual. Lo que no valida se descarta y se explica en `notes`.
     *
     * @param array<string,mixed> $draft
     * @return array{ok:bool, error?:string, before?:array, content?:array, base_hash?:string, notes?:array<int,string>}
     */
    public static function prepare(int $siteId, array $draft): array
    {
        $before = self::current($siteId);
        $after = $before;
        $notes = [];
        $fields = self::fields();
        $locked = self::lockedFonts($siteId);
        $fontOptions = DesignSystem::fontOptions($siteId);

        foreach (self::EDITABLE as $cat => $keys) {
            foreach ((array) ($draft[$cat] ?? []) as $key => $raw) {
                if (!in_array($key, $keys, true)) continue;
                $field = $fields[$cat . '.' . $key];
                if (in_array($key, $locked, true)) {
                    $notes[] = __('asst.design.note_locked_font', ['campo' => $field['label']]);
                    continue;
                }
                $value = self::clean($field, $raw, $fontOptions);
                if ($value === null) {
                    $notes[] = __('asst.design.note_ignored', ['campo' => $field['label'], 'valor' => mb_substr(is_scalar($raw) ? (string) $raw : '?', 0, 40)]);
                    continue;
                }
                $after[$cat][$key] = $value;
            }
        }

        if ($after === $before) {
            return ['ok' => false, 'error' => __('asst.err.design_no_valid_change'), 'notes' => $notes];
        }
        return [
            'ok'        => true,
            'before'    => $before,
            'content'   => $after,
            'base_hash' => self::fingerprint($siteId),
            'notes'     => $notes,
        ];
    }

    /**
     * Guarda un diseño ya preparado si nadie lo ha tocado entre medias.
     *
     * @param array<string,array<string,mixed>> $content
     * @return array{ok:bool, reply?:string, error?:string}
     */
    public static function commit(int $siteId, array $content, string $baseHash, ?int $userId): array
    {
        if (!hash_equals(self::fingerprint($siteId), $baseHash)) {
            return ['ok' => false, 'error' => __('asst.err.design_changed_since_preview')];
        }
        $current = self::current($siteId);
        $next = $current;
        foreach (self::EDITABLE as $cat => $keys) {
            foreach ($keys as $key) {
                if (array_key_exists($key, (array) ($content[$cat] ?? []))) $next[$cat][$key] = $content[$cat][$key];
            }
        }
        if ($next === $current) {
            return ['ok' => false, 'error' => __('asst.err.design_no_valid_change')];
        }

        self::snapshot($siteId, $userId, 'before_ai_edit');
        $palette = [];
        if ($next['colors'] !== $current['colors']) {
            // La paleta va entera: es como la guarda el formulario de Diseño.
            foreach (self::PALETTE_MAP as $token => $key) $palette[$key] = (string) $next['colors'][$token];
            if (!BrandPaletteService::save($siteId, $palette)) {
                return ['ok' => false, 'error' => __('asst.err.design_no_valid_change')];
            }
        }
        if (array_diff_key($next, ['colors' => 1]) !== array_diff_key($current, ['colors' => 1])) {
            $submitted = DesignSystem::effective($siteId);
            foreach (['typography', 'buttons', 'spacing'] as $cat) {
                $submitted[$cat] = array_merge($submitted[$cat], $next[$cat]);
            }
            DesignSystem::saveManualTokens($siteId, DesignSystem::diffManualTokens($submitted, DesignSystem::baseline($siteId)));
        }
        CacheService::flush($siteId);

        $reply = __('asst.design.updated');
        if ($palette !== [] && BrandPaletteService::contrastReport($palette) !== []) {
            $reply .= ' ' . __('asst.design.contrast_reply');
        }
        return ['ok' => true, 'reply' => $reply];
    }

    /** @return array{ok:bool, reply?:string, error?:string} */
    public static function apply(int $siteId, string $instruction, ?int $userId): array
    {
        $proposal = self::propose($siteId, $instruction);
        if (!$proposal['ok']) {
            return $proposal;
        }
        return self::commit($siteId, $proposal['content'], $proposal['base_hash'], $userId);
    }

    /** Devuelve la paleta y los overrides a una versión (guardando antes la actual). */
    public static function restoreVersion(int $siteId, int $versionId, ?int $userId): bool
    {
        $row = Database::selectOne(
            'SELECT version_data FROM versions WHERE id = ? AND entity_type = ? AND entity_id = ? LIMIT 1',
            [$versionId, self::ENTITY, $siteId]
        );
        $data = $row !== null ? json_decode((string) $row['version_data'], true) : null;
        if (!is_array($data) || !array_key_exists('palette', $data) || !array_key_exists('manual', $data)) return false;
        self::snapshot($siteId, $userId, 'before_restore');
        // null = el ajuste no existía: se borra y manda lo heredado (skin).
        foreach ([BrandPaletteService::SETTING_KEY => $data['palette'], DesignSystem::MANUAL_TOKENS_KEY => $data['manual']] as $key => $raw) {
            if ($raw === null) {
                Database::execute('DELETE FROM settings WHERE site_id = ? AND setting_key = ?', [$siteId, $key]);
            } else {
                Database::execute(
                    'INSERT INTO settings (site_id, setting_key, setting_value, is_encrypted) VALUES (?, ?, ?, 0)
                     ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), is_encrypted = 0',
                    [$siteId, $key, (string) $raw]
                );
            }
        }
        CacheService::flush($siteId);
        return true;
    }

    /**
     * Lo que enseña la tarjeta: los dos diseños para la maqueta, la lista de
     * cambios, los avisos de contraste nuevos y cómo cargar las fuentes.
     *
     * @param array<string,array<string,mixed>> $before
     * @param array<string,array<string,mixed>> $after
     * @param array<int,string> $notes
     * @return array<string,mixed>
     */
    public static function preview(int $siteId, array $before, array $after, array $notes = []): array
    {
        $fonts = [];
        $google = [];
        $custom = false;
        $options = DesignSystem::fontOptions($siteId);
        foreach ([$before, $after] as $tokens) {
            foreach (['font_heading', 'font_body'] as $key) {
                $value = (string) $tokens['typography'][$key];
                $fonts[$value] = ['label' => (string) ($options[$value] ?? $value), 'css' => DesignSystem::fontCssValue($value, $siteId)];
                if (str_starts_with($value, 'custom:')) $custom = true;
                elseif ($value !== 'system') $google[$value] = $value;
            }
        }
        $href = '';
        if ($google !== []) {
            $href = 'https://fonts.googleapis.com/css2?family='
                . implode('&family=', array_map(static fn (string $f): string => str_replace(' ', '+', $f) . ':wght@400;700', array_values($google)))
                . '&display=swap';
        }

        // Solo los pares que el cambio estropea: los que ya fallaban no son noticia.
        $was = array_column(BrandPaletteService::contrastReport(self::palette($before)), 'pair');
        $warnings = [];
        foreach (BrandPaletteService::contrastReport(self::palette($after)) as $issue) {
            if (in_array($issue['pair'], $was, true)) continue;
            $warnings[] = __('design.contrast.pair.' . $issue['pair'])
                . ' (' . number_format((float) $issue['value'], 1) . ':1 < ' . number_format((float) $issue['min'], 1) . ':1)';
        }

        return [
            'kind'      => 'design',
            'before'    => $before,
            'after'     => $after,
            'changes'   => self::diff($before, $after, $options),
            'warnings'  => $warnings,
            'notes'     => array_values($notes),
            'labels'    => array_map(static fn (string $k): string => (string) self::fields()['colors.' . $k]['label'], array_combine(self::EDITABLE['colors'], self::EDITABLE['colors'])),
            'fonts'     => $fonts,
            'font_href' => $href,
            'font_css'  => $custom ? CustomFontService::renderFontFaceCss($siteId) : '',
            'edit_url'  => base_url('admin/design'),
        ];
    }

    /**
     * @param array<string,array<string,mixed>> $before
     * @param array<string,array<string,mixed>> $after
     * @param array<string,string> $fontLabels valor => nombre
     * @return array<int,array{key:string,kind:string,type:string,label:string,from:string,to:string,detail:string}>
     */
    public static function diff(array $before, array $after, array $fontLabels = DesignSystem::FONT_OPTIONS): array
    {
        $fields = self::fields();
        $changes = [];
        foreach (self::EDITABLE as $cat => $keys) {
            foreach ($keys as $key) {
                $from = (string) ($before[$cat][$key] ?? '');
                $to = (string) ($after[$cat][$key] ?? '');
                if ($from === $to) continue;
                $field = $fields[$cat . '.' . $key];
                $show = static function (string $v) use ($field, $fontLabels): string {
                    return match ($field['type']) {
                        'font'  => (string) ($fontLabels[$v] ?? preg_replace('/^custom:/', '', $v)),
                        'range' => $v . ($field['unit'] !== '' ? ' ' . $field['unit'] : ''),
                        default => $v,
                    };
                };
                $changes[] = [
                    'key'    => $cat . '.' . $key,
                    'kind'   => 'changed',
                    'type'   => (string) $field['type'],
                    'label'  => (string) $field['label'],
                    'from'   => $from,
                    'to'     => $to,
                    'detail' => $show($from) . ' → ' . $show($to),
                ];
            }
        }
        return $changes;
    }

    /** Roles con fuente de marca asignada: mandan sobre todo y no se cambian aquí. @return string[] */
    public static function lockedFonts(int $siteId): array
    {
        $locked = [];
        foreach (['heading' => 'font_heading', 'body' => 'font_body'] as $role => $key) {
            if (CustomFontService::familyForRole($siteId, $role) !== null) $locked[] = $key;
        }
        return $locked;
    }

    /** Huella de todo el diseño efectivo: cualquier cambio en Diseño la mueve. */
    private static function fingerprint(int $siteId): string
    {
        return AssistantProposal::hash(DesignSystem::effective($siteId));
    }

    /**
     * Valor saneado, o null si no vale.
     *
     * @param array<string,mixed> $field
     * @param array<string,string> $fontOptions
     */
    private static function clean(array $field, mixed $raw, array $fontOptions): string|int|float|null
    {
        if (!is_scalar($raw)) return null;
        $raw = trim((string) $raw);
        switch ($field['type']) {
            case 'color':
                if (preg_match('/^#?([0-9a-f]{3})$/i', $raw, $m) === 1) {
                    $raw = '#' . $m[1][0] . $m[1][0] . $m[1][1] . $m[1][1] . $m[1][2] . $m[1][2];
                }
                $raw = '#' . ltrim(strtolower($raw), '#');
                return preg_match('/^#[0-9a-f]{6}$/', $raw) === 1 ? $raw : null;
            case 'range':
                if (!is_numeric($raw)) return null;
                $n = (float) $raw;
                if ($n < $field['min'] || $n > $field['max']) return null;
                return fmod((float) $field['step'], 1.0) == 0.0 ? (int) round($n) : round($n, 2);
            case 'font':
                return isset($fontOptions[$raw]) ? $raw : null;
        }
        return null;
    }

    /**
     * @param array<string,array<string,mixed>> $tokens
     * @return array<string,array<string,mixed>>
     */
    private static function subset(array $tokens): array
    {
        $fields = self::fields();
        $out = [];
        foreach (self::EDITABLE as $cat => $keys) {
            foreach ($keys as $key) {
                $field = $fields[$cat . '.' . $key];
                $value = $tokens[$cat][$key] ?? $field['default'];
                $out[$cat][$key] = match ($field['type']) {
                    'color' => strtolower((string) $value),
                    'range' => fmod((float) $field['step'], 1.0) == 0.0 ? (int) round((float) $value) : round((float) $value, 2),
                    default => (string) $value,
                };
            }
        }
        return $out;
    }

    /** @return array<string,array<string,mixed>> 'cat.key' => campo del schema */
    private static function fields(): array
    {
        $out = [];
        foreach (DesignSystem::schema() as $cat => $def) {
            foreach ($def['fields'] as $f) $out[$cat . '.' . $f['key']] = $f + ['unit' => ''];
        }
        // Fuera de su pestaña de Diseño, «Radio» o «Tamaño base» no dicen nada.
        $out['typography.base_size']['label'] = __('asst.design.base_size');
        $out['buttons.radius']['label'] = __('asst.design.button_radius');
        $out['spacing.radius_card']['label'] = __('asst.design.card_radius');
        return $out;
    }

    /** @param array<string,array<string,mixed>> $tokens @return array<string,string> */
    private static function palette(array $tokens): array
    {
        $palette = [];
        foreach (self::PALETTE_MAP as $token => $key) $palette[$key] = (string) $tokens['colors'][$token];
        return $palette;
    }

    private static function snapshot(int $siteId, ?int $userId, string $reason): void
    {
        $raw = [];
        foreach (['palette' => BrandPaletteService::SETTING_KEY, 'manual' => DesignSystem::MANUAL_TOKENS_KEY] as $name => $key) {
            $row = Database::selectOne('SELECT setting_value FROM settings WHERE site_id = ? AND setting_key = ? LIMIT 1', [$siteId, $key]);
            $raw[$name] = $row !== null ? (string) $row['setting_value'] : null;
        }
        Database::execute(
            'INSERT INTO versions (entity_type, entity_id, version_data, created_by, reason) VALUES (?, ?, ?, ?, ?)',
            [self::ENTITY, $siteId, json_encode($raw, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), $userId, $reason]
        );
    }
}
