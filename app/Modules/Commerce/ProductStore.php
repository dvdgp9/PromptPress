<?php

declare(strict_types=1);

namespace App\Modules\Commerce;

use Core\Database;

/**
 * ProductStore — persistencia de productos (C2).
 *
 * Producto simple: nombre, descripción, precio (en céntimos), tipo de IVA,
 * stock (NULL = ilimitado), imagen de la biblioteca de medios y activo.
 * El slug es único por sitio (URL de la ficha en /tienda/p/{slug}); se deriva
 * del nombre y se desambigua con sufijo -2, -3… si choca.
 *
 * Importes SIEMPRE en céntimos enteros (ver cursor/commerce-design.md §2).
 */
final class ProductStore
{
    private const DEFAULTS = [
        'name'        => '',
        'description' => '',
        'price_cents' => 0,
        'tax_rate'    => 21.0,
        'stock'       => null,   // null = ilimitado
        'media_id'    => null,
        'active'      => 1,
    ];

    /** @return array<int, array<string,mixed>> productos del sitio con la imagen resuelta */
    public static function all(int $siteId): array
    {
        return Database::select(
            'SELECT p.*, m.path AS media_path
               FROM commerce_products p
               LEFT JOIN media m ON m.id = p.media_id
              WHERE p.site_id = ?
              ORDER BY p.active DESC, p.created_at DESC',
            [$siteId]
        );
    }

    /** @return array<string,mixed>|null */
    public static function find(int $siteId, int $id): ?array
    {
        return Database::selectOne(
            'SELECT p.*, m.path AS media_path
               FROM commerce_products p
               LEFT JOIN media m ON m.id = p.media_id
              WHERE p.site_id = ? AND p.id = ? LIMIT 1',
            [$siteId, $id]
        );
    }

    /** @return array<string,mixed>|null ficha pública por slug (solo activos) */
    public static function findActiveBySlug(int $siteId, string $slug): ?array
    {
        return Database::selectOne(
            'SELECT p.*, m.path AS media_path
               FROM commerce_products p
               LEFT JOIN media m ON m.id = p.media_id
              WHERE p.site_id = ? AND p.slug = ? AND p.active = 1 LIMIT 1',
            [$siteId, $slug]
        );
    }

    /**
     * Crea un producto y devuelve su id. Genera slug único.
     *
     * @param array<string,mixed> $fields
     */
    public static function create(int $siteId, array $fields): int
    {
        $f = self::normalize($fields);
        $slug = self::uniqueSlug($siteId, $f['name'], null);
        Database::execute(
            'INSERT INTO commerce_products
                (site_id, name, slug, description, price_cents, tax_rate, stock, media_id, active, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())',
            [
                $siteId, $f['name'], $slug, $f['description'], $f['price_cents'], $f['tax_rate'],
                $f['stock'], $f['media_id'], $f['active'],
            ]
        );
        $id = (int) Database::lastInsertId();
        self::flushPageCache($siteId);
        return $id;
    }

    /**
     * Actualiza un producto. Regenera el slug solo si cambia el nombre.
     *
     * @param array<string,mixed> $fields
     */
    public static function update(int $siteId, int $id, array $fields): bool
    {
        $existing = self::find($siteId, $id);
        if ($existing === null) {
            return false;
        }
        $f = self::normalize($fields);
        $slug = (string) $existing['name'] === $f['name']
            ? (string) $existing['slug']
            : self::uniqueSlug($siteId, $f['name'], $id);

        Database::execute(
            'UPDATE commerce_products
                SET name = ?, slug = ?, description = ?, price_cents = ?, tax_rate = ?,
                    stock = ?, media_id = ?, active = ?, updated_at = UTC_TIMESTAMP()
              WHERE site_id = ? AND id = ?',
            [
                $f['name'], $slug, $f['description'], $f['price_cents'], $f['tax_rate'],
                $f['stock'], $f['media_id'], $f['active'], $siteId, $id,
            ]
        );
        self::flushPageCache($siteId);
        return true;
    }

    public static function delete(int $siteId, int $id): bool
    {
        $deleted = Database::execute(
            'DELETE FROM commerce_products WHERE site_id = ? AND id = ?',
            [$siteId, $id]
        ) > 0;
        if ($deleted) {
            self::flushPageCache($siteId);
        }
        return $deleted;
    }

    /**
     * C7: las páginas canvas pueden incrustar {{products:featured}} y se
     * cachean como HTML estático → cualquier cambio de producto invalida la
     * caché de páginas del sitio (mismo criterio que el toggle del módulo).
     */
    private static function flushPageCache(int $siteId): void
    {
        try {
            \App\Services\CacheService::flush($siteId);
        } catch (\Throwable) {
            // la caché nunca rompe una operación de catálogo
        }
    }

    /**
     * Normaliza y acota los campos.
     *
     * @param array<string,mixed> $fields
     * @return array{name:string, description:string, price_cents:int, tax_rate:float, stock:?int, media_id:?int, active:int}
     */
    public static function normalize(array $fields): array
    {
        $f = array_merge(self::DEFAULTS, array_intersect_key($fields, self::DEFAULTS));
        $name = mb_substr(trim((string) $f['name']), 0, 160);
        $desc = mb_substr(trim((string) $f['description']), 0, 8000);

        $price = max(0, (int) round((float) $f['price_cents']));
        $tax   = (float) $f['tax_rate'];
        $tax   = max(0.0, min(99.99, $tax));

        // stock: '' o null → ilimitado; si no, entero >= 0.
        $stock = $f['stock'];
        $stockVal = ($stock === null || $stock === '') ? null : max(0, (int) $stock);

        $media = ($f['media_id'] === null || $f['media_id'] === '' || (int) $f['media_id'] <= 0)
            ? null : (int) $f['media_id'];

        return [
            'name'        => $name,
            'description' => $desc,
            'price_cents' => $price,
            'tax_rate'    => round($tax, 2),
            'stock'       => $stockVal,
            'media_id'    => $media,
            'active'      => (int) ((string) $f['active'] === '1'),
        ];
    }

    /**
     * Slug único por sitio derivado del nombre. Excluye $ignoreId (al editar).
     */
    private static function uniqueSlug(int $siteId, string $name, ?int $ignoreId): string
    {
        $base = slugify($name);
        if ($base === '') {
            $base = 'producto';
        }
        $base = mb_substr($base, 0, 170);
        $slug = $base;
        $n = 2;
        while (self::slugTaken($siteId, $slug, $ignoreId)) {
            $slug = $base . '-' . $n;
            $n++;
        }
        return $slug;
    }

    private static function slugTaken(int $siteId, string $slug, ?int $ignoreId): bool
    {
        $row = Database::selectOne(
            'SELECT id FROM commerce_products WHERE site_id = ? AND slug = ?'
            . ($ignoreId !== null ? ' AND id <> ?' : '') . ' LIMIT 1',
            $ignoreId !== null ? [$siteId, $slug, $ignoreId] : [$siteId, $slug]
        );
        return $row !== null;
    }
}
