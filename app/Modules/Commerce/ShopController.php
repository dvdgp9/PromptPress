<?php

declare(strict_types=1);

namespace App\Modules\Commerce;

use App\Modules\Commerce\Payments\PaymentMethods;
use App\Modules\ModuleRegistry;
use App\Services\FormSubmissionService;
use Core\CSRF;
use Core\Request;
use Core\Response;
use Core\Session;

/**
 * ShopController — catálogo público (C3).
 *
 * GET /tienda            → grid de productos activos.
 * GET /tienda/p/{slug}   → ficha de producto con "añadir al carrito" (C4).
 *
 * El sitio es el público (primer site), resuelto vía ModuleRegistry (lección
 * del guard: Auth::siteId() es solo sesión admin).
 */
final class ShopController
{
    public function index(): void
    {
        $siteId = self::siteId();
        $products = array_values(array_filter(
            ProductStore::all($siteId),
            static fn (array $p): bool => (int) $p['active'] === 1
        ));

        $cards = '';
        foreach ($products as $p) {
            $img = ShopRenderer::imageUrl($p['media_path'] ?? null);
            $soldOut = $p['stock'] !== null && (int) $p['stock'] <= 0;
            $cards .= '<a class="pp-shop-card" href="' . e(base_url('tienda/p/' . $p['slug'])) . '">';
            $cards .= $img !== ''
                ? '<img class="pp-shop-card__img" src="' . e($img) . '" alt="' . e((string) $p['name']) . '" loading="lazy">'
                : '<span class="pp-shop-card__img pp-shop-card__img--empty">Sin imagen</span>';
            $cards .= '<span class="pp-shop-card__body">';
            $cards .= '<span class="pp-shop-card__name">' . e((string) $p['name']) . '</span>';
            if ($soldOut) {
                $cards .= '<span><span class="pp-shop-soldout">Agotado</span></span>';
            }
            $cards .= '<span class="pp-shop-card__price">' . e(CommerceSettings::format((int) $p['price_cents'])) . '</span>';
            $cards .= '</span></a>';
        }

        $body  = '<div class="container">';
        $body .= '<div class="pp-shop-head"><h1>Tienda</h1>' . CartService::badge($siteId) . '</div>';
        $body .= $products === []
            ? '<p class="pp-shop-empty">Todavía no hay productos a la venta. Vuelve pronto.</p>'
            : '<div class="pp-shop-grid">' . $cards . '</div>';
        $body .= '</div>';

        ShopRenderer::send($siteId, ['title' => 'Tienda', 'body' => $body]);
    }

