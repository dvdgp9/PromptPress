<?php

declare(strict_types=1);

/**
 * INSTALL-EASY A — Instalador de un solo archivo (`dist/instalar.php`).
 *
 * Se sube ese único archivo al hosting, se abre en el navegador y él baja la
 * última release, comprueba el checksum, la descomprime y se borra. Aquí se
 * prueba sin red todo lo que decide si es seguro tocar la carpeta:
 *   - un zip con rutas que se salen de la carpeta (`../`) no se extrae;
 *   - un zip que no es PromptPress, o cuyo checksum no cuadra, tampoco;
 *   - el `.htaccess` del hosting no se pierde (los bloques de cPanel fijan la
 *     versión de PHP) y la página de bienvenida se aparta sin borrarla;
 *   - si ya hay PromptPress, no se vuelve a instalar encima.
 * La descarga real desde GitHub se comprueba aparte, de punta a punta.
 */

define('PPB_LIB_ONLY', true);
require __DIR__ . '/../dist/instalar.php';

$failed = 0;
function check_ppb(string $name, bool $ok, string $detail = ''): void
{
    global $failed;
    echo ($ok ? 'PASS' : 'FAIL') . ' ' . $name . PHP_EOL;
    if (!$ok) {
        $failed++;
        if ($detail !== '') echo '  -> ' . mb_substr($detail, 0, 400) . PHP_EOL;
    }
}

function ppb_test_dir(): string
{
    $dir = sys_get_temp_dir() . '/ppb-test-' . bin2hex(random_bytes(4));
    mkdir($dir . '/docroot', 0775, true);
    return $dir;
}

function ppb_test_rm(string $dir): void
{
    if (!is_dir($dir)) return;
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $f) {
        $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
    }
    rmdir($dir);
}

/** Zip con forma de paquete PromptPress (+ entradas extra). */
function ppb_test_zip(string $path, array $extra = [], array $skip = []): string
{
    $files = [
        'index.php' => '<?php echo "home";',
        '.htaccess' => "RewriteEngine On\nRewriteRule ^ index.php [QSA,L]\n",
        'config/constants.php' => '<?php define("PP_VERSION", "9.9.9");',
        'core/App.php' => '<?php',
        'app/Services/X.php' => '<?php',
        'install/index.php' => '<?php echo "installer";',
    ] + $extra;
    $zip = new ZipArchive();
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    foreach ($files as $name => $body) {
        if (in_array($name, $skip, true)) continue;
        $zip->addFromString($name, $body);
    }
    $zip->close();
    return hash_file('sha256', $path);
}

// ---------------------------------------------------------------------------
// 1. Entradas del zip
// ---------------------------------------------------------------------------

foreach (['../evil.php', 'a/../../evil.php', '/etc/passwd', '\\windows\\x', 'C:/x.php', 'c:\\x.php', "a\0b"] as $bad) {
    check_ppb('rechaza la entrada ' . json_encode($bad), ppb_unsafe_zip_entry(['index.php', $bad]) === $bad);
}
check_ppb('acepta rutas normales y ocultas',
    ppb_unsafe_zip_entry(['index.php', '.htaccess', 'app/Services/X.php', 'storage/form_uploads/.htaccess', 'a..b/c.php']) === null);

$names = ['index.php', 'config/constants.php', 'core/App.php', 'app/X.php', 'install/index.php'];
check_ppb('un paquete completo tiene la huella', ppb_missing_fingerprint($names) === []);
check_ppb('si falta core/ lo dice',
    ppb_missing_fingerprint(['index.php', 'config/constants.php', 'app/X.php', 'install/index.php']) === ['core/']);

// ---------------------------------------------------------------------------
// 2. Checksum
// ---------------------------------------------------------------------------

$h = str_repeat('ab', 32);
check_ppb('lee el formato de shasum', ppb_parse_sha($h . "  promptpress.zip\n") === $h);
check_ppb('normaliza a minúsculas', ppb_parse_sha(strtoupper($h)) === $h);
check_ppb('una página de error no es un checksum', ppb_parse_sha('Not Found') === null);

