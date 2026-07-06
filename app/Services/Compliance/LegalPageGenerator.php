<?php

declare(strict_types=1);

namespace App\Services\Compliance;

use App\Controllers\Admin\PageController;
use App\Modules\ModuleRegistry;
use App\Services\AI\Actions;
use App\Services\AI\AIActionRunner;
use App\Services\AI\AIException;
use App\Services\CacheService;
use Core\Auth;
use Core\Database;

// (CacheService::flush() en lugar de invalidatePage porque el footer público
//  con los enlaces legales aparece en todas las páginas.)

/**
 * E-GDPR G3 — Generador de páginas legales con IA.
 *
 * Crea o actualiza una página `pages` con `page_type='legal'`, slug reservado
 * (privacidad / politica-de-cookies / aviso-legal) y UNA única sección
 * `article_body` con los bloques generados por IA.
 *
 * Reglas (heredadas de la skill web-compliance-eu-es):
 *   - NUNCA inventar datos legales. La IA escribe `TODO-LEGAL: ...` cuando falta info.
 *   - El idioma de salida sigue `sites.language`.
 *   - El usuario podrá editar la página después con el editor editorial
 *     compartido por `article_body` (también usado por las entradas).
 */
final class LegalPageGenerator
{
    /** Tipos de página legal soportados. Mapea a slug + título por defecto. */
    public const TYPES = [
        'privacy_policy' => [
            'slug'  => 'privacidad',
            'title' => 'Política de privacidad',
            'label' => 'Política de privacidad',
        ],
        'cookie_policy' => [
            'slug'  => 'politica-de-cookies',
            'title' => 'Política de cookies',
            'label' => 'Política de cookies',
        ],
        'legal_notice' => [
            'slug'  => 'aviso-legal',
            'title' => 'Aviso legal',
            'label' => 'Aviso legal',
        ],
        // FEAT-3 C4 — solo visible/generable con el módulo Commerce activo
        // (filtrado en typesFor(); generate() lo rechaza si el módulo está off).
        'purchase_conditions' => [
            'slug'  => 'condiciones-de-compra',
            'title' => 'Condiciones de compra',
            'label' => 'Condiciones de compra',
        ],
    ];

    /**
     * Tipos aplicables a un sitio concreto: `purchase_conditions` solo existe
     * si el módulo Commerce está activo (una web sin tienda no debe ver ni
     * generar condiciones de compra).
     *
     * @return array<string, array{slug:string, title:string, label:string}>
     */
    public static function typesFor(int $siteId): array
    {
        $types = self::TYPES;
        if (!ModuleRegistry::isEnabled($siteId, 'commerce')) {
            unset($types['purchase_conditions']);
        }
        return $types;
    }

    /**
     * Genera (o regenera) una página legal.
     *
     * @return array{ok:bool, page_id:int, title:string, todos:int, blocks_count:int}
     * @throws \InvalidArgumentException si el tipo no es válido
     * @throws AIException si la llamada a IA falla o el output es inválido
     */
    public static function generate(int $siteId, string $type): array
    {
        if (!isset(self::typesFor($siteId)[$type])) {
            throw new \InvalidArgumentException('Tipo de página legal no válido: ' . $type);
        }

        ComplianceService::ensurePageTypeLegal();

        $manifest = ComplianceService::manifest($siteId);
        $language = self::siteLanguage($siteId);

        // Llamada a IA con todo el contexto del manifest.
        $aiInput = [
            'legal_page_type'  => $type,
            'page_language'    => self::languageLabel($language),
            'controller_data'  => self::formatControllerData($manifest['controller'] ?? []),
            'site_features'    => self::formatSiteFeatures($manifest['site_features'] ?? []),
            'own_analytics'    => self::formatOwnAnalytics($siteId),
            'own_commerce'     => self::formatOwnCommerce($siteId),
            'tracking_services'=> self::formatTrackingServices((array) ($manifest['tracking']['services'] ?? [])),
            'forms_list'       => self::formatFormsList($siteId),
            'processors_list'  => self::formatProcessors((array) ($manifest['processors'] ?? [])),
        ];

        $result = AIActionRunner::run(Actions::GENERATE_LEGAL_PAGE, $aiInput, $siteId);
        $data = $result['data'] ?? null;
        if (!is_array($data) || !isset($data['blocks']) || !is_array($data['blocks'])) {
            throw new AIException('La IA no devolvió bloques válidos para la página legal.');
        }

        $title = trim((string) ($data['title'] ?? self::TYPES[$type]['title']));
        if ($title === '') $title = self::TYPES[$type]['title'];
        $blocks = self::sanitizeBlocks($data['blocks']);
        $todoCount = self::countTodos($blocks);

        $pageId = self::upsertLegalPage($siteId, $type, $title, $blocks);

        // Sincroniza manifest.legal_pages[type] con el ID generado.
        $legalPages = (array) ($manifest['legal_pages'] ?? []);
        $legalPages[$type] = $pageId;
        ComplianceService::patch($siteId, ['legal_pages' => $legalPages]);

        // Invalidamos toda la caché del sitio: el footer público con los enlaces
        // legales aparece en todas las páginas, no solo en la legal recién creada.
        CacheService::flush($siteId);

        return [
            'ok'           => true,
            'page_id'      => $pageId,
            'title'        => $title,
            'todos'        => $todoCount,
            'blocks_count' => count($blocks),
        ];
    }