    public function product(array $params = []): void
    {
        $siteId = self::siteId();
        $slug = (string) ($params['slug'] ?? '');
        $p = ProductStore::findActiveBySlug($siteId, $slug);
        if ($p === null) {
            Response::notFound();
        }

        $img = ShopRenderer::imageUrl($p['media_path'] ?? null);
        $stock = $p['stock'] !== null ? (int) $p['stock'] : null;
        $soldOut = $stock !== null && $stock <= 0;
        $includeTax = CommerceSettings::pricesIncludeTax($siteId);
        $taxRate = rtrim(rtrim(number_format((float) $p['tax_rate'], 2, '.', ''), '0'), '.');

        $body  = '<div class="container">';
        $body .= '<p class="pp-shop-breadcrumb"><a href="' . e(base_url('tienda')) . '">Tienda</a> / ' . e((string) $p['name']) . '</p>';
        $body .= '<div class="pp-shop-product">';
        $body .= $img !== ''
            ? '<img class="pp-shop-product__img" src="' . e($img) . '" alt="' . e((string) $p['name']) . '">'
            : '<div class="pp-shop-product__img pp-shop-product__img--empty">Sin imagen</div>';
        $body .= '<div>';
        $body .= '<h1>' . e((string) $p['name']) . '</h1>';
        $body .= '<p class="pp-shop-product__price">' . e(CommerceSettings::format((int) $p['price_cents'])) . '</p>';
        $body .= '<p class="pp-shop-product__tax">' . ($includeTax ? 'IVA (' . e($taxRate) . '%) incluido' : 'Más ' . e($taxRate) . '% de IVA') . '</p>';
        if (trim((string) ($p['description'] ?? '')) !== '') {
            $body .= '<p class="pp-shop-product__desc">' . nl2br(e((string) $p['description'])) . '</p>';
        }

        if ($soldOut) {
            $body .= '<p><span class="pp-shop-soldout">Agotado</span></p>';
        } else {
            $max = $stock !== null ? min($stock, 99) : 99;
            $body .= '<form method="post" action="' . e(base_url('tienda/carrito')) . '">';
            $body .= '<input type="hidden" name="_csrf" value="' . e(\Core\CSRF::token()) . '">';
            $body .= '<input type="hidden" name="product_id" value="' . (int) $p['id'] . '">';
            $body .= '<div class="pp-shop-qty"><label for="pp-shop-qty">Cantidad</label>';
            $body .= '<input type="number" id="pp-shop-qty" name="quantity" value="1" min="1" max="' . $max . '"></div>';
            $body .= '<button type="submit" class="pp-btn pp-btn--lg">Añadir al carrito</button>';
            $body .= '</form>';
            if ($stock !== null && $stock <= 5) {
                $body .= '<p class="pp-shop-stocknote">Quedan ' . $stock . ' unidades.</p>';
            }
        }
        $body .= '</div></div></div>';

        ShopRenderer::send($siteId, [
            'title'       => (string) $p['name'],
            'description' => mb_substr(trim(preg_replace('/\s+/', ' ', (string) ($p['description'] ?? '')) ?? ''), 0, 160),
            'body'        => $body,
        ]);
    }

    // ======================================================================
    // Carrito (C4)
    // ======================================================================

    /** GET /tienda/carrito */
    public function cart(): void
    {
        $siteId = self::siteId();
        $t = CartService::totals($siteId);
        $notice = Session::flash('shop_notice');

        $body = '<div class="container">';
        $body .= '<p class="pp-shop-breadcrumb"><a href="' . e(base_url('tienda')) . '">Tienda</a> / Carrito</p>';
        $body .= '<h1>Tu carrito</h1>';
        if ($notice) {
            $body .= '<p class="pp-shop-notice">' . e($notice) . '</p>';
        }

        if ($t['lines'] === []) {
            $body .= '<p class="pp-shop-empty">El carrito está vacío. <a href="' . e(base_url('tienda')) . '">Ver la tienda</a>.</p>';
        } else {
            $body .= '<form method="post" action="' . e(base_url('tienda/carrito')) . '">';
            $body .= '<input type="hidden" name="_csrf" value="' . e(CSRF::token()) . '">';
            $body .= '<input type="hidden" name="mode" value="update">';
            $body .= '<table class="pp-shop-table"><thead><tr><th>Producto</th><th>Precio</th><th>Cantidad</th><th>Total</th><th></th></tr></thead><tbody>';
            foreach ($t['lines'] as $line) {
                $max = $line['available_stock'] !== null ? min($line['available_stock'], 99) : 99;
                $body .= '<tr>';
                $body .= '<td><a href="' . e(base_url('tienda/p/' . $line['slug'])) . '">' . e($line['name']) . '</a></td>';
                $body .= '<td>' . e(CommerceSettings::format($line['unit_price_cents'])) . '</td>';
                $body .= '<td><input type="number" name="qty[' . $line['product_id'] . ']" value="' . $line['quantity'] . '" min="0" max="' . $max . '"></td>';
                $body .= '<td>' . e(CommerceSettings::format($line['line_total_cents'])) . '</td>';
                $body .= '<td><button type="submit" name="remove" value="' . $line['product_id'] . '" class="pp-shop-remove" aria-label="Quitar">×</button></td>';
                $body .= '</tr>';
            }
            $body .= '</tbody></table>';
            $body .= self::totalsHtml($t);
            $body .= '<div class="pp-shop-cart-actions">';
            $body .= '<button type="submit" class="pp-btn pp-btn--ghost">Actualizar carrito</button> ';
            $body .= '<a class="pp-btn pp-btn--lg" href="' . e(base_url('tienda/checkout')) . '">Finalizar compra</a>';
            $body .= '</div></form>';
        }
        $body .= '</div>';

        ShopRenderer::send($siteId, ['title' => 'Carrito', 'noindex' => true, 'body' => $body]);
    }

