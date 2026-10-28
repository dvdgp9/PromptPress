<?php

declare(strict_types=1);

/**
 * ASST-FORMS — El asistente también edita formularios.
 *
 * Un item de formulario no pertenece a ninguna página: guarda page_id 0 y el
 * formulario en `form_id`. Sin FK: si el formulario se borra, el job lo detecta
 * al ejecutar (FormStore::find) y el histórico de trabajos no se pierde.
 */
return static function (PDO $pdo): void {
    $stmt = $pdo->prepare(
        'SELECT 1 FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1'
    );
    $stmt->execute(['assistant_job_items', 'form_id']);
    if ($stmt->fetchColumn()) {
        return;
    }

    $pdo->exec('ALTER TABLE assistant_job_items ADD COLUMN form_id INT UNSIGNED NULL AFTER page_id');
};