    /**
     * Genera (o regenera) las 3 páginas legales en lote. Reutiliza `generate()`.
     *
     * Precondición: el manifest debe tener `controller.legal_name`,
     * `controller.address` y `controller.email`. Si falta cualquiera, devuelve
     * `['ok' => false, 'error' => '...']` sin tocar la BD ni llamar a la IA.
     *
     * Si una página falla a mitad, las anteriores ya generadas se mantienen
     * (cada `generate()` es transaccional por sí solo). Los fallos quedan
     * reflejados en el array de resultados.
     *
     * @return array{
     *   ok: bool,
     *   error?: string,
     *   results?: array<string, array{ok:bool, page_id?:int, title?:string, todos?:int, error?:string}>,
     *   generated?: int,
     *   failed?: int
     * }
     */
    public static function generateAllLegalPages(int $siteId): array
    {
        $manifest = ComplianceService::manifest($siteId);
        $controller = (array) ($manifest['controller'] ?? []);
        $missing = [];
        foreach (['legal_name', 'address', 'email'] as $key) {
            if (trim((string) ($controller[$key] ?? '')) === '') {
                $missing[] = $key;
            }
        }
        if ($missing !== []) {
            return [
                'ok'    => false,
                'error' => 'Faltan datos del responsable: ' . implode(', ', $missing),
            ];
        }

        $results = [];
        $generated = 0;
        $failed = 0;
        foreach (array_keys(self::typesFor($siteId)) as $type) {
            try {
                $r = self::generate($siteId, $type);
                $results[$type] = [
                    'ok'      => true,
                    'page_id' => $r['page_id'],
                    'title'   => $r['title'],
                    'todos'   => $r['todos'],
                ];
                $generated++;
            } catch (\Throwable $e) {
                $results[$type] = ['ok' => false, 'error' => $e->getMessage()];
                $failed++;
            }
        }

        return [
            'ok'        => $failed === 0,
            'results'   => $results,
            'generated' => $generated,
            'failed'    => $failed,
        ];
    }

    // =====================================================================
    // Internos
    // =====================================================================

    private static function siteLanguage(int $siteId): string
    {
        try {
            $row = Database::selectOne('SELECT language FROM sites WHERE id = ? LIMIT 1', [$siteId]);
            return (string) ($row['language'] ?? 'es');
        } catch (\Throwable $e) {
            return 'es';
        }
    }

    private static function languageLabel(string $code): string
    {
        return match (strtolower($code)) {
            'es' => 'español',
            'en' => 'English',
            'pt' => 'português',
            'fr' => 'français',
            'it' => 'italiano',
            'de' => 'Deutsch',
            'ca' => 'català',
            default => $code,
        };
    }

