<?php

declare(strict_types=1);

namespace Mahbub\SchemaTools\Actions;

use Illuminate\Support\Facades\Config;
use Mahbub\SchemaTools\Dialects\Dialect;
use Mahbub\SchemaTools\Dialects\Dialects;
use Mahbub\SchemaTools\Support\ConnectionDump;
use Mahbub\SchemaTools\Support\DumpResult;
use Mahbub\SchemaTools\Support\FixtureConnections;
use Mahbub\SchemaTools\Support\Manifest;
use Mahbub\SchemaTools\Support\ObjectKind;
use Mahbub\SchemaTools\Support\OrderedNames;
use Mahbub\SchemaTools\Support\SchemaFixtureParser;
use Mahbub\SchemaTools\Support\SourceObject;
use Throwable;

/**
 * Rebuilds the fixtures from the live database each fixture-backed connection
 * points at, using the names in the curated manifest.
 *
 * Each manifest name is resolved against the source catalog and rebuilt by the
 * dialect the connection's driver calls for (SQL Server or MySQL/MariaDB) —
 * tables into `<connection>-schema.sql`, views into `<connection>-views.sql`.
 * The existing file's order is preserved so the git diff stays readable; a name
 * the source no longer has, or a fixture object no longer in the manifest, is
 * dropped with a warning. Connections marked `hand_maintained` in
 * `schema-tools.php` are audited like any other but never dumped.
 *
 * This action only reads the source and computes the fixture content; the command
 * decides whether to write it.
 */
final readonly class DumpSourceSchema
{
    public function __construct(
        private FixtureConnections $fixtureConnections,
        private Manifest $manifest,
        private SchemaFixtureParser $schemaFixtureParser,
        private Dialects $dialects,
    ) {}

    /**
     * @param  list<string>  $only  Restrict the run to these connections; empty means all.
     */
    public function handle(array $only = []): DumpResult
    {
        $manifestData = $this->manifest->load();
        $dumps = [];

        foreach ($this->fixtureConnections->all() as $connection) {
            if ($only !== [] && !in_array($connection, $only, true)) {
                continue;
            }

            $dumps[] = $this->dumpConnection($connection, $manifestData->names($connection));
        }

        return new DumpResult($dumps);
    }

    /**
     * @param  list<string>  $names
     */
    private function dumpConnection(string $connection, array $names): ConnectionDump
    {
        $schemaPath = $this->fixtureConnections->schemaFile($connection);
        $viewsPath = $this->fixtureConnections->viewsFile($connection);

        if (Config::boolean("schema-tools.connections.{$connection}.hand_maintained", false)) {
            return ConnectionDump::skipped($connection, $schemaPath, $viewsPath, 'fixtures are maintained by hand');
        }

        if ($names === []) {
            return ConnectionDump::skipped($connection, $schemaPath, $viewsPath, 'no manifest entries');
        }

        $dialect = $this->dialects->forConnection($connection);

        if (!$dialect instanceof Dialect) {
            $driver = Config::get("database.connections.{$connection}.driver");
            $warning = 'driver `' . (is_string($driver) ? $driver : '?') . '` is not supported (mysql, mariadb, sqlsrv), fixtures left untouched';

            return ConnectionDump::failed($connection, $schemaPath, $viewsPath, [$warning]);
        }

        /** @var array<string, string> Lowercase => canonical. */
        $tables = [];

        /** @var array<string, string> Lowercase => canonical. */
        $views = [];

        $warnings = [];

        try {
            foreach ($names as $name) {
                $sourceObject = $dialect->resolve($connection, $name);

                if (!$sourceObject instanceof SourceObject) {
                    $warnings[] = "`{$name}` not found at source, skipped";

                    continue;
                }

                match ($sourceObject->kind) {
                    ObjectKind::Table => $tables[strtolower($sourceObject->name)] = $sourceObject->name,
                    ObjectKind::View => $views[strtolower($sourceObject->name)] = $sourceObject->name,
                    ObjectKind::Other => $warnings[] = "`{$name}` is neither a table nor a view, skipped",
                };
            }

            $tableOrder = $this->orderSelection(array_keys($this->schemaFixtureParser->parseTables($schemaPath)), $tables);
            $viewOrder = $this->orderSelection($this->schemaFixtureParser->parseViews($viewsPath), $views);

            foreach ([...$tableOrder->dropped, ...$viewOrder->dropped] as $dropped) {
                $warnings[] = "`{$dropped}` in the fixture is no longer in the manifest, dropped";
            }

            $tableBlocks = array_map(static fn (string $table): string => $dialect->tableBlock($connection, $table), $tableOrder->ordered);
            $viewBlocks = array_map(static fn (string $view): string => $dialect->viewBlock($connection, $view), $viewOrder->ordered);
        } catch (Throwable $throwable) {
            $warnings[] = "source query failed ({$throwable->getMessage()}), fixtures left untouched";

            return ConnectionDump::failed($connection, $schemaPath, $viewsPath, $warnings);
        }

        return ConnectionDump::dumped(
            connection: $connection,
            schemaPath: $schemaPath,
            viewsPath: $viewsPath,
            schemaContent: $tableBlocks === [] ? null : $dialect->schemaFixture($connection, $tableBlocks),
            viewsContent: $viewBlocks === [] ? null : $dialect->viewsFixture($viewBlocks),
            tableCount: count($tableBlocks),
            viewCount: count($viewBlocks),
            warnings: $warnings,
        );
    }

    /**
     * @param  list<string>  $existing  Names already in the fixture, in file order.
     * @param  array<string, string>  $selected  Lowercase name => canonical name.
     */
    private function orderSelection(array $existing, array $selected): OrderedNames
    {
        $ordered = [];
        $dropped = [];

        foreach ($existing as $name) {
            if (array_key_exists(strtolower($name), $selected)) {
                $ordered[] = $selected[strtolower($name)];
            } else {
                $dropped[] = $name;
            }
        }

        $new = array_values(array_diff_key($selected, array_flip(array_map(strtolower(...), $existing))));
        sort($new);

        return new OrderedNames(ordered: [...$ordered, ...$new], dropped: $dropped);
    }
}
