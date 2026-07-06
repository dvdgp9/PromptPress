<?php
/**
 * Definición de rutas de PromptPress.
 *
 * Este archivo se incluye dentro de App::run() con $router en scope.
 *
 * @var \Core\Router $router
 */

use App\Controllers\Admin\AITestController;
use App\Controllers\Admin\AIUsageController;
use App\Controllers\Admin\AuthController;
use App\Controllers\Admin\DashboardController;
use App\Controllers\Admin\ChromeController;
use App\Controllers\Admin\DesignController;
use App\Controllers\Admin\SettingsAIController;
use App\Controllers\Admin\MailSettingsController;
use App\Services\DesignSystem;
use App\Controllers\Admin\DocumentController;
use App\Controllers\Admin\FormSubmissionController;
use App\Controllers\Admin\FormsController;
use App\Controllers\Admin\MediaController;
use App\Controllers\Admin\MemoryController;
use App\Controllers\Admin\ModulesController;
use App\Controllers\Admin\OnboardingController;
use App\Controllers\Admin\CanvasController;
use App\Controllers\Admin\PageController;
use App\Controllers\Admin\LinkController;
use App\Controllers\Admin\PostController;
use App\Controllers\Admin\SectionController;
use App\Controllers\Admin\SeoController;
use App\Controllers\Admin\PrivacyController;
use App\Controllers\Admin\PrivacyWizardController;
use App\Controllers\Admin\MarketingController;
use App\Controllers\Admin\SettingsController;
use App\Controllers\Public\FormController as PublicFormController;
use App\Controllers\Public\PageController as PublicPageController;
use App\Controllers\Public\SeoController as PublicSeoController;
use App\Controllers\Public\BrandAssetController;

// Hoja de estilos pública generada desde el design system (T5.3)
$router->get('/design.css', function () {
    $site = \Core\Database::selectOne('SELECT id FROM sites ORDER BY id ASC LIMIT 1');
    $siteId = $site ? (int) $site['id'] : 0;
    $tokens = DesignSystem::load($siteId);
    $css = "/* PromptPress — design system tokens (site={$siteId}) */\n"
         . DesignSystem::renderCssVars($tokens)
         . "\n" . DesignSystem::renderSectionBaseCss();
    header('Content-Type: text/css; charset=UTF-8');
    header('Cache-Control: public, max-age=60');
    echo $css;
});

// Home pública (T7.2) — renderiza la página publicada con page_type=home
$router->get('/', [PublicPageController::class, 'home']);
$router->get('/sitemap.xml', [PublicSeoController::class, 'sitemap']);
$router->get('/robots.txt', [PublicSeoController::class, 'robots']);
$router->get('/brand-assets/{site}/logo', [BrandAssetController::class, 'logo']);

