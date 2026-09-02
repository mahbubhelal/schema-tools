<?php

declare(strict_types=1);

namespace Mahbub\SchemaTools\Support;

use DateTimeInterface;
use Stringable;

/**
 * Pure classification of SQL Server column types, and the PHP-value checks the
 * factory auditor uses. Every method is stateless.
 */
final class ColumnType
{
    /**
     * SQL types whose values are strings; everything else maps to an int key
     * type when deciding a model's `$keyType`.
     *
     * @return list<string>
     */
    public static function stringTypes(): array
    {
        return ['char', 'varchar', 'nchar', 'nvarchar', 'text', 'ntext', 'uniqueidentifier'];
    }

    /**
     * The Eloquent `$keyType` a primary-key column's SQL type implies.
     *
     * @return 'string'|'int'
     */
    public static function keyType(string $type): string
    {
        $base = strtolower((string) preg_replace('/\(.*/s', '', $type));

        return in_array($base, self::stringTypes(), true) ? 'string' : 'int';
    }

    /**
     * Whether a PHP value could be inserted into a column of the given SQL type.
     * Unknown types pass — the check only flags the mismatches it is sure of.
     */
    public static function valueFits(mixed $value, string $type): bool
    {
        $base = strtolower((string) preg_replace('/\(.*/s', '', $type));

        return match (true) {
            $type === 'bit' => is_bool($value) || is_int($value),
            in_array($base, ['tinyint', 'smallint', 'int', 'bigint'], true) => is_int($value),
            in_array($base, ['decimal', 'numeric', 'money', 'smallmoney', 'float', 'real'], true) => is_int($value) || is_float($value) || (is_string($value) && is_numeric($value)),
            in_array($base, ['date', 'datetime', 'datetime2', 'smalldatetime', 'time', 'datetimeoffset'], true) => $value instanceof DateTimeInterface || (is_string($value) && strtotime($value) !== false),
            in_array($base, ['char', 'varchar', 'nchar', 'nvarchar', 'text', 'ntext'], true) => is_string($value) || $value instanceof Stringable,
            $base === 'uniqueidentifier' => is_string($value),
            default => true,
        };
    }

    /**
     * A short, human-readable rendering of a value for a violation message.
     */
    public static function describe(mixed $value): string
    {
        return match (true) {
            is_string($value) => "'" . (strlen($value) > 40 ? substr($value, 0, 37) . '...' : $value) . "' (string)",
            is_bool($value) => ($value ? 'true' : 'false') . ' (bool)',
            is_scalar($value) => $value . ' (' . get_debug_type($value) . ')',
            default => get_debug_type($value),
        };
    }
}