    private static function formatControllerData(array $c): string
    {
        $pairs = [];
        $map = [
            'legal_name'       => 'Razón social',
            'brand_name'       => 'Nombre comercial',
            'tax_id'           => 'NIF/CIF/NIE',
            'address'          => 'Dirección',
            'email'            => 'Email de contacto',
            'phone'            => 'Teléfono',
            'country'          => 'País',
            'registry_details' => 'Datos registrales',
        ];
        foreach ($map as $key => $label) {
            $v = trim((string) ($c[$key] ?? ''));
            $pairs[] = '- ' . $label . ': ' . ($v !== '' ? $v : '(no proporcionado)');
        }
        $dpo = $c['dpo'] ?? null;
        if (is_array($dpo) && !empty($dpo['email'])) {
            $pairs[] = '- DPO: ' . trim((string) ($dpo['name'] ?? '')) . ' <' . trim((string) $dpo['email']) . '>';
        } else {
            $pairs[] = '- DPO: (no designado)';
        }
        return implode("\n", $pairs);
    }

    private static function formatSiteFeatures(array $f): string
    {
        $map = [
            'ecommerce'        => 'Tiene tienda online (ecommerce)',
            'accounts'         => 'Cuentas de usuario',
            'newsletter'       => 'Newsletter / suscripción email',
            'jobs'             => 'Bolsa de empleo / candidaturas',
            'minors_targeted'  => 'Dirigido a menores',
            'booking'          => 'Reservas online',
            'support_chat'     => 'Chat de soporte',
        ];
        $active = [];
        foreach ($map as $key => $label) {
            if (!empty($f[$key])) $active[] = '- ' . $label;
        }
        return $active === [] ? '- Web informativa simple (sin tienda, sin cuentas, sin newsletter)' : implode("\n", $active);
    }

    /**
     * FEAT-3 A-privacy — describe la analítica propia de PromptPress (módulo
     * Analytics) como un dato OBJETIVO y fijo, no un processor externo: no usa
     * cookies, no persiste IP/User-Agent, el visitante se identifica con un
     * hash que rota cada día. Si el módulo no está activo para el sitio, no
     * se menciona nada (comportamiento idéntico al actual).
     */
    private static function formatOwnAnalytics(int $siteId): string
    {
        if (!ModuleRegistry::isEnabled($siteId, 'analytics')) {
            return '- (No activa)';
        }
        return "- Activa: analítica de visitas propia de PromptPress, alojada en el mismo servidor del sitio (no es un servicio de terceros).\n"
             . "- No usa cookies ni almacenamiento en el navegador (localStorage/sessionStorage): no requiere consentimiento de cookies.\n"
             . "- No almacena la dirección IP ni el User-Agent del visitante. El visitante se identifica con un código anónimo (hash) que se calcula con una clave aleatoria que cambia cada día, por lo que no permite seguir a la misma persona entre distintos días.\n"
             . "- Datos recogidos: página visitada, procedencia (referrer) reducida al dominio, tipo de dispositivo y navegador, y eventos de conversión (p. ej. envío de un formulario).\n"
             . "- Conservación: los datos detallados se conservan 90 días; los resúmenes estadísticos agregados (sin datos identificativos) se conservan de forma indefinida.\n"
             . "- Finalidad: medir el uso de la web (páginas más visitadas, procedencia del tráfico) para mejorar el servicio. Base jurídica: interés legítimo del responsable.";
    }

    /**
     * FEAT-3 C4 — Hechos objetivos del módulo Commerce (tienda propia), NO
     * inventables por la IA: métodos de pago realmente configurados, si hay
     * envío físico y qué datos del comprador se recogen. Con el módulo
     * apagado no se menciona nada.
     */
    private static function formatOwnCommerce(int $siteId): string
    {
        if (!ModuleRegistry::isEnabled($siteId, 'commerce')) {
            return '- (No activa)';
        }
        $methods = \App\Modules\Commerce\Payments\PaymentMethods::availableFor($siteId);
        $labels = [];
        foreach ($methods as $key => $m) {
            $labels[] = $key === 'manual'
                ? 'pago manual (transferencia bancaria o pago acordado; el pedido queda pendiente hasta confirmar el pago)'
                : $m->label($siteId);
        }
        $shipping = \App\Modules\Commerce\CommerceSettings::shippingCents($siteId) > 0
            || \App\Modules\Commerce\CommerceSettings::freeShippingOverCents($siteId) !== null;

        return "- Activa: tienda online propia integrada en la web (PromptCommerce), alojada en el mismo servidor del sitio (no es un marketplace de terceros).\n"
             . "- Compra como invitado: no se crean cuentas de cliente ni contraseñas.\n"
             . "- Datos del comprador que se recogen con cada pedido: nombre, email, teléfono (opcional)" . ($shipping ? ", dirección de envío (dirección, población, código postal, provincia)" : '') . " y notas del pedido. Finalidad: gestionar y entregar el pedido. Base jurídica: ejecución de contrato.\n"
             . "- El carrito usa la sesión técnica del navegador (cookie de sesión estrictamente necesaria, exenta de consentimiento).\n"
             . "- Métodos de pago configurados: " . ($labels !== [] ? implode('; ', $labels) : 'ninguno todavía') . ".\n"
             . ($shipping ? "- Hay envío físico de productos con gastos de envío mostrados antes de confirmar el pedido.\n" : "- No hay gastos de envío configurados actualmente.\n")
             . "- Los precios mostrados " . (\App\Modules\Commerce\CommerceSettings::pricesIncludeTax($siteId) ? 'incluyen IVA' : 'NO incluyen IVA (se añade en el carrito antes de confirmar)') . ".";
    }