// ---------------------------------------------------------------------------
// 3. .htaccess del hosting
// ---------------------------------------------------------------------------

$cpanel = "# php -- BEGIN cPanel-generated handler, do not edit\n"
    . "# Set the \"ea-php82\" package as the default \"PHP\" programming language.\n"
    . "<IfModule mime_module>\n  AddHandler application/x-httpd-ea-php82 .php .php8 .phtml\n</IfModule>\n"
    . "# php -- END cPanel-generated handler, do not edit\n";
$old = "RewriteEngine On\nRewriteRule ^wp-admin - [L]\n\n" . $cpanel;
$new = "RewriteEngine On\nRewriteRule ^ index.php [QSA,L]\n";
$merged = ppb_merge_htaccess($old, $new);
check_ppb('conserva el bloque de cPanel', str_contains($merged, 'AddHandler application/x-httpd-ea-php82'), $merged);
check_ppb('y empieza por el de PromptPress', str_starts_with($merged, $new), $merged);
check_ppb('pero no arrastra reglas ajenas', !str_contains($merged, 'wp-admin'), $merged);
check_ppb('sin bloques de cPanel queda el nuevo tal cual', ppb_merge_htaccess("Options -Indexes\n", $new) === $new);
check_ppb('no duplica si ya estaba', substr_count(ppb_merge_htaccess($old, $merged), 'BEGIN cPanel') === 1);

// ---------------------------------------------------------------------------
// 4. Qué hay ya en la carpeta
// ---------------------------------------------------------------------------

$t = ppb_test_dir();
$doc = $t . '/docroot';
foreach (['instalar.php', '.htaccess', 'index.html', 'error_log', '.user.ini', 'favicon.ico'] as $f) {
    file_put_contents($doc . '/' . $f, 'x');
}
mkdir($doc . '/cgi-bin');
mkdir($doc . '/.well-known');
check_ppb('lo típico de un hosting recién creado no cuenta como ajeno',
    ppb_foreign_entries($doc, 'instalar.php') === [], json_encode(ppb_foreign_entries($doc, 'instalar.php')));
file_put_contents($doc . '/wp-config.php', 'x');
mkdir($doc . '/fotos');
check_ppb('lo demás sí', ppb_foreign_entries($doc, 'instalar.php') === ['fotos', 'wp-config.php'],
    json_encode(ppb_foreign_entries($doc, 'instalar.php')));
check_ppb('sin config/constants.php no hay PromptPress', ppb_already_installed($doc) === false);
mkdir($doc . '/config');
file_put_contents($doc . '/config/constants.php', '<?php');
check_ppb('con él, sí', ppb_already_installed($doc) === true);
ppb_test_rm($t);

// ---------------------------------------------------------------------------
// 5. Instalar desde el zip
// ---------------------------------------------------------------------------

// Checksum que no cuadra: no se toca nada.
$t = ppb_test_dir();
$doc = $t . '/docroot';
$sha = ppb_test_zip($t . '/p.zip');
$err = '';
try { ppb_install_from_zip($t . '/p.zip', str_repeat('0', 64), $doc); } catch (RuntimeException $e) { $err = $e->getMessage(); }
check_ppb('checksum distinto: no instala', $err !== '' && !is_file($doc . '/index.php'), $err);
check_ppb('y el error enseña los dos hashes', str_contains($err, $sha) && str_contains($err, str_repeat('0', 64)), $err);
ppb_test_rm($t);

// Zip que se sale de la carpeta: no se extrae NADA (ni lo bueno).
$t = ppb_test_dir();
$doc = $t . '/docroot';
$sha = ppb_test_zip($t . '/p.zip', ['../fuera.php' => '<?php // malo']);
$err = '';
try { ppb_install_from_zip($t . '/p.zip', $sha, $doc); } catch (RuntimeException $e) { $err = $e->getMessage(); }
check_ppb('zip con ../: no instala', $err !== '' && !is_file($t . '/fuera.php') && !is_file($doc . '/index.php'), $err);
check_ppb('y dice qué entrada', str_contains($err, '../fuera.php'), $err);
ppb_test_rm($t);