// Home pública (fallback demo) — conservado para instalación nueva sin home
$router->get('/__demo', function () {
    if (!is_installed()) {
        \Core\Response::html(
            '<!doctype html><meta charset="utf-8"><title>PromptPress</title>'
            . '<div style="font-family:system-ui;padding:2rem;max-width:640px;margin:auto">'
            . '<h1>PromptPress</h1>'
            . '<p>Sistema no instalado. <a href="' . e(base_url('install/')) . '">Instalar</a></p></div>'
        );
        return;
    }
    $site = \Core\Database::selectOne('SELECT id, name FROM sites ORDER BY id ASC LIMIT 1');
    $siteId = $site ? (int) $site['id'] : 0;
    $siteName = $site['name'] ?? 'PromptPress';
    $designHead = DesignSystem::renderHead($siteId);
    $adminUrl = e(base_url('admin/'));

    \Core\Response::html(
        '<!doctype html>'
        . '<html lang="es"><head>'
        . '<meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>' . e($siteName) . '</title>'
        . $designHead
        . '<style>'
        . 'html,body{margin:0;padding:0;background:var(--pp-bg);color:var(--pp-text);font-family:var(--pp-font-body);font-size:var(--pp-font-size-base);line-height:var(--pp-line-height);font-weight:var(--pp-weight-regular)}'
        . '.container{max-width:var(--pp-container-max);margin:0 auto;padding:var(--pp-section-y) 24px}'
        . 'h1{font-family:var(--pp-font-heading);font-weight:var(--pp-weight-bold);font-size:calc(var(--pp-font-size-base) * var(--pp-font-scale) * var(--pp-font-scale) * var(--pp-font-scale));line-height:1.1;margin:0 0 16px}'
        . '.lead{color:var(--pp-text-muted);max-width:48em;margin-bottom:24px}'
        . '.btn{display:inline-block;background:var(--pp-primary);color:#fff;padding:var(--pp-btn-padding-y) var(--pp-btn-padding-x);border-radius:var(--pp-btn-radius);font-weight:var(--pp-btn-weight);font-size:var(--pp-btn-font-size);text-transform:var(--pp-btn-text-transform);text-decoration:none;box-shadow:var(--pp-btn-shadow);border:none;cursor:pointer}'
        . '.btn:hover{filter:brightness(0.95)}'
        . '.tag{display:inline-block;color:var(--pp-accent);background:rgba(0,0,0,0.04);font-size:.75rem;font-weight:600;text-transform:uppercase;letter-spacing:.06em;padding:4px 10px;border-radius:999px;margin-bottom:14px}'
        . '.admin-link{position:fixed;top:16px;right:16px;color:var(--pp-text-muted);font-size:.85rem;text-decoration:none;background:var(--pp-surface);padding:6px 12px;border-radius:var(--pp-radius-card);border:1px solid var(--pp-border)}'
        . '.admin-link:hover{color:var(--pp-primary)}'
        . '</style>'
        . '</head><body>'
        . '<a class="admin-link" href="' . $adminUrl . '">Panel admin →</a>'
        . '<div class="container">'
        . '<span class="tag">Design system activo</span>'
        . '<h1>' . e($siteName) . '</h1>'
        . '<p class="lead">Esta página está usando los tokens del design system configurados en el panel. Cambia colores, fuentes o botones en <code>/admin/design</code> y recarga aquí para verlo.</p>'
        . '<a class="btn" href="' . $adminUrl . '">Ir al panel</a>'
        . '</div>'
        . '</body></html>'
    );
});

// Rutas de autenticación (sin middleware — accesibles sin login)
$router->get('/admin/login',   [AuthController::class, 'showLogin']);
$router->post('/admin/login',  [AuthController::class, 'login']);
$router->get('/admin/logout',  [AuthController::class, 'logout']);
$router->post('/admin/logout', [AuthController::class, 'logout']);

