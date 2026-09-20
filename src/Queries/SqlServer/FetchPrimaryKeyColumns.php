<?php

declare(strict_types=1);

namespace Mahbub\SchemaTools\Queries\SqlServer;

use Illuminate\Database\DatabaseManager;

/**
 * The primary-key columns of a source table, in key order. Empty when the table
 * has no PRIMARY KEY constraint.
 */
final readonly class FetchPrimaryKeyColumns
{
    public function __construct(private DatabaseManager $database) {}

    /**
     * @return list<object{name: string}>
     */
    public function execute(string $connection, string $table): array
    {
        /** @var list<object{name: string}> */
        return $this->database->connection($connection)->select(
            <<<'SQL'
                SELECT col.name AS name
                FROM sys.key_constraints kc
                INNER JOIN sys.index_columns ixc
                    ON ixc.object_id = kc.parent_object_id AND ixc.index_id = kc.unique_index_id
                INNER JOIN sys.columns col
                    ON col.object_id = ixc.object_id AND col.column_id = ixc.column_id
                WHERE kc.parent_object_id = OBJECT_ID(?) AND kc.type = 'PK'
                ORDER BY ixc.key_ordinal
                SQL,
            ['dbo.' . $table],
        );
    }
}