    /** POST /tienda/carrito — añadir (desde la ficha) o actualizar/quitar (desde el carrito). */
    public function cartUpdate(): void
    {
        CSRF::check();
        $siteId = self::siteId();

        if ((string) Request::post('mode', '') === 'update') {
            $remove = (int) Request::post('remove', 0);
            if ($remove > 0) {
                CartService::put($siteId, $remove, 0);
            } else {
                $quantities = Request::post('qty', []);
                if (is_array($quantities)) {
                    foreach ($quantities as $pid => $qty) {
                        $warning = CartService::put($siteId, (int) $pid, (int) $qty);
                        if ($warning !== null) {
                            Session::flash('shop_notice', $warning);
                        }
                    }
                }
            }
            Response::redirect(base_url('tienda/carrito'));
        }

        $warning = CartService::add($siteId, (int) Request::post('product_id', 0), (int) Request::post('quantity', 1));
        if ($warning !== null) {
            Session::flash('shop_notice', $warning);
        }
        Response::redirect(base_url('tienda/carrito'));
    }

    // ======================================================================
    // Checkout (C4)
    // ======================================================================

    /** GET /tienda/checkout */
    public function checkout(): void
    {
        $siteId = self::siteId();
        $t = CartService::totals($siteId);
        if ($t['lines'] === []) {
            Response::redirect(base_url('tienda/carrito'));
        }
        $this->renderCheckout($siteId, $t, [], []);
    }

    /** POST /tienda/checkout — crea el pedido y delega en el método de pago. */
    public function checkoutSubmit(): void
    {
        CSRF::check();
        $siteId = self::siteId();
        $t = CartService::totals($siteId);
        if ($t['lines'] === []) {
            Response::redirect(base_url('tienda/carrito'));
        }

        // Honeypot: responder como éxito aparente sin crear nada.
        if (trim((string) Request::post('company_url', '')) !== '') {
            CartService::clear($siteId);
            Response::redirect(base_url('tienda'));
        }

        $input = [
            'name'     => trim((string) Request::post('name', '')),
            'email'    => trim((string) Request::post('email', '')),
            'phone'    => trim((string) Request::post('phone', '')),
            'address'  => trim((string) Request::post('address', '')),
            'city'     => trim((string) Request::post('city', '')),
            'postcode' => trim((string) Request::post('postcode', '')),
            'province' => trim((string) Request::post('province', '')),
            'notes'    => trim((string) Request::post('notes', '')),
        ];
        $methodKey = (string) Request::post('payment_method', '');

        $errors = [];
        if ($input['name'] === '') {
            $errors[] = 'El nombre es obligatorio.';
        }
        if ($input['email'] === '' || filter_var($input['email'], FILTER_VALIDATE_EMAIL) === false) {
            $errors[] = 'Necesitamos un email válido para el pedido.';
        }
        if (self::shippingNeeded($siteId) && ($input['address'] === '' || $input['city'] === '' || $input['postcode'] === '')) {
            $errors[] = 'Completa la dirección de envío (dirección, población y código postal).';
        }
        $method = PaymentMethods::byKey($siteId, $methodKey);
        if ($method === null) {
            $errors[] = 'Elige un método de pago.';
        }

        if ($errors !== []) {
            $this->renderCheckout($siteId, $t, $input, $errors);
            return;
        }

        $ipHash = FormSubmissionService::ipHash(Request::ip());
        $result = OrderStore::createFromCart($siteId, $input, $method->key(), $ipHash);
        if (!$result['ok']) {
            $msg = match ($result['error'] ?? '') {
                'rate_limited' => 'Hemos recibido varios pedidos seguidos desde tu conexión. Espera unos minutos.',
                'out_of_stock' => 'No queda stock suficiente de «' . ($result['detail'] ?? '') . '». Revisa el carrito.',
                default        => 'No se pudo crear el pedido. Revisa el carrito e inténtalo de nuevo.',
            };
            $this->renderCheckout($siteId, CartService::totals($siteId), $input, [$msg]);
            return;
        }

        $order = $result['order'];
        CartService::clear($siteId);

        // C7 — conversión en Analytics, en la petición del visitante (mismo
        // patrón que booking_created). record() nunca lanza; el guard evita
        // el trabajo si el módulo está apagado.
        if (ModuleRegistry::isEnabled($siteId, 'analytics')) {
            \App\Modules\Analytics\EventRecorder::record(
                $siteId, 'purchase', '/tienda/checkout', null, Request::ip(), Request::userAgent()
            );
        }

        $start = $method->start($siteId, $order);

        // Email de creación (con las instrucciones si son de pago manual).
        try {
            CommerceMailer::sendCreated($siteId, (int) $order['id'],
                $start->instructionsHtml !== null ? trim(strip_tags(str_replace('<br />', "\n", $start->instructionsHtml))) : '');
        } catch (\Throwable) {
            // el email nunca rompe el pedido
        }

        if ($start->redirectUrl !== null) {
            Response::redirect($start->redirectUrl);
        }
        Response::redirect(base_url('tienda/gracias/' . $order['order_number'] . '?k=' . $order['access_key']));
    }

