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
 * pueden convertirse en items `aplicar` (hoy: pages.canvas.edit y forms.edit).
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
            'media'            => self::count("SELECT COUNT(*) AS n FROM media WHERE site_id = ? AND source <> '" . MediaService::SOURCE_ASSISTANT . "'", $siteId),
            'legal_pages'      => self::count("SELECT COUNT(*) AS n FROM pages WHERE site_id = ? AND page_type = 'legal'", $siteId),
            'booking_services' => self::count('SELECT COUNT(*) AS n FROM booking_services WHERE site_id = ?', $siteId),
            'resources'        => self::count('SELECT COUNT(*) AS n FROM resources WHERE site_id = ?', $siteId),
            'commerce_products'=> self::count('SELECT COUNT(*) AS n FROM commerce_products WHERE site_id = ?', $siteId),
            'image_bank'       => ImageBankService::isAvailable() ? 1 : 0,
            'posts'            => self::count("SELECT COUNT(*) AS n FROM pages WHERE site_id = ? AND page_type = 'article'", $siteId),
        ]);
    }

    /**
     * ASST-V2 F3 — Qué pasa en la web cuando se aplica cada capacidad.
     *   read      solo consulta, no cambia nada
     *   draft     se guarda como borrador; se revisa antes de publicar
     *   live      se ve en la web nada más aplicarse
     *   sensitive textos legales, pagos o datos que piden revisión humana
     * Lo que no aparece aquí se trata como «live».
     */
    public const RISK = [
        'pages.canvas.edit'  => 'draft',
        'pages.create'       => 'draft',
        'pages.ideas'        => 'read',
        'posts.create'       => 'draft',
        'posts.ideas'        => 'read',
        'posts.manage'       => 'live',
        'chrome.edit'        => 'live',
        'chrome.manage'      => 'live',
        'design.edit'        => 'live',
        'design.manage'      => 'live',
        'forms.edit'         => 'live',
        'forms.manage'       => 'live',
        'media.manage'       => 'live',
        'seo.page.edit'      => 'live',
        'seo.manage'         => 'live',
        'legal.content'      => 'sensitive',
        'analytics.review'   => 'read',
        'booking.manage'     => 'live',
        'booking.service.edit'   => 'live',
        'booking.service.create' => 'draft',
        'commerce.product.edit'  => 'live',
        'commerce.product.create'=> 'draft',
        'resources.edit'     => 'live',
        'resources.manage'   => 'live',
        'commerce.manage'    => 'sensitive',
        'custom.development' => 'read',
    ];

    /**
     * Permiso de panel que hace falta para que el asistente ejecute cada
     * capacidad automática: el asistente no da más acceso del que ya hay.
     */
    public const EXECUTOR_PERMISSION = [
        'pages.canvas.edit' => Permissions::CAP_CONTENT,
        'forms.edit'        => Permissions::CAP_FORMS,
        'seo.page.edit'     => Permissions::CAP_VISIBILITY,
        // El editor del header y el pie es solo de administradores
        // (ChromeController::save), no de todo el que tiene Apariencia.
        'chrome.edit'       => self::ADMIN_ONLY,
        'design.edit'       => Permissions::CAP_APPEARANCE,
        // ASST-V2 F4f — mismos permisos que /admin/booking, /admin/commerce y /admin/resources.
        'booking.service.edit'    => Permissions::CAP_CLIENTS,
        'booking.service.create'  => Permissions::CAP_CLIENTS,
        'commerce.product.edit'   => Permissions::CAP_CLIENTS,
        'commerce.product.create' => Permissions::CAP_CLIENTS,
        'resources.edit'          => Permissions::CAP_CONTENT,
        'posts.create'      => Permissions::CAP_CONTENT,
        'pages.create'      => Permissions::CAP_CONTENT,
        'pages.ideas'       => Permissions::CAP_CONTENT,
        'posts.ideas'       => Permissions::CAP_CONTENT,
    ];
    private const ADMIN_ONLY = 'role:admin';

    public static function roleCanExecute(?string $role, string $capabilityId): bool
    {
        $cap = self::EXECUTOR_PERMISSION[$capabilityId] ?? null;
        if ($cap === self::ADMIN_ONLY) {
            return $role === Permissions::ROLE_ADMIN;
        }
        return $cap !== null && Permissions::roleHas($role, $cap);
    }

    /** Capacidades cuyo cambio del asistente se deshace desde Actividad. */
    public const UNDOABLE = ['pages.canvas.edit', 'forms.edit', 'seo.page.edit', 'chrome.edit', 'design.edit', 'booking.service.edit', 'commerce.product.edit', 'resources.edit'];

    /**
     * Constructor puro del catálogo; permite probar el gate sin base de datos.
     *
     * @param array<string,array{available?:bool,enabled?:bool}> $moduleStates
     * @param array<string,int> $counts
     * @return array<int,array<string,mixed>>
     */
    public static function catalogForState(array $moduleStates = [], array $counts = []): array
    {
        $canvasEdit = self::capability(
            'pages.canvas.edit',
            'Editar contenido o estructura de una página Canvas existente',
            'automatic',
            '/admin/pages',
            'canvas_edit',
            (int) ($counts['pages'] ?? 0),
            ['Página existente', 'contenido o decisión concreta cuando el cambio lo requiera']
        );
        $canvasEdit['notes'] = self::canvasImageNote($counts);

        $capabilities = [
            $canvasEdit,
            // ASST-V2 F4d — páginas nuevas con el creador del panel (plan +
            // Canvas). Siempre en borrador; artículos y legales no entran aquí.
            // i18n-ignore-start: etiquetas que lee el planner en el prompt.
            self::capability(
                'pages.create',
                'Crear una página nueva (servicio, landing, contacto, producto o inicio) en borrador, con su plan y diseño Canvas',
                'automatic',
                '/admin/pages',
                'page_create',
                (int) ($counts['pages'] ?? 0),
                ['Qué página es y para qué sirve; público o llamada a la acción si los da']
            ),
            self::capability(
                'pages.ideas',
                'Proponer qué páginas le faltan a la web (el operador elige cuáles crear)',
                'automatic',
                '/admin/pages',
                'page_ideas',
                (int) ($counts['pages'] ?? 0),
                ['Enfoque si lo da']
            ),
            // i18n-ignore-end
            // ASST-V2 F4c — el blog: escribir entradas en borrador y proponer ideas
            // con lo mismo que usa /admin/posts/new. Editar, publicar o borrar
            // entradas existentes sigue en posts.manage.
            // i18n-ignore-start: etiquetas que lee el planner en el prompt.
            self::capability(
                'posts.create',
                'Escribir entradas nuevas del blog (se guardan en borrador, con imagen destacada si hay banco de imágenes)',
                'automatic',
                '/admin/posts',
                'post_create',
                (int) ($counts['posts'] ?? 0),
                ['Tema de cada entrada; enfoque, público o extensión si los da']
            ),
            self::capability(
                'posts.ideas',
                'Proponer ideas de entradas para el blog a partir de las que ya hay (el operador elige cuáles escribir)',
                'automatic',
                '/admin/posts',
                'post_ideas',
                (int) ($counts['posts'] ?? 0),
                ['Enfoque o tema si lo da']
            ),
            self::capability(
                'posts.manage',
                'Editar, publicar, despublicar o borrar entradas del blog que ya existen',
                'manual',
                '/admin/posts',
                null,
                (int) ($counts['posts'] ?? 0),
                ['Entrada y cambio concreto']
            ),
            // i18n-ignore-end
            // ASST-V2 F4b — textos y enlaces del header y el pie. El diseño
            // (colores, fondo, logo, columnas, densidad) sigue en chrome.manage.
            // i18n-ignore-start: etiquetas que lee el planner en el prompt.
            self::capability(
                'chrome.edit',
                'Cambiar el menú del header (añadir, quitar, renombrar, ordenar, desplegables), su botón y los textos y enlaces del pie (lema, menú del pie, contacto, redes, copyright); se aplica en vivo en toda la web',
                'automatic',
                '/admin/chrome',
                'chrome_edit',
                null,
                ['Qué elementos, en qué orden y con qué texto o enlace']
            ),
            self::capability(
                'chrome.manage',
                'Cambiar el diseño del header y el pie (colores, fondo, bordes, logo, columnas, densidad)',
                'manual',
                '/admin/chrome',
                null,
                null,
                ['Decisión de diseño']
            ),
            // i18n-ignore-end
            // ASST-V2 F4e — colores de la paleta, tipografías y radios. Logo,
            // favicon, subir fuentes y regenerar el estilo siguen en design.manage.
            // i18n-ignore-start: etiquetas que lee el planner en el prompt.
            self::capability(
                'design.edit',
                'Cambiar los colores de la web (principal, acento, fondo, superficie, texto, bordes), las tipografías de titulares y texto, el tamaño de letra y lo redondeados que son botones y tarjetas; se aplica en vivo en toda la web',
                'automatic',
                '/admin/design',
                'design_edit',
                null,
                ['Qué color, fuente o radio y hacia dónde (un hex, «más oscuro», «más elegante»)']
            ),
            // i18n-ignore-end
            self::capability(
                'design.manage',
                'Subir o cambiar logos, favicon y fuentes propias, o regenerar el estilo visual completo',
                'manual',
                '/admin/design',
                null,
                null,
                ['Decisión de marca o referencia visual']
            ),
            // ASST-FORMS — editar preguntas y textos de un formulario que ya
            // existe. Destinatario, base legal y autorrespuesta siguen en
            // forms.manage / legal.content: el editor automático no los toca.
            self::capability(
                'forms.edit',
                'Editar preguntas, opciones y textos de un formulario existente (se aplica en vivo)',
                'automatic',
                '/admin/formularios',
                'form_edit',
                (int) ($counts['forms'] ?? 0),
                ['Formulario existente del MAPA DE FORMULARIOS', 'preguntas u opciones concretas']
            ),
            self::capability(
                'forms.manage',
                'Crear formularios nuevos o configurar destinatarios, consentimientos y autorrespuesta',
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
            // ASST-V2 F4a — título SEO y meta descripción de una página. La URL
            // (slug), redirecciones, indexación y canonical siguen en seo.manage.
            // i18n-ignore-start: etiquetas que lee el planner en el prompt.
            self::capability(
                'seo.page.edit',
                'Cambiar el título SEO y la meta descripción de una página existente (se aplica en vivo)',
                'automatic',
                '/admin/seo',
                'seo_page_edit',
                (int) ($counts['pages'] ?? 0),
                ['Página existente', 'enfoque o palabras clave si el operador los da']
            ),
            self::capability(
                'seo.manage',
                'Editar la URL (slug), redirecciones, indexación o canonical',
                'manual',
                '/admin/seo',
                null,
                null,
                ['Página o URL y objetivo del cambio']
            ),
            // i18n-ignore-end
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

        // i18n-ignore-start: etiquetas que lee el planner en el prompt.
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
                'label' => 'Configurar reservas: campos que se piden, emails, días sueltos cerrados, confirmación automática, integración externa, activar, desactivar o borrar servicios',
                'path' => '/admin/booking',
                'count' => (int) ($counts['booking_services'] ?? 0),
                'required' => ['Duración, precio, disponibilidad y modalidad'],
            ],
            'resources' => [
                'id' => 'resources.manage',
                'label' => 'Crear recursos descargables, subir su archivo o portada, cambiar el acceso, publicarlos o borrarlos',
                'path' => '/admin/resources',
                'count' => (int) ($counts['resources'] ?? 0),
                'required' => ['Archivo, portada, precio/acceso y textos'],
            ],
            'commerce' => [
                'id' => 'commerce.manage',
                'label' => 'Configurar pagos, Stripe, impuestos, envíos, imágenes de producto, productos digitales, activar, desactivar o borrar productos',
                'path' => '/admin/commerce',
                'count' => (int) ($counts['commerce_products'] ?? 0),
                'required' => ['Producto, precio definitivo, impuestos y método de pago'],
            ],
        ];
        // i18n-ignore-end

        // i18n-ignore-start: etiquetas que lee el planner en el prompt.
        $catalogExecutors = [
            'booking' => [
                ['booking.service.edit', 'Cambiar un servicio de reserva del CATÁLOGO: nombre, descripción, precio visible, duración, margen entre citas, plazas, antelación mínima y máxima y horario semanal (se aplica en vivo; precio, tiempos y horario piden revisión)', 'catalog_edit', ['Servicio del CATÁLOGO (target_id) y cambio concreto']],
                ['booking.service.create', 'Crear un servicio de reserva nuevo (nace desactivado: se revisa y se activa en Reservas)', 'catalog_create', ['Nombre; duración, precio y horario si se dan']],
            ],
            'commerce' => [
                ['commerce.product.edit', 'Cambiar un producto del CATÁLOGO: nombre, descripción, precio o stock (se aplica en vivo; precio y stock piden revisión)', 'catalog_edit', ['Producto del CATÁLOGO (target_id) y cambio concreto']],
                ['commerce.product.create', 'Crear un producto físico nuevo (nace desactivado, sin imagen: se completa y se activa en Tienda)', 'catalog_create', ['Nombre y precio']],
            ],
            'resources' => [
                ['resources.edit', 'Cambiar el título, la descripción o la categoría de un recurso descargable del CATÁLOGO (se aplica en vivo)', 'catalog_edit', ['Recurso del CATÁLOGO (target_id) y textos nuevos']],
            ],
        ];
        // i18n-ignore-end
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
            // ASST-V2 F4f — con el módulo activo, el asistente edita lo que ya hay
            // y crea servicios y productos desactivados.
            foreach ($enabled ? ($catalogExecutors[$key] ?? []) : [] as [$id, $label, $handler, $required]) {
                $capabilities[] = self::capability($id, $label, 'automatic', $definition['path'], $handler, $definition['count'], $required,
                    false, true, 'enabled', $key);
            }
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

    /**
     * Lo que el editor Canvas puede hacer con fotos sin que nadie le dé
     * archivos. Sin esta nota el planificador pedía «enlaces o IDs de
     * Unsplash» aunque el editor busca y descarga las fotos él solo.
     *
     * @param array<string,int> $counts
     */
    private static function canvasImageNote(array $counts): string
    {
        $media = (int) ($counts['media'] ?? 0);
        if ((int) ($counts['image_bank'] ?? 0) === 1) {
            return 'fotos: el editor las consigue solo, buscando y descargando del banco de imágenes (Unsplash)'
                . ($media > 0 ? ' o usando la biblioteca del sitio (' . $media . ' archivos)' : '')
                . '; para poner fotos no hacen falta enlaces, IDs ni archivos';
        }
        if ($media > 0) {
            return 'fotos: el editor usa la biblioteca del sitio (' . $media . ' archivos); no hay banco de imágenes configurado, así que no puede traer fotos nuevas de internet';
        }
        return 'fotos: no hay imágenes en la biblioteca ni banco de imágenes configurado; para poner fotos hay que subirlas antes en /admin/media';
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
                . ' | riesgo=' . $capability['risk']
                . ' | handler=' . ($capability['handler'] ?? 'ninguno')
                . ' | panel=' . ($capability['admin_path'] !== '' ? $capability['admin_path'] : 'ninguno');
            if ($capability['configured_items'] !== null) {
                $line .= ' | configurados=' . $capability['configured_items'];
            }
            if ($required !== '') {
                $line .= ' | datos=' . $required;
            }
            if (($capability['notes'] ?? '') !== '') {
                $line .= ' | notas=' . $capability['notes'];
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
            'notes'              => '',
            'risk'               => self::RISK[$id] ?? 'live',
            'undo'               => in_array($id, self::UNDOABLE, true),
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
