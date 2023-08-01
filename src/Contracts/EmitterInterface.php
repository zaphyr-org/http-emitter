<?php

declare(strict_types=1);

namespace Zaphyr\HttpEmitter\Contracts;

use Psr\Http\Message\ResponseInterface;
use Zaphyr\HttpEmitter\Exceptions\HttpEmitterException;

/**
 * @author merloxx <merloxx@zaphyr.org>
 */
interface EmitterInterface
{
    /**
     * @param ResponseInterface $response
     *
     * @throws HttpEmitterException
     * @return bool
     */
    public function emit(ResponseInterface $response): bool;
}
