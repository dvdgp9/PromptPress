<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Payments;

use RuntimeException;

/**
 * StripeApi — cliente REST mínimo de Stripe + verificación de firma de
 * webhooks (C5). Sin SDK: solo necesitamos crear/leer Checkout Sessions y
 * verificar firmas, y así el módulo no arrastra dependencias (detalle y
 * fuentes en cursor/stripe-api.md).
 */
final class StripeApi
{
    private const BASE = 'https://api.stripe.com/v1';
    private const TIMEOUT = 20;

    /** Tolerancia de la firma del webhook (recomendación oficial: 5 min). */
    public const SIGNATURE_TOLERANCE = 300;

    /**
     * Hook de tests: fn(string $method, string $path, array $params): array
     * que sustituye la llamada HTTP real.
     */
    public static ?\Closure $httpOverride = null;

    /**
     * @param array<string,mixed> $params
     * @return array<string,mixed> Checkout Session (id, url, payment_status, …)
     */
    public static function createCheckoutSession(string $secretKey, array $params): array
    {
        return self::request($secretKey, 'POST', '/checkout/sessions', $params);
    }

    /** @return array<string,mixed> */
    public static function getCheckoutSession(string $secretKey, string $sessionId): array
    {
        return self::request($secretKey, 'GET', '/checkout/sessions/' . rawurlencode($sessionId), []);
    }

    /**
     * Verifica la firma Stripe-Signature de un webhook (HMAC-SHA256 sobre
     * "t.rawBody", comparación constant-time contra cada v1 del header).
     *
     * @param ?int $now inyectable en tests; null = time()
     */
    public static function verifySignature(string $payload, string $sigHeader, string $secret, ?int $now = null): bool
    {
        $timestamp = null;
        $signatures = [];
        foreach (explode(',', $sigHeader) as $part) {
            $kv = explode('=', trim($part), 2);
            if (count($kv) !== 2) {
                continue;
            }
            if ($kv[0] === 't' && ctype_digit($kv[1])) {
                $timestamp = (int) $kv[1];
            } elseif ($kv[0] === 'v1') {
                $signatures[] = $kv[1];
            }
        }
        if ($timestamp === null || $signatures === []) {
            return false;
        }
        if (abs(($now ?? time()) - $timestamp) > self::SIGNATURE_TOLERANCE) {
            return false;
        }
        $expected = hash_hmac('sha256', $timestamp . '.' . $payload, $secret);
        foreach ($signatures as $sig) {
            if (hash_equals($expected, $sig)) {
                return true;
            }
        }
        return false;
    }

    // ======================================================================
    // Internos
    // ======================================================================

    /**
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     * @throws RuntimeException con el mensaje de error de Stripe
     */
    private static function request(string $secretKey, string $method, string $path, array $params): array
    {
        if (self::$httpOverride !== null) {
            return (self::$httpOverride)($method, $path, $params);
        }

        $ch = curl_init(self::BASE . $path . ($method === 'GET' && $params !== [] ? '?' . http_build_query($params) : ''));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::TIMEOUT,
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $secretKey],
        ]);
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            // http_build_query genera la notación de corchetes anidada que
            // espera Stripe: line_items[0][price_data][currency]=eur
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
        }
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            throw new RuntimeException('Stripe: error de conexión (' . $curlErr . ')');
        }
        $data = json_decode((string) $body, true);
        if (!is_array($data)) {
            throw new RuntimeException('Stripe: respuesta no válida (HTTP ' . $status . ')');
        }
        if ($status < 200 || $status >= 300) {
            $msg = (string) ($data['error']['message'] ?? ('HTTP ' . $status));
            throw new RuntimeException('Stripe: ' . $msg);
        }
        return $data;
    }
}