// Rutas admin protegidas por auth
$requireAuth = [\Core\Auth::class, 'requireAuth'];
$requireOnboarding = [\Core\Auth::class, 'requireOnboarding'];
$router->group('/admin', function (\Core\Router $r) {
    $r->get('/',  [DashboardController::class, 'index']);
    $r->get('',   [DashboardController::class, 'index']);

    // Onboarding inicial
    $r->get('/onboarding',                    [OnboardingController::class, 'index']);
    $r->post('/onboarding/step/{step}',        [OnboardingController::class, 'saveStep']);
    $r->post('/onboarding/skip',               [OnboardingController::class, 'skip']);
    $r->post('/onboarding/exit',               [OnboardingController::class, 'exitToPanel']);
    $r->post('/onboarding/autofill-memory',    [OnboardingController::class, 'autofillMemory']);
    $r->post('/onboarding/upload-references',  [OnboardingController::class, 'uploadReferences']);
    $r->post('/onboarding/analyze',            [OnboardingController::class, 'analyze']);
    $r->post('/onboarding/create-pages',       [OnboardingController::class, 'createPages']);
    $r->post('/onboarding/create-post',        [OnboardingController::class, 'createPost']);
    // D-Slice 1 — Step 5 reconvertido a preview + nudges.
    $r->post('/onboarding/compose-skin',        [OnboardingController::class, 'composeSkin']);
    $r->post('/onboarding/prepare-home',        [OnboardingController::class, 'prepareHome']);
    $r->post('/onboarding/nudge',               [OnboardingController::class, 'nudge']);
    $r->get('/onboarding/skin-preview',         [OnboardingController::class, 'skinPreview']);

    // Páginas (CRUD)
    $r->get('/pages',                [PageController::class, 'index']);
    $r->get('/pages/studio',         [PageController::class, 'studio']);
    $r->get('/pages/create',         [PageController::class, 'create']);
    $r->get('/pages/list',           [PageController::class, 'listJson']);    // Enlaces: selector
    $r->post('/pages/quick',         [PageController::class, 'quickCreate']);  // Enlaces: crear al vuelo
    $r->post('/pages',               [PageController::class, 'store']);
    $r->post('/pages/architecture/analyze', [PageController::class, 'architectureAnalyze']);
    $r->post('/pages/ai-opportunities', [PageController::class, 'aiOpportunities']);
    $r->post('/pages/ai-brief',      [PageController::class, 'aiBrief']);
    $r->post('/pages/ai-create',     [PageController::class, 'aiCreate']);
    $r->post('/pages/ai-from-reference', [PageController::class, 'aiCreateFromReference']); // D-MB: recrear desde captura
    $r->post('/pages/{id}/ai-variations', [PageController::class, 'aiVariations']);
    $r->post('/pages/{id}/ai-variations/apply', [PageController::class, 'applyVariation']);
    // Preview de plantilla: la usan las previews de estilo visual en /admin/design
    // (VisualStyleService::cardsForSite). El flujo de creación desde plantilla
    // (galería + ai-create-from-template) se retiró por obsoleto: hoy todo se crea
    // en modo canvas (studio / onboarding).
    $r->get('/pages/ai/templates/{slug}/preview',  [PageController::class, 'aiTemplatePreview']);
    $r->get('/pages/{id}/edit',      [PageController::class, 'edit']);

    // FH3 — Studio Live (páginas canvas: edición conversacional)
    $r->get('/canvas/{id}',           [CanvasController::class, 'studio']);
    $r->get('/canvas/{id}/preview',   [CanvasController::class, 'preview']);
    $r->post('/canvas/{id}/chat',     [CanvasController::class, 'chat']);
    $r->post('/canvas/{id}/section',  [CanvasController::class, 'updateSection']); // FH4 edición directa
    $r->post('/canvas/{id}/insert-form', [CanvasController::class, 'insertForm']); // FORMS F5
    $r->get('/canvas/{id}/versions',  [CanvasController::class, 'versions']);
    $r->post('/canvas/{id}/restore',  [CanvasController::class, 'restore']);
    $r->post('/canvas/{id}/undo',     [CanvasController::class, 'undo']);
    $r->post('/canvas/{id}/redo',     [CanvasController::class, 'redo']);
    $r->post('/canvas/{id}/publish',  [CanvasController::class, 'publish']);
    $r->post('/canvas/{id}/settings', [CanvasController::class, 'saveSettings']); // FH8 — ajustes SEO
    $r->get('/pages/{id}/preview',   [PageController::class, 'preview']);
    $r->post('/pages/{id}',          [PageController::class, 'update']);
    $r->post('/pages/{id}/structure', [PageController::class, 'updateStructure']);

    // F21 — Entradas (blog). Una entrada es una `pages` con page_type='article'.
    // El listado/crear son específicos; el editor de contenido es dedicado.
    $r->get('/posts',                  [PostController::class, 'index']);
    $r->get('/posts/new',              [PostController::class, 'create']);
    $r->post('/posts',                 [PostController::class, 'store']);
    $r->post('/posts/ai-create',       [PostController::class, 'aiCreate']);
    $r->post('/posts/ai-create-from-document', [PostController::class, 'aiCreateFromDocument']);
    $r->post('/posts/ai-suggest-related',      [PostController::class, 'aiSuggestRelated']);
    $r->get('/posts/{id}/edit',        [PostController::class, 'edit']);
    $r->get('/posts/{id}/preview-html', [PostController::class, 'previewHtml']); // FH11 lectura en línea
    $r->post('/posts/{id}/meta',       [PostController::class, 'updateMeta']);
    $r->post('/posts/{id}/body',       [PostController::class, 'updateBody']);
    $r->post('/posts/{id}/status',     [PostController::class, 'updateStatus']);
    $r->post('/posts/{id}/featured/auto', [PostController::class, 'autoFeatured']);
    $r->post('/posts/{id}/delete',     [PostController::class, 'destroy']);
    $r->post('/pages/{id}/delete',   [PageController::class, 'destroy']);

    // SEO — redirecciones, 404 y auditorías accionables
    $r->get('/seo',                         [SeoController::class, 'index']);
    $r->post('/seo/redirects',              [SeoController::class, 'storeRedirect']);
    $r->post('/seo/redirects/{id}',         [SeoController::class, 'redirectAction']);
    $r->post('/seo/404/{id}',               [SeoController::class, 'notFoundAction']);
    $r->get('/links',                       [SeoController::class, 'links']); // legacy

    // Secciones (API JSON consumida por sections-editor.js)
    $r->post('/pages/{pageId}/sections',          [SectionController::class, 'store']);
    $r->post('/pages/{pageId}/sections/reorder',  [SectionController::class, 'reorder']);
    $r->get('/sections/variant-preview',          [SectionController::class, 'variantPreview']);
    $r->post('/sections/variant-preview',         [SectionController::class, 'variantPreview']);
    $r->post('/sections/{id}',                    [SectionController::class, 'update']);
    $r->post('/sections/{id}/delete',             [SectionController::class, 'destroy']);
    $r->get('/sections/{id}/versions',             [SectionController::class, 'versions']);
    $r->post('/sections/{id}/versions/{versionId}/restore', [SectionController::class, 'restoreVersion']);

    // Memoria del sitio
    $r->get('/memory',  [MemoryController::class, 'index']);
    $r->post('/memory', [MemoryController::class, 'update']);

    // Medios (T8.1)
    $r->get('/media',                    [MediaController::class, 'index']);
    $r->get('/media/library',            [MediaController::class, 'library']);
    $r->post('/media',                   [MediaController::class, 'upload']);
    $r->post('/media/{id}/alt',          [MediaController::class, 'updateAlt']);
    $r->post('/media/{id}/delete',       [MediaController::class, 'destroy']);
    // T18.4 — banco de imágenes (Unsplash). bank/search debe ir antes de /media/{id}/* hipotéticos.
    $r->get('/media/bank',               [MediaController::class, 'bankIndex']);
    $r->get('/media/bank/search',        [MediaController::class, 'bankSearch']);
    $r->post('/media/bank/import',       [MediaController::class, 'bankImport']);

    // Formularios (FORMS F2/F3) — apartado de creación/edición
    $r->get('/formularios',              [FormsController::class, 'index']);
    $r->post('/formularios/create',      [FormsController::class, 'create']);
    $r->get('/formularios/{id}',         [FormsController::class, 'edit']);
    $r->post('/formularios/{id}',        [FormsController::class, 'update']);
    $r->post('/formularios/{id}/delete', [FormsController::class, 'destroy']);

    // Mensajes de formularios públicos
    $r->get('/forms',                              [FormSubmissionController::class, 'index']);
    $r->get('/forms/submissions/{id}/files/{key}', [FormSubmissionController::class, 'downloadFile']);
    $r->post('/forms/submissions/{id}/read',       [FormSubmissionController::class, 'markRead']);
    $r->post('/forms/submissions/{id}/delete',     [FormSubmissionController::class, 'destroy']);

    // Documentos base
    $r->get('/documents',                [DocumentController::class, 'index']);
    $r->post('/documents/upload',        [DocumentController::class, 'upload']);
    $r->get('/documents/{id}',           [DocumentController::class, 'show']);
    $r->post('/documents/{id}/rename',   [DocumentController::class, 'rename']);
    $r->post('/documents/{id}/retry',    [DocumentController::class, 'retry']);
    $r->post('/documents/{id}/delete',   [DocumentController::class, 'destroy']);

    // Design system
    $r->get('/design',        [DesignController::class, 'index']);
    $r->post('/design',       [DesignController::class, 'update']);
    $r->post('/design/logo',  [DesignController::class, 'updateLogo']);
    $r->post('/design/logo/delete', [DesignController::class, 'deleteLogo']);
    $r->post('/design/reset', [DesignController::class, 'reset']);
    $r->post('/design/regenerate', [DesignController::class, 'regenerate']);
    // Solo dev: showcase de los 8 skin anchors curados.
    $r->get('/_dev/skin-anchors',  [DesignController::class, 'devSkinAnchors']);
    $r->get('/_dev/preview-all',   [DesignController::class, 'devPreviewAll']);

    // IA — test de proveedor (T6.1)
    $r->get('/ai/test',  [AITestController::class, 'index']);
    $r->post('/ai/test', [AITestController::class, 'run']);

    // IA — explorador de prompts (T6.2)
    $r->get('/ai/prompts',          [AITestController::class, 'prompts']);
    $r->post('/ai/prompts/preview', [AITestController::class, 'promptPreview']);

    // IA — ejecutar acciones end-to-end (T6.3)
    $r->post('/ai/actions/run',     [AITestController::class, 'actionRun']);

    // IA — uso y coste (T6.4)
    $r->get('/ai/usage',            [AIUsageController::class, 'index']);

    // Ajustes
    // E-GDPR — Panel Privacidad (G2 + G3)
    $r->get('/privacy',                  [PrivacyController::class, 'index']);
    $r->post('/privacy/legal',           [PrivacyController::class, 'saveLegal']);
    $r->post('/privacy/pages/generate',     [PrivacyController::class, 'generateLegalPage']);
    $r->post('/privacy/pages/generate-all', [PrivacyController::class, 'generateAll']);

    // E-GDPR-WZ — Wizard de privacidad
    $r->get('/privacy/wizard',         [PrivacyWizardController::class, 'index']);
    $r->post('/privacy/wizard/step1',  [PrivacyWizardController::class, 'saveStep1']);
    $r->post('/privacy/wizard/step2',  [PrivacyWizardController::class, 'saveStep2']);
    $r->post('/privacy/wizard/finish', [PrivacyWizardController::class, 'finish']);
    $r->post('/privacy/cookies',         [PrivacyController::class, 'saveCookies']);
    $r->post('/privacy/banner',          [PrivacyController::class, 'saveBanner']);

    // Marketing — integraciones de tracking + código personalizado (MKT)
    $r->get('/marketing',                 [MarketingController::class, 'index']);
    $r->post('/marketing/integrations',   [MarketingController::class, 'saveIntegrations']);
    $r->post('/marketing/custom',         [MarketingController::class, 'saveCustom']);
    $r->post('/marketing/custom/delete',  [MarketingController::class, 'deleteCustom']);

    $r->get('/settings',      [SettingsController::class, 'index']);
    $r->post('/settings',     [SettingsController::class, 'update']);
    $r->post('/settings/check-updates', [SettingsController::class, 'checkUpdates']);
    $r->post('/settings/apply-update', [SettingsController::class, 'applyUpdate']);
    $r->post('/settings/reset-site', [SettingsController::class, 'resetSite']);
    $r->get('/settings/ai',   [SettingsAIController::class, 'index']);
    $r->post('/settings/ai',  [SettingsAIController::class, 'update']);
    $r->post('/settings/images', [SettingsAIController::class, 'updateImages']); // Unsplash key post-install
    $r->get('/settings/mail',  [MailSettingsController::class, 'index']);  // EMAIL E4 — envío de correo
    $r->post('/settings/mail', [MailSettingsController::class, 'update']);
    $r->post('/settings/mail/test', [MailSettingsController::class, 'test']);
    $r->get('/chrome',          [ChromeController::class, 'index']);   // Editor Header y pie
    $r->post('/chrome',         [ChromeController::class, 'save']);
    $r->post('/chrome/preview', [ChromeController::class, 'preview']);

    // FEAT-3 — Módulos (activación por sitio)
    $r->get('/modules',         [ModulesController::class, 'index']);
    $r->post('/modules/toggle', [ModulesController::class, 'toggle']);
}, [$requireAuth, $requireOnboarding]);

// FEAT-3 — Rutas de cada módulo activable. Se registran siempre; el guard
// requireEnabled() devuelve 404 cuando el módulo está apagado para el sitio.
\App\Modules\ModuleRegistry::registerRoutes($router, [$requireAuth, $requireOnboarding]);

// Health check (debug / smoke test)
$router->get('/_health', function () {
    \Core\Response::json([
        'status'    => 'ok',
        'version'   => PP_VERSION,
        'php'       => PHP_VERSION,
        'installed' => is_installed(),
        'time'      => date('c'),
    ]);
});

// Formularios públicos generados por secciones `form`.
$router->post('/forms/{sectionId}', [PublicFormController::class, 'submit']);

// Página pública por slug (T7.2 + T7.4) — catch-all, debe registrarse tras rutas específicas.
// {slug:path} soporta slugs anidados tipo /servicios/diseno-web.
$router->get('/{slug:path}', [PublicPageController::class, 'show']);
