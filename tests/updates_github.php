<?php

declare(strict_types=1);

/**
 * UPD-GH — Actualizaciones desde GitHub Releases.
 *
 * El botón «Comprobar ahora» del panel llevaba desde siempre sin interlocutor:
 * `UpdateService` hacía POST a `updates.version_check_url`, una clave que no
 * existe en ninguna instalación, así que contestaba «canal no configurado» y
 * parecía roto. Ahora, si no hay endpoint propio, pregunta a la API pública de
 * GitHub Releases.
 *
 * Aquí se prueba lo que se puede probar sin red: la resolución del repo y, sobre
 * todo, el PARSEO de una release. Los casos que se vigilan son los que dejarían
 * al gestor con un botón que miente:
 *   - una release sin `.zip` no puede anunciarse como instalable;
 *   - una etiqueta `v1.3.0` tiene que compararse con `1.2.2` sin la `v`;
 *   - un repo sin publicaciones NO es un error rojo, es «todavía no hay nada».
 */

require_once __DIR__ . '/../config/constants.php';
require_once PP_CORE . '/Autoloader.php';
\Core\Autoloader::register();
require_once PP_ROOT . '/vendor/autoload.php';
\Core\App::boot();

use App\Services\UpdateService;

$failed = 0;
function check_upd(string $name, bool $ok, string $detail = ''): void
{
    global $failed;
    echo ($ok ? 'PASS' : 'FAIL') . ' ' . $name . PHP_EOL;
    if (!$ok) {
        $failed++;
        if ($detail !== '') echo '  -> ' . mb_substr($detail, 0, 400) . PHP_EOL;
    }
}

/** Deja `config()` con un valor concreto durante una comprobación. */
function with_config(array $overrides, callable $fn): mixed
{
    $prop = new ReflectionProperty(\Core\App::class, 'config');
    $prop->setAccessible(true);
    $before = $prop->getValue();
    $prop->setValue(null, array_merge(is_array($before) ? $before : [], $overrides));
    try {
        return $fn();
    } finally {
        $prop->setValue(null, $before);
    }
}

// ---------------------------------------------------------------------------
// 1. De dónde salen las actualizaciones
// ---------------------------------------------------------------------------
// El repo por defecto vive en `constants.php` (que viaja en el paquete) y no en
// `config.php` (que escribe el instalador y es distinto en cada sitio): esa es
// la razón de que una instalación actualizada sepa dónde mirar sin tocar nada.

check_upd('el paquete trae repo por defecto', defined('PP_UPDATES_GITHUB_REPO') && PP_UPDATES_GITHUB_REPO !== '');
check_upd('y es el que usa el servicio', UpdateService::githubRepo() === PP_UPDATES_GITHUB_REPO, UpdateService::githubRepo());

check_upd('config.php puede apuntar a otro repo',
    with_config(['updates' => ['github_repo' => 'otra/cosa']], fn() => UpdateService::githubRepo()) === 'otra/cosa');

// El repo acaba dentro de una URL: un valor con barras, espacios o esquema
// construiría una petición a saber dónde, así que no se acepta. Y un valor malo
// NO cae al repo del producto: el sitio se quedaría mirando, calladito, un repo
// distinto del que su dueño cree haber puesto.
foreach (['no-tiene-barra', 'demasiadas/barras/aqui', 'https://github.com/a/b', 'con espacio/repo', '../../etc'] as $bad) {
    check_upd("«{$bad}» no pasa el filtro",
        with_config(['updates' => ['github_repo' => $bad]], fn() => UpdateService::githubRepo()) === '',
        $bad);
}

// ---------------------------------------------------------------------------
// 2. Parseo de una release
// ---------------------------------------------------------------------------
// La forma del JSON está tomada de una respuesta real de la API
// (`/repos/{repo}/releases/latest`): tag_name, html_url, draft, prerelease y
// assets con name + browser_download_url.

$release = static fn(string $tag, array $assets): array => [
    'tag_name' => $tag,
    'html_url' => 'https://github.com/dvdgp9/PromptPress/releases/tag/' . $tag,
    'draft' => false,
    'prerelease' => false,
    'assets' => $assets,
];
$asset = static fn(string $name): array => [
    'name' => $name,
    'browser_download_url' => 'https://github.com/dvdgp9/PromptPress/releases/download/x/' . $name,
];

