<?php

declare(strict_types=1);

/**
 * Tiny assertion base class (no external dependencies).
 */

final class AssertionFailure extends Exception
{
}

abstract class TestCase
{
    public int $assertions = 0;

    public function assertTrue(mixed $condition, string $message = ''): void
    {
        $this->assertions++;
        if (!$condition) {
            throw new AssertionFailure($message !== '' ? $message : 'Failed asserting that the condition is true');
        }
    }

    public function assertFalse(mixed $condition, string $message = ''): void
    {
        $this->assertions++;
        if ($condition) {
            throw new AssertionFailure($message !== '' ? $message : 'Failed asserting that the condition is false');
        }
    }

    public function assertSame(mixed $expected, mixed $actual, string $message = ''): void
    {
        $this->assertions++;
        if ($expected !== $actual) {
            $msg = $message !== '' ? $message . ' — ' : 'Failed asserting values are identical. ';
            throw new AssertionFailure(
                $msg
                . 'Expected: ' . var_export($expected, true)
                . ', Actual: ' . var_export($actual, true)
            );
        }
    }

    public function assertNull(mixed $value, string $message = ''): void
    {
        $this->assertSame(null, $value, $message);
    }

    public function assertContains(string $needle, string $haystack, string $message = ''): void
    {
        $this->assertions++;
        if (!str_contains($haystack, $needle)) {
            $msg = $message !== '' ? $message . ' — ' : '';
            throw new AssertionFailure($msg . 'String does not contain: ' . substr($needle, 0, 120));
        }
    }

    public function assertNotContains(string $needle, string $haystack, string $message = ''): void
    {
        $this->assertions++;
        if (str_contains($haystack, $needle)) {
            $msg = $message !== '' ? $message . ' — ' : '';
            throw new AssertionFailure($msg . 'String must not contain: ' . substr($needle, 0, 120));
        }
    }

    public function assertMatches(string $pattern, string $subject, string $message = ''): void
    {
        $this->assertions++;
        if (preg_match($pattern, $subject) !== 1) {
            $msg = $message !== '' ? $message . ' — ' : '';
            throw new AssertionFailure($msg . "Pattern {$pattern} does not match: " . substr($subject, 0, 120));
        }
    }

    public function assertFileExists(string $path, string $message = ''): void
    {
        $this->assertions++;
        if (!file_exists($path)) {
            throw new AssertionFailure($message !== '' ? $message : 'File does not exist: ' . $path);
        }
    }

    public function assertDirectoryExists(string $path, string $message = ''): void
    {
        $this->assertions++;
        if (!is_dir($path)) {
            throw new AssertionFailure($message !== '' ? $message : 'Directory does not exist: ' . $path);
        }
    }
}
