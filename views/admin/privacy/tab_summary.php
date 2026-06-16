<?php
/** @var array $status */
/** @var array $manifest */
$gaps = $status['gaps'] ?? [];
$level = $status['level'] ?? 'green';
?>

<div class="pp-privacy-summary">
    <?php if ($level === 'green'): ?>
        <div class="pp-privacy-summary__card pp-privacy-summary__card--ok">
            <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
                <path d="M9 12l2 2 4-4"/>
            </svg>
            <div>
                <h3>Todo en orden</h3>
                <p>Tu sitio cumple con lo básico de la normativa europea. Si añades servicios de analítica o publicidad más adelante, te avisaremos por aquí.</p>
            </div>
        </div>
    <?php else: ?>
        <div class="pp-privacy-summary__card pp-privacy-summary__card--<?= e($level) ?>">
            <div>
                <h3>
                    <?php if ($level === 'red'): ?>
                        Atención: hay algo que arreglar
                    <?php elseif ($level === 'orange'): ?>
                        Te falta un par de cosas antes de estar tranquilo
                    <?php else: ?>
                        Casi listo
                    <?php endif; ?>
                </h3>
                <p>Resuelve estos puntos cuando puedas. La mayoría se arreglan en pocos minutos.</p>
            </div>
        </div>

        <ul class="pp-privacy-gaps">
            <?php foreach ($gaps as $g): ?>
            <li class="pp-privacy-gap pp-privacy-gap--<?= e($g['severity']) ?>">
                <div class="pp-privacy-gap__text">
                    <strong><?= e($g['title']) ?></strong>
                    <span><?= e($g['description'] ?? '') ?></span>
                </div>
                <a class="pp-btn pp-btn--secondary pp-btn--sm" href="<?= e(base_url(ltrim($g['cta_url'], '/'))) ?>">
                    <?= e($g['cta_label']) ?>
                </a>
            </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <div class="pp-privacy-summary__hint">
        <strong>¿Por qué importa esto?</strong>
        <p>La normativa europea (RGPD y la ley española LSSI) exige que cualquier web publique unos textos legales mínimos y, si usa cookies de analítica o publicidad, pida permiso antes de cargarlas. PromptPress te ayuda generando los textos a partir de tus datos y gestionando el banner por ti.</p>
    </div>

    <div class="pp-privacy-summary__wizard-cta">
        <a class="pp-btn pp-btn--secondary pp-btn--sm" href="<?= e(base_url('admin/privacy/wizard')) ?>">
            Reabrir asistente guiado
        </a>
        <small>Útil si quieres regenerar las páginas legales o repasar los datos paso a paso.</small>
    </div>
</div>
