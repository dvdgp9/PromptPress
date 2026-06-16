<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Services\FormSubmissionService;
use Core\Auth;
use Core\CSRF;
use Core\Database;
use Core\Response;
use Core\Session;
use Core\View;

final class FormSubmissionController
{
    public function index(array $params = []): void
    {
        $siteId = $this->requireSiteId();
        FormSubmissionService::ensureSchema();

        $rows = Database::select(
            'SELECT fs.*, p.slug
             FROM form_submissions fs
             JOIN pages p ON p.id = fs.page_id
             WHERE fs.site_id = ?
             ORDER BY fs.created_at DESC
             LIMIT 100',
            [$siteId]
        );

        View::send('admin/forms/index', array_merge(
            DashboardController::getCommonData(),
            [
                'submissions' => $rows,
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

        Database::execute(
            'DELETE FROM form_submissions WHERE id = ? AND site_id = ?',
            [$id, $siteId]
        );

        Session::flash('success', 'Mensaje eliminado.');
        Response::redirect(base_url('admin/forms'));
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
