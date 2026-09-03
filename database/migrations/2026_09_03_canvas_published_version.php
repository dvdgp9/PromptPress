<?php

declare(strict_types=1);

/**
 * STUDIO-UX C4 — Separar el borrador de lo publicado.
 *
 * Hasta ahora `page_canvas` era a la vez el estado de trabajo y lo que servía
 * el público: cada retoque de una página publicada salía al aire al instante.
 * Con `published_version_id` el público lee una versión concreta y el Studio
 * sigue editando el estado de trabajo.
 *
 * Backfill: las páginas ya publicadas apuntan a su versión actual, así que
 * nadie ve cambiar su web por aplicar la migración.
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

    if (!$hasColumn('page_canvas', 'published_version_id')) {
        $pdo->exec(
            "ALTER TABLE page_canvas
             ADD COLUMN published_version_id INT UNSIGNED NULL AFTER current_version_id"
        );
    }

    // Lo que hoy está publicado sigue publicado, byte a byte.
    $pdo->exec(
        "UPDATE page_canvas c
         JOIN pages p ON p.id = c.page_id
         SET c.published_version_id = c.current_version_id
         WHERE p.status = 'published'
           AND c.published_version_id IS NULL
           AND c.current_version_id IS NOT NULL"
    );
};
