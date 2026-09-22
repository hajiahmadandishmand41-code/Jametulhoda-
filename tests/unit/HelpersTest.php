<?php

declare(strict_types=1);

/**
 * Unit tests for app/Helpers/functions.php
 */

final class HelpersTest extends TestCase
{
    public function testEEscapesHtmlAndQuotes(): void
    {
        $this->assertSame(
            '&lt;script&gt;alert(&quot;1&quot;)&lt;/script&gt;',
            e('<script>alert("1")</script>')
        );
    }

    public function testEHandlesPersianText(): void
    {
        $this->assertSame('سلام، دنیا', e('سلام، دنیا'));
    }

    public function testEAcceptsNonStrings(): void
    {
        $this->assertSame('42', e(42));
        $this->assertSame('', e(null));
    }

    public function testUrlBuildsRootRelativePath(): void
    {
        // No app.url configured in tests -> root-relative URLs
        $this->assertSame('/about', url('/about'));
        $this->assertSame('/', url('/'));
        $this->assertSame('/', url(''));
    }

    public function testAssetBuildsAssetsPath(): void
    {
        $this->assertSame('/assets/css/main.css', asset('css/main.css'));
        $this->assertSame('/assets/js/main.js', asset('/js/main.js'));
    }

    public function testViewThrowsForUnknownPage(): void
    {
        try {
            view('definitely-missing-page', []);
            $this->assertTrue(false, 'Expected RuntimeException for unknown view');
        } catch (RuntimeException $e) {
            $this->assertContains('View not found', $e->getMessage());
        }
    }
}
