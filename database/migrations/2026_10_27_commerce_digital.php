<?php

declare(strict_types=1);

/**
 * EBOOK-PAGO — Vender ebooks desde la Tienda.
 *
 * Contrato en `cursor/resources-api.md` §7: Recursos sigue siendo dueño del
 * archivo; Commerce gobierna el precio, el pedido y el derecho de descarga.
 *
 * - `commerce_products.product_type` + `resource_id`: un producto digital
 *   apunta al recurso que se entrega, sin duplicar binarios.
 * - `commerce_order_items.resource_id`: snapshot del derecho. Borrar o cambiar
 *   el producto después no deja sin descarga a quien ya pagó.
 * - `commerce_orders.digital_consent_at`: cuándo aceptó el comprador la entrega
 *   inmediata (y con ella la pérdida del desistimiento).
 * - `resources.access_mode` + `sale`: la ficha ofrece comprar, nunca descargar.
 *
 * Idempotente: en MySQL no existe `ADD COLUMN IF NOT EXISTS`, así que cada paso
 * mira information_schema antes.
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
    $hasConstraint = static function (string $table, string $name) use ($pdo): bool {
        $stmt = $pdo->prepare(
            'SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ? LIMIT 1'
        );
        $stmt->execute([$table, $name]);
        return (bool) $stmt->fetchColumn();
    };

    if (!$hasColumn('commerce_products', 'product_type')) {
        $pdo->exec(
            "ALTER TABLE commerce_products
             ADD COLUMN product_type ENUM('physical','digital') NOT NULL DEFAULT 'physical' AFTER slug"
        );
    }
    if (!$hasColumn('commerce_products', 'resource_id')) {
        $pdo->exec('ALTER TABLE commerce_products ADD COLUMN resource_id INT UNSIGNED NULL AFTER product_type');
    }
    if (!$hasConstraint('commerce_products', 'fk_cp_resource')) {
        $pdo->exec(
            'ALTER TABLE commerce_products
             ADD CONSTRAINT fk_cp_resource FOREIGN KEY (resource_id) REFERENCES resources (id) ON DELETE SET NULL'
        );
    }

    if (!$hasColumn('commerce_order_items', 'resource_id')) {
        $pdo->exec('ALTER TABLE commerce_order_items ADD COLUMN resource_id INT UNSIGNED NULL AFTER product_id');
    }
    if (!$hasConstraint('commerce_order_items', 'fk_ci_resource')) {
        $pdo->exec(
            'ALTER TABLE commerce_order_items
             ADD CONSTRAINT fk_ci_resource FOREIGN KEY (resource_id) REFERENCES resources (id) ON DELETE SET NULL'
        );
    }

    if (!$hasColumn('commerce_orders', 'digital_consent_at')) {
        $pdo->exec('ALTER TABLE commerce_orders ADD COLUMN digital_consent_at DATETIME NULL AFTER notes');
    }

    // Ampliar un ENUM con MODIFY es idempotente por sí mismo.
    $pdo->exec(
        "ALTER TABLE resources
         MODIFY COLUMN access_mode ENUM('direct','form','sale') NOT NULL DEFAULT 'direct'"
    );
};
