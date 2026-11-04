<?php

declare(strict_types=1);

/**
 * BOOK-PAGO — Cobrar con Stripe al reservar un servicio.
 *
 * Contrato: Booking es dueño del hueco; Commerce del cobro. Cada reserva de
 * pago crea un pedido de Commerce de una línea que apunta a la reserva.
 *
 * - `booking_services.requires_payment` + `price_cents` + `tax_rate`: el precio
 *   real (el `price_label` sigue siendo el texto que se enseña).
 * - `booking_bookings.status` + `awaiting_payment`: ocupa plaza solo mientras
 *   `hold_until_utc` no haya pasado (liberación perezosa, sin cron).
 * - `booking_bookings.order_id`: el pedido que la cobra.
 * - `commerce_order_items.booking_id`: la línea sabe qué reserva confirma.
 *
 * Idempotente: cada paso mira information_schema antes.
 */
return static function (PDO $pdo): void {
    $hasColumn = static function (string $table, string $column) use ($pdo): bool {
        $stmt = $pdo->prepare(
            'SELECT 1 FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1'
        );
        $stmt->execute([$table, $column]);
        return (bool) $stmt->fetchColumn();
    };

    if (!$hasColumn('booking_services', 'requires_payment')) {
        $pdo->exec('ALTER TABLE booking_services ADD COLUMN requires_payment TINYINT(1) NOT NULL DEFAULT 0 AFTER price_label');
    }
    if (!$hasColumn('booking_services', 'price_cents')) {
        $pdo->exec('ALTER TABLE booking_services ADD COLUMN price_cents INT UNSIGNED NULL AFTER requires_payment');
    }
    if (!$hasColumn('booking_services', 'tax_rate')) {
        $pdo->exec('ALTER TABLE booking_services ADD COLUMN tax_rate DECIMAL(4,2) NOT NULL DEFAULT 21.00 AFTER price_cents');
    }

    // Ampliar un ENUM con MODIFY es idempotente por sí mismo.
    $pdo->exec(
        "ALTER TABLE booking_bookings
         MODIFY COLUMN status ENUM('pending','confirmed','cancelled','awaiting_payment') NOT NULL DEFAULT 'pending'"
    );
    if (!$hasColumn('booking_bookings', 'hold_until_utc')) {
        $pdo->exec('ALTER TABLE booking_bookings ADD COLUMN hold_until_utc DATETIME NULL AFTER status');
    }
    if (!$hasColumn('booking_bookings', 'order_id')) {
        $pdo->exec('ALTER TABLE booking_bookings ADD COLUMN order_id INT UNSIGNED NULL AFTER hold_until_utc');
    }

    if (!$hasColumn('commerce_order_items', 'booking_id')) {
        $pdo->exec('ALTER TABLE commerce_order_items ADD COLUMN booking_id INT UNSIGNED NULL AFTER resource_id');
    }
};
