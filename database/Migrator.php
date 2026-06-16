<?php

declare(strict_types=1);

namespace PromptPress\Database;

use PDO;
use PDOException;
use RuntimeException;

final class Migrator
{
    public function __construct(
        private PDO $pdo,
        private string $migrationsDir
    ) {
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    /**
     * @return array{applied:string[], skipped:string[], errors:array<int,array{name:string,error:string}>}
     */
    public function run(bool $dryRun = false): array
    {
        $this->ensureMigrationsTable();

        $applied = [];
        $skipped = [];
        $errors = [];

        foreach ($this->files() as $file) {
            $name = $this->migrationName($file);
            if ($this->isApplied($name)) {
                $skipped[] = $name;
                continue;
            }

            if ($dryRun) {
                $applied[] = $name . ' (dry-run)';
                continue;
            }

            try {
                $this->runOne($file, $name);
                $this->markApplied($name);
                $applied[] = $name;
            } catch (\Throwable $e) {
                $errors[] = ['name' => $name, 'error' => $e->getMessage()];
                break;
            }
        }

        return ['applied' => $applied, 'skipped' => $skipped, 'errors' => $errors];
    }

    public function ensureMigrationsTable(): void
    {
        $this->pdo->exec(
            "CREATE TABLE IF NOT EXISTS migrations (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(255) NOT NULL UNIQUE,
                applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    /** @return string[] */
    private function files(): array
    {
        if (!is_dir($this->migrationsDir)) {
            return [];
        }

        $files = array_merge(
            glob($this->migrationsDir . '/*.php') ?: [],
            glob($this->migrationsDir . '/*.sql') ?: []
        );

        sort($files, SORT_NATURAL);
        return $files;
    }

    private function migrationName(string $file): string
    {
        return pathinfo($file, PATHINFO_FILENAME);
    }

    private function isApplied(string $name): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM migrations WHERE name = ? LIMIT 1');
        $stmt->execute([$name]);
        return (bool) $stmt->fetchColumn();
    }

    private function markApplied(string $name): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO migrations (name) VALUES (?)');
        $stmt->execute([$name]);
    }

    private function runOne(string $file, string $name): void
    {
        if (str_ends_with($file, '.php')) {
            $callback = require $file;
            if (!is_callable($callback)) {
                throw new RuntimeException("PHP migration {$name} must return a callable.");
            }
            $callback($this->pdo);
            return;
        }

        $sql = file_get_contents($file);
        if ($sql === false) {
            throw new RuntimeException("Could not read migration {$name}.");
        }

        foreach (self::splitSqlStatements($sql) as $statement) {
            $statement = trim($statement);
            if ($statement === '') {
                continue;
            }
            $this->executeStatement($statement);
        }
    }

    private function executeStatement(string $statement): void
    {
        if ($this->executeIdempotentAlter($statement)) {
            return;
        }

        try {
            $this->pdo->exec($statement);
        } catch (PDOException $e) {
            if ($this->isBenignDuplicateError($e)) {
                return;
            }
            throw $e;
        }
    }

    private function executeIdempotentAlter(string $statement): bool
    {
        if (!preg_match('/^ALTER\s+TABLE\s+`?([a-zA-Z0-9_]+)`?\s+(.+)$/is', $statement, $m)) {
            return false;
        }

        $table = $m[1];
        $operations = self::splitSqlOperations($m[2]);
        $handledAny = false;

        foreach ($operations as $operation) {
            $operation = trim($operation);

            if (preg_match('/^ADD\s+COLUMN\s+IF\s+NOT\s+EXISTS\s+`?([a-zA-Z0-9_]+)`?\s+(.+)$/is', $operation, $colMatch)) {
                $handledAny = true;
                $column = $colMatch[1];
                if (!$this->columnExists($table, $column)) {
                    $this->pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` " . trim($colMatch[2]));
                }
                continue;
            }

            if (preg_match('/^ADD\s+INDEX\s+IF\s+NOT\s+EXISTS\s+`?([a-zA-Z0-9_]+)`?\s+(.+)$/is', $operation, $idxMatch)) {
                $handledAny = true;
                $index = $idxMatch[1];
                if (!$this->indexExists($table, $index)) {
                    $this->pdo->exec("ALTER TABLE `{$table}` ADD INDEX `{$index}` " . trim($idxMatch[2]));
                }
                continue;
            }

            if ($handledAny) {
                throw new RuntimeException("Mixed idempotent/non-idempotent ALTER TABLE is not supported: {$operation}");
            }
        }

        return $handledAny;
    }

    private function columnExists(string $table, string $column): bool
    {
        $stmt = $this->pdo->prepare(
            "SELECT 1 FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?
             LIMIT 1"
        );
        $stmt->execute([$table, $column]);
        return (bool) $stmt->fetchColumn();
    }

    private function indexExists(string $table, string $index): bool
    {
        $stmt = $this->pdo->prepare(
            "SELECT 1 FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?
             LIMIT 1"
        );
        $stmt->execute([$table, $index]);
        return (bool) $stmt->fetchColumn();
    }

    private function isBenignDuplicateError(PDOException $e): bool
    {
        $code = (int) ($e->errorInfo[1] ?? 0);
        return in_array($code, [1050, 1060, 1061], true);
    }

    /** @return string[] */
    public static function splitSqlStatements(string $sql): array
    {
        return self::splitSql($sql, ';');
    }

    /** @return string[] */
    private static function splitSqlOperations(string $sql): array
    {
        return self::splitSql($sql, ',');
    }

    /** @return string[] */
    private static function splitSql(string $sql, string $separator): array
    {
        $parts = [];
        $buffer = '';
        $len = strlen($sql);
        $quote = null;
        $depth = 0;

        for ($i = 0; $i < $len; $i++) {
            $ch = $sql[$i];
            $next = $i + 1 < $len ? $sql[$i + 1] : '';

            if ($quote === null && $ch === '-' && $next === '-') {
                while ($i < $len && $sql[$i] !== "\n") {
                    $i++;
                }
                continue;
            }

            if ($quote === null && $ch === '/' && $next === '*') {
                $i += 2;
                while ($i < $len - 1 && !($sql[$i] === '*' && $sql[$i + 1] === '/')) {
                    $i++;
                }
                $i++;
                continue;
            }

            if ($quote === null && ($ch === "'" || $ch === '"' || $ch === '`')) {
                $quote = $ch;
                $buffer .= $ch;
                continue;
            }

            if ($quote !== null) {
                $buffer .= $ch;
                if ($ch === '\\' && $i + 1 < $len) {
                    $buffer .= $sql[++$i];
                    continue;
                }
                if ($ch === $quote) {
                    $quote = null;
                }
                continue;
            }

            if ($ch === '(') {
                $depth++;
                $buffer .= $ch;
                continue;
            }

            if ($ch === ')' && $depth > 0) {
                $depth--;
                $buffer .= $ch;
                continue;
            }

            if ($ch === $separator && $depth === 0) {
                $parts[] = $buffer;
                $buffer = '';
                continue;
            }

            $buffer .= $ch;
        }

        if (trim($buffer) !== '') {
            $parts[] = $buffer;
        }

        return $parts;
    }
}
