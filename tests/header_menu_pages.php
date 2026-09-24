<?php

declare(strict_types=1);

/** MENU-PAGES T1 — «sale en el menú» desde la vista de páginas: lógica pura. */

require_once __DIR__ . '/../config/constants.php';
require_once PP_CORE . '/Autoloader.php';
\Core\Autoloader::register();
require_once PP_ROOT . '/vendor/autoload.php';
\Core\App::boot();

use App\Services\ChromeService;
use App\Services\HeaderMenuService as M;

$failed = 0;
function check_hm(string $name, bool $ok, string $detail = ''): void
{
    global $failed;
    echo ($ok ? 'PASS' : 'FAIL') . ' ' . $name . PHP_EOL;
    if (!$ok) {
        $failed++;
        if ($detail !== '') echo '  -> ' . mb_substr($detail, 0, 500) . PHP_EOL;
    }
}

/** @param callable():mixed $fn */
function throws_hm(string $name, callable $fn, string $code): void
{
    try {
        $fn();
        check_hm($name, false, 'no lanzó');
    } catch (\DomainException $e) {
        check_hm($name, $e->getMessage() === $code, $e->getMessage());
    }
}

function page(int $id): array
{
    return ['type' => 'page', 'page_id' => $id, 'label' => '', 'visible' => true, 'target' => '_self'];
}

/** @return int[] page_id de primer nivel, en orden */
function topIds(array $menu): array
{
    return array_map(static fn($it) => (int) ($it['page_id'] ?? 0), $menu);
}

$base = ChromeService::sanitize([]);

// --- Automático ------------------------------------------------------------
$auto = ['es' => [11, 12, 13]];
check_hm('auto: contains si sale en la lista automática', M::contains($base, 12, 'es', 'es', $auto));
check_hm('auto: no contains si no sale', !M::contains($base, 99, 'es', 'es', $auto));
check_hm('auto: pageIdsInMenu = lista automática', M::pageIdsInMenu($base, 'es', 'es', $auto) === [11, 12, 13]);
check_hm('auto: isCustom false', !M::isCustom($base, 'es', 'es'));

// Añadir sobre auto: materializa (contacto incluido, como enlace normal) y añade al final.
$c = M::add($base, 20, 'es', 'es', $auto);
check_hm('add sobre auto materializa + añade', topIds($c['header']['menu']) === [11, 12, 13, 20], json_encode($c['header']['menu']));
check_hm('materializado: ítems page con etiqueta vacía (sigue al título)', ($c['header']['menu'][0]['label'] ?? 'x') === '' && ($c['header']['menu'][0]['type'] ?? '') === 'page');
check_hm('materializado: el CTA no se toca', ($c['header']['cta']['mode'] ?? '') === 'auto');
check_hm('add: ahora es custom', M::isCustom($c, 'es', 'es'));
check_hm('add: contains', M::contains($c, 20, 'es', 'es', $auto));

// Quitar sobre auto: materializa y quita.
$c = M::remove($base, 12, 'es', 'es', $auto);
check_hm('remove sobre auto materializa + quita', topIds($c['header']['menu']) === [11, 13], json_encode($c['header']['menu']));

// Auto vacío (sin páginas publicadas) + añadir.
$c = M::add($base, 5, 'es', 'es', ['es' => []]);
check_hm('add sobre auto sin páginas', topIds($c['header']['menu']) === [5]);

// --- Personalizado ---------------------------------------------------------
$custom = $base;
$custom['header']['menu'] = [
    page(1),
    ['type' => 'link', 'label' => 'Blog', 'url' => 'https://x.test', 'visible' => true, 'target' => '_blank'],
    ['type' => 'dropdown', 'label' => 'Servicios', 'visible' => true, 'children' => [page(7), page(8)]],
    page(3),
];
check_hm('custom: contains arriba', M::contains($custom, 1, 'es', 'es', $auto));
check_hm('custom: contains dentro de desplegable', M::contains($custom, 8, 'es', 'es', $auto));
check_hm('custom: ignora la lista automática', !M::contains($custom, 11, 'es', 'es', $auto));
check_hm('custom: pageIdsInMenu', M::pageIdsInMenu($custom, 'es', 'es', $auto) === [1, 7, 8, 3]);

$c = M::add($custom, 20, 'es', 'es', $auto);
check_hm('custom: add al final', topIds($c['header']['menu']) === [1, 0, 0, 3, 20]);
check_hm('custom: add no toca el enlace ni el desplegable', ($c['header']['menu'][1]['url'] ?? '') === 'https://x.test' && count($c['header']['menu'][2]['children']) === 2);

$c = M::add($custom, 20, 'es', 'es', $auto, [3]);
check_hm('custom: add antes del contacto final', topIds($c['header']['menu']) === [1, 0, 0, 20, 3], json_encode(topIds($c['header']['menu'])));

