<?php
/**
 * PromptPress — instalador de un solo archivo (INSTALL-EASY A).
 *
 * Se sube SOLO este archivo a la carpeta pública del dominio y se abre en el
 * navegador. Al pulsar el botón:
 *   1. descarga la última release (`releases/latest/download/promptpress.zip`)
 *      y su `.sha256`, y comprueba que coinciden;
 *   2. revisa el zip ANTES de extraer: ninguna ruta puede salirse de la carpeta
 *      y tiene que tener la huella de PromptPress;
 *   3. lo descomprime aquí, conservando del `.htaccess` del hosting los bloques
 *      de cPanel (fijan la versión de PHP) y apartando su página de bienvenida;
 *   4. se borra a sí mismo y lleva a `/install/`.
 *
 * Si en la carpeta ya hay PromptPress no descarga nada: por eso olvidarse este
 * archivo en el servidor no hace daño.
 *
 * Vive fuera del paquete (`/dist` está excluido en `build_package.php`) y se
 * publica como asset `instalar.php` de cada release.
 *
 * Sintaxis compatible con PHP 7.1 A PROPÓSITO: en un hosting con PHP 7 tiene
 * que poder decir «necesitas PHP 8», no morir con un error de sintaxis. Nada de
 * funciones flecha, `match`, `str_contains`, `mixed`…
 *
 * El CSS va en un <style> dentro del archivo: es un instalador de UN archivo.
 */

declare(strict_types=1);

const PPB_REPO = 'dvdgp9/PromptPress';
const PPB_ZIP_URL = 'https://github.com/' . PPB_REPO . '/releases/latest/download/promptpress.zip';
const PPB_SHA_URL = PPB_ZIP_URL . '.sha256';
const PPB_MIN_PHP = '8.0';
const PPB_BACKUP_SUFFIX = '.antes-de-promptpress';
const PPB_LOCK = '.pp-instalar.lock';

/** Lo que tiene que traer el zip para ser PromptPress (las `/` finales son carpetas). */
const PPB_FINGERPRINT = ['index.php', 'config/constants.php', 'install/index.php', 'core/', 'app/'];

/** Lo que un hosting recién creado ya trae y no cuenta como «carpeta con cosas». */
const PPB_HARMLESS = [
    '.htaccess', '.well-known', 'cgi-bin', 'error_log', '.user.ini', 'php.ini',
    '.ftpquota', 'favicon.ico', '.DS_Store', 'Thumbs.db',
];

/** Páginas de bienvenida del hosting: se apartan (renombradas), nunca se borran. */
const PPB_PLACEHOLDERS = ['index.html', 'index.htm', 'default.html', 'default.htm'];

// ---------------------------------------------------------------------------
// Textos
// ---------------------------------------------------------------------------

