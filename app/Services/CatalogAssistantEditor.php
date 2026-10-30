<?php

declare(strict_types=1);

namespace App\Services;

use App\Modules\Booking\ServiceStore;
use App\Modules\Commerce\CommerceSettings;
use App\Modules\Commerce\ProductStore;
use App\Modules\ModuleRegistry;
use App\Modules\Resources\ResourceStore;
use App\Services\AI\Actions;
use App\Services\AI\AIActionRunner;
use Core\Database;

/**
 * ASST-V2 F4f — Servicios de reserva, productos y recursos desde el asistente.
 *
 * Edita lo que ya existe (textos, precios, duración, plazas, stock y el horario
 * semanal de los servicios) y crea servicios y productos nuevos SIEMPRE
 * desactivados. Pagos, impuestos, archivos, excepciones de fecha, campos y
 * emails de reserva, activar o borrar siguen en sus pantallas.
 *
 * Guarda por los stores del módulo con la fila entera (normalizan con
 * defaults: si no, se perderían imagen, impuestos, campos o emails). Antes
 * deja la fila en `versions` para deshacerlo desde Actividad.
 */
final class CatalogAssistantEditor
{
    /** tipo => capacidades, entidad de `versions`, módulo y campos editables. */
    public const KINDS = [
        'service'  => ['edit' => 'booking.service.edit', 'create' => 'booking.service.create', 'entity' => 'booking_service', 'module' => 'booking'],
        'product'  => ['edit' => 'commerce.product.edit', 'create' => 'commerce.product.create', 'entity' => 'commerce_product', 'module' => 'commerce'],
        'resource' => ['edit' => 'resources.edit', 'create' => null, 'entity' => 'resource', 'module' => 'resources'],
    ];

    private const FIELDS = [
        'service'  => ['name', 'description', 'price_label', 'duration_min', 'buffer_min', 'capacity', 'min_notice_hours', 'max_advance_days', 'hours'],
        'product'  => ['name', 'description', 'price_cents', 'stock'],
        'resource' => ['title', 'description', 'category'],
    ];

    /** Lo que toca dinero, tiempo o plazas: pide «Lo he revisado». */
    private const SENSITIVE = ['price_label', 'duration_min', 'buffer_min', 'capacity', 'min_notice_hours', 'max_advance_days', 'hours', 'price_cents', 'stock'];

