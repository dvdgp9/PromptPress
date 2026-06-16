<?php

declare(strict_types=1);

namespace App\Services\AI;

use Core\Auth;
use Core\Database;

/**
 * Inserta filas en `ai_logs` con trazabilidad de cada llamada al proveedor (T6.4).
 *
 * No lanza excepciones nunca: una BD caída no debe tumbar la acción de IA que
 * acabamos de ejecutar exitosamente. Los errores de logging se vuelcan a error_log.
 */
final class AILogger
{
    /** Éxito: registra la llamada con tokens, coste y duración. */
    public static function logSuccess(
        int $siteId,
        string $provider,
        string $model,
        string $actionType,
        int $tokensIn,
        int $tokensOut,
        int $latencyMs,
        ?array $requestData = null,
        ?array $responseData = null,
    ): void {
        self::insert([
            'site_id'        => $siteId,
            'user_id'        => Auth::id(),
            'provider'       => $provider,
            'model'          => $model,
            'action_type'    => $actionType,
            'tokens_input'   => $tokensIn,
            'tokens_output'  => $tokensOut,
            'estimated_cost' => AIPricing::costFor($model, $tokensIn, $tokensOut),
            'request_data'   => $requestData ? json_encode($requestData, JSON_UNESCAPED_UNICODE) : null,
            'response_data'  => $responseData ? json_encode($responseData, JSON_UNESCAPED_UNICODE) : null,
            'duration_ms'    => $latencyMs,
            'status'         => 'success',
            'error_message'  => null,
        ]);
    }

    /** Error: registra la llamada fallida con el mensaje de error. */
    public static function logError(
        int $siteId,
        string $provider,
        string $model,
        string $actionType,
        string $errorMessage,
        ?int $latencyMs = null,
        ?array $requestData = null,
    ): void {
        self::insert([
            'site_id'        => $siteId,
            'user_id'        => Auth::id(),
            'provider'       => $provider,
            'model'          => $model,
            'action_type'    => $actionType,
            'tokens_input'   => 0,
            'tokens_output'  => 0,
            'estimated_cost' => 0.0,
            'request_data'   => $requestData ? json_encode($requestData, JSON_UNESCAPED_UNICODE) : null,
            'response_data'  => null,
            'duration_ms'    => $latencyMs,
            'status'         => 'error',
            'error_message'  => mb_substr($errorMessage, 0, 1000),
        ]);
    }

    private static function insert(array $row): void
    {
        try {
            Database::execute(
                'INSERT INTO ai_logs
                    (site_id, user_id, provider, model, action_type,
                     tokens_input, tokens_output, estimated_cost,
                     request_data, response_data, duration_ms,
                     status, error_message)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $row['site_id'],
                    $row['user_id'],
                    $row['provider'],
                    $row['model'],
                    $row['action_type'],
                    $row['tokens_input'],
                    $row['tokens_output'],
                    $row['estimated_cost'],
                    $row['request_data'],
                    $row['response_data'],
                    $row['duration_ms'],
                    $row['status'],
                    $row['error_message'],
                ]
            );
        } catch (\Throwable $e) {
            // Nunca propagar — solo log a stderr
            error_log('[AILogger] No se pudo insertar en ai_logs: ' . $e->getMessage());
        }
    }
}
