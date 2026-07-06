<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Payments;

/**
 * PaymentMethods — registro de métodos de pago (C4/C5).
 *
 * StripeCheckout solo se ofrece si el sitio tiene claves configuradas
 * (isConfigured); ManualPayment está siempre disponible.
 */
final class PaymentMethods
{
    /** @return array<string, PaymentMethodInterface> métodos configurados para el sitio, por clave */
    public static function availableFor(int $siteId): array
    {
        $out = [];
        foreach (self::all() as $method) {
            if ($method->isConfigured($siteId)) {
                $out[$method->key()] = $method;
            }
        }
        return $out;
    }

    public static function byKey(int $siteId, string $key): ?PaymentMethodInterface
    {
        return self::availableFor($siteId)[$key] ?? null;
    }

    /** @return PaymentMethodInterface[] */
    private static function all(): array
    {
        return [
            new StripeCheckout(),
            new ManualPayment(),
        ];
    }
}
