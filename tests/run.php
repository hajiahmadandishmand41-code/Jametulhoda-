<?php

declare(strict_types=1);

/**
 * Test runner — Phase 1 + Phase 2 + Phase 3 + Phase 4.1.
 *
 * Usage: php tests/run.php
 * Runs: unit + integration + security suites. Exit code 0 = green.
 *
 * Note: the whole run happens inside an output buffer so that
 * http_response_code() (get/set) keeps working in the CLI SAPI.
 */

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/lib/TestCase.php';
require __DIR__ . '/lib/Runner.php';

$groups = [
    'unit' => __DIR__ . '/unit',
    'integration' => __DIR__ . '/integration',
    'security' => __DIR__ . '/security',
];

ob_start();

$runner = new Runner();

foreach ($groups as $label => $dir) {
    echo "\n== {$label} ==\n";
    $files = glob($dir . '/*Test.php') ?: [];
    sort($files);
    if ($files === []) {
        echo "  (no tests in {$label})\n";
    }
    foreach ($files as $file) {
        $runner->run($file);
    }
}

$exitCode = $runner->summary();
$output = (string) ob_get_clean();

fwrite(STDOUT, $output);
exit($exitCode);