// Zip que no es PromptPress.
$t = ppb_test_dir();
$doc = $t . '/docroot';
$sha = ppb_test_zip($t . '/p.zip', [], ['config/constants.php']);
$err = '';
try { ppb_install_from_zip($t . '/p.zip', $sha, $doc); } catch (RuntimeException $e) { $err = $e->getMessage(); }
check_ppb('zip sin huella: no instala', $err !== '' && !is_file($doc . '/index.php') && str_contains($err, 'config/constants.php'), $err);
ppb_test_rm($t);

// Caso bueno, sobre un hosting cPanel recién creado.
$t = ppb_test_dir();
$doc = $t . '/docroot';
$sha = ppb_test_zip($t . '/p.zip');
file_put_contents($doc . '/.htaccess', $cpanel);
file_put_contents($doc . '/index.html', '<h1>Bienvenido a tu hosting</h1>');
$log = [];
$err = '';
try { $log = ppb_install_from_zip($t . '/p.zip', $sha, $doc); } catch (RuntimeException $e) { $err = $e->getMessage(); }
check_ppb('instala', $err === '' && is_file($doc . '/index.php') && is_file($doc . '/install/index.php'), $err);
check_ppb('el .htaccess es el de PromptPress', str_contains((string) @file_get_contents($doc . '/.htaccess'), 'RewriteRule ^ index.php'));
check_ppb('con el bloque de cPanel conservado', str_contains((string) @file_get_contents($doc . '/.htaccess'), 'ea-php82'));
check_ppb('y copia del original', @file_get_contents($doc . '/.htaccess' . PPB_BACKUP_SUFFIX) === $cpanel);
check_ppb('la bienvenida del hosting se aparta, no se borra',
    !is_file($doc . '/index.html') && is_file($doc . '/index.html' . PPB_BACKUP_SUFFIX));
check_ppb('deja un registro de lo que hizo', count($log) >= 3, json_encode($log));
check_ppb('sin zips temporales sueltos', glob($doc . '/.pp-*') === []);
ppb_test_rm($t);

// ---------------------------------------------------------------------------
// 6. Idiomas
// ---------------------------------------------------------------------------

$strings = ppb_strings();
check_ppb('los cuatro idiomas del panel', array_keys($strings) === ['es', 'en', 'fr', 'pt'], json_encode(array_keys($strings)));
foreach (['en', 'fr', 'pt'] as $l) {
    $diff = array_merge(array_diff_key($strings['es'], $strings[$l]), array_diff_key($strings[$l], $strings['es']));
    check_ppb("{$l} tiene las mismas claves que es", $diff === [], json_encode(array_keys($diff)));
}
check_ppb('elige por Accept-Language', ppb_lang_from('fr-FR,fr;q=0.9,en;q=0.8', null) === 'fr');
check_ppb('salta idiomas sin catálogo', ppb_lang_from('de-DE,pt-BR;q=0.8', null) === 'pt');
check_ppb('sin pistas, castellano', ppb_lang_from(null, null) === 'es');
check_ppb('?lang= manda', ppb_lang_from('fr', 'en') === 'en');
check_ppb('?lang= raro se ignora', ppb_lang_from('fr', '../x') === 'fr');

// ---------------------------------------------------------------------------
// 7. Distribución
// ---------------------------------------------------------------------------

$root = dirname(__DIR__);
check_ppb('no viaja dentro del paquete', str_contains((string) file_get_contents($root . '/scripts/build_package.php'), "'/dist'"));
$wf = (string) file_get_contents($root . '/.github/workflows/release.yml');
check_ppb('la release lo publica como instalar.php', substr_count($wf, 'dist/instalar.php') >= 2);
check_ppb('el README enlaza a él',
    str_contains((string) file_get_contents($root . '/README.md'), 'releases/latest/download/instalar.php'));

echo PHP_EOL . ($failed === 0 ? 'ALL PASS' : $failed . ' FAILED') . PHP_EOL;
exit($failed === 0 ? 0 : 1);
