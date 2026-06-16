<?php
/**
 * PromptPress — Migrator
 *
 * Aplica `install/schema.sql` (y futuras migraciones en `install/migrations/`)
 * sobre una conexión PDO existente.
 *
 * Uso programático (desde el instalador T1.2 o para testing):
 *   require_once __DIR__ . '/migrate.php';
 *   $pdo = new PDO(...);
 *   $result = pp_run_migrations($pdo);
 *   //  $result = ['applied' => [...], 'errors' => [...]]
 *
 * Uso CLI (para desarrollo):
 *   php install/migrate.php --host=127.0.0.1 --port=3306 --db=ppress --user=root --pass=secret
 */

declare(strict_types=1);

/**
 * Ejecuta el schema base contra el PDO dado.
 *
 * @param PDO $pdo conexión PDO con la BD seleccionada
 * @return array{applied:string[], errors:array<int,array{statement:string,error:string}>}
 */
function pp_run_migrations(PDO $pdo): array
{
    $applied = [];
    $errors  = [];

    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // 1. Ejecutar schema base
    $schemaPath = __DIR__ . '/schema.sql';
    if (!is_file($schemaPath)) {
        $errors[] = ['statement' => '(loading schema.sql)', 'error' => "Schema file not found: {$schemaPath}"];
        return ['applied' => $applied, 'errors' => $errors];
    }

    $sql = file_get_contents($schemaPath);
    if ($sql === false) {
        $errors[] = ['statement' => '(reading schema.sql)', 'error' => 'Could not read schema.sql'];
        return ['applied' => $applied, 'errors' => $errors];
    }

    $statements = pp_split_sql_statements($sql);

    foreach ($statements as $stmt) {
        $stmt = trim($stmt);
        if ($stmt === '') {
            continue;
        }
        try {
            $pdo->exec($stmt);
            $applied[] = pp_summarize_statement($stmt);
        } catch (PDOException $e) {
            $errors[] = ['statement' => pp_summarize_statement($stmt), 'error' => $e->getMessage()];
        }
    }

    // 2. Ejecutar migraciones adicionales (futuro)
    //    Convención: install/migrations/NNNN_descripcion.sql ordenadas
    $migrationsDir = __DIR__ . '/migrations';
    if (is_dir($migrationsDir)) {
        $files = glob($migrationsDir . '/*.sql') ?: [];
        sort($files, SORT_NATURAL);
        foreach ($files as $file) {
            $name = basename($file, '.sql');
            // Saltar si ya está aplicada
            $check = $pdo->prepare('SELECT 1 FROM migrations WHERE name = ?');
            $check->execute([$name]);
            if ($check->fetchColumn()) {
                continue;
            }
            $migSql = file_get_contents($file) ?: '';
            $migStatements = pp_split_sql_statements($migSql);
            $hadError = false;
            foreach ($migStatements as $s) {
                $s = trim($s);
                if ($s === '') continue;
                try {
                    $pdo->exec($s);
                    $applied[] = "[$name] " . pp_summarize_statement($s);
                } catch (PDOException $e) {
                    $errors[]  = ['statement' => "[$name] " . pp_summarize_statement($s), 'error' => $e->getMessage()];
                    $hadError = true;
                }
            }
            if (!$hadError) {
                $ins = $pdo->prepare('INSERT INTO migrations (name) VALUES (?)');
                $ins->execute([$name]);
            }
        }
    }

    // 3. Ejecutar las migraciones canónicas del proyecto (`database/migrations/`).
    //    Es la MISMA fuente que usa el desarrollo (`php database/migrate.php`),
    //    así una instalación nueva nace con todo: Canvas (`pages.render_mode`,
    //    `page_canvas`, `page_versions`), SEO y cualquier migración futura, sin
    //    depender de replicar nada a mano en `schema.sql`. El Migrator es
    //    idempotente (IF NOT EXISTS, guardas de columna, ignora 1050/1060/1061),
    //    por lo que es seguro sobre las tablas que el schema base ya creó.
    $migratorPath = dirname(__DIR__) . '/database/Migrator.php';
    if (is_file($migratorPath)) {
        require_once $migratorPath;
        try {
            $migrator = new \PromptPress\Database\Migrator(
                $pdo,
                dirname(__DIR__) . '/database/migrations'
            );
            $result = $migrator->run();
            foreach ($result['applied'] as $name) {
                $applied[] = "[migrations] {$name}";
            }
            foreach ($result['errors'] as $err) {
                $errors[] = [
                    'statement' => '[migrations] ' . ($err['name'] ?? 'desconocida'),
                    'error'     => (string) ($err['error'] ?? 'error desconocido'),
                ];
            }
        } catch (\Throwable $e) {
            $errors[] = ['statement' => '[migrations] (arranque del Migrator)', 'error' => $e->getMessage()];
        }
    }

    return ['applied' => $applied, 'errors' => $errors];
}

