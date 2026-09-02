<?php

declare(strict_types=1);

namespace Mahbub\SchemaTools\Support;

use Illuminate\Support\Facades\Config;

/**
 * The fixture-backed connections — those that ship a
 * `<connection>-schema.sql` file in the configured schema directory. This is
 * the universe every command works within.
 */
final class FixtureConnections
{
    public function schemaPath(): string
    {
        return Config::string('schema-tools.schema_path');
    }

    public function schemaFile(string $connection): string
    {
        return $this->schemaPath() . "/{$connection}-schema.sql";
    }

    public function viewsFile(string $connection): string
    {
        return $this->schemaPath() . "/{$connection}-views.sql";
    }

    /**
     * @return list<string>
     */
    public function all(): array
    {
        $paths = glob($this->schemaPath() . '/*-schema.sql');

        if ($paths === false) {
            return []; // @codeCoverageIgnore
        }

        return array_map(
            static fn (string $path): string => str_replace('-schema.sql', '', basename($path)),
            $paths,
        );
    }

    /**
     * Lowercase source-database name => connection name, for the fixture
     * connections. Lets a three-part SQL reference (Database.dbo.Name) be routed
     * back to the connection that owns that database.
     *
     * @return array<string, string>
     */
    public function databaseMap(): array
    {
        $map = [];

        foreach ($this->all() as $connection) {
            $database = Config::string("database.connections.{$connection}.database");
            $map[strtolower($database)] = $connection;
        }

        return $map;
    }
}
