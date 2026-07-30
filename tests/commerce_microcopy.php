<?php

declare(strict_types=1);

/**
 * I18N-FULL T0.1 — El escaparate de Commerce habla el idioma del sitio.
 *
 * Cubre: claves del módulo completas en los idiomas declarados, interpolación
 * de datos (cantidades, nombre de producto, importes) y ausencia de castellano
 * fijo en el renderizado público.
 */

require_once __DIR__ . '/../config/constants.php';
require_once PP_CORE . '/Autoloader.php';
\Core\Autoloader::register();
require_once PP_ROOT . '/vendor/autoload.php';

use App\Services\Microcopy;

$failed = 0;
function checkShopCopy(string $name, bool $ok, string $detail = ''): void
{
    global $failed;
    echo ($ok ? 'PASS' : 'FAIL') . ' ' . $name . PHP_EOL;
    if (!$ok) {
        $failed++;
        if ($detail !== '') {
            echo '  -> ' . mb_substr($detail, 0, 600) . PHP_EOL;
        }
    }
}

// ---------------------------------------------------------------------------
// 1. Cobertura del diccionario de módulo
// ---------------------------------------------------------------------------

// Política T0.4: las claves de módulo son obligatorias en los idiomas
// declarados; el resto cae a castellano sin romper (mejor castellano correcto
// que una traducción no revisada en un checkout).
$gaps = [];
foreach (Microcopy::MODULE_LANGUAGES as $code) {
    $missing = array_values(array_filter(
        Microcopy::missing($code),
        static fn (string $k): bool => str_starts_with($k, 'shop.')
    ));
    if ($missing !== []) {
        $gaps[] = $code . ': ' . implode(', ', array_slice($missing, 0, 8));
    }
}
checkShopCopy('shop_keys_complete_in_declared_languages', $gaps === [], implode("\n", $gaps));

checkShopCopy(
    'undeclared_language_falls_back_to_spanish',
    !in_array('eu', Microcopy::MODULE_LANGUAGES, true)
        && Microcopy::t('shop.add_to_cart', 'eu') === Microcopy::t('shop.add_to_cart', 'es'),
    'Un idioma no declarado debe caer a castellano, no a cadena vacía'
);

checkShopCopy(
    'shop_translations_are_real',
    Microcopy::t('shop.add_to_cart', 'fr') === 'Ajouter au panier'
        && Microcopy::t('shop.checkout', 'fr') === 'Finaliser la commande'
        && Microcopy::t('shop.cart', 'fr') === 'Panier',
    Microcopy::t('shop.add_to_cart', 'fr')
);

// ---------------------------------------------------------------------------
// 2. Interpolación de datos
// ---------------------------------------------------------------------------

checkShopCopy(
    'placeholders_are_interpolated',
    Microcopy::t('shop.stock_left', 'es', ['n' => 3]) === 'Quedan 3 unidades.'
        && str_contains(Microcopy::t('shop.warn_only_left', 'fr', ['n' => 2, 'product' => 'Chaise']), 'Chaise')
        && str_contains(Microcopy::t('shop.warn_only_left', 'fr', ['n' => 2, 'product' => 'Chaise']), '2'),
    Microcopy::t('shop.stock_left', 'es', ['n' => 3]) . ' / '
        . Microcopy::t('shop.warn_only_left', 'fr', ['n' => 2, 'product' => 'Chaise'])
);

checkShopCopy(
    'missing_placeholder_leaves_no_raw_token',
    !str_contains(Microcopy::t('shop.stock_left', 'es'), '{'),
    Microcopy::t('shop.stock_left', 'es')
);

// Los datos interpolados NO se escapan aquí: el escapado es responsabilidad
// del punto de render (que ya llama a e()). Doble escapado = «&amp;quot;».
checkShopCopy(
    'interpolation_does_not_escape',
    str_contains(Microcopy::t('shop.warn_sold_out', 'es', ['product' => 'Silla & Mesa']), 'Silla & Mesa'),
    Microcopy::t('shop.warn_sold_out', 'es', ['product' => 'Silla & Mesa'])
);

// ---------------------------------------------------------------------------
// 3. El renderizado público ya no lleva castellano fijo
// ---------------------------------------------------------------------------

$hardcoded = [
    'app/Modules/Commerce/ShopController.php' => [
        '>Añadir al carrito<', '>Finalizar compra<', '>Realizar pedido<', '>Actualizar carrito<',
        '>Volver a la tienda<', '<h1>Tienda</h1>', '<h1>Tu carrito</h1>',
        'El nombre es obligatorio.', 'Elige un método de pago.',
        '¡Gracias por tu pedido!', '<dt>Subtotal</dt>', '<dt>Total</dt>',
        'Nombre y apellidos *', 'Código postal *', 'Sin imagen', 'Agotado',
    ],
    'app/Modules/Commerce/CartService.php' => [
        'Ese producto ya no está disponible.', "'Carrito'",
    ],
];
$leftovers = [];
foreach ($hardcoded as $file => $needles) {
    $src = (string) file_get_contents(PP_ROOT . '/' . $file);
    foreach ($needles as $needle) {
        if (str_contains($src, $needle)) {
            $leftovers[] = $file . ' → ' . $needle;
        }
    }
}
checkShopCopy('storefront_has_no_hardcoded_spanish', $leftovers === [], implode("\n", $leftovers));

// Las etiquetas de método de pago y las instrucciones son texto público del
// checkout, de la página de gracias y del email: no pueden quedarse fijas.
$paymentFiles = [
    'app/Modules/Commerce/Payments/ManualPayment.php' => ['Transferencia bancaria o pago acordado', 'pendiente de pago</strong>'],
    'app/Modules/Commerce/Payments/StripeCheckout.php' => ['Tarjeta de crédito o débito'],
];
$payLeftovers = [];
foreach ($paymentFiles as $file => $needles) {
    $src = (string) file_get_contents(PP_ROOT . '/' . $file);
    foreach ($needles as $needle) {
        if (str_contains($src, $needle)) {
            $payLeftovers[] = $file . ' → ' . $needle;
        }
    }
}
checkShopCopy('payment_methods_are_localised', $payLeftovers === [], implode("\n", $payLeftovers));

// Solo un puñado de claves puede llevar marcado inline, y debe ser el nuestro.
// Si aparece marcado en otra clave, alguien está metiendo HTML sin querer en un
// sitio donde se escapa (y saldría «&lt;strong&gt;» a la cara del cliente).
$allowedMarkup = ['shop.manual_pending', 'shop.manual_reference'];
$withMarkup = [];
foreach (Microcopy::keys() as $key) {
    foreach (['es', 'fr'] as $lang) {
        if (str_contains(Microcopy::t($key, $lang), '<') && !in_array($key, $allowedMarkup, true)) {
            $withMarkup[] = $key;
        }
    }
}
checkShopCopy('only_whitelisted_keys_carry_markup', $withMarkup === [], implode(', ', array_unique($withMarkup)));

// El desglose fiscal es texto legal visible: tiene que seguir al idioma.
checkShopCopy(
    'tax_lines_are_localised',
    str_contains(Microcopy::t('shop.tax_included', 'fr', ['rate' => '21']), '21')
        && Microcopy::t('shop.tax_included', 'fr') !== Microcopy::t('shop.tax_included', 'es'),
    Microcopy::t('shop.tax_included', 'fr', ['rate' => '21'])
);

echo PHP_EOL . ($failed === 0 ? 'OK' : $failed . ' FAILED') . PHP_EOL;
exit($failed === 0 ? 0 : 1);
