<?php

declare(strict_types=1);

namespace Zaphyr\HttpEmitterTests\TestAssets;

class HeaderStack
{
    public static bool $headersSent = false;

    public static int $obLevel = 0;

    protected static array $data = [];

    public static function reset(): void
    {
        self::$data = [];
    }

    public static function push(array $header): void
    {
        self::$data[] = $header;
    }

    public static function stack(): array
    {
        return self::$data;
    }

    public static function has(string $header): bool
    {
        foreach (self::$data as $item) {
            if ($item['header'] === $header) {
                return true;
            }
        }

        return false;
    }
}
