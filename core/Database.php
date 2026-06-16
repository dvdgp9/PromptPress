<?php

namespace Core;

use PDO;
use PDOException;
use RuntimeException;

/**
 * Wrapper PDO singleton.
 * Usa configuración de config/config.php (o lanza si no existe).
 */
final class Database
{
    private static ?PDO $pdo = null;

    public static function connection(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $cfg = App::config()['db'] ?? null;
        if (!is_array($cfg)) {
            throw new RuntimeException('Database config missing. Run installer first.');
        }

        $host    = $cfg['host']    ?? '127.0.0.1';
        $port    = $cfg['port']    ?? 3306;
        $name    = $cfg['name']    ?? '';
        $user    = $cfg['user']    ?? '';
        $pass    = $cfg['pass']    ?? '';
        $charset = $cfg['charset'] ?? 'utf8mb4';

        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $host, $port, $name, $charset);

        try {
            self::$pdo = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES {$charset} COLLATE {$charset}_unicode_ci",
            ]);
        } catch (PDOException $e) {
            throw new RuntimeException('DB connection failed: ' . $e->getMessage(), (int) $e->getCode(), $e);
        }

        return self::$pdo;
    }

    /**
     * Test de conexión con credenciales arbitrarias (usado por instalador).
     * @throws PDOException si falla
     */
    public static function testConnection(string $host, int $port, string $name, string $user, string $pass): PDO
    {
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $name);
        return new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
    }

    /** Atajo para SELECT que devuelve todas las filas. */
    public static function select(string $sql, array $params = []): array
    {
        $stmt = self::connection()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /** Atajo para SELECT que devuelve una sola fila o null. */
    public static function selectOne(string $sql, array $params = []): ?array
    {
        $stmt = self::connection()->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /** Atajo para INSERT/UPDATE/DELETE. Devuelve filas afectadas. */
    public static function execute(string $sql, array $params = []): int
    {
        $stmt = self::connection()->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    /** Último ID insertado. */
    public static function lastInsertId(): string
    {
        return self::connection()->lastInsertId();
    }
}
