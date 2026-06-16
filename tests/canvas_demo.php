<?php

// FH1 — Crea/actualiza la página demo del modo Canvas (idempotente):
//  - una sección `form` real (en una página draft auxiliar) para el placeholder
//  - una página `canvas-demo` publicada con HTML/CSS libre + {{form:ID}}
// Uso: php tests/canvas_demo.php  → imprime la URL para QA visual.

require_once __DIR__ . '/../config/constants.php';
require_once PP_CORE . '/Autoloader.php';
\Core\Autoloader::register();
require_once PP_ROOT . '/vendor/autoload.php';
\Core\App::boot();

use App\Services\Canvas\CanvasService;
use Core\Database;

$siteId = 1;
$now = date('Y-m-d H:i:s');

// --- 1. Página auxiliar con sección form (host del formulario real) ---
$formHost = Database::selectOne("SELECT id FROM pages WHERE site_id = ? AND slug = 'canvas-demo-form-host'", [$siteId]);
if (!$formHost) {
    Database::execute(
        'INSERT INTO pages (site_id, title, slug, page_type, status, sort_order, tree_sort_order, created_at, updated_at)
         VALUES (?, "Canvas demo form host", "canvas-demo-form-host", "contact", "draft", 0, 998, ?, ?)',
        [$siteId, $now, $now]
    );
    $formHostId = (int) Database::lastInsertId();
} else {
    $formHostId = (int) $formHost['id'];
}

$formSection = Database::selectOne(
    "SELECT id FROM page_sections WHERE page_id = ? AND section_type = 'form' LIMIT 1",
    [$formHostId]
);
if (!$formSection) {
    Database::execute(
        'INSERT INTO page_sections (page_id, section_type, sort_order, content, status, created_at, updated_at)
         VALUES (?, "form", 0, ?, "editable", ?, ?)',
        [$formHostId, json_encode([
            'heading' => 'Cuéntanos tu proyecto',
            'description' => 'Te respondemos en menos de 24 horas laborables.',
            'submit_text' => 'Enviar mensaje',
            'success_message' => '¡Gracias! Hemos recibido tu mensaje.',
            'fields' => [
                ['label' => 'Nombre', 'name' => 'name', 'field_type' => 'text', 'required' => '1', 'placeholder' => 'Tu nombre'],
                ['label' => 'Email', 'name' => 'email', 'field_type' => 'email', 'required' => '1', 'placeholder' => 'tu@email.com'],
                ['label' => 'Mensaje', 'name' => 'message', 'field_type' => 'textarea', 'required' => '1', 'placeholder' => '¿En qué podemos ayudarte?'],
            ],
        ], JSON_UNESCAPED_UNICODE), $now, $now]
    );
    $formSectionId = (int) Database::lastInsertId();
} else {
    $formSectionId = (int) $formSection['id'];
}

// --- 2. Página canvas demo ---
$page = Database::selectOne("SELECT id FROM pages WHERE site_id = ? AND slug = 'canvas-demo'", [$siteId]);
if (!$page) {
    Database::execute(
        'INSERT INTO pages (site_id, title, slug, page_type, render_mode, meta_title, meta_description, status, sort_order, tree_sort_order, created_at, updated_at, published_at)
         VALUES (?, "Demo Canvas", "canvas-demo", "landing", "canvas", "Demo Canvas", "Página de demostración del modo canvas (HTML libre).", "published", 0, 997, ?, ?, ?)',
        [$siteId, $now, $now, $now]
    );
    $pageId = (int) Database::lastInsertId();
} else {
    $pageId = (int) $page['id'];
    Database::execute("UPDATE pages SET render_mode = 'canvas', status = 'published' WHERE id = ?", [$pageId]);
}

