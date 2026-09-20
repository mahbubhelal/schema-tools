<?php

declare(strict_types=1);

namespace Mahbub\SchemaTools\Support;

final class Identifier
{
    /**
     * A MySQL identifier quoted with backticks, escaping any it contains.
     */
    public static function backtick(string $name): string
    {
        return '`' . str_replace('`', '``', $name) . '`';
    }
}
