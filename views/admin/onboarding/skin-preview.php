<?php
/**
 * D-Slice 1 (S1.13) — Preview REAL de la home del wizard.
 *
 * Genera secciones a través del mismo pipeline que la generación de páginas:
 *   - `PreviewComposer::buildHomeSections` para esqueleto + contenido del onboarding.
 *   - `LayoutSelector::selectForPage` para elegir variantes según el vector.
 *   - `SectionRenderer::renderMany` para pintar HTML con los tokens del skin.
 *
 * FH6 — Si existe el borrador del Inicio canvas, mostramos ESA home real
 * (HTML libre) con el skin vivo: lo que el usuario verá publicado. El skin
 * se aplica vía tokens en el head, así los nudges la re-estilan al instante
 * sin regenerar. Sin borrador canvas, cae al demo de bloques previo.
 *
 * @var int $siteId
 * @var ?array $referencePreviewContent
 * @var string $homeCanvasHtml
 */

use App\Services\DesignSystem;
use App\Services\BrandService;
use App\Services\Personality\PreviewComposer;
use App\Services\Renderer\SectionRenderer;
use Core\Database;

$site = Database::selectOne('SELECT name, language FROM sites WHERE id = ?', [$siteId]) ?? [];
$brandName = trim((string) ($site['name'] ?? '')) !== '' ? (string) $site['name'] : 'Tu marca';
$lang = (string) ($site['language'] ?? 'es');

// Renderiza el head con el skin compuesto (variables CSS, fuentes…).
$designHead = DesignSystem::renderHead($siteId);

SectionRenderer::setSiteContext($siteId);

// FH6 — preferimos el Inicio canvas real si está disponible.
$homeCanvasHtml = trim((string) ($homeCanvasHtml ?? ''));
$useCanvasHome = $homeCanvasHtml !== '';

if ($useCanvasHome) {
    $hasReferencePreview = false;
    $sectionsHtml = $homeCanvasHtml;
} else {
    // Composición demo de la home (bloques) como respaldo.
    $hasReferencePreview = is_array($referencePreviewContent ?? null) && (($referencePreviewContent['validation']['sanitized'] ?? false) === true);
    $sections = $hasReferencePreview
        ? [[
            'id' => 9901,
            'section_type' => 'custom_block',
            'sort_order' => 0,
            'content_json' => $referencePreviewContent,
            'style_json' => null,
        ]]
        : PreviewComposer::buildHomeSections($siteId);
    $sectionsHtml = SectionRenderer::renderMany($sections);
}

// Para que las URLs ficticias (#contacto etc.) no naveguen fuera del iframe.
$disableLinksJs = "document.addEventListener('click', function(e){ var a = e.target.closest('a'); if (a) { e.preventDefault(); } }, true);";
?><!DOCTYPE html>
<html lang="<?= htmlspecialchars($lang, ENT_QUOTES) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Vista previa de tu estilo · <?= htmlspecialchars($brandName, ENT_QUOTES) ?></title>
<?= $designHead ?>
<style>
    /* Reset mínimo para que la página se vea limpia dentro del iframe. */
    html, body { margin: 0; padding: 0; background: var(--pp-bg, #fff); color: var(--pp-text, #1c1917); font-family: var(--pp-font-body, system-ui, sans-serif); }
    body { font-size: var(--pp-font-size-base, 16px); line-height: var(--pp-line-height, 1.5); -webkit-font-smoothing: antialiased; }
    a { color: inherit; }

    /* Mini-nav demo arriba para enmarcar la página. */
    .pp-preview-nav {
        display: flex; align-items: center; justify-content: space-between;
        padding: 18px 32px;
        border-bottom: 1px solid var(--pp-border, #e5e7eb);
        background: var(--pp-bg, #fff);
        font-family: var(--pp-font-body, inherit);
    }
    .pp-preview-nav__brand {
        display: flex; align-items: center; gap: 10px;
        font-family: var(--pp-font-heading, inherit);
        font-weight: 700; font-size: 18px;
        color: var(--pp-text, #1c1917);
    }
    .pp-preview-nav__brand-dot {
        width: 26px; height: 26px;
        border-radius: var(--pp-radius-card, 8px);
        background: var(--pp-primary, #ea580c);
        display: inline-block;
    }
    .pp-preview-nav__links {
        display: flex; gap: 22px;
        color: var(--pp-text-muted, #64748b);
        font-size: 14px;
    }
    .pp-preview-nav__cta {
        background: var(--pp-primary, #ea580c); color: #fff !important;
        padding: 8px 16px;
        border-radius: var(--pp-btn-radius, var(--pp-radius-btn, 8px));
        font-weight: 600; text-decoration: none;
    }
    @media (max-width: 720px) {
        .pp-preview-nav__links { display: none; }
    }

    /* Badge "Vista demo" para que quede claro que el contenido es placeholder. */
    .pp-demo-badge {
        position: fixed; top: 14px; right: 14px;
        background: rgba(15,23,42,.85); color: #fff;
        padding: 5px 12px; border-radius: 999px;
        font-size: 11px; font-weight: 700;
        letter-spacing: 0.04em; text-transform: uppercase;
        z-index: 10;
        font-family: system-ui, sans-serif;
    }

    /* Footer simple. */
    .pp-preview-footer {
        padding: 24px 32px;
        border-top: 1px solid var(--pp-border, #e5e7eb);
        display: flex; justify-content: space-between; align-items: center;
        color: var(--pp-text-muted, #64748b);
        font-size: 13px;
    }
    .pp-preview-footer strong { color: var(--pp-text, #1c1917); font-family: var(--pp-font-heading, inherit); }
</style>
</head>
<body>
<span class="pp-demo-badge"><?= $useCanvasHome ? 'Vista IA · tu inicio real' : ($hasReferencePreview ? 'Vista IA · inspirada en tus referencias' : 'Vista demo · contenido de ejemplo') ?></span>

<header class="pp-preview-nav">
    <span class="pp-preview-nav__brand"><span class="pp-preview-nav__brand-dot"></span><?= htmlspecialchars($brandName, ENT_QUOTES) ?></span>
    <nav class="pp-preview-nav__links">
        <span>Servicios</span>
        <span>Sobre nosotros</span>
        <span>Blog</span>
        <span>Contacto</span>
    </nav>
    <a href="#" class="pp-preview-nav__cta">Empezar</a>
</header>

<main>
<?= $sectionsHtml ?>
</main>

<footer class="pp-preview-footer">
    <strong><?= htmlspecialchars($brandName, ENT_QUOTES) ?></strong>
    <span>© <?= date('Y') ?> · Todos los derechos reservados</span>
</footer>

<script><?= $disableLinksJs ?></script>
</body>
</html>
