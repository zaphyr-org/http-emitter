<?php

declare(strict_types=1);

namespace Zaphyr\HttpEmitterTests;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\StreamInterface;
use Zaphyr\HttpEmitter\SapiStreamEmitter;
use Zaphyr\HttpEmitterTests\TestAssets\HeaderStack;
use Zaphyr\HttpEmitterTests\TestAssets\Stream;
use Zaphyr\HttpMessage\Response;

class SapiStreamEmitterTest extends TestCase
{
    /**
     * @var SapiStreamEmitter
     */
    protected SapiStreamEmitter $emitter;

    public function setUp(): void
    {
        $this->emitter = new SapiStreamEmitter();
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

    public function testEmitDoesNotInjectContentLengthHeader(): void
    {
        $stream = $this->createMock(StreamInterface::class);
        $stream->method('__toString')->willReturn('Content!');
        $stream->method('isSeekable')->willReturn(false);
        $stream->method('isReadable')->willReturn(false);
        $stream->method('eof')->willReturn(true);
        $stream->method('getSize')->willReturn(null);

        $response = (new Response())->withStatus(200)->withBody($stream);

        ob_start();
        self::assertTrue($this->emitter->emit($response));
        ob_end_clean();

        foreach (HeaderStack::stack() as $header) {
            self::assertStringNotContainsString('Content-Length:', (string)$header['header']);
        }
    }

    /**
     * @param bool   $seekable
     * @param bool   $readable
     * @param string $contents
     * @param int    $maxBufferLength
     *
     * @dataProvider emitStreamResponseDataProvider
     */
    public function testEmitStreamResponse(bool $seekable, bool $readable, string $contents, int $maxBufferLength): void
    {
        $size = strlen($contents);
        $startPosition = 0;
        $peakBufferLength = 0;

        $streamMock = new Stream(
            $contents,
            $size,
            $startPosition,
            static function (int $bufferLength) use (&$peakBufferLength): void {
                if ($bufferLength > $peakBufferLength) {
                    $peakBufferLength = $bufferLength;
                }
            }
        );

        $stream = $this->createMock(StreamInterface::class);
        $stream->method('isSeekable')->willReturn($seekable);
        $stream->method('isReadable')->willReturn($readable);

        if ($seekable) {
            $stream
                ->expects(self::atLeastOnce())
                ->method('rewind')
                ->willReturnCallback(fn(): bool => $streamMock->handleRewind());
            $stream
                ->method('seek')
                ->willReturnCallback(fn($offset, $whence): bool => $streamMock->handleSeek($offset, $whence));
        }

        if (!$seekable) {
            $stream->expects(self::never())->method('rewind');
            $stream->expects(self::never())->method('seek');
        }

        if ($readable) {
            $stream->expects(self::never())->method('__toString');
            $stream->method('eof')->willReturnCallback(fn(): bool => $streamMock->handleEof());
            $stream->method('read')->willReturnCallback(fn($length): string => $streamMock->handleRead($length));
        }

        if (!$readable) {
            $stream->expects(self::never())->method('read');
            $stream->expects(self::never())->method('eof');

            $seekable
                ? $stream->method('getContents')
                ->willReturnCallback(fn(): string => $streamMock->handleGetContents())
                : $stream->expects(self::never())->method('getContents');

            $stream->method('__toString')->willReturnCallback(fn(): string => $streamMock->handleToString());
        }

        $response = (new Response())->withStatus(200)->withBody($stream);

        ob_start();

        self::assertTrue((new SapiStreamEmitter($maxBufferLength))->emit($response));
        $emittedContents = ob_get_clean();

        self::assertEquals($contents, $emittedContents);
        self::assertLessThanOrEqual($maxBufferLength, $peakBufferLength);
    }

    /**
     * @param bool   $seekable
     * @param bool   $readable
     * @param array  $range
     * @param string $contents
     * @param int    $maxBufferLength
     *
     * @dataProvider emitRangeStreamResponseDataProvider
     */
    public function testEmitRangeStreamResponse(
        bool $seekable,
        bool $readable,
        array $range,
        string $contents,
        int $maxBufferLength
    ): void {
        [, $first, $last,] = $range;
        $size = strlen($contents);

        $startPosition = $readable && !$seekable ? $first : 0;
        $peakBufferLength = 0;

        $trackPeakBufferLength = static function (int $bufferLength) use (&$peakBufferLength): void {
            if ($bufferLength > $peakBufferLength) {
                $peakBufferLength = $bufferLength;
            }
        };

        $streamMock = new Stream($contents, $size, $startPosition, $trackPeakBufferLength);

        $stream = $this->createMock(StreamInterface::class);
        $stream->method('isSeekable')->willReturn($seekable);
        $stream->method('isReadable')->willReturn($readable);
        $stream->method('getSize')->willReturn($size);
        $stream->method('tell')->willReturnCallback(fn(): int => $streamMock->handleTell());
        $stream->expects(self::never())->method('rewind');

        if ($seekable) {
            $stream->expects(self::atLeastOnce())->method('seek')->willReturnCallback(fn(
                $offset,
                $whence
            ): bool => $streamMock->handleSeek($offset, $whence));
        } else {
            $stream->expects(self::never())->method('seek');
        }

        $stream->expects(self::never())->method('__toString');

        if ($readable) {
            $stream
                ->expects(self::atLeastOnce())
                ->method('read')
                ->with(self::isType('int'))
                ->willReturnCallback(fn($length): string => $streamMock->handleRead($length));
            $stream
                ->expects(self::atLeastOnce())
                ->method('eof')
                ->willReturnCallback(fn(): bool => $streamMock->handleEof());
            $stream->expects(self::never())->method('getContents');
        } else {
            $stream->expects(self::never())->method('read');
            $stream->expects(self::never())->method('eof');
            $stream->expects(self::atLeastOnce())->method('getContents')->willReturnCallback(
                fn(): string => $streamMock->handleGetContents()
            );
        }

        $response = (new Response())
            ->withStatus(200)
            ->withHeader('Content-Range', 'bytes ' . $first . '-' . $last . '/*')
            ->withBody($stream);

        ob_start();

        self::assertTrue((new SapiStreamEmitter($maxBufferLength))->emit($response));

        $emittedContents = ob_get_clean();

        self::assertEquals(substr($contents, $first, $last - $first + 1), $emittedContents);
        self::assertLessThanOrEqual($maxBufferLength, $peakBufferLength);
    }

    /**
     * @param bool       $seekable
     * @param bool       $readable
     * @param int        $sizeBlocks
     * @param int        $maxAllowedBlocks
     * @param array|null $rangeBlocks
     * @param int        $maxBufferLength
     *
     * @dataProvider emitMemoryUsageDataProvider
     */
    public function testEmitMemoryUsage(
        bool $seekable,
        bool $readable,
        int $sizeBlocks,
        int $maxAllowedBlocks,
        array|null $rangeBlocks,
        int $maxBufferLength
    ): void {
        HeaderStack::$headersSent = false;

        $sizeBytes = $maxBufferLength * $sizeBlocks;
        $maxAllowedMemoryUsage = $maxBufferLength * $maxAllowedBlocks;
        $peakBufferLength = 0;
        $peakMemoryUsage = 0;
        $position = 0;
        $first = 0;
        $last = 0;

        if ($rangeBlocks !== null) {
            $first = $maxBufferLength * $rangeBlocks[0];
            $last = ($maxBufferLength * $rangeBlocks[1]) + $maxBufferLength - 1;

            if ($readable && !$seekable) {
                $position = $first;
            }
        }

        $closureTrackMemoryUsage = static function () use (&$peakMemoryUsage): void {
            $peakMemoryUsage = max($peakMemoryUsage, memory_get_usage());
        };

        $contentsCallback = static function (int $position, ?int $length = null) use (&$sizeBytes): string {
            if ($length === null) {
                $length = $sizeBytes - $position;
            }

            return str_repeat('0', $length);
        };

        $trackPeakBufferLength = static function (int $bufferLength) use (&$peakBufferLength): void {
            if ($bufferLength > $peakBufferLength) {
                $peakBufferLength = $bufferLength;
            }
        };

        $streamMock = new Stream($contentsCallback, $sizeBytes, $position, $trackPeakBufferLength);

        $stream = $this->createMock(StreamInterface::class);
        $stream->method('isSeekable')->willReturn($seekable);
        $stream->method('isReadable')->willReturn($readable);
        $stream->method('eof')->willReturnCallback(fn(): bool => $streamMock->handleEof());

        if ($seekable) {
            $stream->method('seek')->willReturnCallback(
                fn($offset, $whence): bool => $streamMock->handleSeek($offset, $whence)
            );
        }

        if ($readable) {
            $stream->method('read')->willReturnCallback(fn($length): string => $streamMock->handleRead($length));
        }

        if (!$readable) {
            $stream->method('getContents')->willReturnCallback(fn(): string => $streamMock->handleGetContents());
        }

        $response = (new Response())->withStatus(200)->withBody($stream);

        if ($rangeBlocks !== null) {
            $response = $response->withHeader('Content-Range', 'bytes ' . $first . '-' . $last . '/*');
        }

        ob_start(
            static function () use (&$closureTrackMemoryUsage): string {
                $closureTrackMemoryUsage();

                return '';
            },
            $maxBufferLength
        );

        gc_collect_cycles();
        gc_disable();

        self::assertTrue((new SapiStreamEmitter($maxBufferLength))->emit($response));

        ob_end_flush();
        gc_enable();
        gc_collect_cycles();

        $localMemoryUsage = memory_get_usage();

        self::assertLessThanOrEqual($maxBufferLength, $peakBufferLength);
        self::assertLessThanOrEqual($maxAllowedMemoryUsage, $peakMemoryUsage - $localMemoryUsage);
    }

    /**
     * @param string $header
     * @param string $body
     * @param string $expected
     *
     * @dataProvider contentRangeDataProvider
     */
    public function testEmitContentRange(string $header, string $body, string $expected): void
    {
        /** @var Response $response */
        $response = (new Response())->withHeader('Content-Range', $header);
        $response->getBody()->write($body);

        ob_start();
        self::assertTrue($this->emitter->emit($response));

        self::assertSame($expected, ob_get_clean());
    }

    public static function emitStreamResponseDataProvider(): array
    {
        return [
            [true, true, '01234567890987654321', 10],
            [true, true, '01234567890987654321', 20],
            [true, true, '01234567890987654321', 100],
            [true, true, '01234567890987654321012', 10],
            [true, true, '01234567890987654321012', 20],
            [true, true, '01234567890987654321012', 100],
            [true, false, '01234567890987654321', 10],
            [true, false, '01234567890987654321', 20],
            [true, false, '01234567890987654321', 100],
            [true, false, '01234567890987654321012', 10],
            [true, false, '01234567890987654321012', 20],
            [true, false, '01234567890987654321012', 100],
            [false, true, '01234567890987654321', 10],
            [false, true, '01234567890987654321', 20],
            [false, true, '01234567890987654321', 100],
            [false, true, '01234567890987654321012', 10],
            [false, true, '01234567890987654321012', 20],
            [false, true, '01234567890987654321012', 100],
            [false, false, '01234567890987654321', 10],
            [false, false, '01234567890987654321', 20],
            [false, false, '01234567890987654321', 100],
            [false, false, '01234567890987654321012', 10],
            [false, false, '01234567890987654321012', 20],
            [false, false, '01234567890987654321012', 100],
        ];
    }

    public static function emitRangeStreamResponseDataProvider(): iterable
    {
        return [
            [true, true, ['bytes', 10, 20, '*'], '01234567890987654321', 5],
            [true, true, ['bytes', 10, 20, '*'], '01234567890987654321', 10],
            [true, true, ['bytes', 10, 20, '*'], '01234567890987654321', 100],
            [true, true, ['bytes', 10, 20, '*'], '01234567890987654321012', 5],
            [true, true, ['bytes', 10, 20, '*'], '01234567890987654321012', 10],
            [true, true, ['bytes', 10, 20, '*'], '01234567890987654321012', 100],
            [true, true, ['bytes', 10, 100, '*'], '01234567890987654321', 5],
            [true, true, ['bytes', 10, 100, '*'], '01234567890987654321', 10],
            [true, true, ['bytes', 10, 100, '*'], '01234567890987654321', 100],
            [true, true, ['bytes', 10, 100, '*'], '01234567890987654321012', 5],
            [true, true, ['bytes', 10, 100, '*'], '01234567890987654321012', 10],
            [true, true, ['bytes', 10, 100, '*'], '01234567890987654321012', 100],
            [true, false, ['bytes', 10, 20, '*'], '01234567890987654321', 5],
            [true, false, ['bytes', 10, 20, '*'], '01234567890987654321', 10],
            [true, false, ['bytes', 10, 20, '*'], '01234567890987654321', 100],
            [true, false, ['bytes', 10, 20, '*'], '01234567890987654321012', 5],
            [true, false, ['bytes', 10, 20, '*'], '01234567890987654321012', 10],
            [true, false, ['bytes', 10, 20, '*'], '01234567890987654321012', 100],
            [true, false, ['bytes', 10, 100, '*'], '01234567890987654321', 5],
            [true, false, ['bytes', 10, 100, '*'], '01234567890987654321', 10],
            [true, false, ['bytes', 10, 100, '*'], '01234567890987654321', 100],
            [true, false, ['bytes', 10, 100, '*'], '01234567890987654321012', 5],
            [true, false, ['bytes', 10, 100, '*'], '01234567890987654321012', 10],
            [true, false, ['bytes', 10, 100, '*'], '01234567890987654321012', 100],
            [false, true, ['bytes', 10, 20, '*'], '01234567890987654321', 5],
            [false, true, ['bytes', 10, 20, '*'], '01234567890987654321', 10],
            [false, true, ['bytes', 10, 20, '*'], '01234567890987654321', 100],
            [false, true, ['bytes', 10, 20, '*'], '01234567890987654321012', 5],
            [false, true, ['bytes', 10, 20, '*'], '01234567890987654321012', 10],
            [false, true, ['bytes', 10, 20, '*'], '01234567890987654321012', 100],
            [false, true, ['bytes', 10, 100, '*'], '01234567890987654321', 5],
            [false, true, ['bytes', 10, 100, '*'], '01234567890987654321', 10],
            [false, true, ['bytes', 10, 100, '*'], '01234567890987654321', 100],
            [false, true, ['bytes', 10, 100, '*'], '01234567890987654321012', 5],
            [false, true, ['bytes', 10, 100, '*'], '01234567890987654321012', 10],
            [false, true, ['bytes', 10, 100, '*'], '01234567890987654321012', 100],
            [false, false, ['bytes', 10, 20, '*'], '01234567890987654321', 5],
            [false, false, ['bytes', 10, 20, '*'], '01234567890987654321', 10],
            [false, false, ['bytes', 10, 20, '*'], '01234567890987654321', 100],
            [false, false, ['bytes', 10, 20, '*'], '01234567890987654321012', 5],
            [false, false, ['bytes', 10, 20, '*'], '01234567890987654321012', 10],
            [false, false, ['bytes', 10, 20, '*'], '01234567890987654321012', 100],
            [false, false, ['bytes', 10, 100, '*'], '01234567890987654321', 5],
            [false, false, ['bytes', 10, 100, '*'], '01234567890987654321', 10],
            [false, false, ['bytes', 10, 100, '*'], '01234567890987654321', 100],
            [false, false, ['bytes', 10, 100, '*'], '01234567890987654321012', 5],
            [false, false, ['bytes', 10, 100, '*'], '01234567890987654321012', 10],
            [false, false, ['bytes', 10, 100, '*'], '01234567890987654321012', 100],
        ];
    }

    public static function emitMemoryUsageDataProvider(): array
    {
        return [
            [true, true, 1000, 20, null, 512],
            [true, true, 1000, 20, null, 4096],
            [true, true, 1000, 20, null, 8192],
            [true, false, 100, 320, null, 512],
            [true, false, 100, 320, null, 4096],
            [true, false, 100, 320, null, 8192],
            [false, true, 1000, 20, null, 512],
            [false, true, 1000, 20, null, 4096],
            [false, true, 1000, 20, null, 8192],
            [false, false, 100, 320, null, 512],
            [false, false, 100, 320, null, 4096],
            [false, false, 100, 320, null, 8192],
            [true, true, 1000, 20, [25, 75], 512],
            [true, true, 1000, 20, [25, 75], 4096],
            [true, true, 1000, 20, [25, 75], 8192],
            [false, true, 1000, 20, [25, 75], 512],
            [false, true, 1000, 20, [25, 75], 4096],
            [false, true, 1000, 20, [25, 75], 8192],
            [true, true, 1000, 20, [250, 750], 512],
            [true, true, 1000, 20, [250, 750], 4096],
            [true, true, 1000, 20, [250, 750], 8192],
            [false, true, 1000, 20, [250, 750], 512],
            [false, true, 1000, 20, [250, 750], 4096],
            [false, true, 1000, 20, [250, 750], 8192],
        ];
    }

    /**
     * @return array<int, array<int, string>>
     */
    public static function contentRangeDataProvider(): array
    {
        return [
            ['bytes 0-2/*', 'Hello world', 'Hel'],
            ['bytes 3-6/*', 'Hello world', 'lo w'],
            ['items 0-0/1', 'Hello world', 'Hello world'],
        ];
    }
}