    /** GET /tienda/gracias/{number}?k=... */
    public function thanks(array $params = []): void
    {
        $siteId = self::siteId();
        $order = OrderStore::findByNumberAndKey(
            $siteId,
            (string) ($params['number'] ?? ''),
            trim((string) Request::get('k', ''))
        );
        if ($order === null) {
            Response::notFound();
        }

        // Stripe (C5): si el webhook aún no llegó, reconciliar contra la API
        // al aterrizar desde Stripe (la transición es idempotente).
        $order = self::reconcileStripe($siteId, $order);

        $body = '<div class="container"><div class="pp-shop-thanks">';
        $body .= '<h1>¡Gracias por tu pedido!</h1>';
        $body .= '<p>Pedido <strong>' . e((string) $order['order_number']) . '</strong> · '
            . 'Te hemos enviado un email a ' . e((string) $order['customer_email']) . ' con el resumen.</p>';
        if ((string) $order['status'] === 'paid') {
            $body .= '<p class="pp-shop-notice">✓ Pago confirmado. Estamos preparando tu pedido.</p>';
        }

        // Estado del pago mientras el pedido siga pendiente (instrucciones de
        // transferencia, o reintento de tarjeta). pendingInstructions es puro:
        // recargar esta página no dispara llamadas a la pasarela.
        $method = PaymentMethods::byKey($siteId, (string) $order['payment_method']);
        if ($method !== null && (string) $order['status'] === 'pending_payment') {
            $pending = $method->pendingInstructions($siteId, $order);
            if ($pending !== null) {
                $body .= '<div class="pp-shop-instructions">' . $pending . '</div>';
            }
        }

        $body .= '<table class="pp-shop-table"><tbody>';
        foreach ($order['items'] as $it) {
            $body .= '<tr><td>' . e((string) $it['product_name']) . ' × ' . (int) $it['quantity'] . '</td>'
                . '<td class="pp-shop-num">' . e(CommerceSettings::format((int) $it['line_total_cents'])) . '</td></tr>';
        }
        $body .= '</tbody></table>';
        $body .= self::totalsHtml([
            'subtotal_cents'     => (int) $order['subtotal_cents'],
            'tax_cents'          => (int) $order['tax_cents'],
            'shipping_cents'     => (int) $order['shipping_cents'],
            'total_cents'        => (int) $order['total_cents'],
            'prices_include_tax' => true, // el desglose del pedido siempre muestra IVA incluido en el total
        ]);
        $body .= '<p><a class="pp-btn pp-btn--ghost" href="' . e(base_url('tienda')) . '">Volver a la tienda</a></p>';
        $body .= '</div></div>';

        ShopRenderer::send($siteId, ['title' => 'Pedido ' . (string) $order['order_number'], 'noindex' => true, 'body' => $body]);
    }

