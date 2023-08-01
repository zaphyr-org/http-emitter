<?php

declare(strict_types=1);

namespace Zaphyr\HttpEmitter;

use Psr\Http\Message\ResponseInterface;
use Zaphyr\HttpEmitter\Contracts\EmitterInterface;
use Zaphyr\HttpEmitter\Exceptions\HttpEmitterException;

/**
 * @author merloxx <merloxx@zaphyr.org>
 */
abstract class AbstractEmitter implements EmitterInterface
{
    /**
     * @throws HttpEmitterException
     * @return void
     */
    protected function assertNoPreviousOutput(): void
    {
        if (headers_sent()) {
            throw new HttpEmitterException('Unable to emit response. Headers already sent');
        }

        if (ob_get_level() > 0 && ob_get_length() > 0) {
            throw new HttpEmitterException('Unable to emit response. Output has been emitted previously');
        }
    }

    /**
     * @param ResponseInterface $response
     *
     * @return void
     */
    protected function emitHeaders(ResponseInterface $response): void
    {
        $statusCode = $response->getStatusCode();

        foreach ($response->getHeaders() as $header => $values) {
            $name = $this->sanitizeHeader($header);
            $first = $name !== 'Set-Cookie';

            foreach ($values as $value) {
                header($name . ': ' . $value, $first, $statusCode);

                $first = false;
            }
        }
    }

    /**
     * @param string $header
     *
     * @return string
     */
    protected function sanitizeHeader(string $header): string
    {
        $header = str_replace('-', ' ', $header);

        return str_replace(' ', '-', ucwords($header));
    }

    /**
     * @param ResponseInterface $response
     *
     * @return void
     */
    protected function emitStatusLine(ResponseInterface $response): void
    {
        $statusCode = $response->getStatusCode();
        $reasonPhrase = $response->getReasonPhrase();

        header(
            'HTTP/' . $response->getProtocolVersion() . ' ' . $statusCode . ($reasonPhrase ? ' ' . $reasonPhrase : ''),
            true,
            $statusCode
        );
    }
}
