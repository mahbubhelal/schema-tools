<?php

declare(strict_types=1);

namespace Mahbub\SchemaTools\Queries\MySql;

use Illuminate\Database\DatabaseManager;
use Mahbub\SchemaTools\Support\Identifier;

/**
 * The CREATE VIEW statement MySQL itself would use to recreate a view, from
 * SHOW CREATE VIEW. Null when the row carries no such statement.
 */
final readonly class FetchCreateView
{
    public function __construct(private DatabaseManager $database) {}

    public function execute(string $connection, string $view): ?string
    {
        $row = (array) $this->database->connection($connection)->selectOne(
            'SHOW CREATE VIEW ' . Identifier::backtick($view),
        );

        $statement = $row['Create View'] ?? null;

        return is_string($statement) ? $statement : null;
    }
}
