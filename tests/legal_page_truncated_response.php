<?php

declare(strict_types=1);

// LEGAL-TRUNC — La política de privacidad fallaba siempre con «No se pudo
// parsear JSON»: la salida llegaba cortada (3500 tokens con thinking de
// Gemini 3) y reintentar a mano repetía el mismo corte. Además, al recargar el
// asistente tras ese fallo, el paso 3 volvía a ofrecer «Generar las 3» y
// habría reescrito las dos que salieron bien. Sin llamadas a IA.

require_once __DIR__ . '/../config/constants.php';
require_once PP_CORE . '/Autoloader.php';
\Core\Autoloader::register();
require_once PP_ROOT . '/vendor/autoload.php';
\Core\App::boot();

use App\Services\AI\AIActionRunner;
use App\Services\AI\AIException;
use App\Services\AI\AIProviderInterface;
use App\Services\AI\AIResponse;
use App\Services\AI\Actions;
use App\Services\Compliance\LegalPageGenerator;
use Core\Database;

final class LegalTruncFakeProvider implements AIProviderInterface
{
    /** @var array<int,AIResponse> */
    private array $responses;
    /** @var array<int,array<string,mixed>> */
    public array $optionsSeen = [];

    /** @param array<int,AIResponse> $responses */
    public function __construct(array $responses)
    {
        $this->responses = array_values($responses);
    }

    public function chat(array $messages, array $options = []): AIResponse
    {
        $this->optionsSeen[] = $options;
        $response = array_shift($this->responses);
        if (!$response instanceof AIResponse) {
            throw new AIException('El fake no tiene más respuestas.');
        }
        return $response;
    }

    public function getName(): string { return 'openrouter'; }
    public function getModel(): string { return 'google/gemini-3-flash-preview'; }
}

$failed = 0;
function checkLegalTrunc(string $name, bool $ok, string $detail = ''): void
{
    global $failed;
    echo ($ok ? 'PASS' : 'FAIL') . ' ' . $name . PHP_EOL;
    if (!$ok) {
        $failed++;
        if ($detail !== '') echo '  -> ' . mb_substr($detail, 0, 500) . PHP_EOL;
    }
}

// ======================================================================
// 1. Presupuesto de la acción
// ======================================================================
$options = (array) (Actions::get(Actions::GENERATE_LEGAL_PAGE)['options'] ?? []);
checkLegalTrunc(
    'legal_page_reserves_enough_output',
    (int) ($options['max_tokens'] ?? 0) >= 8000 && ($options['reasoning_effort'] ?? '') === 'low',
    json_encode($options)
);

// ======================================================================
// 2. Respuesta cortada → un único reintento con presupuesto doblado
// ======================================================================
// Mismo arranque que el error real, cortado a media frase.
$truncated = '{ "title": "Política de Privacidad", "blocks": [ { "type": "paragraph", "text": "En esta Política de Privacidad explicamos cómo recopilamos, utilizamos y protegemos los datos personales de los usuarios que navegan por este sitio web o se ponen en contacto con nosotros." }, { "type": "heading", "level": 2, "text": "Responsable del tratamiento" }, { "type": "paragraph", "text": "El responsable del tratamiento de tus datos es';
$complete = '{"title":"Política de Privacidad","blocks":[{"type":"paragraph","text":"Texto completo."}]}';

$parser = new ReflectionMethod(AIActionRunner::class, 'parseJsonStrict');
$parseError = null;
try {
    $parser->invoke(null, $truncated);
} catch (Throwable $e) {
    $parseError = $e->getPrevious() ?? $e;
}
checkLegalTrunc(
    'truncated_fixture_reproduces_the_reported_error',
    $parseError instanceof AIException
        && str_contains($parseError->getMessage(), 'No se pudo parsear JSON de la respuesta del modelo'),
    $parseError?->getMessage() ?? 'No se lanzó excepción'
);

$retry = new ReflectionMethod(AIActionRunner::class, 'chatWithTruncationRetry');
$messages = [['role' => 'user', 'content' => 'Genera la política de privacidad.']];

