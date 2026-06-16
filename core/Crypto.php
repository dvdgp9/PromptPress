<?php

declare(strict_types=1);

namespace Core;

use RuntimeException;

/**
 * Crypto — encriptación simétrica autenticada (AES-256-GCM)
 *
 * Diseñada para almacenar secretos en BD (API keys de proveedores IA, etc.)
 * usando como clave maestra `app_key` del config.php (64 hex chars = 32 bytes binarios).
 *
 * Formato del ciphertext almacenado:
 *   base64( IV(12 bytes) || TAG(16 bytes) || CIPHERTEXT(N bytes) )
 *
 * Garantías:
 *   - Confidencialidad (AES-256-CTR interno)
 *   - Autenticidad (GCM TAG impide alteraciones silenciosas)
 *   - IV aleatorio único por mensaje (random_bytes)
 */
final class Crypto
{
    private const CIPHER  = 'aes-256-gcm';
    private const IV_LEN  = 12;
    private const TAG_LEN = 16;

    /**
     * Encripta un texto plano con la clave hex (64 chars).
     * @throws RuntimeException si la clave es inválida o falla el cifrado.
     */
    public static function encrypt(string $plaintext, string $hexKey): string
    {
        $key = self::deriveKey($hexKey);
        $iv  = random_bytes(self::IV_LEN);
        $tag = '';
        $ciphertext = openssl_encrypt(
            $plaintext,
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            self::TAG_LEN,
        );
        if ($ciphertext === false) {
            throw new RuntimeException('Encryption failed');
        }
        return base64_encode($iv . $tag . $ciphertext);
    }

    /**
     * Desencripta un blob previamente generado por encrypt().
     * @throws RuntimeException si la clave es inválida o el ciphertext está corrupto/manipulado.
     */
    public static function decrypt(string $encoded, string $hexKey): string
    {
        $key = self::deriveKey($hexKey);
        $raw = base64_decode($encoded, true);
        if ($raw === false || strlen($raw) < self::IV_LEN + self::TAG_LEN + 1) {
            throw new RuntimeException('Invalid ciphertext format');
        }
        $iv         = substr($raw, 0, self::IV_LEN);
        $tag        = substr($raw, self::IV_LEN, self::TAG_LEN);
        $ciphertext = substr($raw, self::IV_LEN + self::TAG_LEN);

        $plaintext = openssl_decrypt(
            $ciphertext,
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
        );
        if ($plaintext === false) {
            throw new RuntimeException('Decryption failed (key incorrecta o ciphertext manipulado)');
        }
        return $plaintext;
    }

    private static function deriveKey(string $hexKey): string
    {
        if (!preg_match('/^[a-f0-9]{64}$/i', $hexKey)) {
            throw new RuntimeException('app_key debe ser una cadena hex de 64 caracteres');
        }
        $key = hex2bin($hexKey);
        if ($key === false || strlen($key) !== 32) {
            throw new RuntimeException('app_key inválida tras hex2bin');
        }
        return $key;
    }
}
