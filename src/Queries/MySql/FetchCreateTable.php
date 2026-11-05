<?php

declare(strict_types=1);

namespace Mahbub\SchemaTools\Queries\MySql;

use Illuminate\Database\DatabaseManager;
use Mahbub\SchemaTools\Support\Identifier;
use UnexpectedValueException;

/**
 * The CREATE TABLE statement MySQL itself would use to recreate a table, from
 * SHOW CREATE TABLE.
 */
final readonly class FetchCreateTable
{
    public function __construct(private DatabaseManager $databaseManager) {}

    public function execute(string $connection, string $table): string
    {
        $query = 'SHOW CREATE TABLE ' . Identifier::backtick($table);
        $row = (array) $this->databaseManager->connection($connection)->selectOne($query);
        $statement = $row['Create Table'] ?? null;

        if (!is_string($statement)) {
            throw new UnexpectedValueException("{$query} returned no CREATE TABLE statement.");
        }

        return $statement;
    }
}