$ok = UpdateService::parseGithubRelease(
    $release('v1.3.0', [$asset('promptpress-1.3.0.zip'), $asset('promptpress-1.3.0.zip.sha256')]),
    '1.2.2'
);
check_upd('una release nueva se anuncia como disponible', $ok['has_update'] === true, json_encode($ok));
check_upd('la etiqueta pierde la v para poder comparar', $ok['latest_version'] === '1.3.0', (string) $ok['latest_version']);
check_upd('el paquete es el .zip', str_ends_with((string) $ok['download_url'], 'promptpress-1.3.0.zip'), (string) $ok['download_url']);
check_upd('el checksum se busca en el .sha256', str_ends_with((string) $ok['checksum_url'], '.sha256'), (string) ($ok['checksum_url'] ?? ''));
check_upd('el changelog apunta a la release', str_contains((string) $ok['changelog_url'], '/releases/tag/'), (string) $ok['changelog_url']);
check_upd('la fuente queda registrada', $ok['source'] === 'github');

// Misma versión y versión vieja: el panel no puede ofrecer "actualizar" a algo
// que ya está puesto (o que es anterior).
$same = UpdateService::parseGithubRelease($release('1.2.2', [$asset('p.zip')]), '1.2.2');
check_upd('la misma versión no ofrece actualización', $same['has_update'] === false && $same['status'] === 'ok', json_encode($same));
$old = UpdateService::parseGithubRelease($release('v1.1.0', [$asset('p.zip')]), '1.2.2');
check_upd('una versión anterior tampoco', $old['has_update'] === false, json_encode($old));
// Una release sin `.sha256` se instala igual: el checksum es comprobación extra.
check_upd('sin .sha256 se sigue pudiendo instalar',
    $same['download_url'] !== null && $same['checksum_url'] === null, json_encode($same));

// ---------------------------------------------------------------------------
// 3. Releases que NO valen
// ---------------------------------------------------------------------------
// Publicar una release sin adjuntar el paquete es el despiste más fácil de
// todos; si el cliente la diera por buena, «Aplicar» fallaría al descargar.

$noZip = UpdateService::parseGithubRelease($release('v1.3.0', [$asset('notas.txt'), $asset('composer.phar')]), '1.2.2');
check_upd('una release sin .zip no se ofrece', $noZip['has_update'] === false && $noZip['status'] === 'error', json_encode($noZip));
check_upd('y lo dice con su versión', str_contains((string) $noZip['message'], '1.3.0'), (string) $noZip['message']);

$noTag = UpdateService::parseGithubRelease(['assets' => [$asset('p.zip')]], '1.2.2');
check_upd('sin etiqueta no hay versión que instalar', $noTag['status'] === 'error', json_encode($noTag));

$noAssets = UpdateService::parseGithubRelease($release('v1.3.0', []), '1.2.2');
check_upd('una release vacía tampoco', $noAssets['has_update'] === false, json_encode($noAssets));

// El .zip se coge aunque no sea el primer asset, y el .sha256 aunque vaya antes.
$mixed = UpdateService::parseGithubRelease(
    $release('v2.0.0', [$asset('LEEME.md'), $asset('promptpress-2.0.0.zip.sha256'), $asset('promptpress-2.0.0.zip')]),
    '1.2.2'
);
check_upd('el orden de los assets da igual',
    str_ends_with((string) $mixed['download_url'], '.zip') && str_ends_with((string) $mixed['checksum_url'], '.sha256'),
    json_encode([$mixed['download_url'], $mixed['checksum_url']]));

// ---------------------------------------------------------------------------
// 4. Los mensajes existen en los cuatro idiomas del panel
// ---------------------------------------------------------------------------

foreach (['upd.err.github_none', 'upd.err.github_rate', 'upd.err.github_http', 'upd.err.github_no_zip', 'upd.err.github_bad_repo'] as $key) {
    check_upd("la clave {$key} está traducida", __($key) !== $key, __($key));
}

// ---------------------------------------------------------------------------
// 5. El paquete se publica con su checksum al lado
// ---------------------------------------------------------------------------
// Sin esto no hay nada que comparar: el `.sha256` es un asset que se sube a
// mano, así que el script que arma el paquete tiene que dejarlo hecho.

$build = (string) file_get_contents(PP_ROOT . '/scripts/build_package.php');
check_upd('build_package deja el .sha256', str_contains($build, "\$out . '.sha256'"), '');
check_upd('y recuerda cómo publicar', str_contains($build, 'releases/new'), '');

echo PHP_EOL . ($failed === 0 ? 'ALL PASS' : $failed . ' FAILED') . PHP_EOL;
exit($failed === 0 ? 0 : 1);
