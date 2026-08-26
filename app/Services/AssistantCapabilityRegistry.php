<?php

declare(strict_types=1);

namespace App\Services;

use App\Modules\ModuleRegistry;
use Core\Database;

/**
 * Registro verificable de lo que PromptPress y el Assistant pueden hacer.
 *
 * La existencia de una función en la plataforma no implica que el Assistant
 * pueda ejecutarla. Solo las entradas con mode=automatic y handler no nulo
 * pueden convertirse en items `aplicar`.
 */
final class AssistantCapabilityRegistry
{
    public const CATEGORIES = [
        'automatable_now',
        'manual_in_platform',
        'needs_input',
        'requires_development',
        'sensitive_review',
    ];

    /** @return array<int,array<string,mixed>> */
    public static function forSite(int $siteId): array
    {
        $modules = [];
        foreach (ModuleRegistry::statusFor($siteId) as $module) {
            $modules[(string) $module['key']] = [
                'available' => (bool) $module['available'],
                'enabled'   => (bool) $module['enabled'],
            ];
        }

        return self::catalogForState($modules, [
            'pages'            => self::count('SELECT COUNT(*) AS n FROM pages WHERE site_id = ? AND slug NOT LIKE \'\\_\\_%\'', $siteId),
            'forms'            => self::count(
                "SELECT COUNT(*) AS n FROM page_sections ps
                 JOIN pages p ON p.id = ps.page_id
                 WHERE p.site_id = ? AND p.slug = '__forms'
                   AND ps.section_type = 'form' AND ps.status != 'deleted'",
                $siteId
            ),
            'media'            => self::count('SELECT COUNT(*) AS n FROM media WHERE site_id = ?', $siteId),
            'legal_pages'      => self::count("SELECT COUNT(*) AS n FROM pages WHERE site_id = ? AND page_type = 'legal'", $siteId),
            'booking_services' => self::count('SELECT COUNT(*) AS n FROM booking_services WHERE site_id = ?', $siteId),
            'resources'        => self::count('SELECT COUNT(*) AS n FROM resources WHERE site_id = ?', $siteId),
            'commerce_products'=> self::count('SELECT COUNT(*) AS n FROM commerce_products WHERE site_id = ?', $siteId),
        ]);
    }

    /**
     * Constructor puro del catálogo; permite probar el gate sin base de datos.
     *
     * @param array<string,array{available?:bool,enabled?:bool}> $moduleStates
     * @param array<string,int> $counts
     * @return array<int,array<string,mixed>>
     */
    public static function catalogForState(array $moduleStates = [], array $counts = []): array
    {
        $capabilities = [
            self::capability(
                'pages.canvas.edit',
                'Editar contenido o estructura de una página Canvas existente',
                'automatic',
                '/admin/pages',
                'canvas_edit',
                (int) ($counts['pages'] ?? 0),
                ['Página existente', 'contenido o decisión concreta cuando el cambio lo requiera']
            ),
            self::capability(
                'pages.create',
                'Crear una página nueva',
                'manual',
                '/admin/pages',
                null,
                (int) ($counts['pages'] ?? 0),
                ['Título', 'objetivo y contenido mínimo']
            ),
            self::capability(
                'posts.manage',
                'Crear o gestionar entradas del blog',
                'manual',
                '/admin/posts',
                null,
                null,
                ['Título y contenido']
            ),
            self::capability(
                'chrome.manage',
                'Editar header, footer, menú y navegación global',
                'manual',
                '/admin/chrome',
                null,
                null,
                ['Textos, enlaces y orden deseado']
            ),
            self::capability(
                'design.manage',
                'Editar colores, tipografías, logos y sistema visual global',
                'manual',
                '/admin/design',
                null,
                null,
                ['Decisión de marca o referencia visual']
            ),
            self::capability(
                'forms.manage',
                'Crear o configurar formularios y sus destinatarios',
                'manual',
                '/admin/formularios',
                null,
                (int) ($counts['forms'] ?? 0),
                ['Campos', 'destinatario y consentimiento aplicable']
            ),
            self::capability(
                'media.manage',
                'Subir, describir o gestionar archivos de la biblioteca',
                'manual',
                '/admin/media',
                null,
                (int) ($counts['media'] ?? 0),
                ['Archivo o fuente permitida y uso previsto']
            ),
            self::capability(
                'seo.manage',
                'Editar SEO, redirecciones o metadatos',
                'manual',
                '/admin/seo',
                null,
                null,
                ['Página o URL y objetivo del cambio']
            ),
            self::capability(
                'legal.content',
                'Añadir o modificar textos, consentimientos o condiciones legales',
                'review',
                '/admin/privacy',
                null,
                (int) ($counts['legal_pages'] ?? 0),
                ['Texto validado por el responsable o asesor legal'],
                true
            ),
        ];

        $moduleDefinitions = [
            'analytics' => [
                'id' => 'analytics.review',
                'label' => 'Consultar o configurar la analítica propia',
                'path' => '/admin/analytics',
                'count' => null,
                'required' => ['Métrica y periodo'],
            ],
            'booking' => [
                'id' => 'booking.manage',
                'label' => 'Crear o configurar servicios, horarios y reservas',
                'path' => '/admin/booking',
                'count' => (int) ($counts['booking_services'] ?? 0),
                'required' => ['Duración, precio, disponibilidad y modalidad'],
            ],
            'resources' => [
                'id' => 'resources.manage',
                'label' => 'Crear o configurar ebooks y recursos descargables',
                'path' => '/admin/resources',
                'count' => (int) ($counts['resources'] ?? 0),
                'required' => ['Archivo, portada, precio/acceso y textos'],
            ],
            'commerce' => [
                'id' => 'commerce.manage',
                'label' => 'Crear o configurar productos, precios y pagos',
                'path' => '/admin/commerce',
                'count' => (int) ($counts['commerce_products'] ?? 0),
                'required' => ['Producto, precio definitivo, impuestos y método de pago'],
            ],
        ];

        foreach ($moduleDefinitions as $key => $definition) {
            $state = $moduleStates[$key] ?? [];
            $available = (bool) ($state['available'] ?? false);
            $enabled = $available && (bool) ($state['enabled'] ?? false);
            $capabilities[] = self::capability(
                $definition['id'],
                $definition['label'],
                'manual',
                $enabled ? $definition['path'] : '/admin/modules',
                null,
                $definition['count'],
                $definition['required'],
                false,
                $available,
                $available ? ($enabled ? 'enabled' : 'disabled') : 'unavailable',
                $key
            );
        }

        $capabilities[] = self::capability(
            'custom.development',
            'Funcionalidad sin capacidad registrada en PromptPress',
            'none',
            '',
            null,
            null,
            ['Definición funcional y decisión de desarrollo'],
            false,
            false,
            'unsupported'
        );

        return $capabilities;
    }

