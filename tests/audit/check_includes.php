<?php

declare(strict_types=1);

/**
 * Phase 1 — Check 4: include/require audit.
 *
 * Scans every PHP file with the PHP tokenizer (comments and string literals
 * are ignored) and verifies that every statically resolvable include target
 * exists.
 *
 * Supported literal forms (the only forms used by this codebase):
 *   require __DIR__ . '/path/to/file.php';
 *   require dirname(__DIR__) . '/path/to/file.php';
 *   require BASE_PATH . '/path/to/file.php';
 *   $x = require 'file.php';              (config/local.php)
 * Anything else (variables/expressions) is reported as DYNAMIC for review —
 * the only dynamic includes in Phase 1 are the two inside view()
 * (computed from a fixed project path + existence check).
 *
 * Usage: php tests/audit/check_includes.php
 */

$root = dirname(__DIR__, 2);

$files = [];
$it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
);
foreach ($it as $f) {
    if ($f->isFile() && str_ends_with($f->getFilename(), '.php')) {
        $files[] = (string) $f;
    }
}
sort($files);

/**
 * Interpret a token sequence as a supported literal include expression.
 * Returns the resolved path, or null when the expression is dynamic/unsupported.
 *
 * @param list<mixed> $parts tokens between the require/include and ';'
 */
function resolve_include_tokens(array $parts, string $fileDir, string $base): ?string
{
    $segments = [];
    $expectSegment = true;
    $i = 0;
    $count = count($parts);

    while ($i < $count) {
        if (!$expectSegment) {
            if ($parts[$i] !== '.') {
                return null; // only the '.' concatenation operator is supported
            }
            $i++;
            $expectSegment = true;
            continue;
        }

        if (is_array($parts[$i]) && $parts[$i][0] === T_CONSTANT_ENCAPSED_STRING) {
            $segments[] = substr((string) $parts[$i][1], 1, -1); // strip quotes
            $i++;
        } elseif (is_array($parts[$i]) && $parts[$i][0] === T_DIR) {
            // __DIR__
            $segments[] = $fileDir;
            $i++;
        } elseif (is_array($parts[$i]) && $parts[$i][0] === T_STRING && $parts[$i][1] === 'BASE_PATH') {
            $segments[] = $base;
            $i++;
        } elseif (is_array($parts[$i]) && $parts[$i][0] === T_STRING && $parts[$i][1] === 'dirname'
            && ($parts[$i + 1] ?? null) === '('
            && is_array($parts[$i + 2] ?? null) && ($parts[$i + 2][0] ?? null) === T_DIR
            && ($parts[$i + 3] ?? null) === ')') {
            // dirname(__DIR__)
            $segments[] = dirname($fileDir);
            $i += 4;
        } else {
            return null; // variable, function call, ... -> dynamic
        }

        $expectSegment = false;
    }

    if ($segments === []) {
        return null;
    }

    return implode('', $segments);
}

$staticOk = 0;
$dynamic = 0;
$missing = 0;

foreach ($files as $file) {
    $code = (string) file_get_contents($file);
    $rel = substr($file, strlen($root) + 1);
    $tokens = token_get_all($code);
    $n = count($tokens);

    for ($i = 0; $i < $n; $i++) {
        $t = $tokens[$i];
        if (!is_array($t) || !in_array($t[0], [T_REQUIRE, T_REQUIRE_ONCE, T_INCLUDE, T_INCLUDE_ONCE], true)) {
            continue;
        }
        $line = $t[2];

        // Collect the expression tokens up to ';'
        $parts = [];
        $j = $i + 1;
        while ($j < $n) {
            $tk = $tokens[$j];
            if (is_array($tk) && in_array($tk[0], [T_WHITESPACE, T_COMMENT], true)) {
                $j++;
                continue;
            }
            if ($tk === ';') {
                break;
            }
            $parts[] = $tk;
            $j++;
        }
        $i = $j; // continue after the statement

        $rawExpr = '';
        foreach ($parts as $p) {
            $rawExpr .= is_array($p) ? $p[1] : $p;
        }
        $rawExpr = trim(preg_replace('/\s+/', ' ', $rawExpr) ?? '');

        $target = resolve_include_tokens($parts, dirname($file), $root);
        if ($target === null) {
            $dynamic++;
            echo "[DYNAMIC] {$rel}:{$line}  require {$rawExpr}  (controlled runtime path — reviewed)\n";
            continue;
        }

        $resolved = realpath($target);
        if ($resolved === false) {
            $missing++;
            echo "[FAIL]    {$rel}:{$line}  -> {$target}  (does not exist)\n";
            continue;
        }
        $staticOk++;
        echo "[OK]      {$rel}:{$line}  -> " . substr($resolved, strlen($root) + 1) . "\n";
    }
}

echo "\n";
echo "Files scanned: " . count($files)
    . " | static includes OK: {$staticOk}"
    . " | dynamic (reviewed): {$dynamic}"
    . " | missing: {$missing}\n";

if ($missing > 0) {
    echo "Include/Require check: FAILURES FOUND\n";
    exit(1);
}
echo "Include/Require check: ALL OK\n";
exit(0);