// HTML libre escrito a mano: usa tokens de marca, layout no expresable en ppb
// (hero asimétrico con badge rotado, marquee de texto, tarjetas solapadas).
$html = <<<HTML
<section data-pp-section="hero" class="cv-hero">
  <div class="cv-hero__inner">
    <div class="cv-hero__text">
      <p class="cv-eyebrow">Modo Canvas · demo</p>
      <h1>Diseño <em>sin techo</em>,<br>con tu marca al mando</h1>
      <p class="cv-lead">Esta página no usa el sistema de bloques: es HTML libre saneado, con CSS propio scoped y el skin del sitio como ley.</p>
      <div class="cv-actions">
        <a class="pp-btn pp-btn--primary pp-btn--lg" href="#contacto">Pide tu propuesta</a>
        <a class="pp-btn pp-btn--ghost pp-btn--lg" href="/inicio-dmb2">Ver modo bloques</a>
      </div>
    </div>
    <div class="cv-hero__panel" aria-hidden="true">
      <div class="cv-chip cv-chip--a">HTML libre</div>
      <div class="cv-chip cv-chip--b">CSS scoped</div>
      <div class="cv-chip cv-chip--c">Undo siempre</div>
    </div>
  </div>
</section>

<section data-pp-section="proof" class="cv-proof">
  <div class="cv-proof__track">
    <span>Sin gramática cerrada</span><span>·</span><span>Tokens de marca</span><span>·</span><span>Formularios reales</span><span>·</span><span>Versionado</span><span>·</span><span>Sin gramática cerrada</span><span>·</span><span>Tokens de marca</span>
  </div>
</section>

<section data-pp-section="cards" class="cv-cards">
  <div class="cv-cards__inner">
    <h2>Lo que el modo bloques no podía expresar</h2>
    <div class="cv-cards__grid">
      <article class="cv-card cv-card--lift"><h3>Solapes y rotaciones</h3><p>Tarjetas que se superponen, elementos girados, composiciones asimétricas reales.</p></article>
      <article class="cv-card"><h3>CSS por página</h3><p>Cada página trae su propio estilo, saneado y aislado del resto del sitio.</p></article>
      <article class="cv-card cv-card--drop"><h3>Funcional de verdad</h3><p>El formulario de abajo es el del sistema: leads, GDPR y rate-limit incluidos.</p></article>
    </div>
  </div>
</section>

<section data-pp-section="interaccion" class="cv-ux">
  <div class="cv-ux__inner">
    <h2>Comportamientos pp-ux (FH5)</h2>
    <div class="cv-ux__cols">
      <div data-pp-behavior="accordion">
        <details><summary>¿Qué es el modo Canvas?</summary><p>HTML libre saneado con CSS propio aislado por página.</p></details>
        <details><summary>¿Puedo deshacer cambios?</summary><p>Sí, cada cambio guarda una versión restaurable.</p></details>
        <details><summary>¿Los formularios funcionan?</summary><p>Sí, se insertan los del sistema con GDPR incluido.</p></details>
      </div>
      <div class="cv-ux__stats">
        <p class="cv-ux__big"><span data-pp-behavior="counter">25</span> versiones guardadas</p>
        <div data-pp-behavior="reveal" data-pp-reveal-delay="1" class="cv-card"><h3>Aparece al scroll</h3><p>Con IntersectionObserver y reduced-motion respetado.</p></div>
        <div data-pp-behavior="reveal" data-pp-reveal-delay="3" class="cv-card"><h3>Escalonado</h3><p>Retardos declarativos del 1 al 5.</p></div>
      </div>
    </div>
    <div data-pp-behavior="slider" class="cv-ux__slider">
      <div class="cv-card"><h3>Slide uno</h3><p>Carrusel con scroll-snap.</p></div>
      <div class="cv-card"><h3>Slide dos</h3><p>Flechas inyectadas por pp-ux.</p></div>
      <div class="cv-card"><h3>Slide tres</h3><p>Sin JS escrito por la IA.</p></div>
      <div class="cv-card"><h3>Slide cuatro</h3><p>Accesible y con teclado.</p></div>
    </div>
  </div>
</section>

<section data-pp-section="contacto" class="cv-contact" id="contacto">
  {{form:$formSectionId}}
</section>
HTML;

