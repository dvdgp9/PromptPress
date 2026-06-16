<?php
/**
 * Autoloader PSR-4 mínimo de PromptPress.
 * No depende de Composer. Mapea namespaces a directorios.
 */

namespace Core;

final class Autoloader
{
    /** @var array<string,string> namespace prefix => base dir */
    private static array $prefixes = [];

    public static function register(): void
    {
        spl_autoload_register([self::class, 'loadClass']);

        // Mapeos por defecto
        self::addNamespace('Core\\', PP_CORE);
        self::addNamespace('App\\',  PP_APP);
    }

    public static function addNamespace(string $prefix, string $baseDir): void
    {
        $prefix  = trim($prefix, '\\') . '\\';
        $baseDir = rtrim($baseDir, '/\\') . DIRECTORY_SEPARATOR;
        self::$prefixes[$prefix] = $baseDir;
    }

    public static function loadClass(string $class): bool
    {
        foreach (self::$prefixes as $prefix => $baseDir) {
            $len = strlen($prefix);
            if (strncmp($prefix, $class, $len) !== 0) {
                continue;
            }
            $relativeClass = substr($class, $len);
            $file = $baseDir . str_replace('\\', DIRECTORY_SEPARATOR, $relativeClass) . '.php';
            if (is_file($file)) {
                require_once $file;
                return true;
            }
        }
        return false;
    }
}
