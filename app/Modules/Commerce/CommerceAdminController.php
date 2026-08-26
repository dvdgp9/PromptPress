<?php

declare(strict_types=1);

namespace App\Modules\Commerce;

use App\Modules\Commerce\Payments\StripeConfig;
use Core\Auth;
use Core\CSRF;
use Core\Request;
use Core\Response;
use Core\Session;
use Core\View;

/**
 * CommerceAdminController — CRUD de productos (C2) + ajustes de pago (C5).
 *
 * La gestión de pedidos (C6) llega después.
 */
final class CommerceAdminController
{
    /** GET /admin/commerce — listado de productos. */
    public function index(): void
    {
        $siteId = $this->requireSiteId();
        View::send('admin/commerce/index', [
            'products'         => ProductStore::all($siteId),
            'pricesIncludeTax' => CommerceSettings::pricesIncludeTax($siteId),
            'notice'           => Session::flash('notice'),
            'error'            => Session::flash('error'),
            'csrf'             => CSRF::token(),
        ]);
    }

    /** POST /admin/commerce/products — crea con nombre y va al editor. */
    public function create(): void
    {
        CSRF::check();
        $siteId = $this->requireSiteId();
        $name = trim((string) Request::post('name', ''));
        if ($name === '') {
            Session::flash('error', __('prod.err.name_required'));
            Response::redirect(base_url('admin/commerce'));
        }
        $id = ProductStore::create($siteId, ['name' => $name, 'active' => '0']);
        Session::flash('notice', __('prod.ok.created'));
        Response::redirect(base_url('admin/commerce/products/' . $id));
    }

    /** GET /admin/commerce/products/{id} — editor. */
    public function edit(array $params = []): void
    {
        $siteId = $this->requireSiteId();
        $product = ProductStore::find($siteId, (int) ($params['id'] ?? 0));
        if ($product === null) {
            Session::flash('error', __('prod.err.not_found'));
            Response::redirect(base_url('admin/commerce'));
        }
        $this->renderEditor($siteId, $product, []);
    }

    /** POST /admin/commerce/products/{id} — guarda. */
    public function update(array $params = []): void
    {
        CSRF::check();
        $siteId = $this->requireSiteId();
        $id = (int) ($params['id'] ?? 0);
        $existing = ProductStore::find($siteId, $id);
        if ($existing === null) {
            Session::flash('error', __('prod.err.not_found'));
            Response::redirect(base_url('admin/commerce'));
        }

        $fields = [
            'name'        => Request::post('name', ''),
            'description' => Request::post('description', ''),
            'price_cents' => CommerceSettings::eurosToCents((string) Request::post('price', '')),
            'tax_rate'    => Request::post('tax_rate', '21'),
            'stock'       => Request::post('stock', ''),
            'media_id'    => Request::post('media_id', ''),
            'active'      => Request::post('active', '0'),
        ];

        $errors = [];
        if (trim((string) $fields['name']) === '') {
            $errors[] = __('bk.err.name_empty');
        }
        if ((int) $fields['price_cents'] <= 0 && (string) $fields['active'] === '1') {
            $errors[] = __('prod.err.price');
        }

        if ($errors !== []) {
            $draft = array_merge($existing, ProductStore::normalize($fields), ['id' => $id]);
            $this->renderEditor($siteId, $draft, $errors);
            return;
        }

        ProductStore::update($siteId, $id, $fields);
        Session::flash('notice', __('prod.ok.saved'));
        Response::redirect(base_url('admin/commerce/products/' . $id));
    }

    /** POST /admin/commerce/products/{id}/delete */
    public function destroy(array $params = []): void
    {
        CSRF::check();
        $siteId = $this->requireSiteId();
        if (ProductStore::delete($siteId, (int) ($params['id'] ?? 0))) {
            Session::flash('notice', __('prod.ok.deleted'));
        } else {
            Session::flash('error', __('prod.err.delete'));
        }
        Response::redirect(base_url('admin/commerce'));
    }

    // ======================================================================
    // Pagos (C5)
    // ======================================================================

    /** GET /admin/commerce/pagos — configuración de métodos de pago. */
    public function payments(): void
    {
        $siteId = $this->requireSiteId();
        $this->renderPayments($siteId, []);
    }

