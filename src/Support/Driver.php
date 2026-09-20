<?php

declare(strict_types=1);

namespace Mahbub\SchemaTools\Support;

use Illuminate\Support\Facades\Config;

/**
 * The database driver families the tools can read a source schema from.
 */
enum Driver: string
{
    case MySql = 'mysql';
    case SqlServer = 'sqlsrv';

    /**
     * The driver family of a configured connection, or null when its driver is
     * one the tools cannot dump from.
     */
    public static function forConnection(string $connection): ?self
    {
        return match (Config::get("database.connections.{$connection}.driver")) {
            'mysql', 'mariadb' => self::MySql,
            'sqlsrv' => self::SqlServer,
            default => null,
        };
    }
}
