<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Services\AI\Actions;
use App\Services\AI\AIActionRunner;
use App\Services\AI\AIException;
use App\Services\AI\AIProviderFactory;
use App\Services\AI\PromptBuilder;
use Core\Auth;
use Core\CSRF;
use Core\Request;
use Core\Response;
use Core\Session;
use Core\View;

/**
 * Test interactivo del proveedor de IA (T6.1).
 *
 * Permite enviar un prompt al provider actualmente configurado y ver la respuesta,
 * verificando end-to-end que la integración funciona.
 */
class AITestController
{
    public function index(): void
    {
        $siteId = $this->requireSiteId();
        $meta   = AIProviderFactory::currentMeta($siteId);

        $data = DashboardController::getCommonData();
        $data = array_merge($data, [
            'providerMeta' => $meta,
            'providers'    => AIProviderFactory::PROVIDERS,
            'csrf'         => CSRF::token(),
        ]);
        View::send('admin/ai/test', $data);
    }

    /**
     * POST /admin/ai/test — devuelve JSON con la respuesta del provider.
     */
    public function run(): void
    {
        CSRF::check();
        $siteId = $this->requireSiteId();

        $prompt = trim((string) (Request::post('prompt') ?? ''));
        if ($prompt === '') {
            Response::json(['ok' => false, 'error' => 'El prompt está vacío'], 422);
            return;
        }
        if (strlen($prompt) > 2000) {
            Response::json(['ok' => false, 'error' => 'El prompt no puede exceder 2000 caracteres'], 422);
            return;
        }

        try {
            $provider = AIProviderFactory::current($siteId);
        } catch (AIException $e) {
            Response::json(['ok' => false, 'error' => $e->getMessage()], 500);
            return;
        }

        if ($provider === null) {
            Response::json([
                'ok'    => false,
                'error' => 'No hay proveedor de IA configurado. Ve al instalador o a Ajustes para configurar uno.',
            ], 400);
            return;
        }

        $messages = [
            ['role' => 'system', 'content' => 'Eres un asistente conciso. Responde en una o dos frases.'],
            ['role' => 'user',   'content' => $prompt],
        ];

        try {
            $response = $provider->chat($messages, [
                'temperature' => 0.7,
                'max_tokens'  => 256,
                'timeout'     => 30,
            ]);
        } catch (AIException $e) {
            Response::json([
                'ok'           => false,
                'error'        => $e->getMessage(),
                'http_status'  => $e->getHttpStatus(),
                'provider'     => $provider->getName(),
                'model'        => $provider->getModel(),
            ], 502);
            return;
        }

        Response::json([
            'ok'       => true,
            'response' => $response->toArray(),
        ]);
    }

    /**
     * GET /admin/ai/prompts — explorador de prompts por acción (T6.2).
     * Muestra el system + user generado sin llamar al modelo.
     */
    public function prompts(): void
    {
        $siteId = $this->requireSiteId();
        $data   = DashboardController::getCommonData();
        $data   = array_merge($data, [
            'actions' => Actions::all(),
            'csrf'    => CSRF::token(),
        ]);
        View::send('admin/ai/prompts', $data);
    }

    /** POST /admin/ai/prompts/preview — devuelve el prompt generado en JSON. */
    public function promptPreview(): void
    {
        CSRF::check();
        $siteId = $this->requireSiteId();

        $action = trim((string) (Request::post('action') ?? ''));
        $rawInp = (string) (Request::post('input_json') ?? '{}');
        $decoded = json_decode($rawInp, true);
        if (!is_array($decoded)) {
            Response::json(['ok' => false, 'error' => 'input_json no es un JSON válido'], 422);
            return;
        }

        // Extras comunes (ej. section_schema como hint para generate_section)
        $extras = [];
        if ($action === Actions::GENERATE_SECTION && isset($decoded['section_type'])) {
            $extras['section_schema'] = '(schema del tipo de sección disponible en T3.3)';
        }

        try {
            $built = PromptBuilder::forAction($action, $decoded, $siteId, $extras);
        } catch (AIException $e) {
            Response::json(['ok' => false, 'error' => $e->getMessage()], 422);
            return;
        }

        Response::json([
            'ok'       => true,
            'messages' => $built['messages'],
            'options'  => $built['options'],
            'meta'     => $built['meta'],
            'tokens_estimate' => [
                'system' => self::estimateTokens($built['messages'][0]['content'] ?? ''),
                'user'   => self::estimateTokens($built['messages'][1]['content'] ?? ''),
            ],
        ]);
    }

    /**
     * POST /admin/ai/actions/run — ejecuta la acción end-to-end (T6.3).
     * Input: action + input_json. Devuelve el output validado.
     */
    public function actionRun(): void
    {
        CSRF::check();
        $siteId = $this->requireSiteId();

        $action = trim((string) (Request::post('action') ?? ''));
        $rawInp = (string) (Request::post('input_json') ?? '{}');
        $decoded = json_decode($rawInp, true);
        if (!is_array($decoded)) {
            Response::json(['ok' => false, 'error' => 'input_json no es un JSON válido'], 422);
            return;
        }

        try {
            $result = AIActionRunner::run($action, $decoded, $siteId);
        } catch (AIException $e) {
            Response::json([
                'ok'          => false,
                'error'       => $e->getMessage(),
                'http_status' => $e->getHttpStatus(),
            ], $e->getHttpStatus() >= 400 && $e->getHttpStatus() < 600 ? $e->getHttpStatus() : 422);
            return;
        }

        Response::json($result);
    }

    /** Estimación gruesa de tokens (1 token ≈ 4 chars en inglés, 3 en español). */
    private static function estimateTokens(string $text): int
    {
        return (int) ceil(mb_strlen($text) / 3.2);
    }

    private function requireSiteId(): int
    {
        $siteId = Auth::siteId();
        if ($siteId === null) {
            Session::flash('error', 'No hay sitio activo.');
            Response::redirect(base_url('admin/'));
        }
        return $siteId;
    }
}
