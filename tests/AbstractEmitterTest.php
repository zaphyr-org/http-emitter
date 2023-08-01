<?php

declare(strict_types=1);

namespace Zaphyr\HttpEmitterTests;

use PHPUnit\Framework\TestCase;
use Zaphyr\HttpEmitter\AbstractEmitter;
use Zaphyr\HttpEmitter\Exceptions\HttpEmitterException;
use Zaphyr\HttpEmitter\SapiEmitter;
use Zaphyr\HttpEmitterTests\TestAssets\HeaderStack;
use Zaphyr\HttpMessage\Response;

class AbstractEmitterTest extends TestCase
{
    /**
     * @var AbstractEmitter
     */
    protected AbstractEmitter $emitter;

    public function setUp(): void
    {
        $this->emitter = new SapiEmitter();
    }

    public function tearDown(): void
    {
        HeaderStack::reset();
        HeaderStack::$headersSent = false;

        unset($this->emitter);
    }

    /* -------------------------------------------------
     * EMIT
     * -------------------------------------------------
     */

    public function testAssertNoPreviousOutputThrowsExceptionWhenHeadersAlreadySent(): void
    {
        HeaderStack::$headersSent = true;

        $this->expectException(HttpEmitterException::class);

        $this->emitter->emit(new Response());
    }

    public function testEmitHeaders(): void
    {
        $response = (new Response())->withStatus(200)->withAddedHeader('Location', 'http://example.com');

        ob_start();
        $this->emitter->emit($response);
        ob_end_clean();

        self::assertTrue(HeaderStack::has('HTTP/1.1 200 OK'));
        self::assertTrue(HeaderStack::has('Location: http://example.com'));
    }

    public function testEmitHeadersAllowsMultipleCookieHeaders(): void
    {
        $response = (new Response())
            ->withStatus(200)
            ->withAddedHeader('Set-Cookie', 'foo=bar')
            ->withAddedHeader('Set-Cookie', 'bar=baz');

        $this->emitter->emit($response);

        $expectedStack = [
            ['header' => 'Set-Cookie: foo=bar', 'replace' => false, 'status_code' => 200],
            ['header' => 'Set-Cookie: bar=baz', 'replace' => false, 'status_code' => 200],
            ['header' => 'HTTP/1.1 200 OK', 'replace' => true, 'status_code' => 200],
        ];

        self::assertSame($expectedStack, HeaderStack::stack());
    }

    public function testEmitDoesNotOverrideResponseCode(): void
    {
        $response = (new Response())
            ->withStatus(202)
            ->withAddedHeader('Location', 'https://example.com')
            ->withAddedHeader('Content-Type', 'text/plain');

        $this->emitter->emit($response);

        $expectedStack = [
            ['header' => 'Location: https://example.com', 'replace' => true, 'status_code' => 202],
            ['header' => 'Content-Type: text/plain', 'replace' => true, 'status_code' => 202],
            ['header' => 'HTTP/1.1 202 Accepted', 'replace' => true, 'status_code' => 202],
        ];

        self::assertSame($expectedStack, HeaderStack::stack());
    }
}
