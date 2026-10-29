<?php

declare(strict_types=1);

/**
 * ASST-V2 F3 — Vista previa de formularios.
 *
 * Un item forms.edit puede llevar la propuesta firmada que se enseñó en la
 * tarjeta. Al aplicar se guarda esa misma propuesta, sin volver a llamar a la
 * IA. Columna opcional: los items sin vista previa siguen como antes.
 */
return static function (PDO $pdo): void {
    $stmt = $pdo->prepare(
        'SELECT 1 FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1'
    );
    $stmt->execute(['assistant_job_items', 'proposal_token']);
    if ($stmt->fetchColumn()) {
        return;
    }

    $pdo->exec('ALTER TABLE assistant_job_items ADD COLUMN proposal_token MEDIUMTEXT NULL AFTER media_ids_json');
};
