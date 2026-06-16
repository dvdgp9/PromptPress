<?php

declare(strict_types=1);

namespace App\Services\Compliance;

use Core\Database;

/**
 * E-GDPR G1 — Servicio principal para el manifest de cumplimiento por sitio.
 *
 * Cada `sites.id` tiene 0 o 1 fila en `site_compliance` con un JSON manifest
 * que captura los datos legales del responsable, las features (ecommerce,
 * newsletter…), los servicios de tracking activos, los processors externos,
 * las referencias a las páginas legales generadas, y los textos del banner
 * de cookies.
 *
 * El manifest sigue (de forma simplificada y adaptada a PromptPress) el
 * esquema de la skill `web-compliance-eu-es`. La regla de oro:
 *   ⚠ NUNCA inventar datos legales. Lo que el usuario no rellene queda
 *     marcado como gap; los textos legales generados con IA escribirán
 *     `TODO-LEGAL: …` en su lugar.
 *
 * Self-healing: `ensureSchema()` crea la tabla en runtime si falta.
 */
final class ComplianceService
{
    /** @var bool|null cache estática del estado del schema por request */
    private static ?bool $schemaReady = null;
    /** @var bool|null cache de si el enum page_type ya soporta 'legal' */
    private static ?bool $pageTypeLegalReady = null;

    /** Versión actual del shape del manifest. Incrementar al cambiar campos. */
    public const MANIFEST_VERSION = 1;

    /**
     * Crea la tabla `site_compliance` si no existe.
     */
    public static function ensureSchema(): void
    {
        if (self::$schemaReady === true) return;

        $row = Database::selectOne(
            "SELECT TABLE_NAME FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'site_compliance' LIMIT 1"
        );
        if ($row) {
            self::$schemaReady = true;
            return;
        }

        Database::execute(
            "CREATE TABLE IF NOT EXISTS site_compliance (
                site_id INT UNSIGNED NOT NULL PRIMARY KEY,
                manifest JSON NOT NULL,
                manifest_version SMALLINT UNSIGNED NOT NULL DEFAULT 1,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT fk_site_compliance_site
                    FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        self::$schemaReady = true;
    }

    /**
     * Garantiza que el ENUM `pages.page_type` incluye 'legal'. Self-healing
     * para instalaciones existentes que no hayan corrido la migración.
     */
    public static function ensurePageTypeLegal(): void
    {
        if (self::$pageTypeLegalReady === true) return;
        try {
            $row = Database::selectOne(
                "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pages' AND COLUMN_NAME = 'page_type' LIMIT 1"
            );
            $colType = (string) ($row['COLUMN_TYPE'] ?? '');
            if (str_contains($colType, "'legal'")) {
                self::$pageTypeLegalReady = true;
                return;
            }
            Database::execute(
                "ALTER TABLE pages
                    MODIFY COLUMN page_type
                    ENUM('home','service','product','landing','article','contact','legal')
                    NOT NULL DEFAULT 'landing'"
            );
            self::$pageTypeLegalReady = true;
        } catch (\Throwable $e) {
            // Sin permisos para ALTER (p.ej. hosting compartido). Lo dejamos para
            // que el usuario lance la migración manualmente.
            self::$pageTypeLegalReady = false;
        }
    }

    /**
     * Devuelve el manifest de un sitio (mergeado sobre los defaults).
     * Si no existe fila, devuelve los defaults sin crear nada en DB.
     */
    public static function manifest(int $siteId): array
    {
        self::ensureSchema();
        $row = Database::selectOne(
            'SELECT manifest, manifest_version FROM site_compliance WHERE site_id = ? LIMIT 1',
            [$siteId]
        );
        $defaults = self::defaultManifest();
        if (!$row) {
            return $defaults;
        }
        $stored = json_decode((string) $row['manifest'], true);
        if (!is_array($stored)) {
            return $defaults;
        }
        // Merge profundo: si el shape evoluciona, los campos nuevos heredan
        // los defaults y los existentes se respetan.
        return self::deepMerge($defaults, $stored);
    }

    /**
     * Persiste el manifest. El llamador es responsable de validar antes.
     * El JSON guardado es ya el manifest completo (mergeado).
     */
    public static function save(int $siteId, array $manifest): void
    {
        self::ensureSchema();
        $json = json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new \RuntimeException('Manifest JSON encoding failed: ' . json_last_error_msg());
        }
        Database::execute(
            'INSERT INTO site_compliance (site_id, manifest, manifest_version)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE manifest = VALUES(manifest), manifest_version = VALUES(manifest_version)',
            [$siteId, $json, self::MANIFEST_VERSION]
        );
    }

    /**
     * Actualiza parcialmente el manifest sin pisar el resto.
     * Útil cuando un tab de la UI solo edita un sub-árbol (controller, tracking…).
     *
     * @param array<string,mixed> $patch
     */
    public static function patch(int $siteId, array $patch): array
    {
        $manifest = self::manifest($siteId);
        $manifest = self::deepMerge($manifest, $patch);
        self::save($siteId, $manifest);
        return $manifest;
    }

