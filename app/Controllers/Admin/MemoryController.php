<?php

namespace App\Controllers\Admin;

use Core\Auth;
use Core\CSRF;
use Core\Database;
use Core\Request;
use Core\Response;
use Core\Session;
use Core\View;

/**
 * Memoria del sitio (T4.1).
 *
 * Gestiona pares clave/valor en `site_memory` que describen el negocio:
 * qué hace, público, tono, servicios, keywords, etc.
 * Estos valores se inyectan luego en los prompts de IA (Fase 6).
 */
class MemoryController
{
    /**
     * Schema de los campos de memoria.
     * key => [label, type, placeholder, help, rows, options]
     */
    public const FIELDS = [
        'business_description' => [
            'label'       => '¿Qué hace tu empresa?',
            'type'        => 'textarea',
            'rows'        => 4,
            'placeholder' => 'Describe en 2-3 frases qué hace tu empresa, qué problema resuelve y para quién.',
            'help'        => 'Base del contexto de toda la IA. Sé concreto y claro.',
        ],
        'target_audience' => [
            'label'       => 'Público objetivo',
            'type'        => 'textarea',
            'rows'        => 3,
            'placeholder' => 'Ej: PYMEs del sector servicios con 10-50 empleados que buscan modernizar su web...',
            'help'        => 'Describe a quién te diriges: sector, tamaño, rol, necesidades.',
        ],
        'tone_of_voice' => [
            'label'       => 'Tono de comunicación',
            'type'        => 'select',
            'options'     => [
                ''             => '— Elige un tono —',
                'profesional'  => 'Profesional y formal',
                'cercano'      => 'Cercano y amigable',
                'tecnico'      => 'Técnico y experto',
                'casual'       => 'Casual y desenfadado',
                'inspiracional' => 'Inspiracional y motivador',
                'directo'      => 'Directo y conciso',
            ],
            'help' => 'Cómo debe sonar tu marca en los textos generados por IA.',
        ],
        'services' => [
            'label'       => 'Servicios / productos',
            'type'        => 'textarea',
            'rows'        => 5,
            'placeholder' => "Un servicio por línea. Ejemplo:\nDiseño web a medida\nConsultoría SEO\nMantenimiento mensual",
            'help'        => 'Lista los principales servicios o productos que ofreces (uno por línea).',
        ],
        'value_proposition' => [
            'label'       => 'Propuesta de valor',
            'type'        => 'textarea',
            'rows'        => 3,
            'placeholder' => '¿Qué te hace diferente? ¿Qué promesa única le haces a tu cliente?',
            'help'        => 'En una frase: por qué elegirte a ti y no a la competencia.',
        ],
        'unique_selling_points' => [
            'label'       => 'Diferenciadores / USPs',
            'type'        => 'textarea',
            'rows'        => 4,
            'placeholder' => "Un diferenciador por línea. Ejemplo:\n15 años de experiencia\nSoporte 24/7\nGarantía de resultados",
            'help'        => 'Ventajas competitivas concretas (uno por línea).',
        ],
        'keywords' => [
            'label'       => 'Palabras clave (SEO)',
            'type'        => 'textarea',
            'rows'        => 2,
            'placeholder' => 'diseño web, desarrollo wordpress, tienda online, barcelona',
            'help'        => 'Keywords separadas por comas. Se usarán como hints de SEO en los textos generados.',
        ],
        'contact_info' => [
            'label'       => 'Información de contacto',
            'type'        => 'textarea',
            'rows'        => 4,
            'placeholder' => "Email: contacto@empresa.com\nTeléfono: +34 600 000 000\nDirección: Calle Mayor 1, Barcelona",
            'help'        => 'Datos de contacto que aparecerán en secciones de contacto y footer.',
        ],
    ];

    // ----------------------------------------------------------------------
    // GET /admin/memory
    // ----------------------------------------------------------------------
    public function index(): void
    {
        $siteId = self::requireSiteId();
        $values = self::loadValues($siteId);

        $this->render([
            'values' => $values,
            'errors' => [],
        ]);
    }

    // ----------------------------------------------------------------------
    // POST /admin/memory
    // ----------------------------------------------------------------------
    public function update(): void
    {
        CSRF::check();
        $siteId = self::requireSiteId();

        $input  = [];
        $errors = [];

        foreach (self::FIELDS as $key => $def) {
            $raw = trim((string) Request::post($key, ''));
            // Normalizar newlines
            $raw = str_replace(["\r\n", "\r"], "\n", $raw);

            if ($def['type'] === 'select' && $raw !== '' && !isset($def['options'][$raw])) {
                $errors[$key] = 'Valor no válido.';
            }
            if (mb_strlen($raw) > 5000) {
                $errors[$key] = 'Demasiado largo (máximo 5000 caracteres).';
            }
            $input[$key] = $raw;
        }

        if (!empty($errors)) {
            $this->render(['values' => $input, 'errors' => $errors]);
            return;
        }

        // UPSERT por cada campo. Si el valor queda vacío → borrar el row.
        foreach ($input as $key => $value) {
            if ($value === '') {
                Database::execute(
                    'DELETE FROM site_memory WHERE site_id = ? AND field_key = ?',
                    [$siteId, $key]
                );
            } else {
                Database::execute(
                    'INSERT INTO site_memory (site_id, field_key, field_value)
                     VALUES (?, ?, ?)
                     ON DUPLICATE KEY UPDATE field_value = VALUES(field_value)',
                    [$siteId, $key, $value]
                );
            }
        }

        Session::flash('success', 'Memoria del sitio guardada correctamente.');
        Response::redirect(base_url('admin/memory'));
    }

    // ======================================================================
    // Helpers
    // ======================================================================

    /**
     * Carga todos los pares key/value de site_memory para este site.
     * Devuelve array con todas las keys del schema (vacías si no existen en BD).
     */
    public static function loadValues(int $siteId): array
    {
        $rows = Database::select(
            'SELECT field_key, field_value FROM site_memory WHERE site_id = ?',
            [$siteId]
        );
        $values = [];
        foreach (self::FIELDS as $key => $_) {
            $values[$key] = '';
        }
        foreach ($rows as $r) {
            $values[$r['field_key']] = $r['field_value'];
        }
        return $values;
    }

    /**
     * Cuántos campos de memoria están rellenos (para mostrar progreso).
     */
    public static function completeness(int $siteId): array
    {
        $values = self::loadValues($siteId);
        $total  = count(self::FIELDS);
        $filled = 0;
        foreach ($values as $v) {
            if (trim((string) $v) !== '') $filled++;
        }
        return [
            'filled'  => $filled,
            'total'   => $total,
            'percent' => $total > 0 ? (int) round(($filled / $total) * 100) : 0,
        ];
    }

    private function render(array $ctx): void
    {
        $data = DashboardController::getCommonData();
        $data = array_merge($data, [
            'values' => $ctx['values'],
            'errors' => $ctx['errors'],
            'fields' => self::FIELDS,
            'csrf'   => CSRF::token(),
        ]);
        View::send('admin/memory/index', $data);
    }

    private static function requireSiteId(): int
    {
        $siteId = Auth::siteId();
        if ($siteId === null) {
            Session::flash('error', 'No hay sitio activo.');
            Response::redirect(base_url('admin/'));
        }
        return $siteId;
    }
}
