<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\AI\AIException;
use Core\Database;

/**
 * PromptPress — Traducción del sitio completo, por pasos (I18N-FULL T5.5).
 *
 * Mismo patrón que el asistente central, que ya está probado en producción:
 *   - un item por petición HTTP (el navegador llama a `step` en bucle);
 *   - un fallo NO detiene los demás: se marca ese item y se sigue;
 *   - un reintento automático solo para fallos transitorios (timeout, 429, 5xx).
 *
 * Y tres reglas propias de traducir:
 *   - solo se traducen páginas del idioma PRINCIPAL (una traducción no se
 *     traduce a su vez);
 *   - las que ya tienen versión en el idioma destino se marcan `skipped`, no
 *     se vuelven a traducir ni se duplican;
 *   - todo aterriza en BORRADOR, como en la traducción página a página.
 */
final class TranslationJobs
{
    /** Tope por trabajo: evita lanzar 200 llamadas a IA de una sentada sin querer. */
    public const MAX_ITEMS = 40;

    /**
     * Páginas candidatas a traducir a `$targetLang`, en orden de aparición.
     *
     * @return array<int, array<string,mixed>>
     */
    public static function candidates(int $siteId, string $targetLang, ?array $onlyPageIds = null): array
    {
        $targetLang = LanguageService::normalize($targetLang);
        $primary    = LanguageService::primaryFor($siteId);

        // Filtro opcional por páginas concretas: lo usa el trabajo cuando el
        // usuario elige un subconjunto en vez de «todo el sitio».
        $extra  = '';
        $params = [$siteId, $primary, $targetLang];
        if ($onlyPageIds !== null) {
            $ids = array_values(array_unique(array_map('intval', $onlyPageIds)));
            if ($ids === []) {
                return [];
            }
            $extra  = ' AND p.id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
            $params = array_merge($params, $ids);
        }

        $rows = Database::select(
            "SELECT p.id, p.title, p.translation_group
             FROM pages p
             WHERE p.site_id = ? AND p.language = ? AND p.slug <> '__forms'
               AND NOT EXISTS (
                   SELECT 1 FROM pages t
                   WHERE t.site_id = p.site_id AND t.translation_group = p.translation_group
                     AND t.language = ?
               )" . $extra . "
             ORDER BY p.tree_sort_order ASC, p.sort_order ASC, p.id ASC",
            $params
        );

        return $rows;
    }

    /**
     * Crea el trabajo. Devuelve el id y cuántas páginas entran.
     *
     * @return array{ok:bool, job_id?:int, count?:int, message?:string}
     */
    public static function createJob(int $siteId, string $targetLang, ?int $userId, ?array $onlyPageIds = null): array
    {
        $targetLang = LanguageService::normalize($targetLang);

        if (!LanguageService::isActive($siteId, $targetLang)) {
            return [
                'ok' => false,
                'message' => LanguageService::label($targetLang) . ' no está activo en esta web. '
                    . 'Puedes activarlo en Ajustes.',
            ];
        }
        if ($targetLang === LanguageService::primaryFor($siteId)) {
            return ['ok' => false, 'message' => 'Ese ya es el idioma principal de la web.'];
        }

        $pages = self::candidates($siteId, $targetLang, $onlyPageIds);
        if ($pages === []) {
            return [
                'ok' => false,
                'message' => 'Todas tus páginas ya tienen versión en ' . LanguageService::label($targetLang) . '.',
            ];
        }

        $pages = array_slice($pages, 0, self::MAX_ITEMS);

        Database::execute(
            'INSERT INTO translation_jobs (site_id, target_lang, status, created_by) VALUES (?, ?, ?, ?)',
            [$siteId, $targetLang, 'pending', $userId]
        );
        $jobId = (int) Database::lastInsertId();

        $order = 0;
        foreach ($pages as $page) {
            Database::execute(
                'INSERT INTO translation_job_items (job_id, page_id, page_title, status, sort_order)
                 VALUES (?, ?, ?, ?, ?)',
                [$jobId, (int) $page['id'], mb_substr((string) $page['title'], 0, 255), 'pending', $order++]
            );
        }

        return ['ok' => true, 'job_id' => $jobId, 'count' => count($pages)];
    }

