<?php
/**
 * @var array $formsList
 */

$basisLabels = [
    'legitimate_interest' => 'Interés legítimo',
    'consent'             => 'Consentimiento',
    'contract'            => 'Contrato',
];
?>

<?php if (empty($formsList)): ?>
<div class="pp-privacy-soon">
    <div class="pp-privacy-soon__icon" aria-hidden="true">
        <svg width="44" height="44" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
            <rect x="4" y="3" width="16" height="18" rx="2"/>
            <line x1="8" y1="8" x2="16" y2="8"/>
            <line x1="8" y1="12" x2="16" y2="12"/>
            <line x1="8" y1="16" x2="13" y2="16"/>
        </svg>
    </div>
    <h3>No tienes formularios en este sitio</h3>
    <p>Cuando añadas una sección "Formulario" en cualquier página, aparecerá aquí con su finalidad y plazo de conservación. Te ayudamos a documentar lo que necesitas para cumplir la normativa.</p>
</div>
<?php else: ?>

<div class="pp-privacy-notice pp-privacy-notice--info">
    <strong>Estos son los formularios de tu sitio.</strong> Edita la base legal y plazo de conservación dentro de cada formulario; la nota de privacidad pública se actualiza sola.
</div>

<div class="pp-privacy-forms">
    <?php foreach ($formsList as $f):
        $basisLabel = $basisLabels[$f['lawful_basis']] ?? $f['lawful_basis'];
    ?>
    <article class="pp-privacy-formcard">
        <header class="pp-privacy-formcard__head">
            <div>
                <h3><?= e($f['heading'] !== '' ? $f['heading'] : 'Formulario sin título') ?></h3>
                <p class="pp-privacy-formcard__meta">
                    en página <strong><?= e($f['page_title']) ?></strong>
                    · <?= (int) $f['fields_count'] ?> campo<?= $f['fields_count'] === 1 ? '' : 's' ?>
                    <?php if ($f['page_status'] !== 'published'): ?>
                        · <span class="pp-privacy-formcard__badge pp-privacy-formcard__badge--draft">Borrador</span>
                    <?php endif; ?>
                </p>
            </div>
            <a class="pp-btn pp-btn--secondary pp-btn--sm"
               href="<?= e(base_url('admin/pages/' . (int) $f['page_id'] . '/edit#sec-' . (int) $f['section_id'])) ?>">
                Editar formulario
            </a>
        </header>

        <dl class="pp-privacy-formcard__grid">
            <div>
                <dt>Base legal</dt>
                <dd>
                    <span class="pp-privacy-formcard__chip pp-privacy-formcard__chip--<?= e($f['lawful_basis']) ?>"><?= e($basisLabel) ?></span>
                </dd>
            </div>
            <div>
                <dt>Conservación</dt>
                <dd><?= e($f['retention_period']) ?></dd>
            </div>
            <div>
                <dt>Marketing opt-in</dt>
                <dd>
                    <?= $f['marketing_opt_in']
                        ? '<span class="pp-privacy-formcard__chip pp-privacy-formcard__chip--marketing">Sí</span>'
                        : '<span class="pp-privacy-formcard__chip pp-privacy-formcard__chip--off">No</span>' ?>
                </dd>
            </div>
        </dl>
    </article>
    <?php endforeach; ?>
</div>

<div class="pp-privacy-summary__hint" style="margin-top: 24px;">
    <strong>¿Cómo se aplica esto?</strong>
    <p>Bajo cada formulario público se muestra automáticamente una nota: <em>"Tus datos se tratarán [base legal] y se conservarán durante [plazo]"</em>, con enlace a tu política de privacidad. Si activas el opt-in de marketing, aparece una casilla aparte (nunca premarcada) para que el visitante consienta por separado.</p>
</div>

<?php endif; ?>