function ppb_strings(): array
{
    static $s = null;
    if ($s !== null) return $s;
    $s = [
        'es' => [
            'title' => 'Instalar PromptPress',
            'intro' => 'Este archivo descarga la última versión de PromptPress, comprueba que ha llegado entera y la deja lista en esta carpeta. Después se borra solo y te lleva al instalador.',
            'req_title' => 'Comprobaciones',
            'req_php' => 'PHP {min} o superior (tienes {version})',
            'req_curl' => 'Extensión cURL (para descargar)',
            'req_zip' => 'Extensión zip (para descomprimir)',
            'req_writable' => 'Permiso para escribir en esta carpeta',
            'req_fail' => 'Hay que resolver lo marcado en rojo antes de seguir. Si no puedes cambiarlo, pídeselo a tu hosting o instala a mano con el zip.',
            'foreign_title' => 'Esta carpeta no está vacía',
            'foreign_help' => 'Hay archivos que no son del hosting. PromptPress no los borra, pero sí sobrescribe los que se llamen igual que los suyos (por ejemplo index.php).',
            'foreign_confirm' => 'Lo entiendo, instalar igualmente aquí',
            'button' => 'Descargar e instalar',
            'working' => 'Descargando e instalando…',
            'already_title' => 'PromptPress ya está en esta carpeta',
            'already_text' => 'No hace falta volver a descargarlo. Este archivo ya no sirve para nada aquí: bórralo si sigue en el servidor.',
            'already_selfdeleted' => 'Este archivo se ha borrado solo.',
            'cta_install' => 'Ir al instalador',
            'cta_admin' => 'Ir al panel',
            'error_title' => 'No se ha podido instalar',
            'error_nothing' => 'Si el fallo ha sido antes de descomprimir, la carpeta no se ha tocado.',
            'retry' => 'Volver a intentarlo',
            'manual' => 'Alternativa: descarga {link}, descomprímelo en esta carpeta y abre /install/.',
            'busy' => 'Ya hay una instalación en marcha en esta carpeta. Espera un minuto y recarga.',
            'done_title' => 'Listo: PromptPress está descargado',
            'done_text' => 'Ahora sigue con el instalador para conectar la base de datos y crear tu usuario.',
            'warn_selfdelete' => 'No he podido borrar instalar.php. Bórralo tú desde el administrador de archivos o por FTP.',
            'log_title' => 'Detalle técnico',
            'log_download' => 'Descargado {url} ({size})',
            'log_checksum' => 'Checksum sha256 correcto: {sha}',
            'log_entries' => 'Zip revisado: {count} entradas, todas dentro de la carpeta',
            'log_extract' => 'Descomprimido en {dir}',
            'log_htaccess_backup' => '.htaccess anterior guardado como {file}',
            'log_htaccess_kept' => 'Conservados {count} bloques de cPanel en el .htaccess',
            'log_renamed' => '{file} apartado como {to}',
            'err_download' => 'No se pudo descargar {url} (HTTP {code}{error}).',
            'err_checksum_fetch' => 'No se pudo leer el checksum en {url}. Sin él no se instala.',
            'err_checksum' => 'El archivo descargado no coincide con su checksum (esperado {expected}, recibido {got}). Puede que la descarga se cortara: vuelve a intentarlo.',
            'err_zip_open' => 'No se pudo abrir el zip descargado (código {code}).',
            'err_zip_unsafe' => 'El zip trae una ruta que se sale de la carpeta ({entry}). No se ha extraído nada.',
            'err_zip_not_pp' => 'El zip no parece PromptPress: falta {missing}. No se ha extraído nada.',
            'err_extract' => 'Falló al descomprimir en {dir}. Revisa los permisos de la carpeta.',
            'err_requirements' => 'Faltan requisitos.',
            'err_confirm' => 'Marca la casilla de confirmación para instalar en una carpeta que no está vacía.',
        ],
        'en' => [
            'title' => 'Install PromptPress',
            'intro' => 'This file downloads the latest version of PromptPress, checks that it arrived intact and sets it up in this folder. Then it deletes itself and takes you to the installer.',
            'req_title' => 'Checks',
            'req_php' => 'PHP {min} or newer (you have {version})',
            'req_curl' => 'cURL extension (to download)',
            'req_zip' => 'zip extension (to unpack)',
            'req_writable' => 'Permission to write in this folder',
            'req_fail' => 'Fix the items in red before continuing. If you can’t change them, ask your hosting provider or install manually with the zip.',
            'foreign_title' => 'This folder is not empty',
            'foreign_help' => 'There are files that don’t come from the hosting. PromptPress won’t delete them, but it will overwrite any that share a name with its own (index.php, for example).',
            'foreign_confirm' => 'I understand, install here anyway',
            'button' => 'Download and install',
            'working' => 'Downloading and installing…',
            'already_title' => 'PromptPress is already in this folder',
            'already_text' => 'No need to download it again. This file is no longer needed here: delete it if it is still on the server.',
            'already_selfdeleted' => 'This file has deleted itself.',
            'cta_install' => 'Go to the installer',
            'cta_admin' => 'Go to the dashboard',
            'error_title' => 'Installation failed',
            'error_nothing' => 'If it failed before unpacking, the folder was not touched.',
            'retry' => 'Try again',
            'manual' => 'Alternative: download {link}, unpack it in this folder and open /install/.',
            'busy' => 'An installation is already running in this folder. Wait a minute and reload.',
            'done_title' => 'Done: PromptPress has been downloaded',
            'done_text' => 'Now continue with the installer to connect the database and create your user.',
            'warn_selfdelete' => 'Couldn’t delete instalar.php. Delete it yourself from the file manager or via FTP.',
            'log_title' => 'Technical details',
            'log_download' => 'Downloaded {url} ({size})',
            'log_checksum' => 'sha256 checksum OK: {sha}',
            'log_entries' => 'Zip checked: {count} entries, all inside the folder',
            'log_extract' => 'Unpacked into {dir}',
            'log_htaccess_backup' => 'Previous .htaccess saved as {file}',
            'log_htaccess_kept' => 'Kept {count} cPanel block(s) in .htaccess',
            'log_renamed' => '{file} moved aside as {to}',
            'err_download' => 'Couldn’t download {url} (HTTP {code}{error}).',
            'err_checksum_fetch' => 'Couldn’t read the checksum at {url}. Installation won’t proceed without it.',
            'err_checksum' => 'The downloaded file doesn’t match its checksum (expected {expected}, got {got}). The download may have been cut off: try again.',
            'err_zip_open' => 'Couldn’t open the downloaded zip (code {code}).',
            'err_zip_unsafe' => 'The zip contains a path that escapes the folder ({entry}). Nothing was extracted.',
            'err_zip_not_pp' => 'The zip doesn’t look like PromptPress: {missing} is missing. Nothing was extracted.',
            'err_extract' => 'Unpacking into {dir} failed. Check the folder permissions.',
            'err_requirements' => 'Some requirements are missing.',
            'err_confirm' => 'Tick the confirmation box to install in a folder that is not empty.',
        ],
        'fr' => [
            'title' => 'Installer PromptPress',
            'intro' => 'Ce fichier télécharge la dernière version de PromptPress, vérifie qu’elle est arrivée complète et l’installe dans ce dossier. Ensuite, il se supprime tout seul et vous emmène vers l’installateur.',
            'req_title' => 'Vérifications',
            'req_php' => 'PHP {min} ou supérieur (vous avez {version})',
            'req_curl' => 'Extension cURL (pour télécharger)',
            'req_zip' => 'Extension zip (pour décompresser)',
            'req_writable' => 'Droit d’écriture dans ce dossier',
            'req_fail' => 'Corrigez les points en rouge avant de continuer. Si vous ne pouvez pas les modifier, demandez à votre hébergeur ou installez manuellement avec le zip.',
            'foreign_title' => 'Ce dossier n’est pas vide',
            'foreign_help' => 'Certains fichiers ne viennent pas de l’hébergeur. PromptPress ne les supprime pas, mais il écrase ceux qui portent le même nom que les siens (index.php, par exemple).',
            'foreign_confirm' => 'J’ai compris, installer ici quand même',
            'button' => 'Télécharger et installer',
            'working' => 'Téléchargement et installation…',
            'already_title' => 'PromptPress est déjà dans ce dossier',
            'already_text' => 'Inutile de le télécharger à nouveau. Ce fichier ne sert plus à rien ici : supprimez-le s’il est encore sur le serveur.',
            'already_selfdeleted' => 'Ce fichier s’est supprimé tout seul.',
            'cta_install' => 'Aller à l’installateur',
            'cta_admin' => 'Aller au panneau',
            'error_title' => 'L’installation a échoué',
            'error_nothing' => 'Si l’erreur s’est produite avant la décompression, le dossier n’a pas été modifié.',
            'retry' => 'Réessayer',
            'manual' => 'Alternative : téléchargez {link}, décompressez-le dans ce dossier et ouvrez /install/.',
            'busy' => 'Une installation est déjà en cours dans ce dossier. Attendez une minute et rechargez.',
            'done_title' => 'C’est fait : PromptPress est téléchargé',
            'done_text' => 'Continuez maintenant avec l’installateur pour connecter la base de données et créer votre utilisateur.',
            'warn_selfdelete' => 'Impossible de supprimer instalar.php. Supprimez-le vous-même depuis le gestionnaire de fichiers ou par FTP.',
            'log_title' => 'Détails techniques',
            'log_download' => 'Téléchargé {url} ({size})',
            'log_checksum' => 'Somme de contrôle sha256 correcte : {sha}',
            'log_entries' => 'Zip vérifié : {count} entrées, toutes dans le dossier',
            'log_extract' => 'Décompressé dans {dir}',
            'log_htaccess_backup' => '.htaccess précédent enregistré sous {file}',
            'log_htaccess_kept' => '{count} bloc(s) cPanel conservé(s) dans le .htaccess',
            'log_renamed' => '{file} mis de côté sous {to}',
            'err_download' => 'Impossible de télécharger {url} (HTTP {code}{error}).',
            'err_checksum_fetch' => 'Impossible de lire la somme de contrôle sur {url}. Sans elle, l’installation ne continue pas.',
            'err_checksum' => 'Le fichier téléchargé ne correspond pas à sa somme de contrôle (attendu {expected}, reçu {got}). Le téléchargement a peut-être été interrompu : réessayez.',
            'err_zip_open' => 'Impossible d’ouvrir le zip téléchargé (code {code}).',
            'err_zip_unsafe' => 'Le zip contient un chemin qui sort du dossier ({entry}). Rien n’a été extrait.',
            'err_zip_not_pp' => 'Le zip ne ressemble pas à PromptPress : il manque {missing}. Rien n’a été extrait.',
            'err_extract' => 'La décompression dans {dir} a échoué. Vérifiez les droits du dossier.',
            'err_requirements' => 'Il manque des prérequis.',
            'err_confirm' => 'Cochez la case de confirmation pour installer dans un dossier qui n’est pas vide.',
        ],
        'pt' => [
            'title' => 'Instalar o PromptPress',
            'intro' => 'Este ficheiro descarrega a versão mais recente do PromptPress, verifica que chegou completa e deixa-a pronta nesta pasta. Depois apaga-se sozinho e leva-o ao instalador.',
            'req_title' => 'Verificações',
            'req_php' => 'PHP {min} ou superior (tem {version})',
            'req_curl' => 'Extensão cURL (para descarregar)',
            'req_zip' => 'Extensão zip (para descomprimir)',
            'req_writable' => 'Permissão de escrita nesta pasta',
            'req_fail' => 'Resolva o que está a vermelho antes de continuar. Se não o puder alterar, peça ao seu alojamento ou instale manualmente com o zip.',
            'foreign_title' => 'Esta pasta não está vazia',
            'foreign_help' => 'Há ficheiros que não são do alojamento. O PromptPress não os apaga, mas substitui os que tenham o mesmo nome que os seus (por exemplo index.php).',
            'foreign_confirm' => 'Compreendo, instalar aqui mesmo assim',
            'button' => 'Descarregar e instalar',
            'working' => 'A descarregar e instalar…',
            'already_title' => 'O PromptPress já está nesta pasta',
            'already_text' => 'Não é preciso descarregá-lo de novo. Este ficheiro já não serve para nada aqui: apague-o se ainda estiver no servidor.',
            'already_selfdeleted' => 'Este ficheiro apagou-se sozinho.',
            'cta_install' => 'Ir para o instalador',
            'cta_admin' => 'Ir para o painel',
            'error_title' => 'Não foi possível instalar',
            'error_nothing' => 'Se a falha ocorreu antes de descomprimir, a pasta não foi alterada.',
            'retry' => 'Tentar novamente',
            'manual' => 'Alternativa: descarregue {link}, descomprima-o nesta pasta e abra /install/.',
            'busy' => 'Já há uma instalação em curso nesta pasta. Aguarde um minuto e recarregue.',
            'done_title' => 'Pronto: o PromptPress foi descarregado',
            'done_text' => 'Agora continue com o instalador para ligar a base de dados e criar o seu utilizador.',
            'warn_selfdelete' => 'Não foi possível apagar instalar.php. Apague-o pelo gestor de ficheiros ou por FTP.',
            'log_title' => 'Detalhe técnico',
            'log_download' => 'Descarregado {url} ({size})',
            'log_checksum' => 'Checksum sha256 correto: {sha}',
            'log_entries' => 'Zip verificado: {count} entradas, todas dentro da pasta',
            'log_extract' => 'Descomprimido em {dir}',
            'log_htaccess_backup' => '.htaccess anterior guardado como {file}',
            'log_htaccess_kept' => 'Conservados {count} blocos do cPanel no .htaccess',
            'log_renamed' => '{file} posto de parte como {to}',
            'err_download' => 'Não foi possível descarregar {url} (HTTP {code}{error}).',
            'err_checksum_fetch' => 'Não foi possível ler o checksum em {url}. Sem ele não se instala.',
            'err_checksum' => 'O ficheiro descarregado não corresponde ao seu checksum (esperado {expected}, recebido {got}). A descarga pode ter sido interrompida: tente novamente.',
            'err_zip_open' => 'Não foi possível abrir o zip descarregado (código {code}).',
            'err_zip_unsafe' => 'O zip traz um caminho que sai da pasta ({entry}). Não foi extraído nada.',
            'err_zip_not_pp' => 'O zip não parece ser o PromptPress: falta {missing}. Não foi extraído nada.',
            'err_extract' => 'Falhou ao descomprimir em {dir}. Verifique as permissões da pasta.',
            'err_requirements' => 'Faltam requisitos.',
            'err_confirm' => 'Marque a caixa de confirmação para instalar numa pasta que não está vazia.',
        ],
    ];
    return $s;
}

