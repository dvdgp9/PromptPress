<?php

declare(strict_types=1);

/**
 * ASST-V2 F4a — Cada item del trabajo guarda qué capacidad ejecuta.
 *
 * Un cambio de SEO y una edición Canvas pueden apuntar a la misma página; el
 * paso necesita saber cuál es. NULL en los items antiguos: se siguen leyendo
 * como antes (form_id → formulario, si no → Canvas).
 */
return static function (PDO $pdo): void {
    $stmt = $pdo->prepare(
        'SELECT 1 FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1'
    );
    $stmt->execute(['assistant_job_items', 'capability_id']);
    if ($stmt->fetchColumn()) {
        return;
    }

    $pdo->exec('ALTER TABLE assistant_job_items ADD COLUMN capability_id VARCHAR(60) NULL AFTER form_id');
};
