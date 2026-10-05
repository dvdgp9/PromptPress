<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Payments;

/**
 * PaymentStart — resultado de iniciar un pago (C4).
 *
 * O bien hay que redirigir al cliente (Stripe Checkout), o bien mostrarle
 * instrucciones en la página de gracias (pago manual). Exactamente uno de
 * los dos campos viene relleno.
 */
final class PaymentStart
{
    private function __construct(
        // Sin `readonly`: es de PHP 8.1 y composer.json admite 8.0.
        public ?string $redirectUrl,
        public ?string $instructionsHtml
    ) {
    }

    public static function redirect(string $url): self
    {
        return new self($url, null);
    }

    public static function instructions(string $html): self
    {
        return new self(null, $html);
    }
}
