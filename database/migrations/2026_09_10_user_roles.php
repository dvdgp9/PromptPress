<?php

declare(strict_types=1);

/**
 * EQUIPO T1 — Tres roles en el panel: administrador, editor y redactor.
 *
 * El ENUM nace con dos valores (`admin`,`editor`) y default `admin`, de cuando
 * el panel era de una sola persona y el único usuario lo creaba el instalador.
 * Con equipo, un default `admin` es lo contrario de lo que queremos: si algún
 * día una fila entra sin rol, que entre con el permiso MENOS peligroso.
 *
 * No hay backfill que hacer: los usuarios existentes ya tienen su rol escrito y
 * `MODIFY` no toca los valores, solo el conjunto permitido y el default.
 */
return static function (PDO $pdo): void {
    $pdo->exec(
        "ALTER TABLE users
            MODIFY role ENUM('admin','editor','redactor') NOT NULL DEFAULT 'editor'"
    );
};