    /**
     * Estado de cumplimiento agregado + lista de gaps detallados.
     * Lo consume el dashboard widget, el panel /admin/privacy y la pill
     * contextual en el editor de páginas.
     *
     * @return array{level:string, gaps:array<int,array{key:string,severity:string,title:string,cta_url:string,cta_label:string}>}
     */
    public static function status(int $siteId): array
    {
        $manifest = self::manifest($siteId);
        $gaps = GapDetector::detect($siteId, $manifest);
        return [
            'level' => self::aggregateLevel($gaps),
            'gaps'  => $gaps,
        ];
    }

    /**
     * Paso actual del wizard de privacidad. Derivado del estado del manifest,
     * sin persistencia de "current_step".
     *
     * 1 — controller incompleto (faltan legal_name, address o email).
     * 2 — controller OK pero el usuario no ha pasado todavía por Cookies.
     * 3 — Todo visto: pantalla de generación.
     */
    public static function wizardCurrentStep(int $siteId): int
    {
        $m = self::manifest($siteId);
        $c = (array) ($m['controller'] ?? []);
        foreach (['legal_name', 'address', 'email'] as $k) {
            if (trim((string) ($c[$k] ?? '')) === '') return 1;
        }
        $seen = (array) ($m['wizard']['steps_seen'] ?? []);
        if (empty($seen['cookies'])) return 2;
        return 3;
    }

    /**
     * ¿El usuario ya completó el wizard alguna vez? Marca de fin.
     */
    public static function wizardCompleted(int $siteId): bool
    {
        $m = self::manifest($siteId);
        if (!empty($m['wizard']['completed_at'])) return true;
        // Compatibilidad con instalaciones previas al wizard: si las 3 páginas
        // legales ya existen, damos el wizard por completado para no empujar
        // al usuario a un flujo de setup que no necesita.
        $legalPages = (array) ($m['legal_pages'] ?? []);
        foreach (['privacy_policy', 'cookie_policy', 'legal_notice'] as $type) {
            if (empty($legalPages[$type])) return false;
        }
        return true;
    }

    /**
     * Esqueleto del manifest con defaults sensatos (España / EU, sin tracking,
     * banner con textos por defecto en español).
     */
    public static function defaultManifest(): array
    {
        return [
            'controller' => [
                'legal_name'       => '',
                'brand_name'       => '',
                'tax_id'           => '',
                'registry_details' => '',
                'address'          => '',
                'email'            => '',
                'phone'            => '',
                'country'          => 'ES',
                'dpo'              => null,
            ],
            'site_features' => [
                'ecommerce'        => false,
                'accounts'         => false,
                'newsletter'       => false,
                'jobs'             => false,
                'minors_targeted'  => false,
                'booking'          => false,
                'support_chat'     => false,
            ],
            'tracking' => [
                'services' => [],
            ],
            'legal_pages' => [
                'privacy_policy' => null,
                'cookie_policy'  => null,
                'legal_notice'   => null,
            ],
            'processors' => [],
            'banner' => [
                'version'         => 1,
                'title'           => 'Cookies en este sitio',
                'description'     => 'Usamos cookies necesarias para que la web funcione. Si lo aceptas, también usaremos otras para analítica y mejorar tu experiencia. Puedes cambiar tu decisión cuando quieras.',
                'accept_label'    => 'Aceptar todas',
                'reject_label'    => 'Rechazar opcionales',
                'configure_label' => 'Configurar',
            ],
            'notes' => '',
        ];
    }

    /**
     * Determina el nivel agregado a partir de la severidad máxima de los gaps.
     * green = todo bien · yellow = falta algo no urgente · orange = falta antes
     * de publicar · red = problema legal activo (p. ej. tracking sin consent).
     */
    private static function aggregateLevel(array $gaps): string
    {
        $rank = ['green' => 0, 'yellow' => 1, 'orange' => 2, 'red' => 3];
        $max = 'green';
        foreach ($gaps as $g) {
            $sev = (string) ($g['severity'] ?? 'yellow');
            if (!isset($rank[$sev])) continue;
            if ($rank[$sev] > $rank[$max]) $max = $sev;
        }
        return $max;
    }

    /**
     * Merge profundo: arrays asociativos se fusionan recursivamente.
     * Arrays indexados (listas) se reemplazan tal cual del lado del override.
     */
    private static function deepMerge(array $base, array $override): array
    {
        foreach ($override as $key => $value) {
            if (is_array($value) && isset($base[$key]) && is_array($base[$key])
                && self::isAssoc($base[$key]) && self::isAssoc($value)) {
                $base[$key] = self::deepMerge($base[$key], $value);
            } else {
                $base[$key] = $value;
            }
        }
        return $base;
    }

    private static function isAssoc(array $arr): bool
    {
        if ($arr === []) return false;
        return array_keys($arr) !== range(0, count($arr) - 1);
    }
}
