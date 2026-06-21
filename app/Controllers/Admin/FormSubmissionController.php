<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Services\FormSubmissionService;
use Core\Auth;
use Core\CSRF;
use Core\Database;
use Core\Request;
use Core\Response;
use Core\Session;
use Core\View;

final class FormSubmissionController
{
    private const PAGE_SIZE = 25;

    public function index(array $params = []): void
    {
        $siteId = $this->requireSiteId();
        FormSubmissionService::ensureSchema();

        $filters = [
            'q' => mb_substr(trim((string) Request::get('q', '')), 0, 120),
            'status' => (string) Request::get('status', ''),
            'form_id' => max(0, (int) Request::get('form_id', 0)),
            'page_id' => max(0, (int) Request::get('page_id', 0)),
            'period' => (string) Request::get('period', ''),
            'delivery' => (string) Request::get('delivery', ''),
            'email_status' => (string) Request::get('email_status', ''),
            'autoresponder_status' => (string) Request::get('autoresponder_status', ''),
            'date_from' => (string) Request::get('date_from', ''),
            'date_to' => (string) Request::get('date_to', ''),
        ];
        $where = ['fs.site_id = ?'];
        $queryParams = [$siteId];
        if ($filters['q'] !== '') {
            $where[] = '(fs.sender_name LIKE ? OR fs.sender_email LIKE ? OR fs.sender_phone LIKE ? OR CAST(fs.payload AS CHAR) LIKE ?)';
            $like = '%' . $filters['q'] . '%';
            array_push($queryParams, $like, $like, $like, $like);
        }
        if (in_array($filters['status'], ['unread', 'read'], true)) {
            $where[] = 'fs.status = ?'; $queryParams[] = $filters['status'];
        }
        if ($filters['form_id'] > 0) {
            $where[] = 'fs.section_id = ?'; $queryParams[] = $filters['form_id'];
        }
        if ($filters['page_id'] > 0) {
            $where[] = 'fs.page_id = ?'; $queryParams[] = $filters['page_id'];
        }
        if (in_array($filters['delivery'], ['issues', 'sent', 'autoresponder_off'], true)) {
            if ($filters['delivery'] === 'issues') {
                $where[] = "(fs.email_status = 'failed' OR fs.autoresponder_status = 'failed')";
            } elseif ($filters['delivery'] === 'sent') {
                $where[] = "(fs.email_status = 'sent' AND fs.autoresponder_status = 'sent')";
            } else {
                $where[] = "fs.autoresponder_status = 'disabled'";
            }
        } else {
            // Compatibilidad con URLs anteriores al filtro semantico.
            if (in_array($filters['email_status'], ['skipped', 'sent', 'failed'], true)) {
                $where[] = 'fs.email_status = ?'; $queryParams[] = $filters['email_status'];
            }
            if (in_array($filters['autoresponder_status'], ['unknown', 'disabled', 'skipped', 'sent', 'failed'], true)) {
                $where[] = 'fs.autoresponder_status = ?'; $queryParams[] = $filters['autoresponder_status'];
            }
        }
        if (in_array($filters['period'], ['7', '30', '90'], true)) {
            $where[] = 'fs.created_at >= DATE_SUB(NOW(), INTERVAL ' . (int) $filters['period'] . ' DAY)';
        } else {
            // period=custom y URLs legacy usan las fechas explicitas.
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $filters['date_from'])) {
                $where[] = 'fs.created_at >= ?'; $queryParams[] = $filters['date_from'] . ' 00:00:00';
            }
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $filters['date_to'])) {
                $where[] = 'fs.created_at <= ?'; $queryParams[] = $filters['date_to'] . ' 23:59:59';
            }
        }
        $whereSql = implode(' AND ', $where);
        $total = (int) (Database::selectOne(
            'SELECT COUNT(*) AS n FROM form_submissions fs WHERE ' . $whereSql,
            $queryParams
        )['n'] ?? 0);
        $totalPages = max(1, (int) ceil($total / self::PAGE_SIZE));
        $page = max(1, min($totalPages, (int) Request::get('page', 1)));
        $offset = ($page - 1) * self::PAGE_SIZE;

        $rows = Database::select(
            'SELECT fs.*, p.slug, p.render_mode, fp.source_label
             FROM form_submissions fs
             JOIN pages p ON p.id = fs.page_id
             LEFT JOIN form_placements fp ON fp.form_id = fs.section_id AND fp.page_id = fs.page_id
             WHERE ' . $whereSql . '
             ORDER BY fs.created_at DESC
             LIMIT ' . self::PAGE_SIZE . ' OFFSET ' . $offset,
            $queryParams
        );
        $metrics = Database::selectOne(
            "SELECT COUNT(*) AS total,
                    SUM(status = 'unread') AS unread,
                    SUM(created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)) AS recent,
                    SUM(email_status = 'failed' OR autoresponder_status = 'failed') AS mail_errors
             FROM form_submissions WHERE site_id = ?",
            [$siteId]
        ) ?? [];
        $formRows = Database::select(
            "SELECT ps.id, ps.content FROM page_sections ps JOIN pages p ON p.id = ps.page_id
             WHERE p.site_id = ? AND ps.section_type = 'form' AND ps.status != 'deleted' ORDER BY ps.id",
            [$siteId]
        );
        $forms = [];
        foreach ($formRows as $formRow) {
            $content = json_decode((string) $formRow['content'], true) ?: [];
            $forms[] = ['id' => (int) $formRow['id'], 'heading' => (string) ($content['heading'] ?? 'Formulario')];
        }
        $originPages = Database::select(
            'SELECT DISTINCT p.id, p.title FROM form_submissions fs JOIN pages p ON p.id = fs.page_id WHERE fs.site_id = ? ORDER BY p.title',
            [$siteId]
        );

        View::send('admin/forms/index', array_merge(
            DashboardController::getCommonData(),
            [
                'submissions' => $rows,
                'filters' => $filters,
                'metrics' => $metrics,
                'forms' => $forms,
                'originPages' => $originPages,
                'page' => $page,
                'total' => $total,
                'totalPages' => $totalPages,
                'csrf' => CSRF::token(),
            ]
        ));
    }

    public function markRead(array $params = []): void
    {
        CSRF::check();
        $siteId = $this->requireSiteId();
        FormSubmissionService::ensureSchema();
        $id = (int) ($params['id'] ?? 0);

        Database::execute(
            'UPDATE form_submissions SET status = ?, read_at = ? WHERE id = ? AND site_id = ?',
            ['read', date('Y-m-d H:i:s'), $id, $siteId]
        );

        Session::flash('success', 'Mensaje marcado como leído.');
        Response::redirect(base_url('admin/forms'));
    }

    public function destroy(array $params = []): void
    {
        CSRF::check();
        $siteId = $this->requireSiteId();
        FormSubmissionService::ensureSchema();
        $id = (int) ($params['id'] ?? 0);

        $row = Database::selectOne(
            'SELECT payload FROM form_submissions WHERE id = ? AND site_id = ? LIMIT 1',
            [$id, $siteId]
        );
        $payload = json_decode((string) ($row['payload'] ?? '{}'), true);
        if (is_array($payload)) {
            FormSubmissionService::deleteFilesFromPayload($payload);
        }

        Database::execute(
            'DELETE FROM form_submissions WHERE id = ? AND site_id = ?',
            [$id, $siteId]
        );

        Session::flash('success', 'Mensaje eliminado.');
        Response::redirect(base_url('admin/forms'));
    }

    public function downloadFile(array $params = []): void
    {
        $siteId = $this->requireSiteId();
        FormSubmissionService::ensureSchema();
        $id = (int) ($params['id'] ?? 0);
        $key = (string) ($params['key'] ?? '');

        $row = Database::selectOne(
            'SELECT payload FROM form_submissions WHERE id = ? AND site_id = ? LIMIT 1',
            [$id, $siteId]
        );
        if ($row === null) {
            Response::notFound('Archivo no encontrado');
        }

        $payload = json_decode((string) ($row['payload'] ?? '{}'), true);
        $payload = is_array($payload) ? $payload : [];
        $file = $payload[$key] ?? null;
        if (!is_array($file) || ($file['type'] ?? '') !== 'file') {
            foreach ($payload as $value) {
                if (is_array($value) && ($value['type'] ?? '') === 'file' && (string) ($value['field_name'] ?? '') === $key) {
                    $file = $value;
                    break;
                }
            }
        }
        if (!is_array($file) || ($file['type'] ?? '') !== 'file') {
            Response::notFound('Archivo no encontrado');
        }

        $path = FormSubmissionService::safeStoredPath((string) ($file['path'] ?? ''));
        if ($path === null || !is_file($path)) {
            Response::notFound('Archivo no encontrado');
        }

        $name = basename((string) ($file['original_name'] ?? 'archivo'));
        $mime = (string) ($file['mime'] ?? 'application/octet-stream');
        header('Content-Type: ' . ($mime !== '' ? $mime : 'application/octet-stream'));
        header('Content-Length: ' . (string) filesize($path));
        header('Content-Disposition: attachment; filename="' . addcslashes($name, "\\\"") . '"');
        header('X-Content-Type-Options: nosniff');
        readfile($path);
        exit;
    }

    private function requireSiteId(): int
    {
        $siteId = Auth::siteId();
        if ($siteId === null) {
            Session::flash('error', 'No hay sitio activo.');
            Response::redirect(base_url('admin/'));
        }
        return $siteId;
    }
}
