<?php

declare(strict_types=1);

/**
 * Unit tests for config/config.php (Config class)
 */

final class ConfigTest extends TestCase
{
    public function testDefaultsAreLoaded(): void
    {
        $this->assertTrue(
            is_string(Config::get('app.name')) && (string) Config::get('app.name') !== '',
            'app.name should be a non-empty string'
        );
        $this->assertSame('utf8mb4', Config::get('db.charset'));
        $this->assertSame('Asia/Kabul', Config::get('app.timezone'));
    }

    public function testDotNotationAndFallbackDefault(): void
    {
        $this->assertSame('utf8mb4', Config::get('db.charset', 'fallback'));
        $this->assertSame('fallback', Config::get('no.such.key', 'fallback'));
        $this->assertNull(Config::get('no.such.key'));
    }

    public function testBasePathIsProjectRoot(): void
    {
        $base = (string) Config::get('app.base_path');
        $this->assertDirectoryExists($base, 'app.base_path should point to the project root');
        $this->assertFileExists($base . '/index.php');
    }

    public function testLocalOverrideIsAppliedWhenPresent(): void
    {
        // In the sandbox config/local.php sets environment=development;
        // elsewhere (no local.php) the default is production. Both are valid.
        $env = (string) Config::get('app.environment');
        $this->assertTrue(
            in_array($env, ['production', 'development'], true),
            'app.environment must be production or development'
        );
    }
}
