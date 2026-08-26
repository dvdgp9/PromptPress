<?php

// LEGAL-SLUG — Las páginas legales estrenan slug en el idioma del sitio, y las
// que ya existen conservan el suyo.
//
// Lo que hay que proteger aquí es una URL publicada: si regenerar el texto le
// cambia el slug a una página que ya está en Google y enlazada desde el pie,
// el arreglo sale más caro que el problema. Sin llamadas a IA.

require_once __DIR__ . '/../config/constants.php';
require_once PP_CORE . '/Autoloader.php';
\Core\Autoloader::register();
require_once PP_ROOT . '/vendor/autoload.php';
\Core\App::boot();

use App\Services\Compliance\ComplianceService;
use App\Services\Compliance\LegalPageGenerator;
use App\Services\LanguageService;
use Core\Database;

$failed = 0;
function check(string $name, bool $ok, string $detail = ''): void
{
    global $failed;
    echo ($ok ? 'PASS' : 'FAIL') . ' ' . $name . PHP_EOL;
    if (!$ok) {
        $failed++;
        if ($detail !== '') echo '  → ' . mb_substr($detail, 0, 300) . PHP_EOL;
    }
}

$types = array_keys(LegalPageGenerator::TYPES);

// ======================================================================
// 1. El mapa de slugs: completo, válido y sin colisiones
// ======================================================================
$missing = [];
$invalid = [];
foreach ($types as $type) {
    $known = LegalPageGenerator::knownSlugs($type);
    foreach ($known as $slug) {
        if (preg_match('#^[a-z0-9]+(?:-[a-z0-9]+)*$#', $slug) !== 1) {
            $invalid[] = $type . '/' . $slug;
        }
    }
    // Un slug por idioma admitido (el castellano histórico cuenta como 'es').
    if (count($known) < 2) {
        $missing[] = $type;
    }
}
check('slugs_url_safe', $invalid === [], implode(' ', $invalid));
check('todos_los_tipos_tienen_variantes', $missing === [], implode(' ', $missing));

// Dos tipos distintos no pueden compartir slug en el mismo idioma: se pisarían.
$collisions = [];
foreach (array_keys(LanguageService::LANGUAGES) as $lang) {
    $seen = [];
    foreach ($types as $type) {
        // Se recorre el mapa a través de knownSlugs + el orden del catálogo:
        // basta con comprobar que el conjunto por idioma no repite.
        $all = LegalPageGenerator::knownSlugs($type);
        foreach ($all as $slug) {
            $key = $lang . '|' . $slug;
            if (isset($seen[$slug])) {
                if ($seen[$slug] !== $type) $collisions[] = $slug . ' (' . $seen[$slug] . ' vs ' . $type . ')';
            }
            $seen[$slug] = $type;
        }
    }
}
check('sin_colisiones_entre_tipos', $collisions === [], implode(' · ', array_unique($collisions)));

// Los castellanos históricos siguen reconociéndose: es lo que hace que una web
// ya publicada no se duplique al regenerar.
check('legacy_es_reconocido', in_array('privacidad', LegalPageGenerator::knownSlugs('privacy_policy'), true)
    && in_array('aviso-legal', LegalPageGenerator::knownSlugs('legal_notice'), true)
    && in_array('politica-de-cookies', LegalPageGenerator::knownSlugs('cookie_policy'), true));

check('frances_es_el_termino_real',
    in_array('mentions-legales', LegalPageGenerator::knownSlugs('legal_notice'), true)
    && in_array('politique-de-confidentialite', LegalPageGenerator::knownSlugs('privacy_policy'), true),
    implode(',', LegalPageGenerator::knownSlugs('legal_notice')));

// ======================================================================
// 2. slugFor sigue al idioma PRINCIPAL del sitio
// ======================================================================
$site = Database::selectOne('SELECT id FROM sites ORDER BY id ASC LIMIT 1');
$siteId = (int) ($site['id'] ?? 0);

