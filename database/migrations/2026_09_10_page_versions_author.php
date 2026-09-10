<?php

declare(strict_types=1);

/**
 * EDIT-LOCK L5 — Quién hizo cada versión de una página canvas.
 *
 * `page_versions` guardaba qué cambió y por qué (`origin`, `summary`) pero no
 * quién: con una sola persona daba igual, con equipo el historial no sirve para
 * lo que más se necesita, que es «¿de quién es esto?».
 *
 * El editor clásico de secciones ya lo hacía (`versions.created_by`); esto pone
 * al Studio a la misma altura.
 *
 * NULL para todo lo anterior, y `ON DELETE SET NULL`: borrar una cuenta no debe
 * llevarse por delante el historial de las páginas que esa persona tocó.
 */
return static function (PDO $pdo): void {
    $stmt = $pdo->prepare(
        'SELECT 1 FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1'
    );
    $stmt->execute(['page_versions', 'created_by']);
    if ($stmt->fetchColumn()) {
        return;
    }

    $pdo->exec('ALTER TABLE page_versions ADD COLUMN created_by INT UNSIGNED NULL AFTER summary');
    $pdo->exec(
        'ALTER TABLE page_versions
         ADD CONSTRAINT fk_page_versions_user
         FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL'
    );
};