/** Idioma: `?lang=` válido manda; si no, el primero del Accept-Language con textos; si no, castellano. */
function ppb_lang_from(?string $accept, ?string $override): string
{
    $available = array_keys(ppb_strings());
    if ($override !== null && in_array($override, $available, true)) {
        return $override;
    }
    foreach (explode(',', (string) $accept) as $part) {
        $tag = trim(explode(';', $part)[0]);
        $code = strtolower(substr($tag, 0, 2));
        if (in_array($code, $available, true)) {
            return $code;
        }
    }
    return 'es';
}

function ppb_t(string $key, array $vars = []): string
{
    $all = ppb_strings();
    $lang = isset($GLOBALS['ppb_lang'], $all[$GLOBALS['ppb_lang']]) ? $GLOBALS['ppb_lang'] : 'es';
    $text = $all[$lang][$key] ?? ($all['es'][$key] ?? $key);
    foreach ($vars as $k => $v) {
        $text = str_replace('{' . $k . '}', (string) $v, $text);
    }
    return $text;
}

function ppb_e(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// ---------------------------------------------------------------------------
// Comprobaciones (sin efectos)
// ---------------------------------------------------------------------------

/** Primera entrada del zip que escribiría fuera de la carpeta, o null si todas son seguras. */
function ppb_unsafe_zip_entry(array $names): ?string
{
    foreach ($names as $name) {
        $name = (string) $name;
        if (strpos($name, "\0") !== false) return $name;
        $norm = str_replace('\\', '/', $name);
        if ($norm !== '' && $norm[0] === '/') return $name;
        if (preg_match('#^[A-Za-z]:#', $norm)) return $name;
        foreach (explode('/', $norm) as $segment) {
            if ($segment === '..') return $name;
        }
    }
    return null;
}

/** Partes de la huella de PromptPress que no aparecen entre las entradas del zip. */
function ppb_missing_fingerprint(array $names): array
{
    $missing = [];
    foreach (PPB_FINGERPRINT as $needle) {
        $found = false;
        foreach ($names as $name) {
            $name = (string) $name;
            $isDir = substr($needle, -1) === '/';
            if ($isDir ? strpos($name, $needle) === 0 : $name === $needle) {
                $found = true;
                break;
            }
        }
        if (!$found) $missing[] = $needle;
    }
    return $missing;
}

/** El hash de un archivo `.sha256` (formato de shasum), o null si no lo es. */
function ppb_parse_sha(string $text): ?string
{
    return preg_match('/\b([a-f0-9]{64})\b/i', $text, $m) === 1 ? strtolower($m[1]) : null;
}

/** Bloques `# … BEGIN cPanel-generated … / # … END cPanel-generated …` de un .htaccess. */
function ppb_cpanel_blocks(string $htaccess): array
{
    preg_match_all('/^#[^\n]*BEGIN cPanel-generated[^\n]*\n.*?^#[^\n]*END cPanel-generated[^\n]*$/ms', $htaccess, $m);
    return $m[0];
}

/**
 * El .htaccess de PromptPress + los bloques de cPanel del anterior (el de la
 * versión de PHP sobre todo). Lo demás del anterior no se arrastra: podrían ser
 * reglas de otro CMS que romperían las rutas.
 */
function ppb_merge_htaccess(string $old, string $new): string
{
    $out = $new;
    foreach (ppb_cpanel_blocks($old) as $block) {
        if (strpos($out, $block) !== false) continue;
        $out = rtrim($out, "\r\n") . "\n\n" . $block . "\n";
    }
    return $out;
}

function ppb_already_installed(string $dir): bool
{
    return is_file($dir . '/config/constants.php');
}

/** Lo que hay en la carpeta y no es del hosting ni de este instalador, ordenado. */
function ppb_foreign_entries(string $dir, string $self): array
{
    $out = [];
    foreach ((array) @scandir($dir) as $entry) {
        $entry = (string) $entry;
        if ($entry === '.' || $entry === '..' || $entry === $self) continue;
        if (strpos($entry, '.pp-') === 0) continue;
        if (in_array($entry, PPB_HARMLESS, true) || in_array($entry, PPB_PLACEHOLDERS, true)) continue;
        $out[] = $entry;
    }
    sort($out);
    return $out;
}

function ppb_requirements(string $dir): array
{
    return [
        [ppb_t('req_php', ['min' => PPB_MIN_PHP, 'version' => PHP_VERSION]), version_compare(PHP_VERSION, PPB_MIN_PHP, '>=')],
        [ppb_t('req_curl'), function_exists('curl_init')],
        [ppb_t('req_zip'), class_exists('ZipArchive')],
        [ppb_t('req_writable'), is_writable($dir)],
    ];
}

function ppb_size(int $bytes): string
{
    return $bytes >= 1048576 ? round($bytes / 1048576, 1) . ' MB' : round($bytes / 1024) . ' KB';
}

// ---------------------------------------------------------------------------
// Acciones
// ---------------------------------------------------------------------------

/**
 * GET por HTTPS (también tras redirecciones). Con `$dest` escribe ahí el
 * cuerpo; si no, lo devuelve.
 *
 * @return array{0:int,1:string,2:string} código HTTP, cuerpo (vacío si $dest), error de cURL
 */
function ppb_http_get(string $url, ?string $dest = null): array
{
    $ch = curl_init($url);
    $fh = null;
    $opts = [
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_CONNECTTIMEOUT => 20,
        CURLOPT_TIMEOUT => 240,
        CURLOPT_USERAGENT => 'PromptPress-instalar/1 (PHP ' . PHP_VERSION . ')',
    ];
    if ($dest !== null) {
        $fh = fopen($dest, 'wb');
        $opts[CURLOPT_FILE] = $fh;
    } else {
        $opts[CURLOPT_RETURNTRANSFER] = true;
    }
    curl_setopt_array($ch, $opts);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = (string) curl_error($ch);
    // Sin curl_close(): obsoleto en PHP 8.5, y un aviso en pantalla antes del
    // `Location` final dejaría la redirección rota.
    unset($ch);
    if ($fh) fclose($fh);
    return [$code, is_string($body) ? $body : '', $error];
}

/**
 * Comprueba y descomprime `$zipPath` en `$dir`. Si algo no cuadra lanza
 * RuntimeException ANTES de extraer: la carpeta queda como estaba.
 *
 * @param array $log se va rellenando aunque falle, para enseñarlo en el error
 * @return array el registro de lo hecho
 */
function ppb_install_from_zip(string $zipPath, string $expectedSha, string $dir, array &$log = []): array
{
    $got = (string) hash_file('sha256', $zipPath);
    if (!hash_equals(strtolower($expectedSha), $got)) {
        throw new RuntimeException(ppb_t('err_checksum', ['expected' => $expectedSha, 'got' => $got]));
    }
    $log[] = ppb_t('log_checksum', ['sha' => $got]);

    $zip = new ZipArchive();
    $opened = $zip->open($zipPath);
    if ($opened !== true) {
        throw new RuntimeException(ppb_t('err_zip_open', ['code' => (string) $opened]));
    }
    $names = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $names[] = (string) $zip->getNameIndex($i);
    }
    $bad = ppb_unsafe_zip_entry($names);
    if ($bad !== null) {
        $zip->close();
        throw new RuntimeException(ppb_t('err_zip_unsafe', ['entry' => $bad]));
    }
    $missing = ppb_missing_fingerprint($names);
    if ($missing !== []) {
        $zip->close();
        throw new RuntimeException(ppb_t('err_zip_not_pp', ['missing' => implode(', ', $missing)]));
    }
    $log[] = ppb_t('log_entries', ['count' => count($names)]);

    // El .htaccess del hosting se guarda antes de que el zip lo pise.
    $htaccess = $dir . '/.htaccess';
    $oldHtaccess = is_file($htaccess) ? (string) @file_get_contents($htaccess) : null;
    if ($oldHtaccess !== null) {
        @file_put_contents($htaccess . PPB_BACKUP_SUFFIX, $oldHtaccess);
        $log[] = ppb_t('log_htaccess_backup', ['file' => '.htaccess' . PPB_BACKUP_SUFFIX]);
    }

    if (!$zip->extractTo($dir)) {
        $zip->close();
        throw new RuntimeException(ppb_t('err_extract', ['dir' => $dir]));
    }
    $zip->close();
    $log[] = ppb_t('log_extract', ['dir' => $dir]);

    if ($oldHtaccess !== null && is_file($htaccess)) {
        $kept = ppb_cpanel_blocks($oldHtaccess);
        if ($kept !== []) {
            file_put_contents($htaccess, ppb_merge_htaccess($oldHtaccess, (string) file_get_contents($htaccess)));
            $log[] = ppb_t('log_htaccess_kept', ['count' => count($kept)]);
        }
    }

    foreach (PPB_PLACEHOLDERS as $file) {
        if (is_file($dir . '/' . $file) && @rename($dir . '/' . $file, $dir . '/' . $file . PPB_BACKUP_SUFFIX)) {
            $log[] = ppb_t('log_renamed', ['file' => $file, 'to' => $file . PPB_BACKUP_SUFFIX]);
        }
    }

    return $log;
}

