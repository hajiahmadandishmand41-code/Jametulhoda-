<?php

declare(strict_types=1);

/**
 * Phase 2 — Check 8: SQL schema audit.
 *
 * A static review of database/schema.sql and database/seed.sql that does not
 * need a database server:
 *   1. both files parse into statements (balanced quotes/parentheses)
 *   2. every table is InnoDB + utf8mb4 and guarded with IF NOT EXISTS
 *   3. no destructive statement hides in schema.sql
 *   4. tables are created after the tables they reference
 *   5. every foreign key points at a table the schema creates
 *   6. the files contain no credentials
 *
 * Usage: php tests/audit/check_schema.php
 */

$root = dirname(__DIR__, 2);

require $root . '/app/Helpers/functions.php';
require $root . '/config/config.php';
require $root . '/app/Helpers/schema.php';

$errors = [];
$checks = 0;

function report(string $label, bool $ok, string $detail = ''): void
{
    global $errors, $checks;
    $checks++;
    if ($ok) {
        echo "[OK]   {$label}\n";
        return;
    }
    echo "[FAIL] {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
    $errors[] = $label;
}

/* -- 1. both files parse ------------------------------------------------ */
$schemaStatements = [];
$seedStatements = [];
try {
    $schemaStatements = schema_statements('schema.sql');
    report('schema.sql parses into ' . count($schemaStatements) . ' statements', $schemaStatements !== []);
} catch (Throwable $e) {
    report('schema.sql parses', false, $e->getMessage());
}
try {
    $seedStatements = schema_statements('seed.sql');
    report('seed.sql parses into ' . count($seedStatements) . ' statements', $seedStatements !== []);
} catch (Throwable $e) {
    report('seed.sql parses', false, $e->getMessage());
}

/* -- balanced quotes/parentheses in every statement --------------------- */
foreach (['schema.sql' => $schemaStatements, 'seed.sql' => $seedStatements] as $file => $statements) {
    $unbalanced = 0;
    foreach ($statements as $statement) {
        $depth = 0;
        $inSingle = false;
        $length = strlen($statement);
        for ($i = 0; $i < $length; $i++) {
            $char = $statement[$i];
            if ($char === "'" ) {
                if ($inSingle && ($statement[$i + 1] ?? '') === "'") {
                    $i++;
                    continue;
                }
                $inSingle = !$inSingle;
                continue;
            }
            if ($inSingle) {
                continue;
            }
            if ($char === '(') {
                $depth++;
            } elseif ($char === ')') {
                $depth--;
            }
        }
        if ($depth !== 0 || $inSingle) {
            $unbalanced++;
        }
    }
    report("{$file}: all statements have balanced quotes and parentheses", $unbalanced === 0, "{$unbalanced} unbalanced");
}

/* -- 2..3. per-table rules --------------------------------------------- */
$createStatements = array_values(array_filter(
    $schemaStatements,
    static fn (string $s): bool => preg_match('/^CREATE\s+TABLE/i', $s) === 1
));
report('schema.sql defines tables', $createStatements !== []);

foreach ($createStatements as $statement) {
    preg_match('/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?([A-Za-z0-9_]+)`?/i', $statement, $m);
    $table = $m[1] ?? '?';

    report(
        "`{$table}`: CREATE TABLE IF NOT EXISTS",
        preg_match('/^CREATE\s+TABLE\s+IF\s+NOT\s+EXISTS/i', $statement) === 1
    );
    report("`{$table}`: ENGINE=InnoDB", preg_match('/ENGINE\s*=\s*InnoDB/i', $statement) === 1);
    report("`{$table}`: CHARSET=utf8mb4", preg_match('/CHARSET\s*=\s*utf8mb4/i', $statement) === 1);
    report("`{$table}`: PRIMARY KEY declared", preg_match('/PRIMARY\s+KEY/i', $statement) === 1);
}

$destructive = 0;
foreach ($schemaStatements as $statement) {
    if (preg_match('/\b(DROP\s+TABLE|TRUNCATE|DELETE\s+FROM|DROP\s+DATABASE)\b/i', $statement) === 1) {
        $destructive++;
    }
}
report('schema.sql contains no destructive statements', $destructive === 0, "{$destructive} found");

/* -- 4..5. foreign keys ------------------------------------------------- */
$tables = schema_table_names('schema.sql');
$position = array_flip($tables);
$fkCount = 0;
$fkErrors = 0;

foreach ($createStatements as $statement) {
    preg_match('/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?([A-Za-z0-9_]+)`?/i', $statement, $m);
    $table = $m[1] ?? '?';

    if (preg_match_all('/REFERENCES\s+`?([A-Za-z0-9_]+)`?/i', $statement, $refs) > 0) {
        foreach ($refs[1] as $target) {
            $fkCount++;
            if (!isset($position[$target])) {
                $fkErrors++;
                echo "[FAIL] `{$table}` references unknown table `{$target}`\n";
                continue;
            }
            if ($target !== $table && $position[$target] > $position[$table]) {
                $fkErrors++;
                echo "[FAIL] `{$table}` references `{$target}`, which is created later\n";
            }
        }
    }

    // Every foreign key needs an explicit ON DELETE rule (no silent defaults).
    $fkDeclarations = preg_match_all('/FOREIGN\s+KEY/i', $statement);
    $onDelete = preg_match_all('/ON\s+DELETE/i', $statement);
    if ($fkDeclarations !== $onDelete) {
        $fkErrors++;
        echo "[FAIL] `{$table}`: every FOREIGN KEY needs an explicit ON DELETE rule\n";
    }
}
report("all {$fkCount} foreign keys resolve, are ordered and define ON DELETE", $fkErrors === 0, "{$fkErrors} problem(s)");

/* -- 6. no credentials -------------------------------------------------- */
foreach (['schema.sql', 'seed.sql'] as $file) {
    $sql = strtoupper((string) file_get_contents(schema_sql_path($file)));
    $bad = preg_match('/\b(IDENTIFIED\s+BY|CREATE\s+USER|GRANT\s+ALL|CREATE\s+DATABASE)\b/', $sql) === 1;
    report("{$file}: no user/privilege/credential statements", !$bad);
}

/* -- summary ------------------------------------------------------------ */
echo "\n";
echo 'Schema checks: ' . $checks . ' | failures: ' . count($errors) . "\n";
if ($errors !== []) {
    echo "Schema check: FAILURES FOUND\n";
    exit(1);
}
echo "Schema check: ALL OK\n";
exit(0);
