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
 * dropped with a warning. Connections listed in `schema-tools.hand_maintained`
 * are audited like any other but never dumped.
 *
 * This action only reads the source and computes the fixture content; the command
 * decides whether to write it.
 */
final readonly class DumpSourceSchema
{
    public function __construct(
        private FixtureConnections $connections,
        private Manifest $manifest,
        private SchemaFixtureParser $parser,
        private Dialects $dialects,
    ) {}

    /**
     * @param  list<string>  $only  Restrict the run to these connections; empty means all.
     */
    public function handle(array $only = []): DumpResult
    {
        $manifest = $this->manifest->load();
        $dumps = [];

        foreach ($this->connections->all() as $connection) {
            if ($only !== [] && !in_array($connection, $only, true)) {
                continue;
            }

            $dumps[] = $this->dumpConnection($connection, $manifest[$connection] ?? []);
        }

        return new DumpResult($dumps);
    }

    /**
     * @param  list<string>  $names
     */
    private function dumpConnection(string $connection, array $names): ConnectionDump
    {
        $schemaPath = $this->connections->schemaFile($connection);
        $viewsPath = $this->connections->viewsFile($connection);

        if (in_array($connection, Config::array('schema-tools.hand_maintained', []), true)) {
            return new ConnectionDump($connection, $schemaPath, $viewsPath, null, null, 0, 0, [], failed: false, skipped: true, skipReason: 'fixtures are maintained by hand');
        }

        if ($names === []) {
            return new ConnectionDump($connection, $schemaPath, $viewsPath, null, null, 0, 0, [], failed: false, skipped: true, skipReason: 'no manifest entries');
        }

        $dialect = $this->dialects->forConnection($connection);

        if (!$dialect instanceof Dialect) {
            $driver = Config::get("database.connections.{$connection}.driver");
            $warning = 'driver `' . (is_string($driver) ? $driver : '?') . '` is not supported (mysql, mariadb, sqlsrv), fixtures left untouched';

            return new ConnectionDump($connection, $schemaPath, $viewsPath, null, null, 0, 0, [$warning], failed: true, skipped: false);
        }

        /** @var array<string, string> $tables Lowercase => canonical. */
        $tables = [];

        /** @var array<string, string> $views Lowercase => canonical. */
        $views = [];

        $warnings = [];

        try {
            foreach ($names as $name) {
                $object = $dialect->resolve($connection, $name);

                if (!$object instanceof SourceObject) {
                    $warnings[] = "`{$name}` not found at source, skipped";

                    continue;
                }

                match ($object->kind) {
                    ObjectKind::Table => $tables[strtolower($object->name)] = $object->name,
                    ObjectKind::View => $views[strtolower($object->name)] = $object->name,
                    ObjectKind::Other => $warnings[] = "`{$name}` is neither a table nor a view, skipped",
                };
            }

            $tableOrder = $this->orderSelection(array_keys($this->parser->parseTables($schemaPath)), $tables);
            $viewOrder = $this->orderSelection($this->parser->parseViews($viewsPath), $views);

            foreach ([...$tableOrder['dropped'], ...$viewOrder['dropped']] as $dropped) {
                $warnings[] = "`{$dropped}` in the fixture is no longer in the manifest, dropped";
            }

            $tableBlocks = array_map(static fn (string $table): string => $dialect->tableBlock($connection, $table), $tableOrder['ordered']);
            $viewBlocks = array_map(static fn (string $view): string => $dialect->viewBlock($connection, $view), $viewOrder['ordered']);
        } catch (Throwable $throwable) {
            $warnings[] = "source query failed ({$throwable->getMessage()}), fixtures left untouched";

            return new ConnectionDump($connection, $schemaPath, $viewsPath, null, null, 0, 0, $warnings, failed: true, skipped: false);
        }

        return new ConnectionDump(
            connection: $connection,
            schemaPath: $schemaPath,
            viewsPath: $viewsPath,
            schemaContent: $tableBlocks === [] ? null : $dialect->schemaFixture($connection, $tableBlocks),
            viewsContent: $viewBlocks === [] ? null : $dialect->viewsFixture($viewBlocks),
            tableCount: count($tableBlocks),
            viewCount: count($viewBlocks),
            warnings: $warnings,
            failed: false,
            skipped: false,
        );
    }

    /**
     * @param  list<string>  $existing  Names already in the fixture, in file order.
     * @param  array<string, string>  $selected  Lowercase name => canonical name.
     * @return array{ordered: list<string>, dropped: list<string>}
     */
    private function orderSelection(array $existing, array $selected): array
    {
        $ordered = [];

        foreach ($existing as $name) {
            if (array_key_exists(strtolower($name), $selected)) {
                $ordered[] = $selected[strtolower($name)];
            }
        }

        $orderedLower = array_map(strtolower(...), $ordered);
        $new = [];

        foreach ($selected as $lower => $canonical) {
            if (!in_array($lower, $orderedLower, true)) {
                $new[] = $canonical;
            }
        }

        sort($new);

        $dropped = array_values(array_filter(
            $existing,
            static fn (string $name): bool => !array_key_exists(strtolower($name), $selected),
        ));

        return ['ordered' => [...$ordered, ...$new], 'dropped' => $dropped];
    }
}
