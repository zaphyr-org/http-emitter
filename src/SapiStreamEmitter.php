<?php

declare(strict_types=1);

namespace Zaphyr\HttpEmitter;

use Psr\Http\Message\ResponseInterface;

/**
 * @author merloxx <merloxx@zaphyr.org>
 */
class SapiStreamEmitter extends AbstractEmitter
{
    /**
     * @param int $maxBufferLength
     */
    public function __construct(private readonly int $maxBufferLength = 8192)
    {
    }

    /**
     * {@inheritdoc}
     */
    public function emit(ResponseInterface $response): bool
    {
        $this->assertNoPreviousOutput();
        $this->emitHeaders($response);
        $this->emitStatusLine($response);

        flush();

        $range = $this->parseContentRange($response->getHeaderLine('Content-Range'));

        if ($range !== null && $range[0] === 'bytes') {
            $this->emitBodyRange($range, $response);
        } else {
            $this->emitBody($response);
        }

        return true;
    }

    /**
     * @param string $header
     *
     * @return array{0: string, 1: int, 2: int, 3: string|int}|null
     */
    private function parseContentRange(string $header): array|null
    {
        if (
            !preg_match(
                '/(?P<unit>[\w]+)\s+(?P<first>\d+)-(?P<last>\d+)\/(?P<length>\d+|\*)/',
                $header,
                $matches
            )
        ) {
            return null;
        }

        return [
            (string)$matches['unit'],
            (int)$matches['first'],
            (int)$matches['last'],
            $matches['length'] === '*' ? '*' : (int)$matches['length'],
        ];
    }

    /**
     * @param array{0: string, 1: int, 2: int, 3: string|int} $range
     * @param ResponseInterface                               $response
     *
     * @return void
     */
    private function emitBodyRange(array $range, ResponseInterface $response): void
    {
        [, $first, $last] = $range;
        $body = $response->getBody();
        $length = $last - $first + 1;

        if ($body->isSeekable()) {
            $body->seek($first);
            $first = 0;
        }

        if (!$body->isReadable()) {
            echo substr($body->getContents(), $first, $length);

            return;
        }

        $remaining = $length;

        while ($remaining >= $this->maxBufferLength && !$body->eof()) {
            $contents = $body->read($this->maxBufferLength);
            $remaining -= strlen($contents);

            echo $contents;
        }

        if ($remaining <= 0 || $body->eof()) {
            return;
        }

        echo $body->read($remaining);
    }

    /**
     * @param ResponseInterface $response
     *
     * @return void
     */
    private function emitBody(ResponseInterface $response): void
    {
        $body = $response->getBody();

        if ($body->isSeekable()) {
            $body->rewind();
        }

        if (!$body->isReadable()) {
            echo $body;

            return;
        }

        while (!$body->eof()) {
            echo $body->read($this->maxBufferLength);
        }
    }
}