$c = M::add($custom, 1, 'es', 'es', $auto);
check_hm('custom: add de una que ya está no duplica', topIds($c['header']['menu']) === [1, 0, 0, 3]);

$hidden = $custom;
$hidden['header']['menu'][3]['visible'] = false;
check_hm('custom: ítem oculto no cuenta como en el menú', !M::contains($hidden, 3, 'es', 'es', $auto));
$c = M::add($hidden, 3, 'es', 'es', $auto);
check_hm('custom: add de un ítem oculto lo vuelve visible sin duplicar', topIds($c['header']['menu']) === [1, 0, 0, 3] && $c['header']['menu'][3]['visible'] === true);

$c = M::remove($custom, 3, 'es', 'es', $auto);
check_hm('custom: remove de arriba', topIds($c['header']['menu']) === [1, 0, 0]);
$c = M::remove($custom, 7, 'es', 'es', $auto);
check_hm('custom: remove dentro de desplegable', array_map(static fn($i) => $i['page_id'], $c['header']['menu'][2]['children']) === [8]);
$c = M::remove(M::remove($custom, 7, 'es', 'es', $auto), 8, 'es', 'es', $auto);
check_hm('custom: desplegable vacío se elimina', topIds($c['header']['menu']) === [1, 0, 3], json_encode($c['header']['menu']));

$only = $base;
$only['header']['menu'] = [page(1)];
throws_hm('custom: no deja quitar el último elemento', fn() => M::remove($only, 1, 'es', 'es', $auto), 'menu_last_item');

$full = $base;
$full['header']['menu'] = array_map('page', range(1, M::MAX_ITEMS));
throws_hm('custom: lleno ⇒ error', fn() => M::add($full, 99, 'es', 'es', $auto), 'menu_full');

// Remove de una página que no está: sin cambios.
$c = M::remove($custom, 42, 'es', 'es', $auto);
check_hm('custom: remove de una que no está no cambia nada', $c['header']['menu'] === $custom['header']['menu']);

// --- Idiomas ---------------------------------------------------------------
$autoMulti = ['es' => [11, 12], 'fr' => [21, 22], 'en' => [31]];

// Base en auto, idioma secundario: crea SU capa desde su lista, la base sigue en auto.
$c = M::add($base, 23, 'fr', 'es', $autoMulti);
check_hm('fr sobre auto: crea capa fr desde su lista', topIds($c['i18n']['fr']['header']['menu'] ?? []) === [21, 22, 23], json_encode($c['i18n'] ?? []));
check_hm('fr sobre auto: la base sigue en auto', ($c['header']['menu'] ?? null) === []);
check_hm('fr sobre auto: en no se toca', !isset($c['i18n']['en']['header']['menu']));
check_hm('fr: contains en fr', M::contains($c, 23, 'fr', 'es', $autoMulti));
check_hm('fr: es sigue en auto', !M::isCustom($c, 'es', 'es') && M::contains($c, 11, 'es', 'es', $autoMulti));

// Base en auto, idioma principal en web multidioma: congela los secundarios en su lista.
$c = M::add($base, 13, 'es', 'es', $autoMulti);
check_hm('es sobre auto multidioma: base materializada', topIds($c['header']['menu']) === [11, 12, 13]);
check_hm('es sobre auto multidioma: fr congelado en su lista', topIds($c['i18n']['fr']['header']['menu'] ?? []) === [21, 22], json_encode($c['i18n'] ?? []));
check_hm('es sobre auto multidioma: en congelado en su lista', topIds($c['i18n']['en']['header']['menu'] ?? []) === [31]);
check_hm('es sobre auto multidioma: fr no cambia lo que ve', M::pageIdsInMenu($c, 'fr', 'es', $autoMulti) === [21, 22]);

// Una capa secundaria ya propia no se pisa al congelar.
$withFr = $base;
$withFr['i18n']['fr']['header']['menu'] = [page(22)];
$withFr['i18n']['fr']['header']['cta']['label'] = 'Contactez';
$c = M::add($withFr, 13, 'es', 'es', $autoMulti);
check_hm('congelar respeta una capa fr existente', topIds($c['i18n']['fr']['header']['menu']) === [22]);
check_hm('congelar no borra otros textos de la capa', ($c['i18n']['fr']['header']['cta']['label'] ?? '') === 'Contactez');

// Base custom + fr sin capa: fr hoy ve la base; al tocar fr se crea su capa desde su lista.
$c = M::add($custom, 23, 'fr', 'es', $autoMulti);
check_hm('fr con base custom sin capa: capa desde su lista', topIds($c['i18n']['fr']['header']['menu'] ?? []) === [21, 22, 23]);
check_hm('fr con base custom: la base no se toca', $c['header']['menu'] === $custom['header']['menu']);