    /** POST /admin/commerce/pagos */
    public function paymentsSave(): void
    {
        CSRF::check();
        $siteId = $this->requireSiteId();

        // Desactivar por completo el pago con tarjeta: borra claves y secretos.
        if ((string) Request::post('disable_stripe', '0') === '1') {
            foreach (['commerce_stripe_sk_test', 'commerce_stripe_sk_live',
                      'commerce_stripe_webhook_secret_test', 'commerce_stripe_webhook_secret_live'] as $k) {
                StripeConfig::saveSecret($siteId, $k, '');
            }
            Session::flash('notice', __('pay.ok.card_disabled'));
            Response::redirect(base_url('admin/commerce/pagos'));
        }

        $mode = (string) Request::post('stripe_mode', 'test') === 'live' ? 'live' : 'test';
        $fields = [
            'commerce_stripe_sk_test'             => ['sk_test_', __('pay.secret_key') . ' (' . __('pay.mode_test') . ')', trim((string) Request::post('sk_test', ''))],
            'commerce_stripe_sk_live'             => ['sk_live_', __('pay.secret_key') . ' (' . __('pay.mode_live') . ')', trim((string) Request::post('sk_live', ''))],
            'commerce_stripe_webhook_secret_test' => ['whsec_', __('pay.webhook_secret') . ' (' . __('pay.mode_test') . ')', trim((string) Request::post('whsec_test', ''))],
            'commerce_stripe_webhook_secret_live' => ['whsec_', __('pay.webhook_secret') . ' (' . __('pay.mode_live') . ')', trim((string) Request::post('whsec_live', ''))],
        ];

        $errors = [];
        foreach ($fields as $settingKey => [$prefix, $label, $value]) {
            if ($value === '') {
                continue; // en blanco = conservar la guardada
            }
            if (str_starts_with($value, 'pk_')) {
                $errors[] = __('pay.err.publishable.html', ['campo' => $label, 'prefijo' => $prefix]);
                continue;
            }
            // Las claves restringidas (rk_test_/rk_live_) también valen.
            $rkPrefix = str_replace('sk_', 'rk_', $prefix);
            if (!str_starts_with($value, $prefix) && !($prefix !== 'whsec_' && str_starts_with($value, $rkPrefix))) {
                $errors[] = __('pay.err.bad_prefix', ['campo' => $label, 'prefijo' => $prefix]);
                continue;
            }
            StripeConfig::saveSecret($siteId, $settingKey, $value);
        }

        CommerceSettings::set($siteId, 'commerce_stripe_mode', $mode);
        CommerceSettings::set($siteId, 'commerce_manual_instructions', trim((string) Request::post('manual_instructions', '')));

        if ($errors !== []) {
            $this->renderPayments($siteId, $errors);
            return;
        }

        if ($mode === 'live' && StripeConfig::secretKey($siteId, 'live') === null) {
            Session::flash('error', __('pay.err.live_no_key'));
        } else {
            Session::flash('notice', __('pay.ok.saved'));
        }
        Response::redirect(base_url('admin/commerce/pagos'));
    }

    /** @param string[] $errors (pueden llevar HTML propio) */
    private function renderPayments(int $siteId, array $errors): void
    {
        View::send('admin/commerce/payments', [
            'mode'               => StripeConfig::mode($siteId),
            'configured'         => StripeConfig::isConfigured($siteId),
            'maskedSkTest'       => StripeConfig::masked(StripeConfig::secretKey($siteId, 'test')),
            'maskedSkLive'       => StripeConfig::masked(StripeConfig::secretKey($siteId, 'live')),
            'maskedWhsecTest'    => StripeConfig::masked(StripeConfig::webhookSecret($siteId, 'test')),
            'maskedWhsecLive'    => StripeConfig::masked(StripeConfig::webhookSecret($siteId, 'live')),
            'webhookUrl'         => base_url('tienda/stripe/webhook'),
            'manualInstructions' => CommerceSettings::get($siteId, 'commerce_manual_instructions'),
            'errors'             => $errors,
            'notice'             => Session::flash('notice'),
            'error'              => Session::flash('error'),
            'csrf'               => CSRF::token(),
        ]);
    }

    // ======================================================================
    // Pedidos (C6)
    // ======================================================================

    /** GET /admin/commerce/pedidos — listado con filtros. */
    public function orders(): void
    {
        $siteId = $this->requireSiteId();
        $filters = [
            'status' => (string) Request::get('status', ''),
            'method' => (string) Request::get('method', ''),
            'q'      => trim((string) Request::get('q', '')),
        ];
        View::send('admin/commerce/orders', [
            'orders'  => OrderStore::listForAdmin($siteId, $filters),
            'counts'  => OrderStore::countByStatus($siteId),
            'filters' => $filters,
            'notice'  => Session::flash('notice'),
            'error'   => Session::flash('error'),
            'csrf'    => CSRF::token(),
        ]);
    }

