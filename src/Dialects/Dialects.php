<?php

declare(strict_types=1);

namespace Mahbub\SchemaTools\Dialects;

use Mahbub\SchemaTools\Support\Driver;

/**
 * Picks the dialect a connection's configured driver calls for.
 */
final readonly class Dialects
{
    public function __construct(
        private MySqlDialect $mySqlDialect,
        private SqlServerDialect $sqlServerDialect,
    ) {}

    public function forConnection(string $connection): ?Dialect
    {
        return match (Driver::forConnection($connection)) {
            Driver::MySql => $this->mySqlDialect,
            Driver::SqlServer => $this->sqlServerDialect,
            null => null,
        };
    }
}