if ($siteId > 0) {
    $originalLang = LanguageService::primaryFor($siteId);
    $originalCol  = (string) (Database::selectOne('SELECT language FROM sites WHERE id = ?', [$siteId])['language'] ?? 'es');

    try {
        LanguageService::setPrimary($siteId, 'fr');
        Database::execute('UPDATE sites SET language = ? WHERE id = ?', ['fr', $siteId]);
        LanguageService::forget($siteId);

        check('slug_nuevo_en_frances',
            LegalPageGenerator::slugFor($siteId, 'privacy_policy') === 'politique-de-confidentialite',
            LegalPageGenerator::slugFor($siteId, 'privacy_policy'));
        check('slug_aviso_legal_en_frances',
            LegalPageGenerator::slugFor($siteId, 'legal_notice') === 'mentions-legales',
            LegalPageGenerator::slugFor($siteId, 'legal_notice'));

        $now = date('Y-m-d H:i:s');

        // 3. Las páginas legales que YA existen (creadas en castellano) se
        //    siguen reconociendo con el sitio en francés. Es la garantía de que
        //    regenerar no crea un duplicado ni cambia una URL publicada.
        $existentes = [];
        foreach ($types as $type) {
            $row = LegalPageGenerator::findExistingPage($siteId, $type);
            if ($row !== null) $existentes[$type] = (string) $row['slug'];
        }
        check('reconoce_las_paginas_ya_creadas', $existentes !== [], 'ninguna página legal en el sitio de dev');

        if (isset($existentes['privacy_policy'])) {
            check('conserva_el_slug_historico', $existentes['privacy_policy'] === 'privacidad',
                $existentes['privacy_policy']);
            $shown = LegalPageGenerator::typesFor($siteId)['privacy_policy']['slug'] ?? '';
            check('el_panel_enseña_la_url_real', $shown === $existentes['privacy_policy'], $shown);
        }

        // Un tipo que NO existe estrena el slug del idioma. Se simula sacando
        // una página de la ecuación: se le cambia el tipo un momento.
        $victim = LegalPageGenerator::findExistingPage($siteId, 'legal_notice');
        if ($victim !== null) {
            $vid = (int) $victim['id'];
            // Se le cambia el tipo y el slug: deja de ser una página legal por
            // los dos caminos de resolución (id del manifest y slug conocido).
            // `patch()` fusiona, así que la entrada del manifest sigue ahí: es
            // justo lo que debe ignorarse al ver que ya no es `page_type=legal`.
            $manifestBefore = (array) (ComplianceService::manifest($siteId)['legal_pages'] ?? []);
            Database::execute("UPDATE pages SET page_type = 'landing', slug = ? WHERE id = ?", ['tmp-legal-slug-test', $vid]);

            $shownLegal = LegalPageGenerator::typesFor($siteId)['legal_notice']['slug'] ?? '';
            check('los_que_no_existen_estrenan_slug', $shownLegal === 'mentions-legales', $shownLegal);
            check('sin_pagina_no_hay_existente', LegalPageGenerator::findExistingPage($siteId, 'legal_notice') === null);

            Database::execute("UPDATE pages SET page_type = 'legal', slug = ? WHERE id = ?", [(string) $victim['slug'], $vid]);
            ComplianceService::patch($siteId, ['legal_pages' => $manifestBefore]);
            check('victima_restaurada',
                (string) (Database::selectOne('SELECT slug FROM pages WHERE id = ?', [$vid])['slug'] ?? '') === (string) $victim['slug']);
        }

        // 4. Resolución por el id del manifest, aunque el slug no sea ninguno
        //    de los conocidos (una página legal renombrada a mano).
        Database::execute(
            "INSERT INTO pages (site_id, title, slug, page_type, language, translation_group, status,
                                sort_order, tree_sort_order, created_at, updated_at, published_at)
             VALUES (?, 'Renombrada', 'nuestras-condiciones-legales', 'legal', 'fr', UUID(), 'published', 997, 997, ?, ?, ?)",
            [$siteId, $now, $now, $now]
        );
        $renamedId = (int) Database::lastInsertId();
        $manifest = ComplianceService::manifest($siteId);
        $legalPages = (array) ($manifest['legal_pages'] ?? []);
        $backup = $legalPages;
        $legalPages['legal_notice'] = $renamedId;
        ComplianceService::patch($siteId, ['legal_pages' => $legalPages]);

        $foundByManifest = LegalPageGenerator::findExistingPage($siteId, 'legal_notice');
        check('reconoce_por_id_del_manifest',
            $foundByManifest !== null && (int) $foundByManifest['id'] === $renamedId,
            json_encode($foundByManifest));

        ComplianceService::patch($siteId, ['legal_pages' => $backup]);
        Database::execute('DELETE FROM pages WHERE id = ?', [$renamedId]);
    } catch (\Throwable $e) {
        check('sin_excepciones', false, get_class($e) . ': ' . $e->getMessage());
    } finally {
        // Pase lo que pase, el sitio de dev vuelve a su idioma.
        LanguageService::setPrimary($siteId, $originalLang);
        Database::execute('UPDATE sites SET language = ? WHERE id = ?', [$originalCol, $siteId]);
        LanguageService::forget($siteId);
    }

    check('idioma_del_sitio_restaurado', LanguageService::primaryFor($siteId) === $originalLang,
        LanguageService::primaryFor($siteId) . ' vs ' . $originalLang);

    $leftovers = Database::selectOne(
        "SELECT COUNT(*) c FROM pages WHERE site_id = ? AND slug IN ('nuestras-condiciones-legales') ",
        [$siteId]
    );
    check('test_no_deja_rastro', (int) ($leftovers['c'] ?? 0) === 0);
}

echo PHP_EOL . ($failed > 0 ? $failed . ' FALLOS' : 'OK') . PHP_EOL;
exit($failed > 0 ? 1 : 0);
