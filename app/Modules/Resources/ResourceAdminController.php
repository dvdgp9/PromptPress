<?php

declare(strict_types=1);

namespace App\Modules\Resources;

use App\Services\FormStore;
use App\Services\FormTemplates;
use App\Services\LanguageService;
use App\Services\Microcopy;
use App\Services\Permissions;
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

    /**
     * RSRC-FORM — Crear aquí mismo el formulario que abre la descarga.
     *
     * Antes, el paso 03 ofrecía un enlace a /admin/formularios. Y el editor de
     * recursos es un POST clásico con `multipart/form-data` sin autoguardado ni
     * aviso de cambios sin guardar: salir por ese enlace se llevaba por delante
     * el título, la descripción y —lo peor— el archivo ya elegido en el paso
     * 02, que un `<input type="file">` no puede recuperar al volver. O sea que
     * el flujo empujaba a perder trabajo justo donde más se había invertido.
     *
     * El formulario se crea y se devuelve, pero NO se engancha al recurso desde
     * aquí: eso lo hace el guardado del editor, como todo lo demás. Enganchar
     * ahora significaría que un recurso ya publicado con descarga directa
     * pasara a exigir formulario sin que nadie haya pulsado Guardar.
     */
    public function createForm(array $params = []): void
    {
        CSRF::check();
        $siteId = $this->requireSiteId();

        // El guard del router protege esta ruta con `content`, que es lo que
        // hace falta para editar un recurso. Crear formularios es otra
        // capacidad distinta (`forms`), y un redactor tiene la primera pero no
        // la segunda: por eso aquí se pregunta, y no es una comprobación
        // repetida.
        if (!Auth::can(Permissions::CAP_FORMS)) {
            Response::json(['ok' => false, 'error' => __('common.access_denied')], 403);
        }

        $resource = ResourceStore::find($siteId, (int) ($params['id'] ?? 0));
        if ($resource === null) {
            Response::json(['ok' => false, 'error' => __('resource.admin.err.not_found')], 404);
        }

        // FORMS-LANG — nace en el idioma PRINCIPAL del sitio, igual que
        // cualquier formulario creado desde plantilla.
        $lang = LanguageService::primaryFor($siteId);
        $content = FormTemplates::content('download', $lang);

        // El encabezado lleva el nombre del recurso: quien lo vea en la lista
        // de formularios tiene que saber de cuál es la puerta sin abrirlo.
        $title = trim((string) ($resource['title'] ?? ''));
        if ($title !== '') {
            $content['heading'] = Microcopy::t('form.tpl.download.heading_for', $lang, ['recurso' => $title]);
        }

        $formId = FormStore::create($siteId, $content);

        Response::json([
            'ok'   => true,
            'form' => [
                'id'      => $formId,
                'heading' => (string) $content['heading'],
                'editUrl' => base_url('admin/formularios/' . $formId),
            ],
        ]);
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