/** Descarga la última release y la instala en `$dir`. */
function ppb_download_and_install(string $dir, array &$log): void
{
    [$code, $body, $error] = ppb_http_get(PPB_SHA_URL);
    $sha = $code === 200 ? ppb_parse_sha($body) : null;
    if ($sha === null) {
        throw new RuntimeException(ppb_t('err_checksum_fetch', ['url' => PPB_SHA_URL]) . ($error !== '' ? ' (' . $error . ')' : ''));
    }

    $tmp = $dir . '/.pp-' . bin2hex(random_bytes(8)) . '.zip';
    try {
        [$code, , $error] = ppb_http_get(PPB_ZIP_URL, $tmp);
        if ($code !== 200 || !is_file($tmp) || filesize($tmp) === 0) {
            throw new RuntimeException(ppb_t('err_download', [
                'url' => PPB_ZIP_URL,
                'code' => (string) $code,
                'error' => $error !== '' ? ', ' . $error : '',
            ]));
        }
        $log[] = ppb_t('log_download', ['url' => PPB_ZIP_URL, 'size' => ppb_size((int) filesize($tmp))]);
        ppb_install_from_zip($tmp, $sha, $dir, $log);
    } finally {
        @unlink($tmp);
    }
}

// ---------------------------------------------------------------------------
// Páginas
// ---------------------------------------------------------------------------

