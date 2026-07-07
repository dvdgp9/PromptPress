<?php

declare(strict_types=1);

/**
 * FEAT-4 AB4 — Test de integración HTTP del escudo completo en FormController.
 *
 * Levanta un `php -S` propio, crea una página canvas temporal publicada que
 * referencia un formulario real del sitio, obtiene CSRF + sesión con GET, y
 * cubre: PoW válido → submission con bot_check='pow'; replay del mismo PoW →
 * "ok" falso sin fila; PoW corrupto → "ok" falso sin fila; sin PoW (sin JS) →
 * submission con bot_check='timetrap'; time-trap fresco (<3 s) → "ok" falso
 * aunque el PoW sea válido. Limpia página, submissions y retos al final.
 */

require_once __DIR__ . '/../config/constants.php';
require_once PP_CORE . '/Autoloader.php';
\Core\Autoloader::register();
\Core\App::boot();

use App\Services\Security\BotGuard;
use Core\Database;

$failed = 0;
function check(string $name, bool $ok, string $detail = ''): void
{
    global $failed;
    if ($ok) {
        echo "PASS  $name\n";
    } else {
        $failed++;
        echo "FAIL  $name" . ($detail !== '' ? "  ($detail)" : '') . "\n";
    }
}

function solve_pow(array $c): string
{
    for ($n = 0; ; $n++) {
        $h = hash('sha256', $c['salt'] . '.' . $n, true);
        $full = intdiv($c['bits'], 8);
        $ok = true;
        for ($i = 0; $i < $full; $i++) {
            if ($h[$i] !== "\x00") { $ok = false; break; }
        }
        $rest = $c['bits'] % 8;
        if ($ok && ($rest === 0 || (ord($h[$full]) >> (8 - $rest)) === 0)) {
            return $c['challenge'] . '.' . $n;
        }
    }
}

// --- Fixture: formulario real + página canvas publicada temporal -----------
$formSection = Database::selectOne(
    "SELECT ps.id FROM page_sections ps JOIN pages p ON p.id = ps.page_id
     WHERE p.site_id = 1 AND ps.section_type = 'form' AND ps.status != 'deleted' LIMIT 1"
);
if ($formSection === null) {
    echo "SKIP: el sitio dev no tiene ninguna sección form\n";
    exit(0);
}
$formId = (int) $formSection['id'];
$slug = 'botguard-submit-test-' . substr(bin2hex(random_bytes(3)), 0, 6);
$now = date('Y-m-d H:i:s');
Database::execute(
    'INSERT INTO pages (site_id, title, slug, page_type, render_mode, status, sort_order, tree_sort_order, created_at, updated_at)
     VALUES (1, "BotGuard submit test", ?, "landing", "canvas", "published", 0, 999, ?, ?)',
    [$slug, $now, $now]
);
$pageId = (int) Database::lastInsertId();
\App\Services\Canvas\CanvasService::save($pageId, '<section data-pp-section="f">{{form:' . $formId . '}}</section>', '', 'generate');
$maxSubmission = (int) (Database::selectOne('SELECT COALESCE(MAX(id),0) m FROM form_submissions')['m'] ?? 0);

// --- Servidor propio --------------------------------------------------------
$port = 8798;
$root = PP_ROOT;
$proc = proc_open(
    ['php', '-S', '127.0.0.1:' . $port, '-t', $root, $root . '/index.php'],
    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $pipes,
    $root,
    array_merge($_ENV, ['PATH' => (string) getenv('PATH')])
);
usleep(400000);
$base = 'http://127.0.0.1:' . $port;
$cookieJar = tempnam(sys_get_temp_dir(), 'ppbg');

function http_get(string $url): string
{
    global $cookieJar;
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $cookieJar, CURLOPT_COOKIEFILE => $cookieJar, CURLOPT_TIMEOUT => 10]);
    $out = (string) curl_exec($ch);
    curl_close($ch);
    return $out;
}

/** @return array{status:int,json:?array} */
function http_post_form(string $url, array $fields): array
{
    global $cookieJar;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR => $cookieJar,
        CURLOPT_COOKIEFILE => $cookieJar,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($fields),
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
        CURLOPT_TIMEOUT => 10,
    ]);
    $raw = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    $json = json_decode($raw, true);
    return ['status' => $status, 'json' => is_array($json) ? $json : null];
}

