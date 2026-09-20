<?php

declare(strict_types=1);

namespace Mahbub\SchemaTools\Queries\SqlServer;

use Illuminate\Database\DatabaseManager;

/**
 * Resolves an object name to its catalog entry (name + type) at the source,
 * or null when the source has no such object.
 */
final readonly class ResolveObject
{
    public function __construct(private DatabaseManager $database) {}

    /**
     * @return object{name: string, type: string}|null
     */
    public function execute(string $connection, string $name): ?object
    {
        /** @var object{name: string, type: string}|null */
        return $this->database->connection($connection)->selectOne(
            'SELECT o.name AS name, o.type AS type FROM sys.objects o WHERE o.object_id = OBJECT_ID(?)',
            ['dbo.' . $name],
        );
    }
}
