<?php

declare(strict_types=1);

namespace Mahbub\SchemaTools\Queries\SqlServer;

use Illuminate\Database\DatabaseManager;
use UnexpectedValueException;

/**
 * The T-SQL definition of a source view, verbatim from the catalog.
 */
final readonly class FetchViewDefinition
{
    public function __construct(private DatabaseManager $databaseManager) {}

    public function execute(string $connection, string $view): string
    {
        /** @var object{definition: string|null}|null */
        $row = $this->databaseManager->connection($connection)->selectOne(
            'SELECT m.definition AS definition FROM sys.sql_modules m WHERE m.object_id = OBJECT_ID(?)',
            ['dbo.' . $view],
        );

        return $row->definition
            ?? throw new UnexpectedValueException("sys.sql_modules has no definition for view [dbo].[{$view}]; it is missing or encrypted.");
    }
}
