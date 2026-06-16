<?php
/**
 * @var array $manifest
 * @var array $legalPagesState  ['privacy_policy'=>['id','title',...]|null, ...]
 * @var array $legalTypes       LegalPageGenerator::TYPES
 * @var string $csrf
 */

$controller = (array) ($manifest['controller'] ?? []);
$controllerReady = trim((string) ($controller['legal_name'] ?? '')) !== ''
                && trim((string) ($controller['address'] ?? '')) !== ''
                && trim((string) ($controller['email'] ?? '')) !== '';
?>

<?php if (!$controllerReady): ?>
<div class="pp-privacy-notice pp-privacy-notice--warning">
    <strong>Antes de generar tus páginas legales:</strong> completa los datos de tu empresa (razón social, dirección y email).
    Sin ellos, la IA dejaría huecos en los textos.
    <a class="pp-btn pp-btn--secondary pp-btn--sm" href="<?= e(base_url('admin/privacy?tab=legal')) ?>">Rellenar datos</a>
</div>
<?php endif; ?>

<?php
$missingPages = array_filter($legalTypes, fn ($info, $key) => ($legalPagesState[$key] ?? null) === null, ARRAY_FILTER_USE_BOTH);
$missingCount = count($missingPages);
?>
<?php if ($controllerReady): ?>
<div class="pp-privacy-bulk">
    <form method="POST" action="<?= e(base_url('admin/privacy/pages/generate-all')) ?>" class="pp-privacy-bulk__form">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <div class="pp-privacy-bulk__text">
            <strong><?= $missingCount === 3 ? 'Genera tus 3 páginas legales de una vez' : 'Regenerar todas las páginas legales' ?></strong>
            <p>La IA usará los mismos datos para las tres y tardará menos de un minuto. Cualquier hueco quedará marcado con <code>TODO-LEGAL:</code>.</p>
        </div>
        <button type="submit" class="pp-btn pp-btn--primary">
            <?= $missingCount > 0 ? 'Generar las 3 con IA' : 'Regenerar las 3 con IA' ?>
        </button>
    </form>
</div>
<?php endif; ?>

<div class="pp-privacy-pages">
    <?php foreach ($legalTypes as $typeKey => $info):
        $existing = $legalPagesState[$typeKey] ?? null;
        $generated = $existing !== null;
    ?>
    <article class="pp-privacy-pagecard <?= $generated ? 'is-generated' : 'is-missing' ?>">
        <header class="pp-privacy-pagecard__head">
            <div>
                <h3><?= e($info['label']) ?></h3>
                <?php if ($generated): ?>
                    <p class="pp-privacy-pagecard__meta">
                        <span class="pp-privacy-pagecard__badge pp-privacy-pagecard__badge--ok">Generada</span>
                        <span>Última actualización: <?= e(date('d/m/Y H:i', strtotime($existing['updated_at']))) ?></span>
                    </p>
                <?php else: ?>
                    <p class="pp-privacy-pagecard__meta">
                        <span class="pp-privacy-pagecard__badge pp-privacy-pagecard__badge--missing">No creada</span>
                        <span>La IA la generará con tus datos en menos de un minuto.</span>
                    </p>
                <?php endif; ?>
            </div>
        </header>

        <div class="pp-privacy-pagecard__actions">
            <form method="POST" action="<?= e(base_url('admin/privacy/pages/generate')) ?>" class="pp-privacy-pagecard__form">
                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                <input type="hidden" name="type" value="<?= e($typeKey) ?>">
                <button type="submit" class="pp-btn pp-btn--primary pp-btn--sm" <?= !$controllerReady ? 'disabled aria-disabled="true"' : '' ?>>
                    <?= $generated ? 'Regenerar con IA' : 'Generar con IA' ?>
                </button>
            </form>
            <?php if ($generated): ?>
                <a class="pp-btn pp-btn--secondary pp-btn--sm" href="<?= e(base_url('admin/posts/' . (int) $existing['id'] . '/edit')) ?>">Editar</a>
                <a class="pp-btn pp-btn--ghost pp-btn--sm" href="<?= e(base_url(ltrim((string) $existing['slug'], '/'))) ?>" target="_blank" rel="noopener">Ver pública ↗</a>
            <?php endif; ?>
        </div>

        <div class="pp-privacy-pagecard__url">
            <code><?= e(base_url(ltrim((string) $info['slug'], '/'))) ?></code>
        </div>
    </article>
    <?php endforeach; ?>
</div>

<div class="pp-privacy-summary__hint" style="margin-top: 24px;">
    <strong>Cómo funciona</strong>
    <p>La IA usa los datos que has rellenado en "Datos de tu empresa", los formularios que tengas y los servicios de tracking activos para redactar cada texto. Si algún dato falta, lo deja marcado como <code>TODO-LEGAL:</code> en el texto para que lo revises. Puedes editar la página después como cualquier otra desde el editor.</p>
</div>
