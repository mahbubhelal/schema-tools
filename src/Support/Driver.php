<?php

declare(strict_types=1);

namespace Mahbub\SchemaTools\Support;

use Mahbub\SchemaTools\Dialects\Dialect;
use Mahbub\SchemaTools\Dialects\MySqlDialect;
use Mahbub\SchemaTools\Dialects\SqlServerDialect;

/**
 * The database driver families the tools can read a source schema from.
 */
enum Driver: string
{
    case MySql = 'mysql';
    case SqlServer = 'sqlsrv';

    /**
     * The driver family of a connection's configured driver, or null when the
     * tools cannot dump from it.
     */
    public static function fromConnectionDriver(mixed $connectionDriver): ?self
    {
        return match ($connectionDriver) {
            'mysql', 'mariadb' => self::MySql,
            'sqlsrv' => self::SqlServer,
            default => null,
        };
    }

    /**
     * @return class-string<Dialect>
     */
    public function dialect(): string
    {
        return match ($this) {
            self::MySql => MySqlDialect::class,
            self::SqlServer => SqlServerDialect::class,
        };
    }
}
