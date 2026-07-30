<?php

declare(strict_types=1);

namespace App\Modules\Commerce;

use App\Services\FormSubmissionService;
use App\Services\LanguageService;
use App\Services\Microcopy;
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

        $lang = self::language($siteId, $order);
        $t = static fn (string $key, array $vars = []): string => Microcopy::t($key, $lang, $vars);
        $number = (string) $order['order_number'];

        $lines = [
            $t('mail.greeting', ['name' => $order['customer_name']]),
            '',
            $t('mail.shop.created_intro', ['number' => $number]),
            '',
        ];
        foreach ($order['items'] as $it) {
            $lines[] = '• ' . $it['product_name'] . ' × ' . (int) $it['quantity']
                . ' — ' . CommerceSettings::format((int) $it['line_total_cents']);
        }
        if ((int) $order['shipping_cents'] > 0) {
            $lines[] = '• ' . $t('shop.shipping') . ' — ' . CommerceSettings::format((int) $order['shipping_cents']);
        }
        $lines[] = '';
        $lines[] = $t('mail.shop.total_with_tax', [
            'total' => CommerceSettings::format((int) $order['total_cents']),
            'tax'   => CommerceSettings::format((int) $order['tax_cents']),
        ]);
        $lines[] = '';
        if ($instructionsText !== '') {
            $lines[] = $instructionsText;
            $lines[] = '';
        }
        $lines[] = $t('mail.shop.will_notify');
        $lines[] = '';
        $lines[] = $siteName;

        $msg = new MailMessage(
            (string) $order['customer_email'],
            $t('mail.shop.created_subject', ['number' => $number]),
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

        if (!in_array($newStatus, ['paid', 'shipped', 'cancelled'], true)) {
            return;
        }
        $lang = self::language($siteId, $order);
        $t = static fn (string $key, array $vars = []): string => Microcopy::t($key, $lang, $vars);
        $number = (string) $order['order_number'];

        $subject = $t('mail.shop.' . $newStatus . '_subject', ['number' => $number]);
        $body = $t('mail.greeting', ['name' => $order['customer_name']]) . "\n\n"
            . $t('mail.shop.' . $newStatus . '_body')
            . "\n\n" . $t('mail.shop.order_line', ['number' => $number])
            . "\n" . $t('mail.shop.total_simple', [
                'total' => CommerceSettings::format((int) $order['total_cents']),
            ])
            . "\n\n" . $siteName;
        self::deliverToCustomer($siteId, $orderId, new MailMessage(
            (string) $order['customer_email'],
            $subject,
            $body,
            '',
            (string) $order['customer_name']
        ));
    }

    // ======================================================================
    // Internos
    // ======================================================================

    /**
     * Idioma del email al cliente.
     *
     * Hoy es el del sitio. Cuando la fase 1 (T1.2) añada `language` a
     * `commerce_orders` —el idioma con el que el cliente compró—, esa columna
     * manda automáticamente sin tocar este mailer.
     *
     * @param array<string,mixed> $order
     */
    private static function language(int $siteId, array $order): string
    {
        $stored = trim((string) ($order['language'] ?? ''));
        return $stored !== '' ? LanguageService::normalize($stored) : LanguageService::codeFor($siteId);
    }

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
