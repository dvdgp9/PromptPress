<?php
/**
 * @var array  $config  config de chrome (mergeada)
 * @var array  $pages   [{id,title,page_type,status}]
 * @var string $csrf
 */
\Core\View::extend('admin/layout');
$h = $config['header'] ?? [];
$hl = $h['layout'] ?? [];
$cta = $h['cta'] ?? [];
$f = $config['footer'] ?? [];
$fs = $f['style'] ?? [];
$fc = $f['contact'] ?? [];
$fn = $f['newsletter'] ?? [];
$sel = static fn($a, $b) => ((string) $a === (string) $b) ? ' selected' : '';
?>

<?php \Core\View::start('title'); ?>Header y pie<?php \Core\View::end(); ?>

<?php \Core\View::start('head'); ?>
<script>
  window.PP_CHROME = <?= json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
  window.PP_PAGES  = <?= json_encode($pages, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
  window.PP_BASEURL = "<?= e(rtrim(base_url(''), '/')) ?>";
  window.PP_CSRF = "<?= e($csrf) ?>";
</script>
<?php \Core\View::end(); ?>

<div class="pp-page-header">
    <h2>Header y pie</h2>
    <p class="pp-page-intro">Diseña el encabezado y el pie de tu sitio: menú, botón, contacto, redes y estilo. Los cambios se aplican a todas las páginas.</p>
</div>

<div class="pp-chrome-editor">
    <form method="POST" action="<?= e(base_url('admin/chrome')) ?>" class="pp-chrome-editor__form" id="chrome-form" autocomplete="off">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <input type="hidden" name="config_json" id="config_json" value="">

        <!-- ===================== HEADER ===================== -->
        <section class="pp-form-card">
            <h3>Header · Menú</h3>
            <p class="pp-design-hint">Elige y ordena lo que aparece en el menú. Si lo dejas vacío, se muestran automáticamente tus páginas publicadas.</p>
            <div id="menu-list" class="pp-chrome-list"></div>
            <div class="pp-chrome-addrow">
                <button type="button" class="pp-btn pp-btn--secondary pp-btn--sm" data-add-menu="page">+ Página</button>
                <button type="button" class="pp-btn pp-btn--secondary pp-btn--sm" data-add-menu="link">+ Enlace</button>
            </div>
        </section>

        <section class="pp-form-card">
            <h3>Header · Botón (CTA)</h3>
            <div class="pp-form-row">
                <div class="pp-form-group">
                    <label for="cta_mode">Modo</label>
                    <select id="cta_mode" name="_cta_mode">
                        <option value="auto"<?= $sel($cta['mode'] ?? 'auto', 'auto') ?>>Automático (página de contacto)</option>
                        <option value="custom"<?= $sel($cta['mode'] ?? '', 'custom') ?>>Personalizado</option>
                        <option value="off"<?= $sel($cta['mode'] ?? '', 'off') ?>>Sin botón</option>
                    </select>
                </div>
                <div class="pp-form-group">
                    <label for="cta_style">Estilo</label>
                    <select id="cta_style" name="_cta_style">
                        <option value="primary"<?= $sel($cta['style'] ?? 'primary', 'primary') ?>>Primario</option>
                        <option value="ghost"<?= $sel($cta['style'] ?? '', 'ghost') ?>>Contorno</option>
                    </select>
                </div>
            </div>
            <div class="pp-form-row" data-cta-custom>
                <div class="pp-form-group">
                    <label for="cta_label">Texto</label>
                    <input type="text" id="cta_label" name="_cta_label" maxlength="60" value="<?= e((string) ($cta['label'] ?? '')) ?>" placeholder="Reserva una cita">
                </div>
                <div class="pp-form-group">
                    <label for="cta_url">Destino</label>
                    <input type="text" id="cta_url" name="_cta_url" maxlength="300" value="<?= e((string) ($cta['url'] ?? '')) ?>" placeholder="/contacto">
                </div>
            </div>
        </section>

        <section class="pp-form-card">
            <h3>Header · Estilo</h3>
            <div class="pp-form-row">
                <label class="pp-checkbox-label"><input type="checkbox" id="h_sticky"<?= !empty($hl['sticky']) ? ' checked' : '' ?>> Fijo al hacer scroll</label>
                <label class="pp-checkbox-label"><input type="checkbox" id="h_transparent"<?= !empty($hl['transparent_over_hero']) ? ' checked' : '' ?>> Transparente sobre el hero</label>
            </div>
            <div class="pp-form-row">
                <div class="pp-form-group">
                    <label for="h_density">Densidad</label>
                    <select id="h_density">
                        <option value="compact"<?= $sel($hl['density'] ?? '', 'compact') ?>>Compacta</option>
                        <option value="regular"<?= $sel($hl['density'] ?? 'regular', 'regular') ?>>Normal</option>
                        <option value="tall"<?= $sel($hl['density'] ?? '', 'tall') ?>>Amplia</option>
                    </select>
                </div>
                <div class="pp-form-group">
                    <label for="h_logo">Posición del logo</label>
                    <select id="h_logo">
                        <option value="left"<?= $sel($hl['logo_position'] ?? 'left', 'left') ?>>Izquierda</option>
                        <option value="center"<?= $sel($hl['logo_position'] ?? '', 'center') ?>>Centro</option>
                    </select>
                </div>
            </div>
        </section>

        <!-- ===================== FOOTER ===================== -->
        <section class="pp-form-card">
            <h3>Pie · Bloques</h3>
            <p class="pp-design-hint">Activa, desactiva y ordena los bloques del pie.</p>
            <div id="blocks-list" class="pp-chrome-list"></div>
        </section>

        <section class="pp-form-card">
            <h3>Pie · Estilo y textos</h3>
            <div class="pp-form-group">
                <label for="f_bg">Fondo</label>
                <select id="f_bg">
                    <option value="dark"<?= $sel($fs['background'] ?? 'dark', 'dark') ?>>Oscuro</option>
                    <option value="light"<?= $sel($fs['background'] ?? '', 'light') ?>>Claro</option>
                    <option value="brand"<?= $sel($fs['background'] ?? '', 'brand') ?>>Color de marca</option>
                </select>
            </div>
            <div class="pp-form-group">
                <label for="f_tagline">Lema (tagline)</label>
                <input type="text" id="f_tagline" maxlength="200" value="<?= e((string) ($f['tagline'] ?? '')) ?>" placeholder="Déjalo vacío para usar el de la memoria del negocio">
            </div>
            <div class="pp-form-group">
                <label for="f_copyright">Texto de copyright</label>
                <input type="text" id="f_copyright" maxlength="160" value="<?= e((string) ($f['copyright'] ?? '')) ?>" placeholder="Déjalo vacío para «© AÑO · Nombre»">
            </div>
        </section>

        <section class="pp-form-card">
            <h3>Pie · Contacto</h3>
            <div class="pp-form-group">
                <label for="c_address">Dirección</label>
                <textarea id="c_address" rows="2" maxlength="300"><?= e((string) ($fc['address'] ?? '')) ?></textarea>
            </div>
            <div class="pp-form-row">
                <div class="pp-form-group"><label for="c_phone">Teléfono</label><input type="text" id="c_phone" maxlength="60" value="<?= e((string) ($fc['phone'] ?? '')) ?>"></div>
                <div class="pp-form-group"><label for="c_email">Email</label><input type="text" id="c_email" maxlength="120" value="<?= e((string) ($fc['email'] ?? '')) ?>"></div>
            </div>
            <div class="pp-form-group"><label for="c_hours">Horario</label><input type="text" id="c_hours" maxlength="120" value="<?= e((string) ($fc['hours'] ?? '')) ?>" placeholder="L-V 9:00-18:00"></div>
        </section>

        <section class="pp-form-card">
            <h3>Pie · Redes sociales</h3>
            <div id="social-list" class="pp-chrome-list"></div>
            <div class="pp-chrome-addrow"><button type="button" class="pp-btn pp-btn--secondary pp-btn--sm" id="add-social">+ Red social</button></div>
        </section>

        <section class="pp-form-card">
            <h3>Pie · Newsletter</h3>
            <label class="pp-checkbox-label"><input type="checkbox" id="n_enabled"<?= !empty($fn['enabled']) ? ' checked' : '' ?>> Mostrar bloque de newsletter</label>
            <div class="pp-form-row">
                <div class="pp-form-group"><label for="n_heading">Titular</label><input type="text" id="n_heading" maxlength="120" value="<?= e((string) ($fn['heading'] ?? '')) ?>" placeholder="Suscríbete a nuestra newsletter"></div>
                <div class="pp-form-group"><label for="n_form">Destino (URL del formulario)</label><input type="text" id="n_form" maxlength="120" value="<?= e((string) ($fn['form_ref'] ?? '')) ?>" placeholder="/contacto"></div>
            </div>
        </section>

        <div class="pp-form-actions">
            <button type="submit" class="pp-btn pp-btn--primary">Guardar cambios</button>
            <button type="button" class="pp-btn pp-btn--secondary" id="refresh-preview">Actualizar vista previa</button>
        </div>
    </form>

    <aside class="pp-chrome-editor__preview">
        <div class="pp-chrome-preview-head"><span>Vista previa</span></div>
        <iframe id="chrome-preview" title="Vista previa del sitio"></iframe>
    </aside>
</div>

<?php \Core\View::start('scripts'); ?>
<?php $js = PP_ROOT . '/admin/assets/js/chrome-editor.js'; $jsVer = file_exists($js) ? filemtime($js) : PP_VERSION; ?>
<script src="<?= e(base_url('admin/assets/js/chrome-editor.js')) ?>?v=<?= e($jsVer) ?>"></script>
<?php \Core\View::end(); ?>
