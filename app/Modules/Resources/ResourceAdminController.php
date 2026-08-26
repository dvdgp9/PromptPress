<?php

declare(strict_types=1);

namespace App\Modules\Resources;

use App\Services\FormStore;
use App\Services\LanguageService;
use Core\Auth;
use Core\CSRF;
use Core\Request;
use Core\Response;
use Core\Session;
use Core\View;

/** Administración de ebooks y recursos descargables (R3). */
final class ResourceAdminController
{
    public function index(): void
    {
        $siteId = $this->requireSiteId();
        View::send('admin/resources/index', [
            'resources' => ResourceStore::all($siteId),
            'notice'    => Session::flash('notice'),
            'error'     => Session::flash('error'),
            'csrf'      => CSRF::token(),
        ]);
    }

    /** Alta rápida: crea siempre un borrador y lleva al editor. */
    public function create(): void
    {
        CSRF::check();
        $siteId = $this->requireSiteId();
        $title = trim((string) Request::post('title', ''));
        if ($title === '') {
            Session::flash('error', __('resource.admin.err.title'));
            Response::redirect(base_url('admin/resources'));
        }

        try {
            $id = ResourceStore::create($siteId, ['title' => $title, 'status' => 'draft']);
        } catch (\Throwable $e) {
            Session::flash('error', $e->getMessage());
            Response::redirect(base_url('admin/resources'));
        }
        Session::flash('notice', __('resource.admin.ok.created'));
        Response::redirect(base_url('admin/resources/' . $id));
    }

    public function edit(array $params = []): void
    {
        $siteId = $this->requireSiteId();
        $resource = ResourceStore::find($siteId, (int) ($params['id'] ?? 0));
        if ($resource === null) {
            Session::flash('error', __('resource.admin.err.not_found'));
            Response::redirect(base_url('admin/resources'));
        }
        $this->renderEditor($siteId, $resource, []);
    }

    public function update(array $params = []): void
    {
        CSRF::check();
        $siteId = $this->requireSiteId();
        $id = (int) ($params['id'] ?? 0);
        $resource = ResourceStore::find($siteId, $id);
        if ($resource === null) {
            Session::flash('error', __('resource.admin.err.not_found'));
            Response::redirect(base_url('admin/resources'));
        }

        $fields = [
            'title'          => Request::post('title', ''),
            'description'    => Request::post('description', ''),
            'category'       => Request::post('category', ''),
            'cover_media_id' => Request::post('cover_media_id', ''),
            // R8 — `language` sigue siendo el idioma base de la ficha; la
            // disponibilidad puede abarcar varios o todos los idiomas.
            'language'       => (string) $resource['language'],
            'language_scope' => Request::post('language_scope', 'selected'),
            'languages'      => Request::post('languages', []),
            'access_mode'    => Request::post('access_mode', 'direct'),
            'form_id'        => Request::post('form_id', ''),
            'status'         => Request::post('status', 'draft'),
        ];
        $errors = [];

        $upload = Request::file('resource_file');
        if (is_array($upload) && (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            try {
                ResourceFileService::storeUpload($siteId, $id, $upload);
                $resource = ResourceStore::find($siteId, $id) ?? $resource;
            } catch (\Throwable $e) {
                $errors[] = $e->getMessage();
            }
        }

        if ($errors === []) {
            try {
                ResourceStore::update($siteId, $id, $fields);
            } catch (\Throwable $e) {
                $errors[] = $e->getMessage();
            }
        }

        if ($errors !== []) {
            // Conserva lo escrito para corregirlo sin perder el formulario.
            $draft = array_merge($resource, $fields, ['id' => $id]);
            $this->renderEditor($siteId, $draft, $errors);
            return;
        }

        Session::flash('notice', (string) $fields['status'] === 'published'
            ? __('resource.admin.ok.published')
            : __('resource.admin.ok.saved'));
        Response::redirect(base_url('admin/resources/' . $id));
    }

    public function destroy(array $params = []): void
    {
        CSRF::check();
        $siteId = $this->requireSiteId();
        if (ResourceFileService::deleteFileAndResource($siteId, (int) ($params['id'] ?? 0))) {
            Session::flash('notice', __('resource.admin.ok.deleted'));
        } else {
            Session::flash('error', __('resource.admin.err.delete'));
        }
        Response::redirect(base_url('admin/resources'));
    }

    /** @return string[] claves i18n que la UI explica antes de publicar. */
    public static function publicationIssues(array $resource): array
    {
        $issues = [];
        if (empty($resource['file_path']) || empty($resource['original_filename'])
            || empty($resource['file_mime']) || (int) ($resource['file_size'] ?? 0) <= 0) {
            $issues[] = 'resource.publish_issue.file';
        }
        if ((string) ($resource['access_mode'] ?? 'direct') === 'form'
            && (int) ($resource['form_id'] ?? 0) <= 0) {
            $issues[] = 'resource.publish_issue.form';
        }
        return $issues;
    }

    /** @param string[] $errors */
    private function renderEditor(int $siteId, array $resource, array $errors): void
    {
        View::send('admin/resources/edit', [
            'resource'          => $resource,
            'forms'             => FormStore::all($siteId),
            'languages'         => LanguageService::activeFor($siteId),
            'publicationIssues' => self::publicationIssues($resource),
            'maxUploadBytes'     => ResourceFileService::effectiveMaxSize(),
            'errors'            => $errors,
            'notice'            => Session::flash('notice'),
            'csrf'              => CSRF::token(),
        ]);
    }

    private function requireSiteId(): int
    {
        $siteId = Auth::siteId();
        if ($siteId === null) Response::forbidden();
        return $siteId;
    }
}