$css = <<<CSS
.cv-hero{background:linear-gradient(160deg, color-mix(in srgb, var(--pp-primary) 12%, var(--pp-bg)), var(--pp-bg) 60%);padding:clamp(64px,10vw,140px) 24px}
.cv-hero__inner{max-width:var(--pp-container-max);margin:0 auto;display:grid;grid-template-columns:1.2fr .8fr;gap:48px;align-items:center}
.cv-eyebrow{font-weight:800;letter-spacing:.14em;text-transform:uppercase;color:var(--pp-primary);font-size:.8rem;margin:0 0 16px}
.cv-hero h1{font-size:clamp(2.4rem,5.5vw,4.2rem);line-height:1.02;margin:0 0 20px}
.cv-hero h1 em{font-style:normal;color:var(--pp-primary)}
.cv-lead{font-size:1.15rem;line-height:1.6;color:var(--pp-text-muted);max-width:36em;margin:0 0 28px}
.cv-actions{display:flex;gap:14px;flex-wrap:wrap}
.cv-hero__panel{position:relative;min-height:260px}
.cv-chip{position:absolute;padding:18px 26px;border-radius:var(--pp-radius-lg);font-weight:800;box-shadow:var(--pp-shadow-lg);background:var(--pp-bg)}
.cv-chip--a{top:8%;left:6%;transform:rotate(-6deg);background:var(--pp-primary);color:var(--pp-on-primary)}
.cv-chip--b{top:42%;left:34%;transform:rotate(3deg)}
.cv-chip--c{top:74%;left:12%;transform:rotate(-2deg);background:var(--pp-text);color:var(--pp-on-text)}
.cv-proof{border-top:var(--pp-divider);border-bottom:var(--pp-divider);overflow:hidden;padding:18px 0;background:var(--pp-surface)}
.cv-proof__track{display:flex;gap:28px;white-space:nowrap;font-weight:700;color:var(--pp-text-muted);animation:cv-marquee 22s linear infinite}
@keyframes cv-marquee{from{transform:translateX(0)}to{transform:translateX(-50%)}}
.cv-cards{padding:clamp(56px,8vw,110px) 24px}
.cv-cards__inner{max-width:var(--pp-container-max);margin:0 auto}
.cv-cards h2{font-size:clamp(1.8rem,3.4vw,2.6rem);margin:0 0 40px;max-width:22ch}
.cv-cards__grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:28px}
.cv-card{background:var(--pp-bg);border:1px solid color-mix(in srgb, var(--pp-text) 10%, transparent);border-radius:var(--pp-radius-xl);padding:30px;box-shadow:var(--pp-shadow-md)}
.cv-card--lift{transform:translateY(-14px) rotate(-1deg)}
.cv-card--drop{transform:translateY(14px) rotate(1deg)}
.cv-card h3{margin:0 0 10px}
.cv-card p{margin:0;color:var(--pp-text-muted);line-height:1.6}
.cv-contact{background:color-mix(in srgb, var(--pp-primary) 7%, var(--pp-bg));padding:clamp(56px,8vw,110px) 24px}
.cv-ux{padding:clamp(56px,8vw,110px) 24px;background:var(--pp-surface)}
.cv-ux__inner{max-width:var(--pp-container-max);margin:0 auto}
.cv-ux h2{margin:0 0 32px}
.cv-ux__cols{display:grid;grid-template-columns:1.1fr .9fr;gap:40px;margin-bottom:48px}
.cv-ux__stats{display:flex;flex-direction:column;gap:16px}
.cv-ux__big{font-family:var(--pp-font-heading);font-size:1.6rem;font-weight:800;margin:0}
.cv-ux__big span{color:var(--pp-primary);font-size:2.6rem}
.cv-ux__slider .cv-card{min-height:120px}
@media (max-width:860px){.cv-ux__cols{grid-template-columns:1fr}}
@media (max-width:860px){.cv-hero__inner{grid-template-columns:1fr}.cv-hero__panel{display:none}.cv-cards__grid{grid-template-columns:1fr}.cv-card--lift,.cv-card--drop{transform:none}}
CSS;

$result = CanvasService::save($pageId, $html, $css, 'generate');

echo "página canvas demo: id={$pageId} → /canvas-demo\n";
echo "form section: {$formSectionId}\n";
echo 'warnings: ' . json_encode($result['warnings'], JSON_UNESCAPED_UNICODE) . "\n";
echo 'versiones: ' . count(CanvasService::versions($pageId)) . "\n";
