<?php

declare(strict_types=1);

/**
 * Tiny test runner (no external dependencies).
 *
 * Convention: a test file tests/<group>/<Name>Test.php declares class <Name>
 * extending TestCase; every public test*() method is one test.
 */

final class Runner
{
    public int $passed = 0;

    public int $failed = 0;

    /** @var list<string> */
    public array $failures = [];

    public function run(string $testFile): void
    {
        require_once $testFile;

        $class = basename($testFile, '.php');
        if (!class_exists($class)) {
            $this->fail($class, 'test class not found in ' . $testFile);
            return;
        }

        foreach (get_class_methods($class) as $method) {
            if (!str_starts_with($method, 'test')) {
                continue;
            }
            $instance = new $class(); // fresh instance per test
            try {
                $instance->{$method}();
                $this->passed++;
                echo "  [OK]   {$class}::{$method} ({$instance->assertions} assertions)\n";
            } catch (Throwable $e) {
                $this->failed++;
                $label = "{$class}::{$method}";
                $this->failures[] = $label . ' — ' . $e->getMessage();
                echo "  [FAIL] {$label}\n         {$e->getMessage()}\n";
            }
        }
    }

    private function fail(string $label, string $message): void
    {
        $this->failed++;
        $this->failures[] = $label . ' — ' . $message;
        echo "  [FAIL] {$label}: {$message}\n";
    }

    /**
     * Print the summary; returns the process exit code.
     */
    public function summary(): int
    {
        echo str_repeat('-', 62) . "\n";
        echo sprintf(
            "Tests: %d passed, %d failed, %d total\n",
            $this->passed,
            $this->failed,
            $this->passed + $this->failed
        );

        if ($this->failed > 0) {
            echo "Failures:\n";
            foreach ($this->failures as $failure) {
                echo "  - {$failure}\n";
            }
            return 1;
        }

        echo "All tests passed.\n";
        return 0;
    }
}
