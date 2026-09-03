<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/constants.php';
require_once PP_CORE . '/Autoloader.php';
\Core\Autoloader::register();
require_once PP_ROOT . '/vendor/autoload.php';

use App\Services\AI\AIActionRunner;
use App\Services\AI\AIException;
use App\Services\AI\AIProviderInterface;
use App\Services\AI\AIResponse;
use App\Services\AI\Actions;
use App\Services\AI\OpenAIProvider;
use App\Services\AI\OpenRouterProvider;

final class PlannerRetryFakeProvider implements AIProviderInterface
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
    public function getModel(): string { return 'google/gemini-3.7-flash'; }
}

$failed = 0;
function checkAssistantPlannerTruncation(string $name, bool $ok, string $detail = ''): void
{
    global $failed;
    echo ($ok ? 'PASS' : 'FAIL') . ' ' . $name . PHP_EOL;
    if (!$ok) {
        $failed++;
        if ($detail !== '') {
            echo '  -> ' . mb_substr($detail, 0, 700) . PHP_EOL;
        }
    }
}

$fixturePath = __DIR__ . '/fixtures/assistant-plan-truncated-gemini.txt';
$raw = (string) file_get_contents($fixturePath);
$trimmed = trim($raw);

checkAssistantPlannerTruncation(
    'production_fixture_is_present',
    $trimmed !== '' && str_contains($trimmed, 'Retrouve toi meme'),
    $fixturePath
);
checkAssistantPlannerTruncation(
    'production_fixture_ends_mid_item',
    str_starts_with($trimmed, '{')
        && str_ends_with($trimmed, '"category": "needs_input",')
        && substr_count($trimmed, '{') > substr_count($trimmed, '}')
        && substr_count($trimmed, '[') > substr_count($trimmed, ']'),
    $trimmed
);

json_decode($trimmed, true);
checkAssistantPlannerTruncation(
    'production_fixture_is_invalid_json',
    json_last_error() === JSON_ERROR_SYNTAX,
    json_last_error_msg()
);

$parser = new ReflectionMethod(AIActionRunner::class, 'parseJsonStrict');
$caught = null;
try {
    $parser->invoke(null, $trimmed);
} catch (Throwable $e) {
    $caught = $e->getPrevious() ?? $e;
}

checkAssistantPlannerTruncation(
    'production_fixture_reproduces_planner_parse_failure',
    $caught instanceof AIException
        && str_contains($caught->getMessage(), 'No se pudo parsear JSON de la respuesta del modelo'),
    $caught?->getMessage() ?? 'No se lanzó excepción'
);
checkAssistantPlannerTruncation(
    'production_fixture_is_not_the_old_list_wrapper_case',
    !str_starts_with($trimmed, '['),
    $trimmed
);

$definition = Actions::get(Actions::PLAN_SITE_CHANGES);
$plannerOptions = (array) ($definition['options'] ?? []);
checkAssistantPlannerTruncation(
    'planner_reserves_enough_visible_output',
    (int) ($plannerOptions['max_tokens'] ?? 0) === 8000
        && ($plannerOptions['reasoning_effort'] ?? '') === 'low',
    json_encode($plannerOptions)
);

$payloadHookExists = method_exists(OpenRouterProvider::class, 'applyProviderOptions');
$openRouterPayload = [];
if ($payloadHookExists) {
    $payloadHook = new ReflectionMethod(OpenRouterProvider::class, 'applyProviderOptions');
    $openRouterPayload = (array) $payloadHook->invoke(
        new OpenRouterProvider('test-key', 'google/gemini-3.7-flash'),
        ['model' => 'google/gemini-3.7-flash', 'max_tokens' => 8000],
        ['max_tokens' => 8000, 'reasoning_effort' => 'low']
    );
}
checkAssistantPlannerTruncation(
    'openrouter_uses_completion_budget_and_low_reasoning',
    $payloadHookExists
        && !isset($openRouterPayload['max_tokens'])
        && (int) ($openRouterPayload['max_completion_tokens'] ?? 0) === 8000
        && ($openRouterPayload['reasoning']['effort'] ?? '') === 'low',
    json_encode($openRouterPayload)
);

$openAiPayload = [];
if ($payloadHookExists) {
    $openAiHook = new ReflectionMethod(OpenAIProvider::class, 'applyProviderOptions');
    $openAiPayload = (array) $openAiHook->invoke(
        new OpenAIProvider('test-key', 'test-model'),
        ['model' => 'test-model', 'max_tokens' => 8000],
        ['max_tokens' => 8000, 'reasoning_effort' => 'low']
    );
}
checkAssistantPlannerTruncation(
    'openai_payload_is_not_changed_by_openrouter_options',
    (int) ($openAiPayload['max_tokens'] ?? 0) === 8000
        && !isset($openAiPayload['max_completion_tokens'])
        && !isset($openAiPayload['reasoning']),
    json_encode($openAiPayload)
);

