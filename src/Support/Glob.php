<?php

declare(strict_types=1);

namespace Mahbub\SchemaTools\Support;

use RuntimeException;

final class Glob
{
    /**
     * @param  list<string>|false  $matches
     *
     * @return list<string>
     */
    public static function orFail(array|false $matches, string $pattern): array
    {
        if ($matches === false) {
            throw new RuntimeException("Could not read the paths matching [{$pattern}].");
        }

        return $matches;
    }
}
