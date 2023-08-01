<?php

declare(strict_types=1);

namespace Zaphyr\HttpEmitterTests;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\StreamInterface;
use Zaphyr\HttpEmitter\SapiEmitter;
use Zaphyr\HttpEmitterTests\TestAssets\HeaderStack;
use Zaphyr\HttpMessage\Response;

class SapiEmitterTest extends TestCase
{
    /**
     * @var SapiEmitter
     */
    protected SapiEmitter $emitter;

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

    public function testEmitBody(): void
    {
        $response = (new Response())->withStatus(200)->withAddedHeader('Content-Type', 'text/plain');
        $response->getBody()->write('hello world');

        $this->expectOutputString('hello world');
        self::assertTrue($this->emitter->emit($response));
    }

    public function testEmitDoesNotInjectContentLengthHeader(): void
    {
        $stream = $this->createMock(StreamInterface::class);
        $stream->method('__toString')->willReturn('hello world');
        $stream->method('getSize')->willReturn(null);

        $response = (new Response())->withStatus(200)->withBody($stream);

        ob_start();
        self::assertTrue($this->emitter->emit($response));
        ob_end_clean();

        foreach (HeaderStack::stack() as $header) {
            self::assertStringNotContainsString('Content-Length:', $header['header']);
        }
    }
}
