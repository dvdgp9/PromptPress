<?php

declare(strict_types=1);

namespace App\Services\Compliance;

use Core\Database;

/**
 * E-GDPR G1 — Detector de gaps de cumplimiento.
 *
 * Cruza el manifest del sitio con el estado real (páginas publicadas, secciones
 * form, tracking activo) y produce una lista de "huecos" con severidad,
 * título humano y CTA. Esto alimenta:
 *   - el semáforo del dashboard widget,
 *   - el resumen del panel /admin/privacy,
 *   - la pill contextual en el editor de páginas.
 *
 * Severidades:
 *   - yellow  → falta algo no urgente (sitio aún no publicado)
 *   - orange  → recomendado antes de publicar
 *   - red     → problema legal activo (p. ej. tracking sin consent)
 */
final class GapDetector
{
    /**
     * @return array<int,array{key:string,severity:string,title:string,cta_url:string,cta_label:string,description:string}>
     */
    public static function detect(int $siteId, array $manifest): array
    {
        $gaps = [];
        $hasPublishedPages   = self::hasPublishedPages($siteId);
        $hasTrackingEnabled  = self::hasTrackingEnabled($manifest);
        $hasFormSections     = self::hasFormSections($siteId);
        $legalPages          = (array) ($manifest['legal_pages'] ?? []);
        $controller          = (array) ($manifest['controller'] ?? []);

        // --- Datos del responsable ---
        $missingControllerFields = self::missingControllerFields($controller);
        if (!empty($missingControllerFields)) {
            // Si todavía no hay nada publicado, es yellow. Si ya hay páginas
            // públicas, sube a orange (textos legales se generarán incompletos).
            $severity = $hasPublishedPages ? 'orange' : 'yellow';
            $gaps[] = [
                'key'         => 'controller_incomplete',
                'severity'    => $severity,
                'title'       => 'Completa los datos de tu empresa',
                'description' => 'Faltan ' . count($missingControllerFields) . ' campos (razón social, NIF, dirección…). Sin ellos, los textos legales saldrán con huecos.',
                'cta_url'     => '/admin/privacy?tab=legal',
                'cta_label'   => 'Rellenar datos',
            ];
        }

        // --- Páginas legales ---
        foreach (['privacy_policy' => 'Política de privacidad', 'legal_notice' => 'Aviso legal'] as $k => $label) {
            if (empty($legalPages[$k])) {
                $gaps[] = [
                    'key'         => 'missing_legal_page_' . $k,
                    'severity'    => $hasPublishedPages ? 'orange' : 'yellow',
                    'title'       => 'Genera tu ' . mb_strtolower($label),
                    'description' => 'La IA puede crearla a partir de tus datos en menos de un minuto.',
                    'cta_url'     => '/admin/privacy?tab=pages',
                    'cta_label'   => 'Generar',
                ];
            }
        }

        // --- Tracking sin consent / cookie policy ---
        if ($hasTrackingEnabled) {
            if (empty($legalPages['cookie_policy'])) {
                $gaps[] = [
                    'key'         => 'tracking_without_cookie_policy',
                    'severity'    => 'red',
                    'title'       => 'Tienes tracking activo sin política de cookies',
                    'description' => 'Activaste analítica o píxeles pero falta la política de cookies. Es obligatoria.',
                    'cta_url'     => '/admin/privacy?tab=pages',
                    'cta_label'   => 'Generar política',
                ];
            }
        }

        // --- Forms sin metadatos legales ---
        // G5 lo refinará. De momento marcamos un gap leve si hay formularios
        // y el manifest no documenta finalidades de tratamiento.
        if ($hasFormSections && empty($manifest['site_features']['newsletter'])
            && empty($manifest['notes'])) {
            // Heurístico mínimo: si hay forms, asumimos que hay tratamiento.
            // El gap real se materializa en G5.
        }

        return $gaps;
    }

    private static function hasPublishedPages(int $siteId): bool
    {
        try {
            $row = Database::selectOne(
                "SELECT COUNT(*) AS c FROM pages WHERE site_id = ? AND status = 'published' LIMIT 1",
                [$siteId]
            );
            return ((int) ($row['c'] ?? 0)) > 0;
        } catch (\Throwable $e) {
            return false;
        }
    }

    private static function hasFormSections(int $siteId): bool
    {
        try {
            $row = Database::selectOne(
                "SELECT COUNT(*) AS c
                 FROM page_sections ps
                 INNER JOIN pages p ON p.id = ps.page_id
                 WHERE p.site_id = ? AND ps.section_type = 'form' LIMIT 1",
                [$siteId]
            );
            return ((int) ($row['c'] ?? 0)) > 0;
        } catch (\Throwable $e) {
            return false;
        }
    }

    private static function hasTrackingEnabled(array $manifest): bool
    {
        $services = (array) ($manifest['tracking']['services'] ?? []);
        foreach ($services as $s) {
            if (!empty($s['enabled'])) return true;
        }
        return false;
    }

    /**
     * Campos del responsable considerados imprescindibles para textos legales.
     * Devuelve las claves de los que están vacíos.
     */
    private static function missingControllerFields(array $controller): array
    {
        $required = ['legal_name', 'tax_id', 'address', 'email'];
        $missing = [];
        foreach ($required as $k) {
            $v = trim((string) ($controller[$k] ?? ''));
            if ($v === '') $missing[] = $k;
        }
        return $missing;
    }
}
