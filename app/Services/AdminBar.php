<?php

declare(strict_types=1);

namespace App\Services;

use Core\Auth;

/**
 * ADMIN-BAR — Saltar al panel desde la web, sin escribir la URL a mano.
 *
 * Con la sesión iniciada, cada página pública lleva abajo a la derecha una
 * píldora con dos accesos: el panel y el editor de ESTA página.
 *
 * Por qué una píldora flotante y no una barra fija arriba como la de WordPress:
 * esa obliga a empujar el `<body>`, y aquí las cabeceras son pegajosas y de
 * diseño variable (cada sitio tiene el suyo), así que se rompería de una forma
 * distinta en cada web. Flotando no le quita un píxel a nadie.
 *
 * **Quien la pinte tiene que dejar la página fuera de la caché.** El HTML
 * público se guarda entero con clave de sitio + slug + idioma y sin nada del
 * usuario: una página cacheada CON barra enseñaría los enlaces del panel a todo
 * el mundo. La guarda vive en `Public\PageController`, y hay un test que la
 * sujeta.
 *
 * El CSS y el JS van en línea a propósito: un asset aparte volvería a depender
 * de que el navegador se traiga la versión nueva, que es el fallo que costó la
 * 1.2.8.
 */
final class AdminBar
{
    /** ¿Hay que pintarla? Único sitio donde se decide. */
    public static function shouldRender(): bool
    {
        return Auth::check();
    }

    /**
     * A dónde lleva «Editar esta página».
     *
     * Una entrada del blog es una fila de `pages` con `page_type='article'`, así
     * que las tres variantes salen de la misma tabla y se distinguen aquí.
     *
     * @param array<string,mixed> $page
     */
    public static function editUrl(array $page): string
    {
        $id = (int) ($page['id'] ?? 0);
        if ($id <= 0) return base_url('admin/');

        if (($page['page_type'] ?? '') === 'article') {
            return base_url('admin/posts/' . $id . '/edit');
        }
        if (($page['render_mode'] ?? 'sections') === 'canvas') {
            return base_url('admin/canvas/' . $id);
        }
        return base_url('admin/pages/' . $id . '/edit');
    }

    /**
     * HTML de la píldora. Cadena vacía si no hay sesión, para que el llamador
     * pueda concatenar sin preguntar.
     *
     * @param array<string,mixed> $page
     */
    public static function render(array $page): string
    {
        if (!self::shouldRender()) return '';

        $isCanvas = ($page['render_mode'] ?? 'sections') === 'canvas';
        $isArticle = ($page['page_type'] ?? '') === 'article';
        $editLabel = $isArticle ? __('bar.edit_post') : ($isCanvas ? __('bar.edit_studio') : __('bar.edit_page'));

        $panelUrl = e(base_url('admin/'));
        $editUrl  = e(self::editUrl($page));

        // Sin aviso de "borrador": la ruta pública solo sirve páginas
        // `status='published'`, así que aquí nunca se está mirando una en
        // borrador. Si algún día el gestor puede previsualizar borradores en la
        // web de verdad, ESE es el momento de añadirlo.
        $h  = '<div class="pp-adminbar" id="pp-adminbar" hidden>';
        $h .= '<div class="pp-adminbar__actions">';
        $h .= '<a class="pp-adminbar__link" href="' . $panelUrl . '">' . self::icon('panel') . '<span>' . e(__('bar.panel')) . '</span></a>';
        // EQUIPO T4 — Hoy los tres roles editan contenido, así que este botón
        // sale siempre. Se pregunta igual para que la barra no pueda prometer
        // un 403 si mañana aparece un rol de solo lectura.
        if (Auth::can(Permissions::CAP_CONTENT)) {
            $h .= '<a class="pp-adminbar__link pp-adminbar__link--primary" href="' . $editUrl . '">' . self::icon('edit') . '<span>' . e($editLabel) . '</span></a>';
        }
        $h .= '</div>';
        $h .= '<button type="button" class="pp-adminbar__toggle" id="pp-adminbar-toggle"'
            . ' aria-expanded="true" aria-controls="pp-adminbar" title="' . e(__('bar.hide')) . '"'
            . ' aria-label="' . e(__('bar.hide')) . '">' . self::icon('chevron') . '</button>';
        $h .= '</div>';

        return $h . self::css() . self::js();
    }

