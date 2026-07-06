<?php

declare(strict_types=1);

namespace App\Modules\Commerce;

use App\Services\FormSubmissionService;
use App\Services\Mail\MailMessage;
use App\Services\Mail\MailService;
use Core\Database;

/**
 * CommerceMailer — emails del ciclo de vida de un pedido (C4/C6).
 *
 * Mismo patrón que BookingMailer: un fallo de SMTP NUNCA pierde el pedido;
 * el resultado del envío al cliente queda en email_status/email_error.
 */
final class CommerceMailer
{
    /** Email al crear el pedido (cliente + aviso al admin). */
    public static function sendCreated(int $siteId, int $orderId, string $instructionsText = ''): void
    {
        $ctx = self::context($siteId, $orderId);
        if ($ctx === null) {
            return;
        }
        [$order, $siteName] = $ctx;

        $lines = [
            'Hola ' . $order['customer_name'] . ',',
            '',
            'Hemos recibido tu pedido ' . $order['order_number'] . '. Resumen:',
            '',
        ];
        foreach ($order['items'] as $it) {
            $lines[] = '• ' . $it['product_name'] . ' × ' . (int) $it['quantity']
                . ' — ' . CommerceSettings::format((int) $it['line_total_cents']);
        }
        if ((int) $order['shipping_cents'] > 0) {
            $lines[] = '• Envío — ' . CommerceSettings::format((int) $order['shipping_cents']);
        }
        $lines[] = '';
        $lines[] = 'Total: ' . CommerceSettings::format((int) $order['total_cents'])
            . ' (incluye ' . CommerceSettings::format((int) $order['tax_cents']) . ' de IVA)';
        $lines[] = '';
        if ($instructionsText !== '') {
            $lines[] = $instructionsText;
            $lines[] = '';
        }
        $lines[] = 'Te avisaremos por email cuando el pedido avance.';
        $lines[] = '';
        $lines[] = $siteName;

        $msg = new MailMessage(
            (string) $order['customer_email'],
            'Pedido recibido: ' . $order['order_number'],
            implode("\n", $lines),
            '',
            (string) $order['customer_name']
        );
        self::deliverToCustomer($siteId, $orderId, $msg);

        self::notifyAdmin($siteId, sprintf(
            "Nuevo pedido %s (%s)\n\nCliente: %s <%s>%s\nTotal: %s\nMétodo de pago: %s\n\nGestión: %s",
            $order['order_number'],
            'pendiente de pago',
            $order['customer_name'],
            $order['customer_email'],
            $order['customer_phone'] !== null ? "\nTeléfono: " . $order['customer_phone'] : '',
            CommerceSettings::format((int) $order['total_cents']),
            (string) $order['payment_method'],
            base_url('admin/commerce/pedidos/' . $orderId)
        ), 'Nuevo pedido ' . $order['order_number'] . ' — ' . CommerceSettings::format((int) $order['total_cents']));
    }

    /** Email al cliente cuando el pedido cambia de estado (C6). */
    public static function sendStatusChange(int $siteId, int $orderId, string $newStatus): void
    {
        $ctx = self::context($siteId, $orderId);
        if ($ctx === null) {
            return;
        }
        [$order, $siteName] = $ctx;

        $subjects = [
            'paid'      => 'Pago recibido: pedido ' . $order['order_number'],
            'shipped'   => 'Pedido enviado: ' . $order['order_number'],
            'cancelled' => 'Pedido cancelado: ' . $order['order_number'],
        ];
        $bodies = [
            'paid'      => 'Hemos recibido el pago de tu pedido. Lo estamos preparando.',
            'shipped'   => 'Tu pedido está en camino.',
            'cancelled' => 'Tu pedido ha sido cancelado. Si tienes dudas, responde a este email.',
        ];
        if (!isset($subjects[$newStatus])) {
            return;
        }
        $body = 'Hola ' . $order['customer_name'] . ",\n\n" . $bodies[$newStatus]
            . "\n\nPedido: " . $order['order_number']
            . "\nTotal: " . CommerceSettings::format((int) $order['total_cents'])
            . "\n\n" . $siteName;
        self::deliverToCustomer($siteId, $orderId, new MailMessage(
            (string) $order['customer_email'],
            $subjects[$newStatus],
            $body,
            '',
            (string) $order['customer_name']
        ));
    }

    // ======================================================================
    // Internos
    // ======================================================================

    /** @return array{0:array<string,mixed>,1:string}|null */
    private static function context(int $siteId, int $orderId): ?array
    {
        $order = OrderStore::find($siteId, $orderId);
        if ($order === null) {
            return null;
        }
        $site = Database::selectOne('SELECT name FROM sites WHERE id = ? LIMIT 1', [$siteId]) ?? [];
        return [$order, (string) ($site['name'] ?? 'PromptPress')];
    }

    private static function deliverToCustomer(int $siteId, int $orderId, MailMessage $msg): void
    {
        try {
            if (!MailService::isConfigured($siteId)) {
                self::mark($orderId, 'skipped', null);
                return;
            }
            $result = MailService::send($siteId, $msg, 'commerce');
            self::mark($orderId, $result->ok ? 'sent' : 'failed', $result->ok ? null : (string) $result->error);
        } catch (\Throwable $e) {
            self::mark($orderId, 'failed', $e->getMessage());
        }
    }

    private static function notifyAdmin(int $siteId, string $body, string $subject): void
    {
        try {
            if (!MailService::isConfigured($siteId)) {
                return;
            }
            $to = FormSubmissionService::recipientForSite($siteId);
            if ($to === null || $to === '') {
                return;
            }
            MailService::send($siteId, new MailMessage($to, $subject, $body), 'commerce');
        } catch (\Throwable) {
            // el aviso al admin nunca rompe el flujo
        }
    }

    private static function mark(int $orderId, string $status, ?string $error): void
    {
        Database::execute(
            'UPDATE commerce_orders SET email_status = ?, email_error = ? WHERE id = ?',
            [$status, $error !== null ? mb_substr($error, 0, 255) : null, $orderId]
        );
    }
}