$page = http_get("$base/$slug");
preg_match('/name="_csrf" value="([0-9a-f]+)"/', $page, $mCsrf);
$csrf = $mCsrf[1] ?? '';
check('fixture_pagina_con_form', str_contains($page, 'data-pp-form-id="' . $formId . '"') && $csrf !== '');
check('fixture_script_inyectado', str_contains($page, 'pp-botguard.js'));

$agedTs = BotGuard::issueTimestamp(time() - 60);
$common = ['_csrf' => $csrf, '_return' => '/' . $slug, 'nombre' => 'Test AB4', 'email' => 'ab4@test.com', 'mensaje' => 'integración'];

function rows_since(int $max): array
{
    return Database::select('SELECT id, bot_check FROM form_submissions WHERE id > ? ORDER BY id', [$max]);
}

// 1. PoW válido + ts envejecido → submission con bot_check='pow'.
$pow = solve_pow(BotGuard::issueChallenge());
$r = http_post_form("$base/forms/$formId", $common + ['_pp_ts' => $agedTs, '_pp_pow' => $pow]);
$rows = rows_since($maxSubmission);
check('pow_valido_crea_fila', ($r['json']['ok'] ?? false) && isset($r['json']['submission_id']) && count($rows) === 1, json_encode($r));
check('pow_valido_bot_check', ($rows[0]['bot_check'] ?? '') === 'pow', json_encode($rows));
$mark = (int) ($rows[0]['id'] ?? $maxSubmission);

// 2. Replay del mismo PoW → "ok" falso, sin fila nueva.
$r = http_post_form("$base/forms/$formId", $common + ['_pp_ts' => $agedTs, '_pp_pow' => $pow]);
check('pow_replay_ok_falso', ($r['json']['ok'] ?? false) && !isset($r['json']['submission_id']) && rows_since($mark) === [], json_encode($r));

// 3. PoW corrupto → "ok" falso, sin fila.
$r = http_post_form("$base/forms/$formId", $common + ['_pp_ts' => $agedTs, '_pp_pow' => 'basura.no.valida']);
check('pow_corrupto_ok_falso', ($r['json']['ok'] ?? false) && !isset($r['json']['submission_id']) && rows_since($mark) === [], json_encode($r));

// 4. Sin PoW (degradación sin JS) → submission con bot_check='timetrap'.
$r = http_post_form("$base/forms/$formId", $common + ['_pp_ts' => BotGuard::issueTimestamp(time() - 45)]);
$rows = rows_since($mark);
check('sin_pow_crea_fila', ($r['json']['ok'] ?? false) && isset($r['json']['submission_id']) && count($rows) === 1, json_encode($r));
check('sin_pow_bot_check_timetrap', ($rows[0]['bot_check'] ?? '') === 'timetrap', json_encode($rows));
$mark = (int) ($rows[0]['id'] ?? $mark);

// 5. Time-trap fresco (<3 s) manda aunque el PoW sea válido → "ok" falso.
$pow2 = solve_pow(BotGuard::issueChallenge());
$r = http_post_form("$base/forms/$formId", $common + ['_pp_ts' => BotGuard::issueTimestamp(), '_pp_pow' => $pow2]);
check('timetrap_gana_a_pow', ($r['json']['ok'] ?? false) && !isset($r['json']['submission_id']) && rows_since($mark) === [], json_encode($r));

// 6. Ts caducado (>6 h) → error 422 explícito (no ok falso).
$r = http_post_form("$base/forms/$formId", $common + ['_pp_ts' => BotGuard::issueTimestamp(time() - 22000)]);
check('ts_caducado_422', $r['status'] === 422 && ($r['json']['status'] ?? '') === 'error', json_encode($r));

// --- Limpieza ---------------------------------------------------------------
proc_terminate($proc);
@unlink($cookieJar);
Database::execute('DELETE FROM form_submissions WHERE id > ?', [$maxSubmission]);
Database::execute('DELETE FROM page_canvas WHERE page_id = ?', [$pageId]);
Database::execute('DELETE FROM pages WHERE id = ?', [$pageId]);
foreach ([$pow, $pow2] as $sol) {
    $p = explode('.', $sol); // 1.exp.salt.bits.sig.nonce
    Database::execute('DELETE FROM botguard_solved WHERE challenge_hash = ?', [hash('sha256', "$p[1].$p[2].$p[3].$p[4]")]);
}
\App\Services\CacheService::flush(1);

echo $failed === 0 ? "\nALL PASS (10)\n" : "\n$failed FAILED\n";
exit($failed === 0 ? 0 : 1);
