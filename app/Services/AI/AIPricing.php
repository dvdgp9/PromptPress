<?php

declare(strict_types=1);

namespace App\Services\AI;

/**
 * Precios estimados por modelo en USD por 1M tokens.
 *
 * Fuente: pricing pages de cada proveedor a principios de 2025.
 * Son ESTIMACIONES — el proveedor final factura según su propia lista.
 * Para OpenRouter, el precio es el que OpenRouter cobra (markup ~0-5% sobre el base).
 *
 * Formato: [input_usd_per_1m, output_usd_per_1m]
 */
final class AIPricing
{
    /** Tarifa por defecto cuando el modelo es desconocido. */
    private const FALLBACK = [0.50, 1.50];

    /** @return array<string,array{0:float,1:float}> */
    private static function table(): array
    {
        return [
            // --- OpenAI (provider=openai, model sin prefijo) ---
            'gpt-4o-mini'             => [0.15, 0.60],
            'gpt-4o'                  => [2.50, 10.00],
            'gpt-4.1-mini'            => [0.40, 1.60],
            'gpt-4.1'                 => [2.00, 8.00],
            'o1-mini'                 => [3.00, 12.00],
            'o1'                      => [15.00, 60.00],

            // --- Anthropic (provider=anthropic) ---
            'claude-3-5-haiku-latest'  => [0.80, 4.00],
            'claude-3-5-sonnet-latest' => [3.00, 15.00],
            'claude-3-opus-latest'     => [15.00, 75.00],

            // --- Mistral (provider=mistral) ---
            'mistral-small-latest'     => [0.20, 0.60],
            'mistral-large-latest'     => [2.00, 6.00],
            'codestral-latest'         => [0.30, 0.90],

            // --- OpenRouter (provider=openrouter, model con prefijo) ---
            'openai/gpt-4o-mini'                             => [0.15, 0.60],
            'openai/gpt-4o'                                  => [2.50, 10.00],
            'anthropic/claude-3.5-haiku'                     => [0.80, 4.00],
            'anthropic/claude-3.5-sonnet'                    => [3.00, 15.00],
            'google/gemini-3.1-pro-preview'                  => [2.00, 12.00],
            'google/gemini-3-flash-preview'                  => [0.50, 3.00],
            'google/gemini-3.1-flash-lite-preview'           => [0.25, 1.50],
            'google/gemini-2.5-pro'                          => [1.25, 10.00],
            'google/gemini-2.5-flash'                        => [0.30, 2.50],
            'google/gemini-2.5-flash-lite'                   => [0.10, 0.40],
            'google/gemini-2.0-flash-001'                    => [0.10, 0.40],
            'google/gemma-4-31b-it'                          => [0.13, 0.38],
            'google/gemma-4-31b-it:free'                     => [0.00, 0.00],
            'google/gemma-4-26b-a4b-it'                      => [0.08, 0.35],
            'google/gemma-4-26b-a4b-it:free'                 => [0.00, 0.00],
            'meta-llama/llama-3.3-70b-instruct'              => [0.60, 0.60],
            'meta-llama/llama-3.1-70b-instruct'              => [0.40, 0.40],
            'meta-llama/llama-3.1-8b-instruct:free'          => [0.00, 0.00],
            'mistralai/mistral-small-24b-instruct-2501:free' => [0.00, 0.00],
            'mistralai/mistral-small'                        => [0.20, 0.60],
        ];
    }

    /**
     * Calcula el coste en USD para una llamada dada.
     * Devuelve 0.0 si el modelo es gratis. Nunca lanza excepción.
     */
    public static function costFor(string $model, int $tokensIn, int $tokensOut): float
    {
        [$inRate, $outRate] = self::rateFor($model);
        $cost = ($tokensIn / 1_000_000) * $inRate + ($tokensOut / 1_000_000) * $outRate;
        return round($cost, 6);
    }

    /**
     * Devuelve la tarifa [in, out] para un modelo, o la fallback si no se conoce.
     *
     * @return array{0:float,1:float}
     */
    public static function rateFor(string $model): array
    {
        $table = self::table();
        if (isset($table[$model])) return $table[$model];

        // Intento fuzzy: OpenRouter puede llevar sufijos como ":nitro", ":beta"
        $normalized = preg_replace('/:(?:free|nitro|beta|extended)$/', '', $model) ?? $model;
        if ($normalized !== $model && isset($table[$normalized])) return $table[$normalized];

        return self::FALLBACK;
    }

    /** ¿El coste de este modelo es conocido (no la fallback)? */
    public static function isKnown(string $model): bool
    {
        $table = self::table();
        if (isset($table[$model])) return true;
        $normalized = preg_replace('/:(?:free|nitro|beta|extended)$/', '', $model) ?? $model;
        return isset($table[$normalized]);
    }
}
