<?php

declare(strict_types=1);

namespace App\Modules\Resources;

use App\Modules\ModuleRegistry;
use App\Services\LanguageService;
use Core\Response;
use Core\Request;
use Core\Session;

/** Endpoint público de descarga. R3 registrará y activará sus rutas. */
final class ResourceDownloadController
{
    public function direct(array $params = []): void
    {
        Session::close();
        $siteId = ModuleRegistry::resolveSiteId();
        if ($siteId === null) Response::notFound();

        $rawLanguage = trim((string) ($params['lang'] ?? ''));
        $language = $rawLanguage !== '' ? LanguageService::normalize($rawLanguage) : LanguageService::primaryFor($siteId);
        if (($rawLanguage !== '' && !LanguageService::isSupported($rawLanguage))
            || !ResourceStore::languageAvailableForSite($siteId, $language)) {
            Response::notFound();
        }

        $slug = trim((string) ($params['slug'] ?? ''), '/');
        $token = Request::get('token');
        $prepared = is_string($token) && $token !== ''
            ? ResourceFileService::prepareConditionedDownload($siteId, $language, $slug, $token)
            : ResourceFileService::prepareDirectDownload($siteId, $language, $slug);
        if ($prepared === null) Response::notFound();

        // R7 — conversión únicamente cuando el archivo ya está validado y se
        // va a servir. La etiqueta es lógica y estable: jamás incluye query,
        // token firmado, email ni ningún dato capturado por el formulario.
        if (ModuleRegistry::isEnabled($siteId, 'analytics')) {
            \App\Modules\Analytics\EventRecorder::record(
                $siteId,
                'resource_download',
                '/recursos/' . $slug . '/descargar',
                null,
                Request::ip(),
                Request::userAgent()
            );
        }
        ResourceFileService::stream($prepared);
    }
}