    /** Mismos límites que ServiceStore::normalize. */
    private const RANGES = [
        'duration_min' => [5, 480], 'buffer_min' => [0, 240], 'capacity' => [1, 500],
        'min_notice_hours' => [0, 720], 'max_advance_days' => [1, 365],
    ];
    private const TEXT_MAX = ['name' => 120, 'title' => 180, 'description' => 4000, 'price_label' => 60, 'category' => 100];
    private const DAYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];
    private const MAX_RANGES_PER_DAY = 4;

    /** Tipo de una capacidad del catálogo, o null. */
    public static function kindFor(string $capabilityId): ?string
    {
        foreach (self::KINDS as $kind => $def) {
            if ($capabilityId === $def['edit'] || $capabilityId === $def['create']) return $kind;
        }
        return null;
    }

    public static function isCreate(string $capabilityId): bool
    {
        $kind = self::kindFor($capabilityId);
        return $kind !== null && self::KINDS[$kind]['create'] === $capabilityId;
    }

    /**
     * Lo que hay en los módulos activos, para el planificador.
     *
     * @return array<string,array{kind:string,id:int,title:string,line:string}> 'kind:id' => fila
     */
    public static function siteCatalog(int $siteId): array
    {
        $out = [];
        $on = static fn (string $module): bool => ModuleRegistry::isEnabled($siteId, $module);
        // i18n-ignore-start: líneas del mapa que viaja al prompt del planificador.
        try {
            if ($on('booking')) {
                foreach (ServiceStore::all($siteId) as $s) {
                    $state = self::state($siteId, 'service', (int) $s['id']);
                    if ($state === null) continue;
                    $out['service:' . (int) $s['id']] = ['kind' => 'service', 'id' => (int) $s['id'], 'title' => (string) $s['name'],
                        'line' => $state['duration_min'] . ' min · ' . ($state['price_label'] !== '' ? $state['price_label'] : '-')
                            . ' · ' . ((int) $s['active'] === 1 ? 'activo' : 'desactivado') . ' · horario: ' . self::hoursText($state['hours'])];
                }
            }
            if ($on('commerce')) {
                foreach (ProductStore::all($siteId) as $p) {
                    $out['product:' . (int) $p['id']] = ['kind' => 'product', 'id' => (int) $p['id'], 'title' => (string) $p['name'],
                        'line' => CommerceSettings::format((int) $p['price_cents']) . ' · stock ' . ($p['stock'] === null ? 'ilimitado' : (int) $p['stock'])
                            . ' · ' . ((int) $p['active'] === 1 ? 'activo' : 'desactivado')];
                }
            }
            if ($on('resources')) {
                foreach (ResourceStore::all($siteId) as $r) {
                    $out['resource:' . (int) $r['id']] = ['kind' => 'resource', 'id' => (int) $r['id'], 'title' => (string) $r['title'],
                        'line' => (string) ($r['category'] ?? '') . ' · ' . (string) $r['status']];
                }
            }
        } catch (\Throwable $e) {
            error_log('[CatalogAssistantEditor] catalog site=' . $siteId . ': ' . $e->getMessage());
        }
        // i18n-ignore-end
        return $out;
    }

    /**
     * Lo editable de un elemento. Horario: lista de 7 días (0 = lunes).
     *
     * @return array<string,mixed>|null
     */
    public static function state(int $siteId, string $kind, int $id): ?array
    {
        if ($kind === 'service') {
            $row = ServiceStore::find($siteId, $id);
            if ($row === null) return null;
            $hours = [];
            for ($d = 0; $d < 7; $d++) $hours[$d] = array_values((array) ($row['hours'][$d] ?? []));
            return [
                'name' => (string) $row['name'], 'description' => (string) ($row['description'] ?? ''),
                'price_label' => (string) ($row['price_label'] ?? ''), 'duration_min' => (int) $row['duration_min'],
                'buffer_min' => (int) $row['buffer_min'], 'capacity' => (int) $row['capacity'],
                'min_notice_hours' => (int) $row['min_notice_hours'], 'max_advance_days' => (int) $row['max_advance_days'],
                'hours' => $hours,
            ];
        }
        if ($kind === 'product') {
            $row = ProductStore::find($siteId, $id);
            if ($row === null) return null;
            return [
                'name' => (string) $row['name'], 'description' => (string) ($row['description'] ?? ''),
                'price_cents' => (int) $row['price_cents'], 'stock' => $row['stock'] === null ? null : (int) $row['stock'],
            ];
        }
        if ($kind === 'resource') {
            $row = ResourceStore::find($siteId, $id);
            if ($row === null) return null;
            return ['title' => (string) $row['title'], 'description' => (string) ($row['description'] ?? ''), 'category' => (string) ($row['category'] ?? '')];
        }
        return null;
    }

    /** Punto de partida de un elemento nuevo. @return array<string,mixed> */
    private static function blank(string $kind): array
    {
        return match ($kind) {
            'service' => ['name' => '', 'description' => '', 'price_label' => '', 'duration_min' => 60, 'buffer_min' => 0, 'capacity' => 1,
                'min_notice_hours' => 12, 'max_advance_days' => 60, 'hours' => array_fill(0, 7, [])],
            'product' => ['name' => '', 'description' => '', 'price_cents' => 0, 'stock' => null],
            default   => [],
        };
    }

    /**
     * Prepara el cambio sin guardar nada. `$id` 0 = elemento nuevo.
     *
     * @return array{ok:bool, error?:string, before?:?array, content?:array, base_hash?:string, notes?:array<int,string>, sensitive?:bool}
     * @throws \App\Services\AI\AIException si falla la llamada al modelo
     */
    public static function propose(int $siteId, string $kind, int $id, string $instruction): array
    {
        $current = $id > 0 ? self::state($siteId, $kind, $id) : self::blank($kind);
        if ($current === null) {
            return ['ok' => false, 'error' => __('asst.err.catalog_not_found')];
        }
        $result = AIActionRunner::run(Actions::EDIT_CATALOG_ITEM, [
            'request' => $instruction,
            'kind'    => $kind,
            'mode'    => $id > 0 ? 'editar' : 'crear',
            'current' => json_encode(self::forModel($kind, $current), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ], $siteId);
        $draft = is_array($result['data'] ?? null) ? $result['data'] : [];
        return self::prepare($siteId, $kind, $id, $draft);
    }

    /**
     * Aplica `$draft` (solo lo que cambia) sobre el estado actual. Lo que no
     * valida se descarta y se explica en `notes`.
     *
     * @param array<string,mixed> $draft
     * @return array{ok:bool, error?:string, before?:?array, content?:array, base_hash?:string, notes?:array<int,string>, sensitive?:bool}
     */
    public static function prepare(int $siteId, string $kind, int $id, array $draft): array
    {
        if (!isset(self::FIELDS[$kind]) || ($id === 0 && self::KINDS[$kind]['create'] === null)) {
            return ['ok' => false, 'error' => __('asst.err.catalog_not_found')];
        }
        $before = $id > 0 ? self::state($siteId, $kind, $id) : null;
        if ($id > 0 && $before === null) {
            return ['ok' => false, 'error' => __('asst.err.catalog_not_found')];
        }
        $after = $before ?? self::blank($kind);
        $notes = [];
        if ($kind === 'product' && array_key_exists('price', $draft)) {
            $draft['price_cents'] = $draft['price'];
        }

        foreach (self::FIELDS[$kind] as $field) {
            if (!array_key_exists($field, $draft)) continue;
            $raw = $draft[$field];
            if ($field === 'hours') {
                [$hours, $error] = self::cleanHours($raw, $after['hours']);
                if ($error === 'overlap') {
                    return ['ok' => false, 'error' => __('asst.err.catalog_hours_overlap'), 'notes' => $notes];
                }
                if ($error !== null) $notes[] = __('asst.catalog.note_bad_hours', ['dia' => $error]);
                $after['hours'] = $hours;
                continue;
            }
            $value = self::clean($field, $raw);
            if ($value === false) {
                $notes[] = __('asst.catalog.note_ignored', ['campo' => self::label($kind, $field), 'valor' => mb_substr(is_scalar($raw) ? (string) $raw : '?', 0, 40)]);
                continue;
            }
            $after[$field] = $value;
        }

        $nameKey = $kind === 'resource' ? 'title' : 'name';
        if (trim((string) $after[$nameKey]) === '') {
            return ['ok' => false, 'error' => __('asst.err.catalog_needs_name'), 'notes' => $notes];
        }
        if ($before !== null && $after === $before) {
            return ['ok' => false, 'error' => __('asst.err.catalog_no_valid_change'), 'notes' => $notes];
        }
        $sensitive = false;
        if ($before !== null) {
            foreach (self::SENSITIVE as $field) {
                if (array_key_exists($field, $after) && $after[$field] !== $before[$field]) $sensitive = true;
            }
        }
        return [
            'ok'        => true,
            'before'    => $before,
            'content'   => $after,
            'base_hash' => $before !== null ? AssistantProposal::hash($before) : '',
            'notes'     => $notes,
            'sensitive' => $sensitive,
        ];
    }

    /** ¿Lo preparado toca precio, tiempo, plazas u horario? @param array<string,mixed> $content */
    public static function isSensitive(int $siteId, string $kind, int $id, array $content): bool
    {
        if ($id <= 0) return false;
        $before = self::state($siteId, $kind, $id);
        if ($before === null) return true;
        foreach (self::SENSITIVE as $field) {
            if (array_key_exists($field, $content) && array_key_exists($field, $before) && $content[$field] !== $before[$field]) return true;
        }
        return false;
    }

    /**
     * Guarda una edición si nadie ha tocado el elemento entre medias.
     *
     * @param array<string,mixed> $content
     * @return array{ok:bool, reply?:string, error?:string}
     */
    public static function commit(int $siteId, string $kind, int $id, array $content, string $baseHash, ?int $userId): array
    {
        $current = self::state($siteId, $kind, $id);
        if ($current === null) {
            return ['ok' => false, 'error' => __('asst.err.catalog_not_found')];
        }
        if (!hash_equals(AssistantProposal::hash($current), $baseHash)) {
            return ['ok' => false, 'error' => __('asst.err.catalog_changed_since_preview')];
        }
        $next = array_merge($current, array_intersect_key($content, $current));
        self::snapshot($siteId, $kind, $id, $userId, 'before_ai_edit');
        if (!self::write($siteId, $kind, $id, $next)) {
            return ['ok' => false, 'error' => __('asst.err.catalog_not_found')];
        }
        $vars = ['nombre' => (string) ($next['name'] ?? $next['title'])];
        return ['ok' => true, 'reply' => match ($kind) {
            'service' => __('asst.catalog.updated_service', $vars),
            'product' => __('asst.catalog.updated_product', $vars),
            default   => __('asst.catalog.updated_resource', $vars),
        }];
    }

    /**
     * Crea un servicio o producto DESACTIVADO.
     *
     * @param array<string,mixed> $content
     * @return array{ok:bool, entity_id?:int, reply?:string, error?:string}
     */
    public static function create(int $siteId, string $kind, array $content): array
    {
        $fields = array_merge(self::blank($kind), array_intersect_key($content, self::blank($kind)));
        if (trim((string) ($fields['name'] ?? '')) === '') {
            return ['ok' => false, 'error' => __('asst.err.catalog_needs_name')];
        }
        if ($kind === 'service') {
            $id = ServiceStore::create($siteId, array_diff_key($fields, ['hours' => 1]) + ['active' => 0]);
            ServiceStore::update($siteId, $id, array_merge((array) Database::selectOne('SELECT * FROM booking_services WHERE id = ?', [$id]), ['active' => 0]),
                self::hoursForStore($fields['hours']), []);
        } elseif ($kind === 'product') {
            $id = ProductStore::create($siteId, $fields + ['active' => 0, 'product_type' => 'physical']);
        } else {
            return ['ok' => false, 'error' => __('asst.err.catalog_not_found')];
        }
        $vars = ['nombre' => (string) $fields['name']];
        return ['ok' => true, 'entity_id' => $id, 'reply' => $kind === 'service'
            ? __('asst.catalog.created_service', $vars)
            : __('asst.catalog.created_product', $vars)];
    }

    /** Vuelve a la versión guardada (guardando antes la actual). */
    public static function restoreVersion(int $siteId, string $kind, int $id, int $versionId, ?int $userId): bool
    {
        $row = Database::selectOne(
            'SELECT version_data FROM versions WHERE id = ? AND entity_type = ? AND entity_id = ? LIMIT 1',
            [$versionId, self::KINDS[$kind]['entity'] ?? '', $id]
        );
        $data = $row !== null ? json_decode((string) $row['version_data'], true) : null;
        if (!is_array($data) || self::state($siteId, $kind, $id) === null) return false;
        self::snapshot($siteId, $kind, $id, $userId, 'before_restore');
        if ($kind === 'service') {
            $current = ServiceStore::find($siteId, $id);
            $hours = [];
            foreach ((array) ($data['hours'] ?? []) as $d => $ranges) if ((array) $ranges !== []) $hours[(int) $d] = (array) $ranges;
            return ServiceStore::update($siteId, $id, array_merge($current, (array) $data['row']), $hours, (array) ($data['exceptions'] ?? []));
        }
        if ($kind === 'product') {
            return ProductStore::update($siteId, $id, (array) $data['row']);
        }
        return ResourceStore::update($siteId, $id, array_intersect_key((array) $data['row'], array_flip(self::FIELDS['resource'])));
    }

    /**
     * Lo que enseña la tarjeta: ficha antes y después y la lista de cambios.
     *
     * @param array<string,mixed>|null $before
     * @param array<string,mixed> $after
     * @param array<int,string> $notes
     * @return array<string,mixed>
     */
    public static function preview(int $siteId, string $kind, ?array $before, array $after, array $notes, bool $sensitive): array
    {
        return [
            'kind'      => 'catalog',
            'item_kind' => $kind,
            'is_new'    => $before === null,
            'before'    => $before !== null ? self::display($kind, $before) : null,
            'after'     => self::display($kind, $after),
            'changes'   => $before !== null ? self::diff($kind, $before, $after) : [],
            'notes'     => array_values($notes),
            'sensitive' => $sensitive,
            'weekdays'  => array_map(static fn (string $k): string => __($k), \App\Modules\Booking\BookingAdminController::WEEKDAYS),
            'labels'    => array_combine(self::FIELDS[$kind], array_map(static fn (string $f): string => self::label($kind, $f), self::FIELDS[$kind])),
        ];
    }

    /**
     * @param array<string,mixed> $before
     * @param array<string,mixed> $after
     * @return array<int,array{key:string,kind:string,label:string,detail:string,sensitive:bool}>
     */
    public static function diff(string $kind, array $before, array $after): array
    {
        $changes = [];
        foreach (self::FIELDS[$kind] as $field) {
            if (($before[$field] ?? null) === ($after[$field] ?? null)) continue;
            if ($field === 'hours') {
                $days = [];
                foreach ($after['hours'] as $d => $ranges) {
                    if ($ranges === ($before['hours'][$d] ?? [])) continue;
                    $days[] = mb_strtolower(__(\App\Modules\Booking\BookingAdminController::WEEKDAYS[$d])) . ' '
                        . ($ranges === [] ? __('asst.catalog.closed') : self::rangesText($ranges));
                }
                $detail = implode('; ', $days);
            } elseif ($field === 'description') {
                $detail = mb_strimwidth((string) $after[$field], 0, 90, '…');
            } else {
                $detail = self::show($field, $before[$field]) . ' → ' . self::show($field, $after[$field]);
            }
            $changes[] = [
                'key'       => $field,
                'kind'      => 'changed',
                'label'     => self::label($kind, $field),
                'detail'    => $detail,
                'sensitive' => in_array($field, self::SENSITIVE, true),
            ];
        }
        return $changes;
    }

    /** Datos del elemento para el informe. @return array<string,mixed>|null */
    public static function card(int $siteId, string $kind, int $id): ?array
    {
        $state = self::state($siteId, $kind, $id);
        if ($state === null) return null;
        $path = match ($kind) {
            'service'  => 'admin/booking/services/' . $id,
            'product'  => 'admin/commerce/products/' . $id,
            default    => 'admin/resources/' . $id,
        };
        return ['id' => $id, 'title' => (string) ($state['name'] ?? $state['title']), 'edit_url' => base_url($path)];
    }

    // ------------------------------------------------------------------

    /** @param array<string,mixed> $state @return array<string,mixed> */
    private static function display(string $kind, array $state): array
    {
        if ($kind === 'product') {
            $state['price'] = CommerceSettings::format((int) $state['price_cents']);
        }
        return $state;
    }

    /** Lo que ve la IA: precio en euros y horario por días con nombre. @param array<string,mixed> $state */
    private static function forModel(string $kind, array $state): array
    {
        if ($kind === 'product') {
            $state['price'] = round(((int) $state['price_cents']) / 100, 2);
            unset($state['price_cents']);
        }
        if ($kind === 'service') {
            $state['hours'] = array_combine(self::DAYS, $state['hours']);
        }
        return $state;
    }

    /** Valor limpio, o false si no vale. */
    private static function clean(string $field, mixed $raw): mixed
    {
        if ($field === 'stock') {
            if ($raw === null || $raw === '' || (is_string($raw) && preg_match('/^(ilimitado|unlimited|-)$/iu', trim($raw)) === 1)) return null;
            return is_numeric($raw) && (int) $raw >= 0 && (int) $raw <= 1000000 ? (int) $raw : false;
        }
        if ($field === 'price_cents') {
            if (!is_scalar($raw)) return false;
            $text = str_replace([' ', '€'], '', (string) $raw);
            if (preg_match('/^\d{1,7}([.,]\d{1,2})?$/', $text) !== 1) return false;
            return CommerceSettings::eurosToCents($text);
        }
        if (isset(self::RANGES[$field])) {
            [$min, $max] = self::RANGES[$field];
            return is_numeric($raw) && (int) $raw >= $min && (int) $raw <= $max ? (int) $raw : false;
        }
        if (!is_scalar($raw)) return false;
        $text = trim(strip_tags((string) $raw));
        if ($field !== 'description' && $field !== 'category' && $field !== 'price_label' && $text === '') return false;
        return mb_substr($text, 0, self::TEXT_MAX[$field] ?? 4000);
    }

    /**
     * Horario de la IA ({mon: [{start,end}]}) sobre el actual. Los días que no
     * vienen se quedan como están; [] cierra el día. Un tramo mal escrito
     * descarta ese día (se avisa); un solape anula el cambio entero.
     *
     * @param array<int,array<int,array{start:string,end:string}>> $current
     * @return array{0:array<int,array<int,array{start:string,end:string}>>, 1:?string}
     */
    private static function cleanHours(mixed $raw, array $current): array
    {
        $hours = $current;
        $bad = null;
        if (!is_array($raw)) return [$hours, __('asst.catalog.all_days')];
        foreach ($raw as $key => $ranges) {
            $d = is_int($key) ? $key : array_search(strtolower((string) $key), self::DAYS, true);
            if ($d === false || $d < 0 || $d > 6 || !is_array($ranges)) continue;
            $clean = [];
            $valid = true;
            foreach ($ranges as $r) {
                $start = is_array($r) ? (string) ($r['start'] ?? $r[0] ?? '') : '';
                $end = is_array($r) ? (string) ($r['end'] ?? $r[1] ?? '') : '';
                if (preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $start) !== 1 || preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $end) !== 1 || $start >= $end) {
                    $valid = false;
                    break;
                }
                $clean[] = ['start' => $start, 'end' => $end];
            }
            if (!$valid || count($clean) > self::MAX_RANGES_PER_DAY) {
                $bad = mb_strtolower(__(\App\Modules\Booking\BookingAdminController::WEEKDAYS[$d]));
                continue;
            }
            usort($clean, static fn (array $a, array $b): int => strcmp($a['start'], $b['start']));
            foreach ($clean as $i => $r) {
                if ($i > 0 && $r['start'] < $clean[$i - 1]['end']) return [$current, 'overlap'];
            }
            $hours[$d] = $clean;
        }
        return [$hours, $bad];
    }

    /** @param array<int,array<int,array{start:string,end:string}>> $hours */
    private static function hoursForStore(array $hours): array
    {
        $out = [];
        foreach ($hours as $d => $ranges) if ($ranges !== []) $out[(int) $d] = $ranges;
        return $out;
    }

    /** @param array<string,mixed> $next */
    private static function write(int $siteId, string $kind, int $id, array $next): bool
    {
        if ($kind === 'service') {
            $row = ServiceStore::find($siteId, $id);
            if ($row === null) return false;
            return ServiceStore::update($siteId, $id, array_merge($row, array_diff_key($next, ['hours' => 1])),
                self::hoursForStore($next['hours']), (array) $row['exceptions']);
        }
        if ($kind === 'product') {
            $row = ProductStore::find($siteId, $id);
            if ($row === null) return false;
            return ProductStore::update($siteId, $id, array_merge($row, $next));
        }
        return ResourceStore::update($siteId, $id, [
            'title' => $next['title'],
            'description' => $next['description'] !== '' ? $next['description'] : null,
            'category' => $next['category'] !== '' ? $next['category'] : null,
        ]);
    }

    private static function snapshot(int $siteId, string $kind, int $id, ?int $userId, string $reason): void
    {
        $data = match ($kind) {
            'service'  => (static function () use ($siteId, $id): array {
                $row = (array) ServiceStore::find($siteId, $id);
                return [
                    'row' => array_diff_key($row, ['hours' => 1, 'exceptions' => 1]),
                    'hours' => (array) ($row['hours'] ?? []),
                    'exceptions' => (array) ($row['exceptions'] ?? []),
                ];
            })(),
            'product'  => ['row' => (array) ProductStore::find($siteId, $id)],
            default    => ['row' => array_intersect_key((array) ResourceStore::find($siteId, $id), array_flip(self::FIELDS['resource']))],
        };
        Database::execute(
            'INSERT INTO versions (entity_type, entity_id, version_data, created_by, reason) VALUES (?, ?, ?, ?, ?)',
            [self::KINDS[$kind]['entity'], $id, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), $userId, $reason]
        );
    }

    private static function label(string $kind, string $field): string
    {
        return match ($field) {
            'name' => __('asst.catalog.f.name'),
            'title' => __('asst.catalog.f.title'),
            'description' => __('asst.catalog.f.description'),
            'price_label' => __('asst.catalog.f.price'),
            'price_cents' => __('asst.catalog.f.price_cents'),
            'duration_min' => __('asst.catalog.f.duration_min'),
            'buffer_min' => __('asst.catalog.f.buffer_min'),
            'capacity' => __('asst.catalog.f.capacity'),
            'min_notice_hours' => __('asst.catalog.f.min_notice_hours'),
            'max_advance_days' => __('asst.catalog.f.max_advance_days'),
            'hours' => __('asst.catalog.f.hours'),
            'stock' => __('asst.catalog.f.stock'),
            default => __('asst.catalog.f.category'),
        };
    }

    private static function show(string $field, mixed $value): string
    {
        return match ($field) {
            'price_cents' => CommerceSettings::format((int) $value),
            'stock' => $value === null ? __('asst.catalog.unlimited') : (string) $value,
            'duration_min', 'buffer_min' => $value . ' min',
            'min_notice_hours' => $value . ' h',
            'max_advance_days' => __('asst.catalog.days', ['n' => (string) $value]),
            default => (string) $value !== '' ? (string) $value : '-',
        };
    }

    /** @param array<int,array{start:string,end:string}> $ranges */
    private static function rangesText(array $ranges): string
    {
        return implode(', ', array_map(static fn (array $r): string => $r['start'] . '-' . $r['end'], $ranges));
    }

    /** @param array<int,array<int,array{start:string,end:string}>> $hours */
    private static function hoursText(array $hours): string
    {
        $parts = [];
        foreach ($hours as $d => $ranges) {
            if ($ranges !== []) $parts[] = self::DAYS[$d] . ' ' . self::rangesText($ranges);
        }
        // i18n-ignore: viaja al prompt.
        return $parts !== [] ? implode('; ', $parts) : 'sin horario';
    }
}