    private static function icon(string $name): string
    {
        $paths = [
            'panel'   => '<rect x="3" y="3" width="7" height="9" rx="1.5"/><rect x="14" y="3" width="7" height="5" rx="1.5"/>'
                       . '<rect x="14" y="12" width="7" height="9" rx="1.5"/><rect x="3" y="16" width="7" height="5" rx="1.5"/>',
            'edit'    => '<path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/>',
            'chevron' => '<path d="M9 18l6-6-6-6"/>',
        ];
        return '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" '
            . 'stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
            . ($paths[$name] ?? '') . '</svg>';
    }

    private static function css(): string
    {
        // Colores propios y no los tokens de la marca: esto no es parte de la
        // web, es una herramienta encima. Con los tokens se camuflaría justo en
        // los sitios donde más estorba confundirla con el contenido.
        return '<style>'
            . '.pp-adminbar{position:fixed;right:16px;bottom:16px;z-index:2147483000;display:flex;align-items:center;gap:4px;'
            . 'background:#111827;color:#fff;border-radius:999px;padding:5px;'
            . 'box-shadow:0 10px 30px rgba(15,23,42,.32);'
            . "font:600 13px/1 system-ui,-apple-system,'Segoe UI',sans-serif}"
            . '.pp-adminbar[hidden]{display:none}'
            . '.pp-adminbar__actions{display:flex;align-items:center;gap:4px;overflow:hidden;max-width:520px;'
            . 'transition:max-width .2s ease,opacity .2s ease}'
            . '.pp-adminbar.is-collapsed .pp-adminbar__actions{max-width:0;opacity:0}'
            . '.pp-adminbar.is-collapsed .pp-adminbar__toggle svg{transform:rotate(180deg)}'
            . '.pp-adminbar__link{display:inline-flex;align-items:center;gap:7px;padding:8px 14px;border-radius:999px;'
            . 'color:#fff;text-decoration:none;white-space:nowrap}'
            . '.pp-adminbar__link:hover{background:rgba(255,255,255,.14)}'
            . '.pp-adminbar__link--primary{background:#fff;color:#111827}'
            . '.pp-adminbar__link--primary:hover{background:#e5e7eb;color:#111827}'
            . '.pp-adminbar__toggle{display:grid;place-items:center;width:34px;height:34px;flex:none;border:0;border-radius:999px;'
            . 'background:transparent;color:#fff;cursor:pointer;padding:0}'
            . '.pp-adminbar__toggle:hover{background:rgba(255,255,255,.14)}'
            . '.pp-adminbar__toggle svg{display:block;transition:transform .2s ease}'
            . '@media (prefers-reduced-motion:reduce){.pp-adminbar__actions,.pp-adminbar__toggle svg{transition:none}}'
            // Ni en papel ni en un PDF de la web: ahí no pinta nada.
            . '@media print{.pp-adminbar{display:none!important}}'
            . '</style>';
    }

    private static function js(): string
    {
        // Arranca oculta y la enseña el JS: si el navegador no ejecuta scripts,
        // una píldora que no se puede plegar sería un estorbo permanente.
        // Plegada o no es cosa de ESTE navegador, así que localStorage; si no
        // se puede (modo privado), sale desplegada y no pasa nada.
        return '<script>(function(){'
            . "var bar=document.getElementById('pp-adminbar');"
            . "if(!bar)return;"
            . "var btn=document.getElementById('pp-adminbar-toggle');"
            . "var KEY='pp-adminbar-collapsed';"
            . "var collapsed=false;"
            . "try{collapsed=localStorage.getItem(KEY)==='1';}catch(e){}"
            . "function paint(){bar.classList.toggle('is-collapsed',collapsed);"
            . "btn.setAttribute('aria-expanded',collapsed?'false':'true');}"
            . "paint();bar.hidden=false;"
            . "btn.addEventListener('click',function(){collapsed=!collapsed;paint();"
            . "try{localStorage.setItem(KEY,collapsed?'1':'0');}catch(e){}});"
            . '})();</script>';
    }
}
