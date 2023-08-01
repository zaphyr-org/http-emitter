<?php

namespace Zaphyr\HttpEmitter;

use Zaphyr\HttpEmitterTests\TestAssets\HeaderStack;

function headers_sent(): bool
{
    return HeaderStack::$headersSent;
}

function header(string $string, bool $replace = true, int|null $statusCode = null): void
{
    HeaderStack::push([
        'header' => $string,
        'replace' => $replace,
        'status_code' => $statusCode,
    ]);
}