    /** GET /admin/commerce/pedidos/{id} — detalle del pedido. */
    public function order(array $params = []): void
    {
        $siteId = $this->requireSiteId();
        $order = OrderStore::find($siteId, (int) ($params['id'] ?? 0));
        if ($order === null) {
            Session::flash('error', __('order.err.not_found'));
            Response::redirect(base_url('admin/commerce/pedidos'));
        }
        View::send('admin/commerce/order', [
            'order'  => $order,
            'nextStates' => OrderStore::ADMIN_TRANSITIONS[(string) $order['status']] ?? [],
            'notice' => Session::flash('notice'),
            'error'  => Session::flash('error'),
            'csrf'   => CSRF::token(),
        ]);
    }

    /** POST /admin/commerce/pedidos/{id}/status — transición de estado + email al cliente. */
    public function orderStatus(array $params = []): void
    {
        CSRF::check();
        $siteId = $this->requireSiteId();
        $id = (int) ($params['id'] ?? 0);
        $to = (string) Request::post('status', '');
        $detailUrl = base_url('admin/commerce/pedidos/' . $id);

        $order = OrderStore::find($siteId, $id);
        if ($order === null) {
            Session::flash('error', __('order.err.not_found'));
            Response::redirect(base_url('admin/commerce/pedidos'));
        }
        $from = (string) $order['status'];
        if (!in_array($to, OrderStore::ADMIN_TRANSITIONS[$from] ?? [], true)) {
            Session::flash('error', __('order.err.bad_transition'));
            Response::redirect($detailUrl);
        }

        if (!OrderStore::transition($siteId, $id, $to)) {
            Session::flash('error', __('order.err.update'));
            Response::redirect($detailUrl);
        }

        $mail = 'failed';
        try {
            $mail = CommerceMailer::sendStatusChange($siteId, $id, $to);
        } catch (\Throwable) {
            // el email nunca revierte el cambio de estado
        }

        $labels = ['paid' => __('order.marked.paid'), 'shipped' => __('order.marked.shipped'), 'cancelled' => __('order.marked.cancelled')];
        // El aviso cuenta lo que ha pasado de verdad: prometer un email que no
        // ha salido (sitio sin SMTP) hace creer que el cliente ya está avisado.
        $key = 'order.ok.status_changed';
        if ($mail !== 'sent') {
            $key .= $mail === 'skipped' ? '_no_mail' : '_mail_failed';
        }
        Session::flash('notice', __($key, ['estado' => $labels[$to] ?? $to])
            . ($to === 'paid' && $from === 'pending_payment' ? ' ' . __('order.ok.stock_note') : ''));
        Response::redirect($detailUrl);
    }

    /** POST /admin/commerce/pedidos/{id}/notes — notas internas (no visibles para el cliente). */
    public function orderNotes(array $params = []): void
    {
        CSRF::check();
        $siteId = $this->requireSiteId();
        $id = (int) ($params['id'] ?? 0);
        $detailUrl = base_url('admin/commerce/pedidos/' . $id);
        if (OrderStore::find($siteId, $id) === null) {
            Session::flash('error', __('order.err.not_found'));
            Response::redirect(base_url('admin/commerce/pedidos'));
        }
        OrderStore::saveAdminNotes($siteId, $id, (string) Request::post('admin_notes', ''));
        Session::flash('notice', __('order.ok.notes_saved'));
        Response::redirect($detailUrl);
    }

    // ======================================================================
    // Helpers
    // ======================================================================

    /** @param array<string,mixed> $product @param string[] $errors */
    private function renderEditor(int $siteId, array $product, array $errors): void
    {
        View::send('admin/commerce/edit', [
            'product'          => $product,
            'pricesIncludeTax' => CommerceSettings::pricesIncludeTax($siteId),
            'errors'           => $errors,
            'notice'           => Session::flash('notice'),
            'csrf'             => CSRF::token(),
        ]);
    }

    private function requireSiteId(): int
    {
        $siteId = Auth::siteId();
        if ($siteId === null) {
            Session::flash('error', __('bk.err.no_site'));
            Response::redirect(base_url('admin/'));
        }
        return $siteId;
    }
}
