<?php

declare(strict_types=1);

namespace Mahbub\SchemaTools\Support;

/**
 * What a manifest name resolved to at the source.
 */
enum ObjectKind
{
    case Table;
    case View;
    case Other;

    public static function fromMySqlTableType(string $tableType): self
    {
        return match ($tableType) {
            'BASE TABLE' => self::Table,
            'VIEW' => self::View,
            default => self::Other,
        };
    }

    public static function fromSqlServerObjectType(string $objectType): self
    {
        return match (trim($objectType)) {
            'U' => self::Table,
            'V' => self::View,
            default => self::Other,
        };
    }
}
