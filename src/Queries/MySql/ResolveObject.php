<?php

declare(strict_types=1);

namespace Mahbub\SchemaTools\Queries\MySql;

use Illuminate\Database\DatabaseManager;

/**
 * Resolves a name to its information_schema entry (name + table type) in the
 * connection's own database, or null when there is no such table or view.
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
            'SELECT TABLE_NAME AS name, TABLE_TYPE AS type FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [$name],
        );
    }
}