// Capa fr existente: se edita ella.
throws_hm('fr: no deja quitar el último de su capa', fn() => M::remove($withFr, 22, 'fr', 'es', $autoMulti), 'menu_last_item');
$c = M::remove(M::add($withFr, 21, 'fr', 'es', $autoMulti), 22, 'fr', 'es', $autoMulti);
check_hm('fr: add + remove sobre su capa', topIds($c['i18n']['fr']['header']['menu']) === [21]);

// --- Lo que sale sobrevive al saneado con el que se guarda ------------------
$c = M::add(M::add($base, 13, 'es', 'es', $autoMulti), 23, 'fr', 'es', $autoMulti);
$s = ChromeService::sanitize($c);
check_hm('sanitize conserva el menú base', topIds($s['header']['menu']) === [11, 12, 13]);
check_hm('sanitize conserva la capa fr', topIds($s['i18n']['fr']['header']['menu'] ?? []) === [21, 22, 23]);
check_hm('sanitize: el render de fr usa su capa', topIds(ChromeService::localized($s, 'fr')['header']['menu']) === [21, 22, 23]);

// --- Con BD: materializar enlaza lo mismo que el automático ------------------
$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'localhost';
$siteId = (int) (\Core\Database::selectOne('SELECT id FROM sites ORDER BY id ASC LIMIT 1')['id'] ?? 0);
if ($siteId > 0) {
    $lang = \App\Services\LanguageService::primaryFor($siteId);
    $ids = \App\Services\BrandService::autoNavPageIds($siteId, $lang);
    check_hm('BD: autoNavPageIds devuelve ids', $ids !== [] && min($ids) > 0, json_encode($ids));

    $hrefs = static function (string $html): array {
        preg_match('~<header.*?</header>~s', $html, $m);
        preg_match_all('~href="([^"]+)"~', $m[0] ?? '', $all);
        $out = array_values(array_unique($all[1]));
        sort($out);
        return $out;
    };
    $autoCfg = ChromeService::sanitize([]);
    $matCfg = M::add($autoCfg, $ids[0], $lang, $lang, [$lang => $ids]); // ya está: solo materializa
    $autoHrefs = $hrefs(\App\Services\BrandService::publicHeader($siteId, $autoCfg, $lang));
    $matHrefs = $hrefs(\App\Services\BrandService::publicHeader($siteId, ChromeService::sanitize($matCfg), $lang));
    check_hm('BD: el menú materializado enlaza las mismas páginas que el automático', $autoHrefs === $matHrefs,
        json_encode(['auto' => $autoHrefs, 'mat' => $matHrefs]));

    // Un borrador en un menú personalizado no se enlaza: sería una página que
    // el visitante no puede ver. Vuelve solo al publicarla.
    $pub = \Core\Database::selectOne("SELECT id, slug FROM pages WHERE site_id = ? AND status = 'published' AND page_type NOT IN ('home','legal','article') ORDER BY id LIMIT 1", [$siteId]);
    $drafts = \Core\Database::select("SELECT id, slug FROM pages WHERE site_id = ? AND status = 'draft' AND slug NOT LIKE '\\_\\_%' ORDER BY id LIMIT 2", [$siteId]);
    if ($pub && count($drafts) === 2) {
        $cfg = ChromeService::sanitize([
            'header' => ['menu' => [
                page((int) $pub['id']),
                page((int) $drafts[0]['id']),
                ['type' => 'dropdown', 'label' => 'SoloBorradores', 'visible' => true, 'children' => [page((int) $drafts[1]['id'])]],
            ]],
            'footer' => ['nav' => [page((int) $pub['id']), page((int) $drafts[0]['id'])]],
        ]);
        $h = \App\Services\BrandService::publicHeader($siteId, $cfg, $lang);
        $f = \App\Services\BrandService::publicFooter($siteId, $cfg, $lang, false);
        $hasSlug = static fn(string $html, string $slug): bool => (bool) preg_match('~href="[^"]*/' . preg_quote($slug, '~') . '"~', $html);
        check_hm('borrador: el header enlaza la publicada', $hasSlug($h, (string) $pub['slug']));
        check_hm('borrador: el header NO enlaza el borrador', !$hasSlug($h, (string) $drafts[0]['slug']));
        check_hm('borrador: desplegable solo con borradores no se pinta', !str_contains($h, 'SoloBorradores'));
        check_hm('borrador: el pie enlaza la publicada', $hasSlug($f, (string) $pub['slug']));
        check_hm('borrador: el pie NO enlaza el borrador', !$hasSlug($f, (string) $drafts[0]['slug']));
    } else {
        check_hm('BD: hay una publicada y dos borradores para probar', false);
    }
}

echo PHP_EOL . ($failed === 0 ? 'OK' : $failed . ' FALLO(S)') . PHP_EOL;
exit($failed === 0 ? 0 : 1);
