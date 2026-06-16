<?php

declare(strict_types=1);

/**
 * FH6 — Historial canvas mejorado:
 *  - page_versions.summary: descripción legible de QUÉ cambió (no "a ciegas").
 *  - page_canvas.current_version_id: puntero de versión actual para undo/redo
 *    real (deshacer/rehacer mueven el puntero; un cambio nuevo trunca el redo).
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

    if (!$hasColumn('page_versions', 'summary')) {
        $pdo->exec(
            "ALTER TABLE page_versions
             ADD COLUMN summary VARCHAR(255) NOT NULL DEFAULT '' AFTER origin"
        );
    }

    if (!$hasColumn('page_canvas', 'current_version_id')) {
        $pdo->exec(
            "ALTER TABLE page_canvas
             ADD COLUMN current_version_id INT UNSIGNED NULL AFTER css"
        );
    }
};
