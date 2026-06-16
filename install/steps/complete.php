<?php
/**
 * Paso 5: Finalización del wizard.
 *
 * Solo se llega aquí si los pasos previos se completaron y `install/.installed` existe.
 */

use Core\Session;

// Si por alguna razón llegamos aquí sin la flag, redirigir al paso anterior
if (!is_file(PP_INSTALLED_FLAG)) {
    \Core\Response::redirect(InstallerApp::stepUrl('ai_provider'));
}

$warning = (string) (Session::get('install_warning') ?? '');
Session::set('install_warning', null);

$adminUrl = base_url('admin/');

ob_start();
?>
<div class="pp-complete">
    <div class="pp-complete__icon">✓</div>
    <h1 class="pp-step-title">¡Instalación completada!</h1>
    <p class="pp-step-intro">
        PromptPress está listo. Ya puedes acceder al panel de administración para empezar a crear contenido.
    </p>

    <?php if ($warning !== ''): ?>
        <div class="pp-alert pp-alert--warn">
            <strong>Aviso:</strong> <?= e($warning) ?>
        </div>
    <?php endif; ?>

    <div class="pp-alert pp-alert--info">
        <strong>Próximos pasos recomendados:</strong>
        <ul style="margin: 0.5rem 0 0 1.25rem;">
            <li>Sube documentos de referencia para alimentar la <em>memoria del sitio</em>.</li>
            <li>Crea tu primera página y prueba el editor de secciones.</li>
            <li>Personaliza el sistema de diseño (colores, tipografía).</li>
        </ul>
    </div>

    <div class="pp-form__actions" style="justify-content: center;">
        <a href="<?= e($adminUrl) ?>" class="pp-btn pp-btn--primary pp-btn--lg">
            Ir al panel de administración →
        </a>
    </div>
</div>
<?php
$content = (string) ob_get_clean();
InstallerApp::renderStep('complete', 'Instalación finalizada', $content);
