<?php

declare(strict_types=1);

namespace App\Services\AI;

use RuntimeException;

/**
 * Base compartida por los providers: cliente HTTP, gestión de errores.
 */
abstract class BaseProvider implements AIProviderInterface
{
    public function __construct(
        protected readonly string $apiKey,
        protected readonly string $model,
    ) {
        if (trim($this->apiKey) === '') {
            throw new AIException('API key vacía para el proveedor ' . $this->getName());
        }
    }

    public function getModel(): string { return $this->model; }

    /**
     * POST JSON + parse de respuesta. Devuelve [status, decodedBody, rawBody, latencyMs].
     *
     * @param array<string,string> $headers
     * @return array{0:int, 1:array<string,mixed>|null, 2:string, 3:int}
     */
    protected function httpPostJson(string $url, array $headers, array $body, int $timeoutSec = 60): array
    {
        if (!function_exists('curl_init')) {
            throw new AIException('cURL no está disponible en este servidor');
        }

        $ch = curl_init($url);
        if ($ch === false) {
            throw new AIException('No se pudo inicializar cURL');
        }

        $headerLines = [];
        foreach ($headers as $k => $v) { $headerLines[] = $k . ': ' . $v; }
        $headerLines[] = 'Content-Type: application/json';

        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            CURLOPT_HTTPHEADER     => $headerLines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeoutSec,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT      => 'PromptPress/' . (defined('PP_VERSION') ? PP_VERSION : 'dev'),
        ]);

        $t0 = microtime(true);
        $rawBody = curl_exec($ch);
        $latencyMs = (int) round((microtime(true) - $t0) * 1000);
        $err  = curl_error($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($rawBody === false || $rawBody === '') {
            throw new AIException(
                'Error de red al contactar con ' . $this->getName() . ($err !== '' ? ': ' . $err : ''),
                0
            );
        }

        $decoded = json_decode((string) $rawBody, true);
        if (!is_array($decoded)) {
            throw new AIException(
                $this->getName() . ' respondió con contenido no-JSON (HTTP ' . $code . ')',
                $code,
                substr((string) $rawBody, 0, 300)
            );
        }

        return [$code, $decoded, (string) $rawBody, $latencyMs];
    }

    /**
     * Extrae el mensaje de error del body decodificado, según formato del proveedor.
     */
    abstract protected function extractError(array $decoded): string;

    /**
     * Valida el código HTTP; si no es 2xx lanza AIException con detalle.
     */
    protected function assertOk(int $status, ?array $decoded): void
    {
        if ($status >= 200 && $status < 300) return;

        $providerMsg = $decoded ? $this->extractError($decoded) : '';
        $msg = $this->getName() . ' devolvió HTTP ' . $status;
        if ($status === 401 || $status === 403) {
            $msg .= ' — API key rechazada. Verifica la clave en Ajustes.';
        } elseif ($status === 429) {
            $msg .= ' — rate limit. Reintenta en unos segundos.';
        } elseif ($status >= 500) {
            $msg .= ' — error del servidor del proveedor.';
        }
        if ($providerMsg !== '') {
            $msg .= ' Detalle: ' . $providerMsg;
        }
        throw new AIException($msg, $status, $providerMsg !== '' ? $providerMsg : null);
    }

    /**
     * Sanea lista de mensajes: elimina entradas inválidas y fuerza strings.
     *
     * @param array<int,array<string,mixed>> $messages
     * @return array<int,array{role: string, content: string}>
     */
    protected function sanitizeMessages(array $messages): array
    {
        $out = [];
        $validRoles = ['system', 'user', 'assistant'];
        foreach ($messages as $m) {
            $role    = isset($m['role']) && is_string($m['role']) ? $m['role'] : '';
            $content = isset($m['content']) && is_string($m['content']) ? $m['content'] : '';
            if (!in_array($role, $validRoles, true) || $content === '') continue;
            $out[] = ['role' => $role, 'content' => $content];
        }
        if ($out === []) {
            throw new AIException('No hay mensajes válidos para enviar al modelo');
        }
        return $out;
    }
}