    /**
     * GET /tienda/pagar/{number}?k=... — reintento de pago con tarjeta (C5).
     * Crea una Checkout Session nueva para un pedido pendiente y redirige.
     */
    public function payRetry(array $params = []): void
    {
        $siteId = self::siteId();
        $order = OrderStore::findByNumberAndKey(
            $siteId,
            (string) ($params['number'] ?? ''),
            trim((string) Request::get('k', ''))
        );
        if ($order === null) {
            Response::notFound();
        }

        $thanksUrl = base_url('tienda/gracias/' . $order['order_number'] . '?k=' . $order['access_key']);
        // Por si el pago ya entró (webhook o reconciliación) entre medias.
        $order = self::reconcileStripe($siteId, $order);
        $method = PaymentMethods::byKey($siteId, (string) $order['payment_method']);
        if ((string) $order['status'] !== 'pending_payment' || $method === null || $method->key() !== 'stripe') {
            Response::redirect($thanksUrl);
        }

        $start = $method->start($siteId, $order);
        Response::redirect($start->redirectUrl ?? $thanksUrl);
    }

    // ======================================================================
    // Helpers
    // ======================================================================

    /**
     * Si un pedido Stripe sigue pendiente, consulta la Checkout Session y lo
     * confirma cuando Stripe ya lo da por pagado (refuerzo del webhook,
     * recomendado por la doc de fulfillment). Devuelve el pedido actualizado.
     *
     * @param array<string,mixed> $order
     * @return array<string,mixed>
     */
    private static function reconcileStripe(int $siteId, array $order): array
    {
        $ref = (string) ($order['payment_ref'] ?? '');
        if ((string) $order['status'] !== 'pending_payment'
            || (string) $order['payment_method'] !== 'stripe'
            || !str_starts_with($ref, 'cs_')) {
            return $order;
        }
        try {
            $secretKey = \App\Modules\Commerce\Payments\StripeConfig::secretKey($siteId);
            if ($secretKey === null) {
                return $order;
            }
            $session = \App\Modules\Commerce\Payments\StripeApi::getCheckoutSession($secretKey, $ref);
            if ((string) ($session['payment_status'] ?? '') === 'paid') {
                StripeWebhookController::markPaid($siteId, $session);
                return OrderStore::find($siteId, (int) $order['id']) ?? $order;
            }
        } catch (\Throwable $e) {
            logger('Stripe reconcile pedido ' . $order['order_number'] . ': ' . $e->getMessage(), 'WARNING');
        }
        return $order;
    }