function ppb_page(string $title, string $body): void
{
    $lang = isset($GLOBALS['ppb_lang']) ? (string) $GLOBALS['ppb_lang'] : 'es';
    echo '<!doctype html><html lang="' . ppb_e($lang) . '"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<meta name="robots" content="noindex">'
        . '<title>' . ppb_e($title) . ' · PromptPress</title><style>'
        . ':root{--bg:#f6f6f4;--card:#fff;--text:#1c1c1a;--muted:#6b6b66;--line:#e4e4df;--accent:#1f5eff;--ok:#1a7f45;--bad:#b42318;--warn-bg:#fff8e6;--warn-line:#f0d58a;--bad-bg:#fdf1f0;--bad-line:#f2c4bf}'
        . '@media (prefers-color-scheme:dark){:root{--bg:#141413;--card:#1e1e1c;--text:#ecece8;--muted:#a3a39c;--line:#33332f;--accent:#7aa2ff;--ok:#5cc98a;--bad:#ff8a7a;--warn-bg:#2c2614;--warn-line:#5c4d1f;--bad-bg:#2e1a18;--bad-line:#5e2a24}}'
        . '*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--text);font:16px/1.55 system-ui,-apple-system,"Segoe UI",sans-serif}'
        . 'main{max-width:600px;margin:48px auto;padding:0 16px}.card{background:var(--card);border:1px solid var(--line);border-radius:12px;padding:28px}'
        . 'h1{font-size:1.45rem;margin:0 0 12px}h2{font-size:1rem;margin:24px 0 8px}p{margin:0 0 12px}.muted{color:var(--muted);font-size:.9rem}'
        . 'ul.checks{list-style:none;padding:0;margin:0}ul.checks li{padding:6px 0;border-bottom:1px solid var(--line)}ul.checks li:last-child{border-bottom:0}'
        . '.ok::before{content:"✓ ";color:var(--ok);font-weight:700}.bad{color:var(--bad)}.bad::before{content:"✗ ";font-weight:700}'
        . '.box{border:1px solid var(--warn-line);background:var(--warn-bg);border-radius:8px;padding:14px 16px;margin:16px 0}'
        . '.box.error{border-color:var(--bad-line);background:var(--bad-bg)}.box ul{margin:6px 0 10px;padding-left:20px;max-height:180px;overflow:auto}'
        . 'label{display:flex;gap:8px;align-items:flex-start;cursor:pointer}'
        . '.btn{display:inline-block;margin-top:20px;background:var(--accent);color:#fff;border:0;border-radius:8px;padding:12px 20px;font:inherit;font-weight:600;cursor:pointer;text-decoration:none}'
        . '.btn.secondary{background:transparent;color:var(--accent);border:1px solid var(--line);margin-left:8px}.btn[disabled]{opacity:.6;cursor:wait}'
        . 'details{margin-top:20px}summary{cursor:pointer;color:var(--muted);font-size:.9rem}details ol{font:13px/1.5 ui-monospace,Menlo,monospace;padding-left:20px;overflow-wrap:anywhere}'
        . 'a{color:var(--accent)}code{font-family:ui-monospace,Menlo,monospace;font-size:.9em}'
        . '</style></head><body><main><div class="card"><h1>' . ppb_e($title) . '</h1>' . $body . '</div></main></body></html>';
}

