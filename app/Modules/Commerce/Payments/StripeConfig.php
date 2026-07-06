<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Payments;

use App\Modules\Commerce\CommerceSettings;
use Core\App;
use Core\Crypto;

/**
 * StripeConfig — claves de Stripe por sitio (C5).
 *
 * Settings (patrón booking_api_key: cifradas con app_key):
 *   commerce_stripe_mode                       'test' | 'live'
 *   commerce_stripe_sk_test / _sk_live         secret key (cifrada)
 *   commerce_stripe_webhook_secret_test/_live  signing secret whsec_ (cifrada)
 *
 * Para Stripe Checkout hosted solo hacen falta la secret key y el secreto
 * del webhook; la publishable key no se usa en esta integración.
 */
final class StripeConfig
{
    /** @return 'test'|'live' */
    public static function mode(int $siteId): string
    {
        return CommerceSettings::get($siteId, 'commerce_stripe_mode') === 'live' ? 'live' : 'test';
    }

    public static function secretKey(int $siteId, ?string $mode = null): ?string
    {
        return self::decrypted($siteId, 'commerce_stripe_sk_' . ($mode ?? self::mode($siteId)));
    }

    public static function webhookSecret(int $siteId, ?string $mode = null): ?string
    {
        return self::decrypted($siteId, 'commerce_stripe_webhook_secret_' . ($mode ?? self::mode($siteId)));
    }

    /** ¿Se puede ofrecer pago con tarjeta? (secret key del modo activo presente) */
    public static function isConfigured(int $siteId): bool
    {
        return self::secretKey($siteId) !== null;
    }

    /** Guarda un secreto cifrado; cadena vacía = borrar. */
    public static function saveSecret(int $siteId, string $settingKey, string $value): void
    {
        $value = trim($value);
        CommerceSettings::set(
            $siteId,
            $settingKey,
            $value === '' ? '' : Crypto::encrypt($value, self::appKey()),
            $value !== ''
        );
    }

    /** "sk_test_••••4242" para mostrar en el admin sin revelar el secreto. */
    public static function masked(?string $secret): ?string
    {
        if ($secret === null || $secret === '') {
            return null;
        }
        $prefix = preg_match('/^([a-z]+_(?:test|live)_|whsec_)/', $secret, $m) === 1 ? $m[1] : '';
        return $prefix . '••••' . substr($secret, -4);
    }

    private static function decrypted(int $siteId, string $settingKey): ?string
    {
        $stored = CommerceSettings::get($siteId, $settingKey);
        if ($stored === '') {
            return null;
        }
        try {
            $plain = Crypto::decrypt($stored, self::appKey());
            return $plain !== '' ? $plain : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private static function appKey(): string
    {
        return (string) App::config()['app_key'];
    }
}