    /** @param array<string,mixed> $t @param array<string,string> $old @param string[] $errors */
    private function renderCheckout(int $siteId, array $t, array $old, array $errors): void
    {
        $methods = PaymentMethods::availableFor($siteId);
        $needShipping = self::shippingNeeded($siteId);
        $v = static fn (string $k): string => e((string) ($old[$k] ?? ''));

        $body = '<div class="container">';
        $body .= '<p class="pp-shop-breadcrumb"><a href="' . e(base_url('tienda')) . '">Tienda</a> / <a href="' . e(base_url('tienda/carrito')) . '">Carrito</a> / Checkout</p>';
        $body .= '<h1>Finalizar compra</h1>';
        foreach ($errors as $err) {
            $body .= '<p class="pp-shop-error">' . e($err) . '</p>';
        }
        $body .= '<div class="pp-shop-checkout">';

        // Columna izquierda: formulario.
        $body .= '<form method="post" action="' . e(base_url('tienda/checkout')) . '" class="pp-shop-form">';
        $body .= '<input type="hidden" name="_csrf" value="' . e(CSRF::token()) . '">';
        $body .= '<input type="text" name="company_url" value="" class="pp-shop-hp" tabindex="-1" autocomplete="off" aria-hidden="true">';
        $body .= '<h2>Tus datos</h2>';
        $body .= '<label>Nombre y apellidos *<input type="text" name="name" maxlength="120" required value="' . $v('name') . '"></label>';
        $body .= '<label>Email *<input type="email" name="email" maxlength="190" required value="' . $v('email') . '"></label>';
        $body .= '<label>Teléfono<input type="tel" name="phone" maxlength="40" value="' . $v('phone') . '"></label>';
        if ($needShipping) {
            $body .= '<h2>Dirección de envío</h2>';
            $body .= '<label>Dirección *<input type="text" name="address" maxlength="200" required value="' . $v('address') . '"></label>';
            $body .= '<div class="pp-shop-form-row">';
            $body .= '<label>Población *<input type="text" name="city" maxlength="80" required value="' . $v('city') . '"></label>';
            $body .= '<label>Código postal *<input type="text" name="postcode" maxlength="12" required value="' . $v('postcode') . '"></label>';
            $body .= '</div>';
            $body .= '<label>Provincia<input type="text" name="province" maxlength="80" value="' . $v('province') . '"></label>';
        }
        $body .= '<label>Notas del pedido<textarea name="notes" rows="2" maxlength="2000">' . $v('notes') . '</textarea></label>';

        $body .= '<h2>Pago</h2>';
        $selected = (string) ($old['payment_method'] ?? array_key_first($methods) ?? '');
        foreach ($methods as $key => $method) {
            $body .= '<label class="pp-shop-pay"><input type="radio" name="payment_method" value="' . e($key) . '"'
                . ($selected === $key ? ' checked' : '') . '> ' . e($method->label($siteId)) . '</label>';
        }
        if ($methods === []) {
            $body .= '<p class="pp-shop-error">Ahora mismo no hay ningún método de pago disponible.</p>';
        }
        $body .= '<button type="submit" class="pp-btn pp-btn--lg"' . ($methods === [] ? ' disabled' : '') . '>Realizar pedido</button>';
        $body .= '</form>';

        // Columna derecha: resumen.
        $body .= '<aside class="pp-shop-summary"><h2>Tu pedido</h2><table class="pp-shop-table"><tbody>';
        foreach ($t['lines'] as $line) {
            $body .= '<tr><td>' . e($line['name']) . ' × ' . $line['quantity'] . '</td>'
                . '<td class="pp-shop-num">' . e(CommerceSettings::format($line['line_total_cents'])) . '</td></tr>';
        }
        $body .= '</tbody></table>' . self::totalsHtml($t) . '</aside>';

        $body .= '</div></div>';

        ShopRenderer::send($siteId, ['title' => 'Finalizar compra', 'noindex' => true, 'body' => $body]);
    }

    /** Desglose de totales (carrito, checkout y gracias). @param array<string,mixed> $t */
    private static function totalsHtml(array $t): string
    {
        $h = '<dl class="pp-shop-totals">';
        $h .= '<div><dt>Subtotal</dt><dd>' . e(CommerceSettings::format((int) $t['subtotal_cents'])) . '</dd></div>';
        if ((int) $t['shipping_cents'] > 0) {
            $h .= '<div><dt>Envío</dt><dd>' . e(CommerceSettings::format((int) $t['shipping_cents'])) . '</dd></div>';
        }
        $h .= '<div class="pp-shop-totals__grand"><dt>Total</dt><dd>' . e(CommerceSettings::format((int) $t['total_cents'])) . '</dd></div>';
        $h .= '<div class="pp-shop-totals__tax"><dt>Incluye IVA</dt><dd>' . e(CommerceSettings::format((int) $t['tax_cents'])) . '</dd></div>';
        $h .= '</dl>';
        return $h;
    }

    /** ¿El sitio tiene envío configurado (y por tanto pedimos dirección)? */
    private static function shippingNeeded(int $siteId): bool
    {
        return CommerceSettings::shippingCents($siteId) > 0
            || CommerceSettings::freeShippingOverCents($siteId) !== null;
    }

    private static function siteId(): int
    {
        $siteId = ModuleRegistry::resolveSiteId();
        if ($siteId === null) {
            Response::notFound();
        }
        return $siteId;
    }
}
