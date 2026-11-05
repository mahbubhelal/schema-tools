<?php

declare(strict_types=1);

namespace Mahbub\SchemaTools\Queries\MySql;

use Illuminate\Database\DatabaseManager;
use Mahbub\SchemaTools\Support\Identifier;
use UnexpectedValueException;

/**
 * The CREATE VIEW statement MySQL itself would use to recreate a view, from
 * SHOW CREATE VIEW.
 */
final readonly class FetchCreateView
{
    public function __construct(private DatabaseManager $databaseManager) {}

    public function execute(string $connection, string $view): string
    {
        $query = 'SHOW CREATE VIEW ' . Identifier::backtick($view);
        $row = (array) $this->databaseManager->connection($connection)->selectOne($query);
        $statement = $row['Create View'] ?? null;

        if (!is_string($statement)) {
            throw new UnexpectedValueException("{$query} returned no CREATE VIEW statement.");
        }

        return $statement;
    }
}
