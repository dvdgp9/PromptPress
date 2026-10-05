<?php

declare(strict_types=1);

/**
 * composer.json admite PHP >= 8.0, pero en local se desarrolla con 8.4: una
 * función o sintaxis de 8.1+ pasa todos los tests aquí y revienta en un
 * hosting con 8.0 (yroa.es, 2026-10: `array_is_list()` tumbaba el asistente
 * con un 502). Este test busca en el código que se instala lo que 8.0 no tiene.
 */

$root = dirname(__DIR__);
$dirs = ['app', 'core', 'config', 'install', 'lang', 'views'];

/** patrón => motivo */
$forbidden = [
    '/\barray_is_list\s*\(/'                        => 'array_is_list() es de PHP 8.1',
    '/\bfsync\s*\(/'                                => 'fsync() es de PHP 8.1',
    '/\benum_exists\s*\(/'                          => 'enum_exists() es de PHP 8.1',
    '/\breadonly\b/'                                => 'readonly es de PHP 8.1',
    '/^\s*enum\s+[A-Z]\w*/m'                        => 'enum es de PHP 8.1',
    '/\b[\w\\\\:>-]+\(\.\.\.\)/'                    => 'first-class callable syntax es de PHP 8.1',
];

$failed = 0;
foreach ($dirs as $dir) {
    if (!is_dir("$root/$dir")) {
        continue;
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$root/$dir", FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }
        $src = (string) file_get_contents($file->getPathname());
        // Solo código: los comentarios pueden nombrar estas funciones para explicar por qué no se usan.
        $code = '';
        foreach (token_get_all($src) as $tok) {
            if (is_array($tok) && in_array($tok[0], [T_COMMENT, T_DOC_COMMENT, T_INLINE_HTML, T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
                $code .= str_repeat("\n", substr_count($tok[1], "\n"));
                continue;
            }
            $code .= is_array($tok) ? $tok[1] : $tok;
        }
        foreach ($forbidden as $pattern => $why) {
            if (preg_match_all($pattern, $code, $m, PREG_OFFSET_CAPTURE)) {
                foreach ($m[0] as [$hit, $offset]) {
                    $line = substr_count($code, "\n", 0, $offset) + 1;
                    $rel = substr($file->getPathname(), strlen($root) + 1);
                    echo "FAIL $rel:$line — $why ($hit)\n";
                    $failed++;
                }
            }
        }
    }
}

echo $failed === 0 ? "ALL PASS\n" : "{$failed} FAILED\n";
exit($failed === 0 ? 0 : 1);
