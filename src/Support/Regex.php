<?php

declare(strict_types=1);

namespace Mahbub\SchemaTools\Support;

use RuntimeException;

final class Regex
{
    /**
     * @template TResult of int|string|array<mixed>
     *
     * @param  TResult|false|null  $result
     *
     * @return TResult
     */
    public static function orFail(int|string|array|false|null $result): int|string|array
    {
        if ($result === false || $result === null) {
            throw self::lastError();
        }

        return $result;
    }

    public static function failOnError(): void
    {
        if (preg_last_error() !== PREG_NO_ERROR) {
            throw self::lastError();
        }
    }

    private static function lastError(): RuntimeException
    {
        return new RuntimeException('Regular expression failed: ' . preg_last_error_msg());
    }
}
