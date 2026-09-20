<?php

declare(strict_types=1);

namespace Mahbub\SchemaTools\Support;

use BackedEnum;
use DateTimeInterface;
use Stringable;

/**
 * Pure classification of SQL Server and MySQL column types, and the PHP-value
 * checks the factory auditor uses. Every method is stateless.
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
        return [
            'char', 'varchar', 'nchar', 'nvarchar', 'text', 'ntext', 'uniqueidentifier',
            'tinytext', 'mediumtext', 'longtext', 'enum', 'set', 'binary', 'varbinary',
        ];
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
     * A backed enum is judged by the value Eloquent's enum cast stores. Unknown
     * types pass — the check only flags the mismatches it is sure of.
     */
    public static function valueFits(mixed $value, string $type): bool
    {
        if ($value instanceof BackedEnum) {
            $value = $value->value;
        }

        $base = strtolower((string) preg_replace('/\(.*/s', '', $type));

        return match (true) {
            $type === 'bit', $type === 'tinyint(1)' => is_bool($value) || is_int($value),
            in_array($base, ['tinyint', 'smallint', 'mediumint', 'int', 'integer', 'bigint', 'year'], true) => is_int($value),
            in_array($base, ['decimal', 'numeric', 'money', 'smallmoney', 'float', 'real', 'double'], true) => is_int($value) || is_float($value) || (is_string($value) && is_numeric($value)),
            in_array($base, ['date', 'datetime', 'datetime2', 'smalldatetime', 'time', 'datetimeoffset', 'timestamp'], true) => $value instanceof DateTimeInterface || (is_string($value) && strtotime($value) !== false),
            in_array($base, ['char', 'varchar', 'nchar', 'nvarchar', 'text', 'ntext', 'tinytext', 'mediumtext', 'longtext', 'enum', 'set', 'binary', 'varbinary', 'blob', 'tinyblob', 'mediumblob', 'longblob'], true) => is_string($value) || $value instanceof Stringable,
            $base === 'uniqueidentifier' => is_string($value),
            $base === 'json' => is_string($value) || is_array($value),
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
