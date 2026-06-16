<?php

declare(strict_types=1);

namespace App\Services\Compliance;

/**
 * Validación de identificadores fiscales españoles.
 *
 * Cubre:
 *  - DNI: 8 dígitos + letra de control.
 *  - NIE: X|Y|Z + 7 dígitos + letra de control.
 *  - CIF: letra inicial + 7 dígitos + dígito/letra de control.
 *
 * Los identificadores de otros países se aceptan como texto libre (no validados)
 * — el manifest tiene `controller.country` para diferenciar.
 */
final class NifValidator
{
    private const DNI_LETTERS = 'TRWAGMYFPDXBNJZSQVHLCKE';

    /**
     * Devuelve true si el valor es un DNI/NIE/CIF español válido.
     */
    public static function isValid(string $value): bool
    {
        $value = strtoupper(trim($value));
        if ($value === '') return false;
        // Quitar guiones y espacios habituales.
        $value = preg_replace('/[\s\-\.]/', '', $value);
        if ($value === null) return false;

        if (preg_match('/^[0-9]{8}[A-Z]$/', $value)) {
            return self::checkDni($value);
        }
        if (preg_match('/^[XYZ][0-9]{7}[A-Z]$/', $value)) {
            return self::checkNie($value);
        }
        if (preg_match('/^[ABCDEFGHJNPQRSUVW][0-9]{7}[0-9A-J]$/', $value)) {
            return self::checkCif($value);
        }
        return false;
    }

    private static function checkDni(string $value): bool
    {
        $num = (int) substr($value, 0, 8);
        $letter = substr($value, 8, 1);
        return self::DNI_LETTERS[$num % 23] === $letter;
    }

    private static function checkNie(string $value): bool
    {
        $map = ['X' => '0', 'Y' => '1', 'Z' => '2'];
        $normalized = $map[$value[0]] . substr($value, 1, 7);
        return self::checkDni($normalized . substr($value, 8, 1));
    }

    private static function checkCif(string $value): bool
    {
        $digits = substr($value, 1, 7);
        $control = substr($value, 8, 1);
        $sumEven = 0;
        $sumOdd  = 0;
        for ($i = 0; $i < 7; $i++) {
            $n = (int) $digits[$i];
            if ($i % 2 === 0) {
                // Pares (posiciones impares en notación humana): doblar y sumar dígitos.
                $double = $n * 2;
                $sumOdd += intdiv($double, 10) + ($double % 10);
            } else {
                $sumEven += $n;
            }
        }
        $total = $sumEven + $sumOdd;
        $unit  = $total % 10;
        $expectedDigit = $unit === 0 ? 0 : 10 - $unit;
        $expectedLetter = 'JABCDEFGHI'[$expectedDigit];

        $firstLetter = $value[0];
        // Letras que solo aceptan dígito de control (sociedades), las que solo letra,
        // y mixtas. Para simplificar aceptamos cualquiera de las dos representaciones.
        if (ctype_digit($control)) {
            return (int) $control === $expectedDigit;
        }
        return $control === $expectedLetter;
    }
}
