<?php

declare(strict_types=1);

/**
 * Security baseline checks (Phase 1) — file-level invariants.
 */

final class BasicsTest extends TestCase
{
    private function read(string $rel): string
    {
        $path = BASE_PATH . '/' . $rel;
        $this->assertFileExists($path);
        return (string) file_get_contents($path);
    }

    public function testHtaccessBlocksInternalDirectories(): void
    {
        $ht = $this->read('.htaccess');
        foreach (['config', 'app', 'views', 'pages', 'tests', 'database', 'docs', 'logs'] as $dir) {
            $this->assertContains($dir, $ht, ".htaccess must block /{$dir}");
        }
        $this->assertContains('RewriteRule ^ index.php [QSA,L]', $ht, 'front controller rewrite missing');
        $this->assertContains('Options -Indexes', $ht, 'directory listing must be disabled');
    }

    public function testHtaccessSendsSecurityHeaders(): void
    {
        $ht = $this->read('.htaccess');
        $this->assertContains('X-Content-Type-Options', $ht);
        $this->assertContains('X-Frame-Options', $ht);
        $this->assertContains('Content-Security-Policy', $ht);
    }

    public function testUploadsDenyCodeExecution(): void
    {
        $ht = $this->read('uploads/.htaccess');
        $this->assertContains('php', strtolower($ht));
        $this->assertContains('Require all denied', $ht);
    }

    public function testAdminIsDeniedUntilBuilt(): void
    {
        $ht = $this->read('admin/.htaccess');
        $this->assertContains('Require all denied', $ht);
    }

    public function testLocalConfigIsGitIgnored(): void
    {
        $this->assertContains('config/local.php', $this->read('.gitignore'));
    }

    public function testProductionDefaultsContainNoCredentials(): void
    {
        $config = $this->read('config/config.php');
        $this->assertContains("'password' => ''", $config, 'default DB password must be empty');
        // Read any real local password dynamically (never hardcode secrets in tests)
        $localFile = BASE_PATH . '/config/local.php';
        if (is_file($localFile)) {
            $local = (array) require $localFile;
            $realPassword = (string) ($local['db']['password'] ?? '');
            if ($realPassword !== '') {
                $this->assertNotContains($realPassword, $config, 'committed config must not contain the real local password');
            }
        }
    }

    public function testLayoutEscapesDynamicValues(): void
    {
        $layout = $this->read('views/layouts/main.php');
        $this->assertContains('e(', $layout, 'layout must escape dynamic output');
        $this->assertNotContains('<html lang="fa" dir="rtl">', (string) e('<html lang="fa" dir="rtl">'));
    }
}
