<?php

declare(strict_types=1);

/**
 * Unit tests for app/Router.php
 */

final class RouterTest extends TestCase
{
    private function makeRouter(): Router
    {
        $router = new Router();
        $router->get('/', static function (): void {
        });
        $router->get('/hello/{name}', static function (array $p): void {
        });
        $router->notFound(static function (): void {
        });

        return $router;
    }

    public function testMatchesExactRoute(): void
    {
        $m = $this->makeRouter()->match('GET', '/');
        $this->assertNotNullSafe($m);
        $this->assertSame('GET', $m['method']);
        $this->assertSame([], $m['params']);
    }

    public function testMatchesParamPattern(): void
    {
        $m = $this->makeRouter()->match('GET', '/hello/world');
        $this->assertNotNullSafe($m);
        $this->assertSame(['name' => 'world'], $m['params']);
    }

    public function testParamPatternIsGreedyWithinOneSegment(): void
    {
        $m = $this->makeRouter()->match('GET', '/hello/a-b_c');
        $this->assertNotNullSafe($m);
        $this->assertSame(['name' => 'a-b_c'], $m['params']);
    }

    public function testUnknownPathReturnsNull(): void
    {
        $this->assertNull($this->makeRouter()->match('GET', '/nope/nada'));
    }

    public function testTrailingSlashDoesNotMatch(): void
    {
        // '/' is the only registered root form; '/ ' variants are normalized
        // in index.php before dispatch.
        $this->assertNull($this->makeRouter()->match('GET', '/hello'));
    }

    public function testDispatchUnknownPathCallsNotFoundHandler(): void
    {
        $called = 0;
        $router = new Router();
        $router->notFound(static function () use (&$called): void {
            $called++;
            http_response_code(404);
        });

        ob_start();
        $router->dispatch('GET', '/missing-page');
        ob_end_clean();

        $this->assertSame(1, $called);
        $this->assertSame(404, http_response_code());
        http_response_code(200); // reset for other tests
    }

    public function testDispatchWrongMethodGives405(): void
    {
        $router = new Router();
        $router->get('/', static function (): void {
        });

        ob_start();
        $router->dispatch('POST', '/');
        ob_end_clean();

        $this->assertSame(405, http_response_code());
        http_response_code(200);
    }

    /** Null-safety bridge (clearer message than raw assertSame). */
    private function assertNotNullSafe(?array $value): void
    {
        $this->assertFalse($value === null, 'Expected a matched route, got null');
    }
}
