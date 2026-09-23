<?php

declare(strict_types=1);

final class BrandingSecurityTest extends TestCase
{
    private string $file;

    public function setUp(): void
    {
        $this->file = tempnam(sys_get_temp_dir(), 'branding-');
        file_put_contents($this->file, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aD1sAAAAASUVORK5CYII='));
    }

    public function tearDown(): void
    {
        if (isset($this->file) && is_file($this->file)) { unlink($this->file); }
    }

    private function reject(string $name): void
    {
        try {
            SiteSettings::validateImage($this->file, $name);
            $this->assertTrue(false, 'File must be rejected: ' . $name);
        } catch (InvalidArgumentException $e) {
            $this->assertTrue(true);
        }
    }

    public function testValidPngAndMimeExtensionAgreement(): void
    {
        $this->assertSame('png', SiteSettings::validateImage($this->file, 'نشان.PNG'));
        foreach (['logo.jpg', 'logo.webp', 'logo.svg', 'logo.php', 'logo.html', 'logo.ico'] as $name) {
            $this->reject($name);
        }
    }

    public function testTraversalAndExecutableNames(): void
    {
        foreach (['../logo.png', '..\\logo.png', 'logo.php.png', 'logo.php8.png', 'logo.phtml.png', "logo\0.png"] as $name) {
            $this->reject($name);
        }
        foreach (['../../config/local.php', 'uploads/site/a.php', 'uploads/site/../media/a.png', '/uploads/site/' . str_repeat('a', 48) . '.png'] as $path) {
            $this->assertFalse(SiteSettings::ownedPath($path));
        }
        $this->assertTrue(SiteSettings::ownedPath('uploads/site/' . str_repeat('a', 48) . '.png'));
    }

    public function testHtmlSvgPhpAndOversizeFilesCannotMasqueradeAsPng(): void
    {
        foreach (['<svg onload="alert(1)"></svg>', '<html>not an image</html>', '<?php echo "test"; ?>', str_repeat('x', SiteSettings::MAX_BYTES + 1), ''] as $payload) {
            file_put_contents($this->file, $payload);
            clearstatcache(true, $this->file);
            $this->reject('logo.png');
        }
    }

    public function testStaticStorageAndInstallerPolicies(): void
    {
        $policy = (string) file_get_contents(BASE_PATH . '/uploads/site/.htaccess');
        $this->assertContains('Require all denied', $policy);
        $this->assertContains('SetHandler default-handler', $policy);
        $this->assertContains('nosniff', $policy);
        $installer = (string) file_get_contents(BASE_PATH . '/install.php');
        $this->assertNotContains('$e->errorInfo[2]', $installer);
        $this->assertNotContains('$e->getMessage()', $installer);
        $this->assertContains("$" . "stage = 'اجرای Migration'", $installer);
        $this->assertContains('installer_apply_migrations($pdo)', $installer);
        $runner = (string) file_get_contents(BASE_PATH . '/app/Helpers/migrations.php');
        $this->assertContains('hash_equals', $runner);
        $this->assertContains('/database/migrations/*.sql', $runner);
    }

    public function testMigrationMatchesFreshSchema(): void
    {
        $migration = (string) file_get_contents(BASE_PATH . '/database/migrations/2026-09-23_site_settings.sql');
        $schema = (string) file_get_contents(BASE_PATH . '/database/schema.sql');
        $this->assertContains(trim($migration), $schema);
        $this->assertContains('PRIMARY KEY (`setting_key`)', $migration);
        $this->assertContains('ENGINE=InnoDB DEFAULT CHARSET=utf8mb4', $migration);
    }
}
