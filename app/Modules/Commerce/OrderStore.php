<?php

declare(strict_types=1);

namespace App\Modules\Commerce;

use Core\Database;

/**
 * OrderStore — creación y consulta de pedidos (C4).
 *
 * `createFromCart()` es el único camino de escritura del checkout:
 *   - Recalcula los totales desde BD (CartService::totals, nunca del cliente).
 *   - En transacción, bloquea la fila del sitio (patrón del lock de Booking:
 *     serializa los checkouts del sitio, evita números de pedido duplicados
 *     y carreras de stock) → valida stock → siguiente número PC-<año>-<seq>
 *     → inserta pedido + líneas snapshot.
 *   - El stock NO se decrementa aquí: se decrementa al pasar a 'paid'
 *     (webhook de Stripe o el admin marcando pagado), ver diseño §2.
 */
final class OrderStore
{
    public const RATE_LIMIT_MAX = 5;      // pedidos por IP…
    public const RATE_LIMIT_WINDOW = 10;  // …cada N minutos

    /**
     * Crea un pedido desde el carrito actual.
     *
     * @param array{name:string, email:string, phone?:string, address?:string,
     *              city?:string, postcode?:string, province?:string, notes?:string} $customer
     * @return array{ok:bool, order?:array<string,mixed>, error?:string, detail?:string}
     */
    public static function createFromCart(int $siteId, array $customer, string $paymentMethod, ?string $ipHash): array
    {
        $totals = CartService::totals($siteId);
        if ($totals['lines'] === []) {
            return ['ok' => false, 'error' => 'empty_cart'];
        }
        if ($ipHash !== null && self::isRateLimited($siteId, $ipHash)) {
            return ['ok' => false, 'error' => 'rate_limited'];
        }

        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            // Serializa los checkouts del sitio (número de pedido + stock).
            $lock = $pdo->prepare('SELECT id FROM sites WHERE id = ? FOR UPDATE');
            $lock->execute([$siteId]);

            // Stock definitivo dentro del lock.
            foreach ($totals['lines'] as $line) {
                if ($line['available_stock'] !== null) {
                    $st = $pdo->prepare('SELECT stock FROM commerce_products WHERE id = ?');
                    $st->execute([$line['product_id']]);
                    $stock = $st->fetch(\PDO::FETCH_ASSOC)['stock'] ?? null;
                    if ($stock !== null && (int) $stock < $line['quantity']) {
                        $pdo->rollBack();
                        return [
                            'ok'     => false,
                            'error'  => 'out_of_stock',
                            'detail' => (string) $line['name'],
                        ];
                    }
                }
            }

            $number = self::nextOrderNumber($pdo, $siteId);
            $accessKey = bin2hex(random_bytes(16));

            $ins = $pdo->prepare(
                'INSERT INTO commerce_orders
                    (site_id, order_number, status, payment_method, access_key, currency,
                     subtotal_cents, shipping_cents, tax_cents, total_cents,
                     customer_name, customer_email, customer_phone,
                     ship_address, ship_city, ship_postcode, ship_province,
                     notes, ip_hash, created_at, updated_at)
                 VALUES (?, ?, \'pending_payment\', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())'
            );
            $ins->execute([
                $siteId, $number, $paymentMethod, $accessKey, CommerceSettings::CURRENCY,
                $totals['subtotal_cents'], $totals['shipping_cents'], $totals['tax_cents'], $totals['total_cents'],
                mb_substr(trim((string) $customer['name']), 0, 120),
                mb_substr(trim((string) $customer['email']), 0, 190),
                self::nullable($customer['phone'] ?? '', 40),
                self::nullable($customer['address'] ?? '', 200),
                self::nullable($customer['city'] ?? '', 80),
                self::nullable($customer['postcode'] ?? '', 12),
                self::nullable($customer['province'] ?? '', 80),
                self::nullable($customer['notes'] ?? '', 2000),
                $ipHash,
            ]);
            $orderId = (int) $pdo->lastInsertId();

            $insItem = $pdo->prepare(
                'INSERT INTO commerce_order_items
                    (order_id, product_id, product_name, unit_price_cents, tax_rate, quantity, line_total_cents)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            foreach ($totals['lines'] as $line) {
                $insItem->execute([
                    $orderId, $line['product_id'], $line['name'], $line['unit_price_cents'],
                    $line['tax_rate'], $line['quantity'], $line['line_total_cents'],
                ]);
            }

            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        $order = self::find($siteId, $orderId);
        return ['ok' => true, 'order' => $order ?? []];
    }

    /** @return array<string,mixed>|null pedido con 'items' hidratados */
    public static function find(int $siteId, int $id): ?array
    {
        $order = Database::selectOne(
            'SELECT * FROM commerce_orders WHERE site_id = ? AND id = ? LIMIT 1',
            [$siteId, $id]
        );
        if ($order === null) {
            return null;
        }
        $order['items'] = Database::select(
            'SELECT * FROM commerce_order_items WHERE order_id = ? ORDER BY id',
            [$id]
        );
        return $order;
    }

    /** Página /gracias: número público + access_key (anti-enumeración). */
    public static function findByNumberAndKey(int $siteId, string $number, string $key): ?array
    {
        if ($key === '' || strlen($key) > 64) {
            return null;
        }
        $order = Database::selectOne(
            'SELECT * FROM commerce_orders WHERE site_id = ? AND order_number = ? LIMIT 1',
            [$siteId, $number]
        );
        if ($order === null || !hash_equals((string) $order['access_key'], $key)) {
            return null;
        }
        $order['items'] = Database::select(
            'SELECT * FROM commerce_order_items WHERE order_id = ? ORDER BY id',
            [(int) $order['id']]
        );
        return $order;
    }

    /**
     * Cambia el estado de un pedido. Al pasar a 'paid' decrementa stock (una
     * sola vez: solo desde estados previos a paid). Devuelve false si el
     * pedido no existe o la transición se repite.
     */
    public static function transition(int $siteId, int $orderId, string $to): bool
    {
        if (!in_array($to, ['paid', 'shipped', 'cancelled'], true)) {
            return false;
        }
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $st = $pdo->prepare('SELECT id, status FROM commerce_orders WHERE site_id = ? AND id = ? FOR UPDATE');
            $st->execute([$siteId, $orderId]);
            $row = $st->fetch(\PDO::FETCH_ASSOC);
            if ($row === false || (string) $row['status'] === $to) {
                $pdo->rollBack();
                return false;
            }
            $from = (string) $row['status'];

            $pdo->prepare('UPDATE commerce_orders SET status = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?')
                ->execute([$to, $orderId]);

            // Decremento de stock exactamente una vez: pending_payment → paid.
            if ($to === 'paid' && $from === 'pending_payment') {
                $items = $pdo->prepare('SELECT product_id, quantity FROM commerce_order_items WHERE order_id = ?');
                $items->execute([$orderId]);
                $dec = $pdo->prepare(
                    'UPDATE commerce_products SET stock = GREATEST(0, stock - ?) WHERE id = ? AND stock IS NOT NULL'
                );
                foreach ($items->fetchAll(\PDO::FETCH_ASSOC) as $it) {
                    if ($it['product_id'] !== null) {
                        $dec->execute([(int) $it['quantity'], (int) $it['product_id']]);
                    }
                }
            }
            $pdo->commit();
            return true;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Transiciones de estado permitidas desde el admin (máquina de estados).
     * El webhook de Stripe usa transition() directamente (pending→paid); esta
     * tabla solo restringe lo que el panel ofrece y valida.
     *
     * @var array<string, string[]>
     */
    public const ADMIN_TRANSITIONS = [
        'pending_payment' => ['paid', 'cancelled'],
        'paid'            => ['shipped', 'cancelled'],
        'shipped'         => ['cancelled'],
        'cancelled'       => [],
    ];

    /**
     * Listado para el admin con filtros. Sin items (para la tabla); cada fila
     * lleva item_count. Devuelve como mucho 200 pedidos, más recientes primero.
     *
     * @param array{status?:string, method?:string, q?:string} $filters
     * @return array<int, array<string,mixed>>
     */
    public static function listForAdmin(int $siteId, array $filters = []): array
    {
        $where = ['o.site_id = ?'];
        $args  = [$siteId];

        $status = (string) ($filters['status'] ?? '');
        if (isset(self::ADMIN_TRANSITIONS[$status])) {
            $where[] = 'o.status = ?';
            $args[]  = $status;
        }
        $method = (string) ($filters['method'] ?? '');
        if (in_array($method, ['stripe', 'manual'], true)) {
            $where[] = 'o.payment_method = ?';
            $args[]  = $method;
        }
        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            $where[] = '(o.order_number LIKE ? OR o.customer_email LIKE ? OR o.customer_name LIKE ?)';
            $like = '%' . $q . '%';
            array_push($args, $like, $like, $like);
        }

        return Database::select(
            'SELECT o.*, (SELECT COUNT(*) FROM commerce_order_items WHERE order_id = o.id) AS item_count
               FROM commerce_orders o
              WHERE ' . implode(' AND ', $where) . '
              ORDER BY o.created_at DESC, o.id DESC
              LIMIT 200',
            $args
        );
    }

    /** @return array<string,int> conteo por estado (estados sin pedidos → 0). */
    public static function countByStatus(int $siteId): array
    {
        $counts = array_fill_keys(array_keys(self::ADMIN_TRANSITIONS), 0);
        $rows = Database::select(
            'SELECT status, COUNT(*) AS n FROM commerce_orders WHERE site_id = ? GROUP BY status',
            [$siteId]
        );
        foreach ($rows as $row) {
            $counts[(string) $row['status']] = (int) $row['n'];
        }
        return $counts;
    }

    /** Guarda las notas internas del pedido (no visibles para el cliente). */
    public static function saveAdminNotes(int $siteId, int $orderId, string $notes): bool
    {
        return Database::execute(
            'UPDATE commerce_orders SET admin_notes = ?, updated_at = UTC_TIMESTAMP() WHERE site_id = ? AND id = ?',
            [mb_substr(trim($notes), 0, 5000), $siteId, $orderId]
        ) > 0;
    }

    public static function isRateLimited(int $siteId, string $ipHash): bool
    {
        $row = Database::selectOne(
            'SELECT COUNT(*) AS n FROM commerce_orders
              WHERE site_id = ? AND ip_hash = ?
                AND created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL ' . self::RATE_LIMIT_WINDOW . ' MINUTE)',
            [$siteId, $ipHash]
        );
        return (int) ($row['n'] ?? 0) >= self::RATE_LIMIT_MAX;
    }

    /** Siguiente PC-<año>-<seq> del sitio. Llamar DENTRO de la transacción con lock. */
    private static function nextOrderNumber(\PDO $pdo, int $siteId): string
    {
        $year = gmdate('Y');
        $prefix = 'PC-' . $year . '-';
        $st = $pdo->prepare(
            'SELECT order_number FROM commerce_orders
              WHERE site_id = ? AND order_number LIKE ?
              ORDER BY id DESC LIMIT 1'
        );
        $st->execute([$siteId, $prefix . '%']);
        $last = $st->fetch(\PDO::FETCH_ASSOC);
        $seq = 1;
        if ($last !== false) {
            $seq = (int) substr((string) $last['order_number'], strlen($prefix)) + 1;
        }
        return $prefix . str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
    }

    private static function nullable(string $value, int $max): ?string
    {
        $value = mb_substr(trim($value), 0, $max);
        return $value !== '' ? $value : null;
    }
}