$retryMethodExists = method_exists(AIActionRunner::class, 'chatWithPlannerRetry');
$retryResponses = [];
$fake = new PlannerRetryFakeProvider([
    new AIResponse($trimmed, 'google/gemini-3.7-flash', 'openrouter', 900, 2500, 'length', 17013),
    new AIResponse(
        '{"summary":"Falta concretar la página.","items":[]}',
        'google/gemini-3.7-flash',
        'openrouter',
        900,
        80,
        'stop',
        3500
    ),
]);
if ($retryMethodExists) {
    $retryMethod = new ReflectionMethod(AIActionRunner::class, 'chatWithPlannerRetry');
    $retryResponses = (array) $retryMethod->invoke(
        null,
        $fake,
        Actions::PLAN_SITE_CHANGES,
        [['role' => 'user', 'content' => 'Planifica el cambio.']],
        ['max_tokens' => 8000, 'reasoning_effort' => 'low', 'response_format' => 'json']
    );
}
checkAssistantPlannerTruncation(
    'length_finish_reason_retries_once_with_larger_budget',
    $retryMethodExists
        && count($retryResponses) === 2
        && count($fake->optionsSeen) === 2
        && (int) ($fake->optionsSeen[1]['max_tokens'] ?? 0) === 16000
        && ($fake->optionsSeen[1]['reasoning_effort'] ?? '') === 'low',
    json_encode($fake->optionsSeen)
);

$stopFake = new PlannerRetryFakeProvider([
    new AIResponse($trimmed, 'google/gemini-3.7-flash', 'openrouter', 900, 100, 'stop', 1000),
]);
$stopResponses = [];
if ($retryMethodExists) {
    $stopResponses = (array) $retryMethod->invoke(
        null,
        $stopFake,
        Actions::PLAN_SITE_CHANGES,
        [['role' => 'user', 'content' => 'Planifica el cambio.']],
        ['max_tokens' => 8000, 'reasoning_effort' => 'low', 'response_format' => 'json']
    );
}
checkAssistantPlannerTruncation(
    'malformed_stop_response_is_not_retried_blindly',
    $retryMethodExists && count($stopResponses) === 1 && count($stopFake->optionsSeen) === 1,
    json_encode($stopFake->optionsSeen)
);

$missingFinishFake = new PlannerRetryFakeProvider([
    new AIResponse($trimmed, 'google/gemini-3.7-flash', 'openrouter', 900, 100, null, 1000),
    new AIResponse('{"summary":"Plan","items":[]}', 'google/gemini-3.7-flash', 'openrouter', 900, 50, 'stop', 1000),
]);
$missingFinishResponses = [];
if ($retryMethodExists) {
    $missingFinishResponses = (array) $retryMethod->invoke(
        null,
        $missingFinishFake,
        Actions::PLAN_SITE_CHANGES,
        [['role' => 'user', 'content' => 'Planifica el cambio.']],
        ['max_tokens' => 8000, 'reasoning_effort' => 'low', 'response_format' => 'json']
    );
}
checkAssistantPlannerTruncation(
    'unmistakably_incomplete_json_retries_when_finish_reason_is_missing',
    count($missingFinishResponses) === 2 && count($missingFinishFake->optionsSeen) === 2,
    json_encode($missingFinishFake->optionsSeen)
);

$twiceTruncatedFake = new PlannerRetryFakeProvider([
    new AIResponse($trimmed, 'google/gemini-3.7-flash', 'openrouter', 900, 100, 'length', 1000),
    new AIResponse($trimmed, 'google/gemini-3.7-flash', 'openrouter', 900, 100, 'length', 1000),
]);
$twiceTruncatedResponses = [];
if ($retryMethodExists) {
    $twiceTruncatedResponses = (array) $retryMethod->invoke(
        null,
        $twiceTruncatedFake,
        Actions::PLAN_SITE_CHANGES,
        [['role' => 'user', 'content' => 'Planifica el cambio.']],
        ['max_tokens' => 8000, 'reasoning_effort' => 'low', 'response_format' => 'json']
    );
}
checkAssistantPlannerTruncation(
    'planner_never_attempts_a_third_generation',
    count($twiceTruncatedResponses) === 2 && count($twiceTruncatedFake->optionsSeen) === 2,
    json_encode($twiceTruncatedFake->optionsSeen)
);

echo $failed === 0 ? "ALL PASS\n" : "{$failed} FAILED\n";
exit($failed === 0 ? 0 : 1);