$fake = new LegalTruncFakeProvider([
    new AIResponse($truncated, 'google/gemini-3-flash-preview', 'openrouter', 2300, 8000, 'length', 30000),
    new AIResponse($complete, 'google/gemini-3-flash-preview', 'openrouter', 2300, 2600, 'stop', 20000),
]);
$responses = (array) $retry->invoke(null, $fake, Actions::GENERATE_LEGAL_PAGE, $messages, $options);
checkLegalTrunc(
    'truncated_legal_page_retries_once_with_double_budget',
    count($responses) === 2
        && (int) ($fake->optionsSeen[1]['max_tokens'] ?? 0) === 16000
        && ($fake->optionsSeen[1]['reasoning_effort'] ?? '') === 'low'
        && $responses[1]->content === $complete,
    json_encode($fake->optionsSeen)
);

// Sin finish_reason pero con el JSON claramente a medias: también repite.
$noFinish = new LegalTruncFakeProvider([
    new AIResponse($truncated, 'google/gemini-3-flash-preview', 'openrouter', 2300, 3500, null, 30000),
    new AIResponse($complete, 'google/gemini-3-flash-preview', 'openrouter', 2300, 2600, 'stop', 20000),
]);
$noFinishResponses = (array) $retry->invoke(null, $noFinish, Actions::GENERATE_LEGAL_PAGE, $messages, $options);
checkLegalTrunc('incomplete_json_without_finish_reason_retries', count($noFinishResponses) === 2);

// Una respuesta buena no se repite.
$okFake = new LegalTruncFakeProvider([
    new AIResponse($complete, 'google/gemini-3-flash-preview', 'openrouter', 2300, 2600, 'stop', 20000),
]);
$okResponses = (array) $retry->invoke(null, $okFake, Actions::GENERATE_LEGAL_PAGE, $messages, $options);
checkLegalTrunc('complete_legal_page_is_not_retried', count($okResponses) === 1 && count($okFake->optionsSeen) === 1);

// El reintento sigue acotado: otras acciones no repiten aunque lleguen cortadas.
$otherFake = new LegalTruncFakeProvider([
    new AIResponse($truncated, 'google/gemini-3-flash-preview', 'openrouter', 100, 400, 'length', 1000),
]);
$otherResponses = (array) $retry->invoke(null, $otherFake, Actions::INFER_BRAND_PERSONALITY, $messages, ['max_tokens' => 400]);
checkLegalTrunc('other_actions_are_not_retried', count($otherResponses) === 1 && count($otherFake->optionsSeen) === 1);

// ======================================================================
// 3. Paso 3 del asistente tras un fallo parcial
// ======================================================================
/** Pinta step_3.php con un estado de páginas dado y devuelve el HTML. */
function renderWizardStep3(array $legalPagesState): string
{
    $manifest = ['controller' => ['legal_name' => 'Demo SL', 'address' => 'Calle 1', 'email' => 'a@b.es']];
    $legalTypes = [
        'privacy_policy' => ['slug' => 'privacidad', 'title' => 'x', 'label' => __('legal.type.privacy_policy')],
        'cookie_policy'  => ['slug' => 'politica-de-cookies', 'title' => 'x', 'label' => __('legal.type.cookie_policy')],
        'legal_notice'   => ['slug' => 'aviso-legal', 'title' => 'x', 'label' => __('legal.type.legal_notice')],
    ];
    $formsList = [];
    $trackingCatalog = [];
    $csrf = 'test';
    ob_start();
    include PP_ROOT . '/views/admin/privacy/wizard/step_3.php';
    return (string) ob_get_clean();
}

/** @return array<int,string> claves de los tipos que el JS va a generar */
function wizardTypesToGenerate(string $html): array
{
    if (!preg_match('/data-legal-generate="([^"]*)"/', $html, $m)) return [];
    $types = json_decode(html_entity_decode($m[1], ENT_QUOTES), true);
    return array_column((array) $types, 'key');
}

