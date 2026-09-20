<?php

declare(strict_types=1);

namespace Mahbub\SchemaTools\Queries\MySql;

use Illuminate\Database\DatabaseManager;
use Mahbub\SchemaTools\Support\Identifier;

/**
 * The CREATE TABLE statement MySQL itself would use to recreate a table, from
 * SHOW CREATE TABLE. Null when the row carries no such statement (a view).
 */
final readonly class FetchCreateTable
{
    public function __construct(private DatabaseManager $database) {}

    public function execute(string $connection, string $table): ?string
    {
        $row = (array) $this->database->connection($connection)->selectOne(
            'SHOW CREATE TABLE ' . Identifier::backtick($table),
        );

        $statement = $row['Create Table'] ?? null;

        return is_string($statement) ? $statement : null;
    }
}