/**
 * Divide un string SQL en sentencias por `;` ignorando los `;` dentro de:
 *  - cadenas (' / ")
 *  - comentarios (-- ... \n  y  /* ... *\/)
 */
function pp_split_sql_statements(string $sql): array
{
    $statements = [];
    $buffer     = '';
    $len        = strlen($sql);
    $i          = 0;

    while ($i < $len) {
        $ch = $sql[$i];
        $next = $i + 1 < $len ? $sql[$i + 1] : '';

        // Comentario de línea --
        if ($ch === '-' && $next === '-') {
            // saltar hasta fin de línea
            while ($i < $len && $sql[$i] !== "\n") $i++;
            continue;
        }
        // Comentario de bloque /* */
        if ($ch === '/' && $next === '*') {
            $i += 2;
            while ($i < $len - 1 && !($sql[$i] === '*' && $sql[$i + 1] === '/')) $i++;
            $i += 2;
            continue;
        }
        // Cadena entre comillas
        if ($ch === "'" || $ch === '"' || $ch === '`') {
            $quote = $ch;
            $buffer .= $ch;
            $i++;
            while ($i < $len) {
                $c = $sql[$i];
                $buffer .= $c;
                if ($c === '\\' && $i + 1 < $len) {
                    $buffer .= $sql[$i + 1];
                    $i += 2;
                    continue;
                }
                if ($c === $quote) {
                    $i++;
                    break;
                }
                $i++;
            }
            continue;
        }
        // Final de sentencia
        if ($ch === ';') {
            $statements[] = $buffer;
            $buffer = '';
            $i++;
            continue;
        }

        $buffer .= $ch;
        $i++;
    }

    if (trim($buffer) !== '') {
        $statements[] = $buffer;
    }
    return $statements;
}

/** Devuelve los primeros 80 chars relevantes de una sentencia para logging */
function pp_summarize_statement(string $stmt): string
{
    $clean = preg_replace('/\s+/', ' ', trim($stmt)) ?? '';
    return strlen($clean) > 80 ? substr($clean, 0, 77) . '...' : $clean;
}

// ============================================================
// CLI runner
// ============================================================
if (PHP_SAPI === 'cli' && isset($argv) && realpath($argv[0]) === __FILE__) {
    $opts = getopt('', ['host:', 'port:', 'db:', 'user:', 'pass::']);
    $host = $opts['host'] ?? '127.0.0.1';
    $port = (int) ($opts['port'] ?? 3306);
    $db   = $opts['db']   ?? null;
    $user = $opts['user'] ?? null;
    $pass = $opts['pass'] ?? '';

    if (!$db || !$user) {
        fwrite(STDERR, "Uso: php install/migrate.php --host=H --port=P --db=NAME --user=USER --pass=PASS\n");
        exit(2);
    }

    try {
        $pdo = new PDO(
            "mysql:host=$host;port=$port;dbname=$db;charset=utf8mb4",
            $user,
            $pass,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
    } catch (PDOException $e) {
        fwrite(STDERR, "Conexión fallida: " . $e->getMessage() . "\n");
        exit(1);
    }

    $result = pp_run_migrations($pdo);

    echo "Aplicadas: " . count($result['applied']) . " sentencias\n";
    foreach ($result['applied'] as $s) {
        echo "  ✓ $s\n";
    }
    if (!empty($result['errors'])) {
        echo "\nErrores: " . count($result['errors']) . "\n";
        foreach ($result['errors'] as $err) {
            echo "  ✗ {$err['statement']}\n     → {$err['error']}\n";
        }
        exit(1);
    }
    echo "\nMigración completada correctamente.\n";
}