$page = ['id' => 1, 'title' => 't', 'slug' => 's', 'updated_at' => '2026-10-27 10:00:00', 'status' => 'published'];

$partialHtml = renderWizardStep3(['privacy_policy' => null, 'cookie_policy' => $page, 'legal_notice' => $page]);
checkLegalTrunc(
    'partial_state_only_generates_the_missing_page',
    wizardTypesToGenerate($partialHtml) === ['privacy_policy'],
    json_encode(wizardTypesToGenerate($partialHtml))
);
checkLegalTrunc(
    'partial_state_button_says_the_missing_one',
    str_contains($partialHtml, e(__('privacy.wizard.generate_missing_one')))
        && !str_contains($partialHtml, e(__('privacy.wizard.generate_n', ['n' => 3]))),
    $partialHtml
);
checkLegalTrunc(
    'partial_state_names_the_missing_page',
    str_contains($partialHtml, e(__('legal.type.privacy_policy')))
        && str_contains($partialHtml, 'name="only_missing" value="1"'),
    $partialHtml
);

$twoMissing = renderWizardStep3(['privacy_policy' => null, 'cookie_policy' => null, 'legal_notice' => $page]);
checkLegalTrunc(
    'two_missing_pages_use_the_plural_label',
    wizardTypesToGenerate($twoMissing) === ['privacy_policy', 'cookie_policy']
        && str_contains($twoMissing, e(__('privacy.wizard.generate_missing_other', ['n' => 2]))),
    $twoMissing
);

$emptyHtml = renderWizardStep3(['privacy_policy' => null, 'cookie_policy' => null, 'legal_notice' => null]);
checkLegalTrunc(
    'fresh_wizard_still_generates_all_three',
    wizardTypesToGenerate($emptyHtml) === ['privacy_policy', 'cookie_policy', 'legal_notice']
        && str_contains($emptyHtml, e(__('privacy.wizard.generate_n', ['n' => 3])))
        && !str_contains($emptyHtml, 'only_missing'),
    $emptyHtml
);

$doneHtml = renderWizardStep3(['privacy_policy' => $page, 'cookie_policy' => $page, 'legal_notice' => $page]);
checkLegalTrunc(
    'complete_wizard_offers_regenerating_all',
    wizardTypesToGenerate($doneHtml) === ['privacy_policy', 'cookie_policy', 'legal_notice']
        && str_contains($doneHtml, e(__('privacy.wizard.regenerate_n', ['n' => 3])))
        && !str_contains($doneHtml, 'only_missing'),
    $doneHtml
);

// ======================================================================
// 4. Sin JS: `only_missing` no vuelve a llamar a la IA por lo que ya existe
// ======================================================================
// Solo se puede comprobar sin IA en un sitio que ya tiene todas sus páginas.
$siteId = (int) (Database::selectOne('SELECT id FROM sites ORDER BY id LIMIT 1')['id'] ?? 0);
$state = $siteId > 0 ? \App\Controllers\Admin\PrivacyController::loadLegalPagesState($siteId) : [];
if ($state !== [] && !in_array(null, $state, true)) {
    $logsBefore = (int) (Database::selectOne(
        "SELECT COUNT(*) AS n FROM ai_logs WHERE action_type = 'generate_legal_page'"
    )['n'] ?? 0);
    $result = LegalPageGenerator::generateAllLegalPages($siteId, true);
    $logsAfter = (int) (Database::selectOne(
        "SELECT COUNT(*) AS n FROM ai_logs WHERE action_type = 'generate_legal_page'"
    )['n'] ?? 0);
    checkLegalTrunc(
        'only_missing_skips_existing_pages',
        ($result['generated'] ?? -1) === 0 && ($result['failed'] ?? -1) === 0 && $logsAfter === $logsBefore,
        json_encode($result)
    );
} else {
    echo "SKIP only_missing_skips_existing_pages (el primer sitio no tiene todas sus páginas legales)\n";
}

echo $failed === 0 ? "ALL PASS\n" : "{$failed} FAILED\n";
exit($failed === 0 ? 0 : 1);
