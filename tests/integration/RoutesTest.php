<?php

declare(strict_types=1);

/**
 * Integration tests: real dispatch through the registered route table,
 * capturing the rendered HTML and the response code.
 */

final class RoutesTest extends TestCase
{
    /**
     * @return array{0:int, 1:string} [status code, body]
     */
    private function dispatch(string $method, string $path): array
    {
        $router = new Router();
        define_routes($router);

        http_response_code(200); // explicit default: CLI has no implicit 200
        ob_start();
        $router->dispatch($method, $path);
        $body = (string) ob_get_clean();
        $code = (int) http_response_code();
        http_response_code(200); // reset for other tests

        return [$code, $body];
    }

    public function testHomeRendersLayoutAndHero(): void
    {
        [$code, $body] = $this->dispatch('GET', '/');

        $this->assertSame(200, $code);
        $this->assertContains('<!DOCTYPE html>', $body);
        $this->assertContains('<html lang="fa" dir="rtl">', $body);
        $this->assertContains('class="hero"', $body);
        $this->assertContains('main.css', $body);
        $this->assertContains('main.js', $body);
        $this->assertContains(e((string) Config::get('app.name')), $body);
    }

    public function testHomeHasNoInlineScriptsOrStyles(): void
    {
        [, $body] = $this->dispatch('GET', '/');
        // Strict CSP (see .htaccess) forbids inline handlers/styles
        $this->assertNotContains('<script>', $body);
        $this->assertNotContains('<style>', $body);
        $this->assertNotContains('onclick=', $body);
    }

    public function testUnknownPathRenders404Page(): void
    {
        [$code, $body] = $this->dispatch('GET', '/this-page-does-not-exist');

        $this->assertSame(404, $code);
        $this->assertContains('صفحه پیدا نشد', $body);
        $this->assertContains('not-found', $body);
    }

    public function test404PageLinksToHome(): void
    {
        [, $body] = $this->dispatch('GET', '/nope');
        $this->assertContains('href="/"', $body);
    }

    public function testPostToKnownPathIs405(): void
    {
        [$code, $body] = $this->dispatch('POST', '/');
        $this->assertSame(405, $code);
        $this->assertContains('Method Not Allowed', $body);
    }

    public function test404TitleIsSet(): void
    {
        [, $body] = $this->dispatch('GET', '/missing');
        $this->assertContains('<title>صفحه پیدا نشد |', $body);
    }

    public function testHomeTitleIsSet(): void
    {
        [, $body] = $this->dispatch('GET', '/');
        $this->assertContains('<title>خانه |', $body);
    }
}
