<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\AI\AIException;
use App\Services\Canvas\CanvasChatService;
use Core\Database;

/**
 * FEAT-5 F5-T4 — Ejecución de un plan confirmado del asistente central.
 *
 * Modelo de ejecución: sin colas ni cron. El navegador llama en bucle a
 * "ejecutar siguiente item" (stepJob) hasta que no quedan pendientes; cada
 * paso es UNA llamada IA sobre UNA página, reutilizando CanvasChatService.
 * Un fallo en un item lo marca failed y NO detiene los siguientes.
 *
 * Todo queda como versión draft en cada página (origin 'assistant'):
 * publicar/deshacer se hace con los mecanismos ya existentes del Studio.
 */
final class SiteAssistantJobs
{
    /** Máximo de items aplicables por job (control de coste/duración). */
    public const MAX_ITEMS = 12;

    /**
     * Crea un job con sus items (solo los 'aplicar' del plan, re-validados).
     *
     * @param array<int,array<string,mixed>> $items Items del plan (status aplicar)
     * @return array{ok:bool, error?:string, job?:array<string,mixed>}
     */
    public static function createJob(int $siteId, string $requestText, string $summary, array $items, ?int $userId): array
    {
        $pages = SiteAssistantPlanner::sitePages($siteId);

        $clean = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $pageId      = (int) ($item['page_id'] ?? 0);
            $instruction = trim((string) ($item['instruction'] ?? ''));
            $section     = trim((string) ($item['section'] ?? ''));
            $page        = $pages[$pageId] ?? null;

            // Solo se ejecuta lo que sigue siendo válido AHORA: página editable
            // e instrucción no vacía. Lo demás se ignora en silencio (el plan
            // ya se lo explicó al usuario; aquí solo llega lo confirmado).
            if ($page === null || !$page['editable'] || $instruction === '' || mb_strlen($instruction) > 4000) {
                continue;
            }
            if ($section !== '' && !in_array($section, (array) $page['sections'], true)) {
                $section = '';
            }
            $clean[] = [
                'page_id'     => $pageId,
                'page_title'  => (string) $page['title'],
                'section'     => $section,
                'instruction' => $instruction,
            ];
            if (count($clean) >= self::MAX_ITEMS) {
                break;
            }
        }

        if ($clean === []) {
            return ['ok' => false, 'error' => 'No hay ningún cambio aplicable en este plan.'];
        }

        Database::execute(
            'INSERT INTO assistant_jobs (site_id, request_text, summary, status, created_by) VALUES (?, ?, ?, "pending", ?)',
            [$siteId, mb_substr($requestText, 0, 4000), mb_substr($summary, 0, 2000), $userId]
        );
        $jobId = (int) Database::lastInsertId();

        foreach ($clean as $i => $item) {
            Database::execute(
                'INSERT INTO assistant_job_items (job_id, page_id, page_title, section, instruction, sort_order) VALUES (?, ?, ?, ?, ?, ?)',
                [$jobId, $item['page_id'], $item['page_title'], $item['section'], $item['instruction'], $i]
            );
        }

