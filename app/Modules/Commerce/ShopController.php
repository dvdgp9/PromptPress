<?php

declare(strict_types=1);

namespace App\Modules\Commerce;

use App\Modules\Commerce\Payments\PaymentMethods;
use App\Modules\ModuleRegistry;
use App\Services\FormSubmissionService;
use App\Services\Microcopy;
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
        $tr = self::copy($siteId);
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
                : '<span class="pp-shop-card__img pp-shop-card__img--empty">' . e($tr('shop.no_image')) . '</span>';
            $cards .= '<span class="pp-shop-card__body">';
            $cards .= '<span class="pp-shop-card__name">' . e((string) $p['name']) . '</span>';
            if ($soldOut) {
                $cards .= '<span><span class="pp-shop-soldout">' . e($tr('shop.sold_out')) . '</span></span>';
            }
            $cards .= '<span class="pp-shop-card__price">' . e(CommerceSettings::format((int) $p['price_cents'])) . '</span>';
            $cards .= '</span></a>';
        }

        $body  = '<div class="container">';
        $body .= '<div class="pp-shop-head"><h1>' . e($tr('shop.title')) . '</h1>' . CartService::badge($siteId) . '</div>';
        $body .= $products === []
            ? '<p class="pp-shop-empty">' . e($tr('shop.empty_catalog')) . '</p>'
            : '<div class="pp-shop-grid">' . $cards . '</div>';
        $body .= '</div>';

        ShopRenderer::send($siteId, ['title' => $tr('shop.title'), 'body' => $body]);
    }

    public function product(array $params = []): void
    {
        $siteId = self::siteId();
        $tr = self::copy($siteId);
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
        $body .= '<p class="pp-shop-breadcrumb"><a href="' . e(base_url('tienda')) . '">' . e($tr('shop.title')) . '</a> / ' . e((string) $p['name']) . '</p>';
        $body .= '<div class="pp-shop-product">';
        $body .= $img !== ''
            ? '<img class="pp-shop-product__img" src="' . e($img) . '" alt="' . e((string) $p['name']) . '">'
            : '<div class="pp-shop-product__img pp-shop-product__img--empty">' . e($tr('shop.no_image')) . '</div>';
        $body .= '<div>';
        $body .= '<h1>' . e((string) $p['name']) . '</h1>';
        $body .= '<p class="pp-shop-product__price">' . e(CommerceSettings::format((int) $p['price_cents'])) . '</p>';
        $body .= '<p class="pp-shop-product__tax">' . e($includeTax ? $tr('shop.tax_included', ['rate' => $taxRate]) : $tr('shop.tax_excluded', ['rate' => $taxRate])) . '</p>';
        if (trim((string) ($p['description'] ?? '')) !== '') {
            $body .= '<p class="pp-shop-product__desc">' . nl2br(e((string) $p['description'])) . '</p>';
        }

        if ($soldOut) {
            $body .= '<p><span class="pp-shop-soldout">' . e($tr('shop.sold_out')) . '</span></p>';
        } else {
            $max = $stock !== null ? min($stock, 99) : 99;
            $body .= '<form method="post" action="' . e(base_url('tienda/carrito')) . '">';
            $body .= '<input type="hidden" name="_csrf" value="' . e(\Core\CSRF::token()) . '">';
            $body .= '<input type="hidden" name="product_id" value="' . (int) $p['id'] . '">';
            $body .= '<div class="pp-shop-qty"><label for="pp-shop-qty">' . e($tr('shop.quantity')) . '</label>';
            $body .= '<input type="number" id="pp-shop-qty" name="quantity" value="1" min="1" max="' . $max . '"></div>';
            $body .= '<button type="submit" class="pp-btn pp-btn--lg">' . e($tr('shop.add_to_cart')) . '</button>';
            $body .= '</form>';
            if ($stock !== null && $stock <= 5) {
                $body .= '<p class="pp-shop-stocknote">' . e($tr('shop.stock_left', ['n' => $stock])) . '</p>';
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
        $tr = self::copy($siteId);
        $t = CartService::totals($siteId);
        $notice = Session::flash('shop_notice');

        $body = '<div class="container">';
        $body .= '<p class="pp-shop-breadcrumb"><a href="' . e(base_url('tienda')) . '">' . e($tr('shop.title')) . '</a> / ' . e($tr('shop.cart')) . '</p>';
        $body .= '<h1>' . e($tr('shop.your_cart')) . '</h1>';
        if ($notice) {
            $body .= '<p class="pp-shop-notice">' . e($notice) . '</p>';
        }

        if ($t['lines'] === []) {
            $body .= '<p class="pp-shop-empty">' . e($tr('shop.cart_empty')) . ' <a href="' . e(base_url('tienda')) . '">' . e($tr('shop.view_shop')) . '</a>.</p>';
        } else {
            $body .= '<form method="post" action="' . e(base_url('tienda/carrito')) . '">';
            $body .= '<input type="hidden" name="_csrf" value="' . e(CSRF::token()) . '">';
            $body .= '<input type="hidden" name="mode" value="update">';
            $body .= '<table class="pp-shop-table"><thead><tr><th>' . e($tr('shop.col_product')) . '</th><th>' . e($tr('shop.col_price'))
            . '</th><th>' . e($tr('shop.quantity')) . '</th><th>' . e($tr('shop.total')) . '</th><th></th></tr></thead><tbody>';
            foreach ($t['lines'] as $line) {
                $max = $line['available_stock'] !== null ? min($line['available_stock'], 99) : 99;
                $body .= '<tr>';
                $body .= '<td><a href="' . e(base_url('tienda/p/' . $line['slug'])) . '">' . e($line['name']) . '</a></td>';
                $body .= '<td>' . e(CommerceSettings::format($line['unit_price_cents'])) . '</td>';
                $body .= '<td><input type="number" name="qty[' . $line['product_id'] . ']" value="' . $line['quantity'] . '" min="0" max="' . $max . '"></td>';
                $body .= '<td>' . e(CommerceSettings::format($line['line_total_cents'])) . '</td>';
                $body .= '<td><button type="submit" name="remove" value="' . $line['product_id'] . '" class="pp-shop-remove" aria-label="' . e($tr('shop.remove')) . '">×</button></td>';
                $body .= '</tr>';
            }
            $body .= '</tbody></table>';
            $body .= self::totalsHtml($siteId, $t);
            $body .= '<div class="pp-shop-cart-actions">';
            $body .= '<button type="submit" class="pp-btn pp-btn--ghost">' . e($tr('shop.update_cart')) . '</button> ';
            $body .= '<a class="pp-btn pp-btn--lg" href="' . e(base_url('tienda/checkout')) . '">' . e($tr('shop.checkout')) . '</a>';
            $body .= '</div></form>';
        }
        $body .= '</div>';

        ShopRenderer::send($siteId, ['title' => $tr('shop.cart'), 'noindex' => true, 'body' => $body]);
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
        $tr = self::copy($siteId);
        $t = CartService::totals($siteId);
        if ($t['lines'] === []) {
            Response::redirect(base_url('tienda/carrito'));
        }

        // Honeypot: responder como éxito aparente sin crear nada.
        if (trim((string) Request::post('company_url', '')) !== '') {
            CartService::clear($siteId);
            Response::redirect(base_url('tienda'));
        }

        // FEAT-4 AB5 — time-trap ESTRICTO (el checkout siempre lo renderizamos
        // nosotros con el campo): ausente, manipulado o <3 s → mismo éxito
        // aparente del honeypot. Caducado (>6 h con el checkout abierto) →
        // error amable re-renderizando el formulario.
        $ts = Request::post('_pp_ts');
        $tsCheck = \App\Services\Security\BotGuard::verifyTimestamp(is_string($ts) ? $ts : null);
        if ($tsCheck === \App\Services\Security\BotGuard::TOO_FAST
            || $tsCheck === \App\Services\Security\BotGuard::INVALID) {
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
        if ($tsCheck === \App\Services\Security\BotGuard::EXPIRED) {
            $errors[] = $tr('shop.err_expired');
        }
        if ($input['name'] === '') {
            $errors[] = $tr('shop.err_name');
        }
        if ($input['email'] === '' || filter_var($input['email'], FILTER_VALIDATE_EMAIL) === false) {
            $errors[] = $tr('shop.err_email');
        }
        if (self::shippingNeeded($siteId) && ($input['address'] === '' || $input['city'] === '' || $input['postcode'] === '')) {
            $errors[] = $tr('shop.err_address');
        }
        $method = PaymentMethods::byKey($siteId, $methodKey);
        if ($method === null) {
            $errors[] = $tr('shop.err_payment_method');
        }

        if ($errors !== []) {
            $this->renderCheckout($siteId, $t, $input, $errors);
            return;
        }

        $ipHash = FormSubmissionService::ipHash(Request::ip());
        $result = OrderStore::createFromCart($siteId, $input, $method->key(), $ipHash);
        if (!$result['ok']) {
            $msg = match ($result['error'] ?? '') {
                'rate_limited' => $tr('shop.err_rate_limited'),
                'out_of_stock' => $tr('shop.err_out_of_stock', ['product' => (string) ($result['detail'] ?? '')]),
                default        => $tr('shop.err_order_failed'),
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
        $tr = self::copy($siteId);
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
        $body .= '<h1>' . e($tr('shop.thanks_title')) . '</h1>';
        $body .= '<p>' . e($tr('shop.order')) . ' <strong>' . e((string) $order['order_number']) . '</strong> · '
            . e($tr('shop.thanks_email_sent', ['email' => (string) $order['customer_email']])) . '</p>';
        if ((string) $order['status'] === 'paid') {
            $body .= '<p class="pp-shop-notice">' . e($tr('shop.payment_confirmed')) . '</p>';
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
        $body .= self::totalsHtml($siteId, [
            'subtotal_cents'     => (int) $order['subtotal_cents'],
            'tax_cents'          => (int) $order['tax_cents'],
            'shipping_cents'     => (int) $order['shipping_cents'],
            'total_cents'        => (int) $order['total_cents'],
            'prices_include_tax' => true, // el desglose del pedido siempre muestra IVA incluido en el total
        ]);
        $body .= '<p><a class="pp-btn pp-btn--ghost" href="' . e(base_url('tienda')) . '">' . e($tr('shop.back_to_shop')) . '</a></p>';
        $body .= '</div></div>';

        ShopRenderer::send($siteId, ['title' => $tr('shop.order') . ' ' . (string) $order['order_number'], 'noindex' => true, 'body' => $body]);
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
        $tr = self::copy($siteId);
        $methods = PaymentMethods::availableFor($siteId);
        $needShipping = self::shippingNeeded($siteId);
        $v = static fn (string $k): string => e((string) ($old[$k] ?? ''));

        $body = '<div class="container">';
        $body .= '<p class="pp-shop-breadcrumb"><a href="' . e(base_url('tienda')) . '">' . e($tr('shop.title')) . '</a> / <a href="'
            . e(base_url('tienda/carrito')) . '">' . e($tr('shop.cart')) . '</a> / ' . e($tr('shop.checkout')) . '</p>';
        $body .= '<h1>' . e($tr('shop.checkout')) . '</h1>';
        foreach ($errors as $err) {
            $body .= '<p class="pp-shop-error">' . e($err) . '</p>';
        }
        $body .= '<div class="pp-shop-checkout">';

        // Columna izquierda: formulario.
        $body .= '<form method="post" action="' . e(base_url('tienda/checkout')) . '" class="pp-shop-form">';
        $body .= '<input type="hidden" name="_csrf" value="' . e(CSRF::token()) . '">';
        $body .= '<input type="hidden" name="_pp_ts" value="' . e(\App\Services\Security\BotGuard::issueTimestamp()) . '">';
        $body .= '<input type="text" name="company_url" value="" class="pp-shop-hp" tabindex="-1" autocomplete="off" aria-hidden="true">';
        $body .= '<h2>' . e($tr('shop.your_details')) . '</h2>';
        $body .= '<label>' . e($tr('shop.field_name')) . ' *<input type="text" name="name" maxlength="120" required value="' . $v('name') . '"></label>';
        $body .= '<label>' . e($tr('shop.field_email')) . ' *<input type="email" name="email" maxlength="190" required value="' . $v('email') . '"></label>';
        $body .= '<label>' . e($tr('shop.field_phone')) . '<input type="tel" name="phone" maxlength="40" value="' . $v('phone') . '"></label>';
        if ($needShipping) {
            $body .= '<h2>' . e($tr('shop.shipping_address')) . '</h2>';
            $body .= '<label>' . e($tr('shop.field_address')) . ' *<input type="text" name="address" maxlength="200" required value="' . $v('address') . '"></label>';
            $body .= '<div class="pp-shop-form-row">';
            $body .= '<label>' . e($tr('shop.field_city')) . ' *<input type="text" name="city" maxlength="80" required value="' . $v('city') . '"></label>';
            $body .= '<label>' . e($tr('shop.field_postcode')) . ' *<input type="text" name="postcode" maxlength="12" required value="' . $v('postcode') . '"></label>';
            $body .= '</div>';
            $body .= '<label>' . e($tr('shop.field_province')) . '<input type="text" name="province" maxlength="80" value="' . $v('province') . '"></label>';
        }
        $body .= '<label>' . e($tr('shop.field_notes')) . '<textarea name="notes" rows="2" maxlength="2000">' . $v('notes') . '</textarea></label>';

        $body .= '<h2>' . e($tr('shop.payment')) . '</h2>';
        $selected = (string) ($old['payment_method'] ?? array_key_first($methods) ?? '');
        foreach ($methods as $key => $method) {
            $body .= '<label class="pp-shop-pay"><input type="radio" name="payment_method" value="' . e($key) . '"'
                . ($selected === $key ? ' checked' : '') . '> ' . e($method->label($siteId)) . '</label>';
        }
        if ($methods === []) {
            $body .= '<p class="pp-shop-error">' . e($tr('shop.no_payment_methods')) . '</p>';
        }
        $body .= '<button type="submit" class="pp-btn pp-btn--lg"' . ($methods === [] ? ' disabled' : '') . '>' . e($tr('shop.place_order')) . '</button>';
        $body .= '</form>';

        // Columna derecha: resumen.
        $body .= '<aside class="pp-shop-summary"><h2>' . e($tr('shop.your_order')) . '</h2><table class="pp-shop-table"><tbody>';
        foreach ($t['lines'] as $line) {
            $body .= '<tr><td>' . e($line['name']) . ' × ' . $line['quantity'] . '</td>'
                . '<td class="pp-shop-num">' . e(CommerceSettings::format($line['line_total_cents'])) . '</td></tr>';
        }
        $body .= '</tbody></table>' . self::totalsHtml($siteId, $t) . '</aside>';

        $body .= '</div></div>';

        ShopRenderer::send($siteId, ['title' => $tr('shop.checkout'), 'noindex' => true, 'body' => $body]);
    }

/** Desglose de totales (carrito, checkout y gracias). @param array<string,mixed> $totals */
    private static function totalsHtml(int $siteId, array $totals): string
    {
        $tr = self::copy($siteId);
        $h = '<dl class="pp-shop-totals">';
        $h .= '<div><dt>' . e($tr('shop.subtotal')) . '</dt><dd>' . e(CommerceSettings::format((int) $totals['subtotal_cents'])) . '</dd></div>';
        if ((int) $totals['shipping_cents'] > 0) {
            $h .= '<div><dt>' . e($tr('shop.shipping')) . '</dt><dd>' . e(CommerceSettings::format((int) $totals['shipping_cents'])) . '</dd></div>';
        }
        $h .= '<div class="pp-shop-totals__grand"><dt>' . e($tr('shop.total')) . '</dt><dd>' . e(CommerceSettings::format((int) $totals['total_cents'])) . '</dd></div>';
        $h .= '<div class="pp-shop-totals__tax"><dt>' . e($tr('shop.tax_line')) . '</dt><dd>' . e(CommerceSettings::format((int) $totals['tax_cents'])) . '</dd></div>';
        $h .= '</dl>';
        return $h;
    }

    /**
     * Traductor ligado al idioma del sitio. Se usa como `$tr('shop.cart')` en
     * todos los renderizadores públicos de la tienda.
     *
     * @return callable(string, array<string,mixed>=): string
     */
    private static function copy(int $siteId): callable
    {
        return static fn (string $key, array $vars = []): string => Microcopy::site($siteId, $key, $vars);
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
