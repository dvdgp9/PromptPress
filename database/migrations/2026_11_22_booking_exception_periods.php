<?php

declare(strict_types=1);

/**
 * BOOK-VAC — Excepciones de reservas por periodo (vacaciones del X al Y).
 *
 * `booking_hours.date_end`: NULL = la excepción es solo el día `date` (como
 * siempre); con fecha = cubre de `date` a `date_end`, ambos incluidos.
 *
 * Idempotente: mira information_schema antes.
 */
return static function (PDO $pdo): void {
    $stmt = $pdo->prepare(
        'SELECT 1 FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1'
    );
    $stmt->execute(['booking_hours', 'date_end']);
    if (!$stmt->fetchColumn()) {
        $pdo->exec('ALTER TABLE booking_hours ADD COLUMN date_end DATE DEFAULT NULL AFTER date');
    }
};