    private static function formatTrackingServices(array $services): string
    {
        $enabled = array_values(array_filter($services, fn ($s) => !empty($s['enabled'])));
        if ($enabled === []) {
            return '- (Ninguno activo)';
        }
        $lines = [];
        foreach ($enabled as $s) {
            $name     = (string) ($s['name'] ?? $s['key'] ?? 'Servicio');
            $category = (string) ($s['category'] ?? 'analytics');
            $processor= (string) ($s['processor'] ?? '');
            $transfer = !empty($s['transfer_outside_eea']) ? 'sí (fuera del EEE)' : 'no';
            $lines[]  = '- ' . $name . ' · categoría: ' . $category
                      . ($processor !== '' ? ' · proveedor: ' . $processor : '')
                      . ' · transferencia internacional: ' . $transfer;
        }
        return implode("\n", $lines);
    }

    private static function formatFormsList(int $siteId): string
    {
        try {
            $rows = Database::select(
                "SELECT ps.id, ps.content, p.title AS page_title
                 FROM page_sections ps
                 INNER JOIN pages p ON p.id = ps.page_id
                 WHERE p.site_id = ? AND ps.section_type = 'form'
                 ORDER BY p.id, ps.sort_order
                 LIMIT 20",
                [$siteId]
            );
        } catch (\Throwable $e) {
            return '- (No se pudo leer la lista de formularios)';
        }
        if ($rows === []) return '- (No hay formularios en la web)';
        $lines = [];
        foreach ($rows as $r) {
            $content = json_decode((string) $r['content'], true);
            $heading = is_array($content) ? trim((string) ($content['heading'] ?? '')) : '';
            $fields  = is_array($content) ? (array) ($content['fields'] ?? []) : [];
            $fieldNames = [];
            foreach ($fields as $f) {
                $n = trim((string) ($f['label'] ?? $f['name'] ?? ''));
                if ($n !== '') $fieldNames[] = $n;
            }
            $lines[] = '- Formulario "' . ($heading !== '' ? $heading : 'sin título')
                     . '" en página "' . ($r['page_title'] ?? '') . '"'
                     . ($fieldNames !== [] ? ' · campos: ' . implode(', ', $fieldNames) : '');
        }
        return implode("\n", $lines);
    }

    private static function formatProcessors(array $processors): string
    {
        if ($processors === []) return '- (No se han declarado processors adicionales)';
        $lines = [];
        foreach ($processors as $p) {
            $name = (string) ($p['name'] ?? 'Proveedor');
            $role = (string) ($p['role'] ?? '');
            $country = (string) ($p['country'] ?? '');
            $transfer = !empty($p['transfer_outside_eea']) ? 'sí' : 'no';
            $lines[] = '- ' . $name
                     . ($role !== '' ? ' · rol: ' . $role : '')
                     . ($country !== '' ? ' · país: ' . $country : '')
                     . ' · transferencia fuera del EEE: ' . $transfer;
        }
        return implode("\n", $lines);
    }