    /** @param array<int,array<string,mixed>> $catalog */
    public static function renderForPrompt(array $catalog): string
    {
        $lines = ['SIN_HANDLER_NO_EJECUTAR: solo mode=automatic + handler permite category=automatable_now.'];
        foreach ($catalog as $capability) {
            $required = implode('; ', (array) ($capability['required_inputs'] ?? []));
            $line = '- ' . $capability['id']
                . ' | ' . $capability['label']
                . ' | plataforma=' . ((bool) $capability['platform_available'] ? 'sí' : 'no')
                . ' | estado=' . $capability['state']
                . ' | mode=' . $capability['mode']
                . ' | handler=' . ($capability['handler'] ?? 'ninguno')
                . ' | panel=' . ($capability['admin_path'] !== '' ? $capability['admin_path'] : 'ninguno');
            if ($capability['configured_items'] !== null) {
                $line .= ' | configurados=' . $capability['configured_items'];
            }
            if ($required !== '') {
                $line .= ' | datos=' . $required;
            }
            $lines[] = $line;
        }
        return implode("\n", $lines);
    }

    /** @param array<int,array<string,mixed>> $catalog @return array<string,array<string,mixed>> */
    public static function byId(array $catalog): array
    {
        $out = [];
        foreach ($catalog as $capability) {
            $out[(string) $capability['id']] = $capability;
        }
        return $out;
    }

    /** @return array<string,mixed> */
    private static function capability(
        string $id,
        string $label,
        string $mode,
        string $adminPath,
        ?string $handler,
        ?int $configuredItems,
        array $requiredInputs,
        bool $sensitive = false,
        bool $platformAvailable = true,
        string $state = 'available',
        ?string $module = null
    ): array {
        return [
            'id'                 => $id,
            'label'              => $label,
            'platform_available' => $platformAvailable,
            'state'              => $state,
            'mode'               => $mode,
            'handler'            => $handler,
            'admin_path'         => $adminPath,
            'configured_items'   => $configuredItems,
            'required_inputs'    => $requiredInputs,
            'sensitive'          => $sensitive,
            'module'             => $module,
        ];
    }

    private static function count(string $sql, int $siteId): int
    {
        try {
            return (int) (Database::selectOne($sql, [$siteId])['n'] ?? 0);
        } catch (\Throwable $e) {
            error_log('[AssistantCapabilityRegistry] count failed: ' . $e->getMessage());
            return 0;
        }
    }
}