    /**
     * Traduce UNA página pendiente y devuelve el estado del trabajo.
     *
     * @return array{ok:bool, error?:string, job?:array<string,mixed>}
     */
    public static function stepJob(int $jobId, int $siteId): array
    {
        $job = Database::selectOne(
            'SELECT * FROM translation_jobs WHERE id = ? AND site_id = ? LIMIT 1',
            [$jobId, $siteId]
        );
        if (!$job) {
            return ['ok' => false, 'error' => 'No hemos encontrado ese trabajo de traducción.'];
        }
        if ($job['status'] === 'done') {
            return ['ok' => true, 'job' => self::jobState($jobId, $siteId)];
        }

        $item = Database::selectOne(
            'SELECT * FROM translation_job_items WHERE job_id = ? AND status = "pending" ORDER BY sort_order ASC LIMIT 1',
            [$jobId]
        );
        if (!$item) {
            Database::execute('UPDATE translation_jobs SET status = "done" WHERE id = ?', [$jobId]);
            return ['ok' => true, 'job' => self::jobState($jobId, $siteId)];
        }

        if ($job['status'] === 'pending') {
            Database::execute('UPDATE translation_jobs SET status = "running" WHERE id = ?', [$jobId]);
        }
        Database::execute('UPDATE translation_job_items SET status = "running" WHERE id = ?', [$item['id']]);

        $targetLang = (string) $job['target_lang'];
        $page = Database::selectOne(
            'SELECT * FROM pages WHERE id = ? AND site_id = ? LIMIT 1',
            [(int) $item['page_id'], $siteId]
        );

        try {
            if ($page === null) {
                throw new \RuntimeException('Esta página ya no existe.');
            }

            // Puede haberse traducido entre medias (a mano, o en otro trabajo).
            $blocked = TranslationWriter::precheck($siteId, $page, $targetLang);
            if ($blocked !== null) {
                Database::execute(
                    'UPDATE translation_job_items SET status = "skipped", new_page_id = ?, error = ? WHERE id = ?',
                    [$blocked['page_id'] ?? null, mb_substr((string) $blocked['message'], 0, 1000), $item['id']]
                );
            } else {
                $outcome = self::translateWithRetry($siteId, $page, $targetLang);
                if ($outcome['ok']) {
                    Database::execute(
                        'UPDATE translation_job_items SET status = "done", new_page_id = ?, error = NULL WHERE id = ?',
                        [(int) $outcome['page_id'], $item['id']]
                    );
                } else {
                    Database::execute(
                        'UPDATE translation_job_items SET status = "failed", error = ? WHERE id = ?',
                        [mb_substr((string) $outcome['message'], 0, 1000), $item['id']]
                    );
                }
            }
        } catch (\Throwable $e) {
            error_log('[translation job] job=' . $jobId . ' item=' . $item['id'] . ' ' . get_class($e) . ': ' . $e->getMessage());
            Database::execute(
                'UPDATE translation_job_items SET status = "failed", error = ? WHERE id = ?',
                ['No hemos podido traducir esta página. No se ha creado nada para ella.', $item['id']]
            );
        }

        $pending = Database::selectOne(
            'SELECT 1 FROM translation_job_items WHERE job_id = ? AND status = "pending" LIMIT 1',
            [$jobId]
        );
        if ($pending === null) {
            Database::execute('UPDATE translation_jobs SET status = "done" WHERE id = ?', [$jobId]);
        }

        return ['ok' => true, 'job' => self::jobState($jobId, $siteId)];
    }

    /**
     * Estado completo del trabajo, listo para pintar en el panel.
     *
     * @return array<string,mixed>|null
     */
    public static function jobState(int $jobId, int $siteId): ?array
    {
        $job = Database::selectOne(
            'SELECT * FROM translation_jobs WHERE id = ? AND site_id = ? LIMIT 1',
            [$jobId, $siteId]
        );
        if (!$job) {
            return null;
        }

        $items = Database::select(
            'SELECT id, page_id, page_title, status, new_page_id, error
             FROM translation_job_items WHERE job_id = ? ORDER BY sort_order ASC',
            [$jobId]
        );

        $counts = ['pending' => 0, 'running' => 0, 'done' => 0, 'failed' => 0, 'skipped' => 0];
        foreach ($items as $item) {
            $status = (string) $item['status'];
            if (isset($counts[$status])) {
                $counts[$status]++;
            }
        }

        return [
            'id'          => (int) $job['id'],
            'status'      => (string) $job['status'],
            'target_lang' => (string) $job['target_lang'],
            'language'    => LanguageService::label((string) $job['target_lang']),
            'counts'      => $counts,
            'total'       => count($items),
            'items'       => array_map(static fn (array $i): array => [
                'page_id'     => (int) $i['page_id'],
                'title'       => (string) $i['page_title'],
                'status'      => (string) $i['status'],
                'new_page_id' => $i['new_page_id'] !== null ? (int) $i['new_page_id'] : null,
                'error'       => $i['error'] !== null ? (string) $i['error'] : null,
            ], $items),
        ];
    }

    // =====================================================================
    // Internos
    // =====================================================================

    /**
     * Traduce y guarda una página, con UN reintento si el fallo es transitorio.
     *
     * Mismo criterio que el asistente: timeout/red (status 0), rate limit (429)
     * y 5xx del proveedor se reintentan una vez; un fallo de contenido, no.
     *
     * @param array<string,mixed> $page
     * @return array{ok:bool, page_id?:int, message?:string}
     */
    private static function translateWithRetry(int $siteId, array $page, string $targetLang): array
    {
        $isCanvas = ((string) ($page['render_mode'] ?? 'sections')) === 'canvas';

        for ($attempt = 1; $attempt <= 2; $attempt++) {
            try {
                $translation = $isCanvas
                    ? PageTranslator::translateCanvas($siteId, $page, $targetLang)
                    : PageTranslator::translateSections($siteId, $page, $targetLang);

                if (!($translation['ok'] ?? false)) {
                    return ['ok' => false, 'message' => (string) ($translation['message'] ?? 'No se pudo traducir.')];
                }

                $saved = $isCanvas
                    ? TranslationWriter::createCanvas($siteId, $page, $targetLang, $translation)
                    : TranslationWriter::createSections($siteId, $page, $targetLang, $translation);

                if (!($saved['ok'] ?? false)) {
                    return ['ok' => false, 'message' => (string) ($saved['message'] ?? 'No se pudo guardar.')];
                }
                return ['ok' => true, 'page_id' => (int) $saved['page_id']];
            } catch (AIException $e) {
                $status = $e->getHttpStatus();
                $transient = $status === 0 || $status === 429 || $status >= 500;
                if (!$transient || $attempt === 2) {
                    return [
                        'ok' => false,
                        'message' => 'La traducción de esta página no llegó a completarse. '
                            . 'No se ha creado nada para ella; puedes traducirla suelta más tarde.',
                    ];
                }
                sleep(2);
            }
        }

        return ['ok' => false, 'message' => 'No hemos podido traducir esta página.'];
    }
}
