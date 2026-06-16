<?php

declare(strict_types=1);

/**
 * FH1 — Pivote "Canvas": páginas de marketing con HTML libre.
 *  - pages.render_mode: 'sections' (clásico) | 'canvas' (HTML libre).
 *  - page_canvas: el HTML/CSS vivo de cada página canvas (1:1 con pages).
 *  - page_versions: snapshots para deshacer (cada guardado crea versión).
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

    if (!$hasColumn('pages', 'render_mode')) {
        $pdo->exec(
            "ALTER TABLE pages
             ADD COLUMN render_mode ENUM('sections','canvas') NOT NULL DEFAULT 'sections'
             AFTER page_type"
        );
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS page_canvas (
            page_id INT UNSIGNED PRIMARY KEY,
            html LONGTEXT NOT NULL,
            css MEDIUMTEXT NOT NULL DEFAULT '',
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            CONSTRAINT fk_page_canvas_page
                FOREIGN KEY (page_id) REFERENCES pages(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS page_versions (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            page_id INT UNSIGNED NOT NULL,
            html LONGTEXT NOT NULL,
            css MEDIUMTEXT NOT NULL DEFAULT '',
            origin VARCHAR(20) NOT NULL DEFAULT 'generate',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_page_versions_page (page_id, id),
            CONSTRAINT fk_page_versions_page
                FOREIGN KEY (page_id) REFERENCES pages(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
};
