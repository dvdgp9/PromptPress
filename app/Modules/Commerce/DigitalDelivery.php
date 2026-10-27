<?php

declare(strict_types=1);

namespace App\Modules\Commerce;

use App\Modules\Resources\ResourceAccessService;

/**
 * DigitalDelivery — el derecho de descarga de quien compró un ebook (EBOOK-PAGO).
 *
 * Reparto del contrato (cursor/resources-api.md §7): Recursos guarda y sirve el
 * archivo; aquí se decide si una compra concreta da derecho a bajarlo.
 *
 * - El derecho vive en la LÍNEA del pedido (`commerce_order_items.resource_id`):
 *   cambiar o borrar el producto después no se lo quita a nadie.
 * - Solo dan derecho los pedidos pagados (o marcados como enviados). Cancelar
 *   un pedido —p. ej. tras devolver el dinero— corta los enlaces ya enviados,
 *   porque el estado se comprueba en cada descarga, no al firmar.
 * - El enlace caduca a los 7 días. La página del pedido y el reenvío desde el
 *   panel firman uno nuevo cada vez.
 */
final class DigitalDelivery
{
    public const LINK_TTL = 7 * 86400;

    /** Estados del pedido que dan derecho a descargar. */
    private const ENTITLED_STATUSES = ['paid', 'shipped'];

    /**
     * Líneas del pedido que entregan un archivo.
     *
     * @param array<string,mixed> $order con 'items' hidratados
     * @return array<int, array<string,mixed>>
     */
    public static function digitalItems(array $order): array
    {
        return array_values(array_filter(
            (array) ($order['items'] ?? []),
            static fn (array $it): bool => (int) ($it['resource_id'] ?? 0) > 0
        ));
    }

    /** @param array<string,mixed> $order */
    public static function isEntitled(array $order): bool
    {
        return in_array((string) ($order['status'] ?? ''), self::ENTITLED_STATUSES, true);
    }

    /** ¿Todo el pedido es digital? (cambia el «estamos preparando tu pedido»). @param array<string,mixed> $order */
    public static function isDigitalOnly(array $order): bool
    {
        $items = (array) ($order['items'] ?? []);
        return $items !== [] && count(self::digitalItems($order)) === count($items);
    }

    /**
     * Enlaces de descarga recién firmados. Vacío si el pedido aún no da derecho.
     *
     * @param array<string,mixed> $order
     * @return array<int, array{item_id:int, title:string, url:string, expires_at:int}>
     */
    public static function links(int $siteId, array $order, ?int $now = null): array
    {
        if (!self::isEntitled($order)) {
            return [];
        }
        $now ??= time();
        $out = [];
        foreach (self::digitalItems($order) as $item) {
            $token = ResourceAccessService::issuePurchaseToken(
                $siteId,
                (int) $order['id'],
                (int) $item['id'],
                (int) $item['resource_id'],
                self::LINK_TTL,
                $now
            );
            $out[] = [
                'item_id'    => (int) $item['id'],
                'title'      => (string) $item['product_name'],
                'url'        => base_url('tienda/descargar') . '?token=' . rawurlencode($token),
                'expires_at' => $now + self::LINK_TTL,
            ];
        }
        return $out;
    }

    /**
     * Valida un enlace contra el estado REAL del pedido.
     *
     * 'expired' solo se devuelve si, por lo demás, la compra seguiría dando
     * derecho: a un pedido cancelado no se le explica nada, se le da un 404.
     *
     * @return array{status:string, order?:array<string,mixed>, resource_id?:int}
     *         status: 'ok' | 'expired' | 'invalid'
     */
    public static function authorize(int $siteId, string $token, ?int $now = null): array
    {
        $payload = $token !== '' ? ResourceAccessService::readPurchaseToken($token, $siteId, $now) : null;
        if ($payload === null) {
            return ['status' => 'invalid'];
        }
        $order = OrderStore::find($siteId, $payload['order_id']);
        if ($order === null || !self::isEntitled($order)) {
            return ['status' => 'invalid'];
        }
        $match = null;
        foreach (self::digitalItems($order) as $item) {
            if ((int) $item['id'] === $payload['item_id'] && (int) $item['resource_id'] === $payload['resource_id']) {
                $match = $item;
                break;
            }
        }
        if ($match === null) {
            return ['status' => 'invalid'];
        }
        if ($payload['expired']) {
            return ['status' => 'expired', 'order' => $order];
        }
        return ['status' => 'ok', 'order' => $order, 'resource_id' => $payload['resource_id']];
    }
}