    /**
     * Limpia los blocks devueltos por IA: tipos válidos, niveles válidos, sin
     * HTML/markdown infiltrado en `text`.
     */
    private static function sanitizeBlocks(array $blocks): array
    {
        $out = [];
        foreach ($blocks as $b) {
            if (!is_array($b)) continue;
            $type = (string) ($b['type'] ?? '');
            switch ($type) {
                case 'paragraph':
                    $text = trim((string) ($b['text'] ?? ''));
                    if ($text !== '') $out[] = ['type' => 'paragraph', 'text' => $text];
                    break;
                case 'heading':
                    $level = (int) ($b['level'] ?? 2);
                    if ($level !== 2 && $level !== 3) $level = 2;
                    $text = trim((string) ($b['text'] ?? ''));
                    if ($text !== '') $out[] = ['type' => 'heading', 'level' => $level, 'text' => $text];
                    break;
                case 'list':
                    $style = ((string) ($b['style'] ?? '')) === 'ordered' ? 'ordered' : 'unordered';
                    $items = array_values(array_filter(array_map(
                        fn ($i) => trim((string) $i),
                        (array) ($b['items'] ?? [])
                    ), fn ($i) => $i !== ''));
                    if ($items !== []) $out[] = ['type' => 'list', 'style' => $style, 'items' => $items];
                    break;
                case 'divider':
                    $out[] = ['type' => 'divider'];
                    break;
                // quote y otros tipos los obviamos en páginas legales — no aportan.
            }
        }
        return $out;
    }

    private static function countTodos(array $blocks): int
    {
        $count = 0;
        foreach ($blocks as $b) {
            $text = '';
            if (isset($b['text'])) $text = (string) $b['text'];
            if (isset($b['items'])) $text = implode("\n", (array) $b['items']);
            if ($text !== '' && stripos($text, 'TODO-LEGAL') !== false) $count++;
        }
        return $count;
    }

    /**
     * Crea o actualiza la página legal y su única sección article_body.
     * Estrategia:
     *   1. Busca la página por manifest.legal_pages[$type] o por slug reservado.
     *   2. Si existe → UPDATE pages.title + REPLACE sección article_body.
     *   3. Si no → INSERT pages + INSERT page_sections.
     */
    private static function upsertLegalPage(int $siteId, string $type, string $title, array $blocks): int
    {
        $info = self::TYPES[$type];
        $slug = $info['slug'];
        $now  = date('Y-m-d H:i:s');
        $contentJson = json_encode(['blocks' => $blocks], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        // Buscar página existente: prioridad por slug reservado dentro del sitio.
        $existing = Database::selectOne(
            'SELECT id FROM pages WHERE site_id = ? AND slug = ? LIMIT 1',
            [$siteId, $slug]
        );

        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            if ($existing) {
                $pageId = (int) $existing['id'];
                Database::execute(
                    "UPDATE pages
                        SET title = ?, page_type = 'legal', status = 'published',
                            updated_at = ?, published_at = COALESCE(published_at, ?)
                      WHERE id = ?",
                    [$title, $now, $now, $pageId]
                );
                // Borrar todas las secciones existentes y volver a crear UNA article_body.
                Database::execute('DELETE FROM page_sections WHERE page_id = ?', [$pageId]);
                Database::execute(
                    "INSERT INTO page_sections
                        (page_id, section_type, sort_order, content, style, status, created_at, updated_at)
                     VALUES (?, 'article_body', 0, ?, NULL, 'editable', ?, ?)",
                    [$pageId, $contentJson, $now, $now]
                );
            } else {
                $treeOrder = PageController::nextTreeOrder($siteId, null);
                Database::execute(
                    "INSERT INTO pages
                        (site_id, title, slug, page_type, parent_id, nav_label,
                         meta_title, meta_description, status, sort_order, tree_sort_order,
                         created_by, created_at, updated_at, published_at)
                     VALUES (?, ?, ?, 'legal', NULL, NULL, NULL, NULL,
                             'published', 999, ?, ?, ?, ?, ?)",
                    [
                        $siteId, $title, $slug,
                        $treeOrder, Auth::id() ?? 1, $now, $now, $now,
                    ]
                );
                $pageId = (int) Database::lastInsertId();
                Database::execute(
                    "INSERT INTO page_sections
                        (page_id, section_type, sort_order, content, style, status, created_at, updated_at)
                     VALUES (?, 'article_body', 0, ?, NULL, 'editable', ?, ?)",
                    [$pageId, $contentJson, $now, $now]
                );
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }

        return $pageId;
    }
}