function ppb_log_html(array $log): string
{
    if ($log === []) return '';
    $items = '';
    foreach ($log as $line) $items .= '<li>' . ppb_e((string) $line) . '</li>';
    return '<details><summary>' . ppb_e(ppb_t('log_title')) . '</summary><ol>' . $items . '</ol></details>';
}

function ppb_manual_html(): string
{
    $link = '<a href="' . ppb_e(PPB_ZIP_URL) . '">promptpress.zip</a>';
    return '<p class="muted">' . str_replace('{link}', $link, ppb_e(ppb_t('manual'))) . '</p>';
}

function ppb_form_html(array $checks, array $foreign, string $error): string
{
    $reqOk = true;
    $items = '';
    foreach ($checks as $c) {
        $reqOk = $reqOk && $c[1];
        $items .= '<li class="' . ($c[1] ? 'ok' : 'bad') . '">' . ppb_e($c[0]) . '</li>';
    }
    $html = '<p>' . ppb_e(ppb_t('intro')) . '</p>'
        . '<h2>' . ppb_e(ppb_t('req_title')) . '</h2><ul class="checks">' . $items . '</ul>';
    if ($error !== '') {
        $html .= '<div class="box error"><p>' . ppb_e($error) . '</p></div>';
    }
    if (!$reqOk) {
        return $html . '<div class="box error"><p>' . ppb_e(ppb_t('req_fail')) . '</p></div>' . ppb_manual_html();
    }

    $html .= '<form method="post" id="ppb-form">';
    if ($foreign !== []) {
        $list = '';
        foreach ($foreign as $f) $list .= '<li><code>' . ppb_e($f) . '</code></li>';
        $html .= '<div class="box"><p><strong>' . ppb_e(ppb_t('foreign_title')) . '</strong></p>'
            . '<p>' . ppb_e(ppb_t('foreign_help')) . '</p><ul>' . $list . '</ul>'
            . '<label><input type="checkbox" name="confirm" value="1"> ' . ppb_e(ppb_t('foreign_confirm')) . '</label></div>';
    }
    $html .= '<button class="btn" type="submit" id="ppb-go">' . ppb_e(ppb_t('button')) . '</button></form>'
        . '<script>document.getElementById("ppb-form").addEventListener("submit",function(){'
        . 'var b=document.getElementById("ppb-go");b.disabled=true;b.textContent=' . json_encode(ppb_t('working')) . ';});</script>';
    return $html . ppb_manual_html();
}

