<?php

declare(strict_types=1);

namespace Zaphyr\HttpEmitter;

use Psr\Http\Message\ResponseInterface;

/**
 * @author merloxx <merloxx@zaphyr.org>
 */
class SapiEmitter extends AbstractEmitter
{
    /**
     * {@inheritdoc}
     */
    public function emit(ResponseInterface $response): bool
    {
        $this->assertNoPreviousOutput();
        $this->emitHeaders($response);
        $this->emitStatusLine($response);

        echo $response->getBody();

        return true;
    }
}
