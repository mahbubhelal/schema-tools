<?php

declare(strict_types=1);

namespace Mahbub\SchemaTools\Queries\SqlServer;

use Illuminate\Database\DatabaseManager;

/**
 * The T-SQL definition of a source view, verbatim from the catalog. Null when
 * the source has no such view.
 */
final readonly class FetchViewDefinition
{
    public function __construct(private DatabaseManager $database) {}

    public function execute(string $connection, string $view): ?string
    {
        /** @var object{definition: string}|null $row */
        $row = $this->database->connection($connection)->selectOne(
            'SELECT m.definition AS definition FROM sys.sql_modules m WHERE m.object_id = OBJECT_ID(?)',
            ['dbo.' . $view],
        );

        return $row?->definition;
    }
}