// ---------------------------------------------------------------------------
// Controlador
// ---------------------------------------------------------------------------

function ppb_run(): void
{
    $dir = __DIR__;
    $self = basename(__FILE__);
    $GLOBALS['ppb_lang'] = ppb_lang_from(
        isset($_SERVER['HTTP_ACCEPT_LANGUAGE']) ? (string) $_SERVER['HTTP_ACCEPT_LANGUAGE'] : null,
        isset($_GET['lang']) ? (string) $_GET['lang'] : null
    );
    header('Content-Type: text/html; charset=UTF-8');
    header('Cache-Control: no-store');
    header('X-Robots-Tag: noindex');

    $base = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/'))), '/');
    $installUrl = $base . '/install/';
    $ctas = '<a class="btn" href="' . ppb_e($installUrl) . '">' . ppb_e(ppb_t('cta_install')) . '</a>';

    if (ppb_already_installed($dir)) {
        $deleted = @unlink(__FILE__);
        ppb_page(ppb_t('already_title'), '<p>' . ppb_e(ppb_t($deleted ? 'already_selfdeleted' : 'already_text')) . '</p>'
            . $ctas . '<a class="btn secondary" href="' . ppb_e($base . '/admin/') . '">' . ppb_e(ppb_t('cta_admin')) . '</a>');
        return;
    }

    $checks = ppb_requirements($dir);
    $foreign = ppb_foreign_entries($dir, $self);

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        ppb_page(ppb_t('title'), ppb_form_html($checks, $foreign, ''));
        return;
    }

    foreach ($checks as $c) {
        if (!$c[1]) {
            ppb_page(ppb_t('title'), ppb_form_html($checks, $foreign, ppb_t('err_requirements')));
            return;
        }
    }
    if ($foreign !== [] && empty($_POST['confirm'])) {
        ppb_page(ppb_t('title'), ppb_form_html($checks, $foreign, ppb_t('err_confirm')));
        return;
    }

    // Dos clics (o dos pestañas) no pueden descomprimir a la vez.
    $lock = @fopen($dir . '/' . PPB_LOCK, 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
        ppb_page(ppb_t('title'), '<div class="box error"><p>' . ppb_e(ppb_t('busy')) . '</p></div>');
        return;
    }

    @set_time_limit(300);
    ignore_user_abort(true);
    $log = [];
    try {
        ppb_download_and_install($dir, $log);
    } catch (Throwable $e) {
        flock($lock, LOCK_UN);
        fclose($lock);
        @unlink($dir . '/' . PPB_LOCK);
        ppb_page(ppb_t('error_title'), '<div class="box error"><p>' . ppb_e($e->getMessage()) . '</p></div>'
            . '<p class="muted">' . ppb_e(ppb_t('error_nothing')) . '</p>'
            . ppb_log_html($log)
            . '<a class="btn" href="' . ppb_e($self . (isset($_GET['lang']) ? '?lang=' . rawurlencode((string) $_GET['lang']) : '')) . '">' . ppb_e(ppb_t('retry')) . '</a>'
            . ppb_manual_html());
        return;
    }
    flock($lock, LOCK_UN);
    fclose($lock);
    @unlink($dir . '/' . PPB_LOCK);

    // Todo bien y el archivo se ha borrado: directo al instalador.
    if (@unlink(__FILE__)) {
        header('Location: ' . $installUrl, true, 303);
        return;
    }
    ppb_page(ppb_t('done_title'), '<div class="box"><p>' . ppb_e(ppb_t('warn_selfdelete')) . '</p></div>'
        . '<p>' . ppb_e(ppb_t('done_text')) . '</p>' . $ctas . ppb_log_html($log));
}

if (!defined('PPB_LIB_ONLY')) {
    ppb_run();
}
