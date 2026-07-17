<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/constants.php';
require_once PP_CORE . '/Autoloader.php';
\Core\Autoloader::register();
require_once PP_ROOT . '/vendor/autoload.php';

use App\Services\AI\Actions;
use App\Services\Canvas\CanvasChatService;

$failed = 0;
function checkCanvasEnvelope(string $name, bool $ok, string $detail = ''): void
{
    global $failed;
    echo ($ok ? 'PASS' : 'FAIL') . ' ' . $name . PHP_EOL;
    if (!$ok) {
        $failed++;
        if ($detail !== '') {
            echo '  -> ' . mb_substr($detail, 0, 500) . PHP_EOL;
        }
    }
}

$sectionDef = Actions::get(Actions::EDIT_CANVAS_SECTION);
$pageDef = Actions::get(Actions::EDIT_CANVAS_PAGE);
checkCanvasEnvelope('section_uses_text_output', ($sectionDef['output'] ?? '') === 'text');
checkCanvasEnvelope('page_uses_text_output', ($pageDef['output'] ?? '') === 'text');
checkCanvasEnvelope(
    'prompts_require_envelope',
    str_contains((string) ($sectionDef['instruction'] ?? ''), '<pp-html>')
        && str_contains((string) ($sectionDef['instruction'] ?? ''), '<pp-css>')
        && str_contains((string) ($pageDef['instruction'] ?? ''), '<pp-reply>')
);

$hasParser = method_exists(CanvasChatService::class, 'parseEditEnvelope');
checkCanvasEnvelope('envelope_parser_exists', $hasParser);

if ($hasParser) {
    $raw = <<<'TEXT'
<pp-html>
<section data-pp-section="hero"><img alt="Academia \"Lorca\""><h1>Bienvenido</h1></section>
</pp-html>
<pp-css>
.hero::after{content:"Una frase con 'comillas'"}
</pp-css>
<pp-reply>
He actualizado la bienvenida.
</pp-reply>
TEXT;
    $parsed = CanvasChatService::parseEditEnvelope($raw);
    checkCanvasEnvelope('html_preserves_quotes', str_contains($parsed['html'], 'alt="Academia \\"Lorca\\""'), $parsed['html']);
    checkCanvasEnvelope('css_preserves_quotes', str_contains($parsed['css'], 'content:"Una frase'), $parsed['css']);
    checkCanvasEnvelope('reply_is_parsed', $parsed['reply'] === 'He actualizado la bienvenida.', $parsed['reply']);

    $cssOnly = CanvasChatService::parseEditEnvelope(
        "<pp-html>\n</pp-html>\n<pp-css>\n.hero{color:red}\n</pp-css>\n<pp-reply>Listo.</pp-reply>"
    );
    checkCanvasEnvelope('css_only_is_valid', $cssOnly['html'] === '' && $cssOnly['css'] === '.hero{color:red}');

    $invalidRejected = false;
    try {
        CanvasChatService::parseEditEnvelope('<pp-html></pp-html><pp-css></pp-css><pp-reply>Vacío.</pp-reply>');
    } catch (Throwable) {
        $invalidRejected = true;
    }
    checkCanvasEnvelope('empty_edit_is_rejected', $invalidRejected);
}

echo $failed === 0 ? "ALL PASS\n" : "{$failed} FAILED\n";
exit($failed === 0 ? 0 : 1);