        return ['ok' => true, 'job' => self::jobState($jobId, $siteId)];
    }

    /**
     * Ejecuta el siguiente item pendiente del job. Devuelve el estado completo
     * tras el paso. Si no quedan pendientes, marca el job como done.
     *
     * @return array{ok:bool, error?:string, job?:array<string,mixed>}
     */
    public static function stepJob(int $jobId, int $siteId): array
    {
        $job = Database::selectOne(
            'SELECT * FROM assistant_jobs WHERE id = ? AND site_id = ? LIMIT 1',
            [$jobId, $siteId]
        );
        if (!$job) {
            return ['ok' => false, 'error' => 'Trabajo no encontrado.'];
        }
        if ($job['status'] === 'done') {
            return ['ok' => true, 'job' => self::jobState($jobId, $siteId)];
        }

        $item = Database::selectOne(
            'SELECT * FROM assistant_job_items WHERE job_id = ? AND status = "pending" ORDER BY sort_order ASC LIMIT 1',
            [$jobId]
        );
        if (!$item) {
            Database::execute('UPDATE assistant_jobs SET status = "done" WHERE id = ?', [$jobId]);
            return ['ok' => true, 'job' => self::jobState($jobId, $siteId)];
        }

        if ($job['status'] === 'pending') {
            Database::execute('UPDATE assistant_jobs SET status = "running" WHERE id = ?', [$jobId]);
        }
        Database::execute('UPDATE assistant_job_items SET status = "running" WHERE id = ?', [$item['id']]);

        $page = Database::selectOne(
            "SELECT * FROM pages WHERE id = ? AND site_id = ? AND render_mode = 'canvas' LIMIT 1",
            [(int) $item['page_id'], $siteId]
        );

        try {
            if ($page === null) {
                throw new \RuntimeException('La página ya no existe o dejó de ser editable.');
            }
            $outcome = CanvasChatService::applyInstruction(
                $siteId,
                $page,
                (string) $item['instruction'],
                (string) $item['section'],
                '',
                'assistant',
                'Asistente'
            );
            if ($outcome['ok']) {
                Database::execute(
                    'UPDATE assistant_job_items SET status = "done", reply = ?, error = NULL WHERE id = ?',
                    [mb_substr((string) ($outcome['reply'] ?? ''), 0, 1000), $item['id']]
                );
            } else {
                Database::execute(
                    'UPDATE assistant_job_items SET status = "failed", error = ? WHERE id = ?',
                    [mb_substr((string) ($outcome['error'] ?? 'Error desconocido.'), 0, 1000), $item['id']]
                );
            }
        } catch (AIException $e) {
            error_log('[assistant job] job=' . $jobId . ' item=' . $item['id'] . ' ai status=' . $e->getHttpStatus() . ': ' . $e->getMessage());
            Database::execute(
                'UPDATE assistant_job_items SET status = "failed", error = ? WHERE id = ?',
                ['La IA no devolvió un cambio válido para esta página. La página no ha cambiado.', $item['id']]
            );
        } catch (\Throwable $e) {
            error_log('[assistant job] job=' . $jobId . ' item=' . $item['id'] . ' ' . get_class($e) . ': ' . $e->getMessage());
            Database::execute(
                'UPDATE assistant_job_items SET status = "failed", error = ? WHERE id = ?',
                [mb_substr('No se pudo aplicar: ' . $e->getMessage(), 0, 1000), $item['id']]
            );
        }

        // ¿Quedan pendientes? Si no, cerrar el job.
        $pending = Database::selectOne(
            'SELECT 1 FROM assistant_job_items WHERE job_id = ? AND status = "pending" LIMIT 1',
            [$jobId]
        );
        if ($pending === null) {
            Database::execute('UPDATE assistant_jobs SET status = "done" WHERE id = ?', [$jobId]);
        }

        return ['ok' => true, 'job' => self::jobState($jobId, $siteId)];
    }

    /**
     * Estado completo del job para la UI.
     *
     * @return array<string,mixed>|null
     */
    public static function jobState(int $jobId, int $siteId): ?array
    {
        $job = Database::selectOne(
            'SELECT id, status, summary, request_text FROM assistant_jobs WHERE id = ? AND site_id = ? LIMIT 1',
            [$jobId, $siteId]
        );
        if (!$job) {
            return null;
        }
        $items = Database::select(
            'SELECT id, page_id, page_title, section, instruction, status, reply, error
             FROM assistant_job_items WHERE job_id = ? ORDER BY sort_order ASC',
            [$jobId]
        );
        $total = count($items);
        $doneCount = 0;
        foreach ($items as &$it) {
            if (in_array($it['status'], ['done', 'failed'], true)) {
                $doneCount++;
            }
            $it['page_id'] = (int) $it['page_id'];
            $it['id'] = (int) $it['id'];
        }
        unset($it);

        return [
            'id'        => (int) $job['id'],
            'status'    => (string) $job['status'],
            'summary'   => (string) ($job['summary'] ?? ''),
            'total'     => $total,
            'completed' => $doneCount,
            'items'     => $items,
        ];
    }
}
