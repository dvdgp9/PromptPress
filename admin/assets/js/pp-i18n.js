/**
 * ADMIN-I18N — Traducción en el navegador (`pp.t`).
 *
 * Vive en su propio fichero porque hay DOS clases de pantalla en el panel:
 *   - las que extienden `admin/layout` (cargan este fichero y admin.js);
 *   - las standalone, como el Canvas Studio, que no tienen layout ni sidebar
 *     y solo cargan su propio JS.
 *
 * Estaba dentro de admin.js con un comentario que decía que también servía a
 * las standalone… pero el Studio no carga admin.js, así que sus 94 llamadas a
 * `pp.t()` lanzaban "pp is not defined" y cortaban el script a medias (por eso
 * fallaba, por ejemplo, insertar un bloque desde el panel lateral). Separarlo
 * permite que las dos clases de pantalla lo carguen sin arrastrar el resto.
 *
 * `window.PP_I18N` lo inyecta la vista con las claves ya resueltas al idioma
 * del gestor (prefijo `js.` y sufijo `_js`; ver `AdminI18n::jsCatalog()`).
 */
(function () {
    'use strict';

    var pp = window.pp = window.pp || {};

    /**
     * Texto de una clave. Si falta, devuelve la clave: se ve feo —que es lo que
     * se quiere— pero ninguna pantalla se rompe por una traducción olvidada.
     *
     *     pp.t('js.saving')
     *     pp.t('js.items_left', { n: 3 })
     */
    pp.t = function (key, vars) {
        var strings = window.PP_I18N || {};
        var text = Object.prototype.hasOwnProperty.call(strings, key) ? strings[key] : key;
        if (!vars) return text;
        return text.replace(/\{([a-z0-9_]+)\}/gi, function (match, name) {
            return Object.prototype.hasOwnProperty.call(vars, name) ? String(vars[name]) : match;
        });
    };
})();
