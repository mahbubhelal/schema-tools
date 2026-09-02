<?php

declare(strict_types=1);

namespace Mahbub\SchemaTools\Queries;

use Illuminate\Database\DatabaseManager;

/**
 * The columns of a source table, in ordinal order, with the catalog metadata
 * needed to reconstruct each column's DDL.
 *
 * @phpstan-type TableColumnRow object{
 *     name: string,
 *     type_name: string,
 *     max_length: int|string,
 *     numeric_precision: int|string,
 *     numeric_scale: int|string,
 *     is_nullable: int|string,
 *     is_identity: int|string,
 *     collation_name: string|null,
 *     seed_value: int|string|null,
 *     increment_value: int|string|null,
 *     default_definition: string|null
 * }
 */
final readonly class FetchTableColumns
{
    public function __construct(private DatabaseManager $database) {}

    /**
     * @return list<TableColumnRow>
     */
    public function execute(string $connection, string $table): array
    {
        /** @var list<TableColumnRow> */
        return $this->database->connection($connection)->select(
            <<<'SQL'
                SELECT c.name AS name,
                       t.name AS type_name,
                       c.max_length AS max_length,
                       c.precision AS numeric_precision,
                       c.scale AS numeric_scale,
                       c.is_nullable AS is_nullable,
                       c.is_identity AS is_identity,
                       c.collation_name AS collation_name,
                       ic.seed_value AS seed_value,
                       ic.increment_value AS increment_value,
                       dc.definition AS default_definition
                FROM sys.columns c
                INNER JOIN sys.types t ON t.user_type_id = c.user_type_id
                LEFT JOIN sys.identity_columns ic ON ic.object_id = c.object_id AND ic.column_id = c.column_id
                LEFT JOIN sys.default_constraints dc ON dc.parent_object_id = c.object_id AND dc.parent_column_id = c.column_id
                WHERE c.object_id = OBJECT_ID(?)
                ORDER BY c.column_id
                SQL,
            ['dbo.' . $table],
        );
    }
}
