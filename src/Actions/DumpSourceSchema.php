<?php

declare(strict_types=1);

namespace Mahbub\SchemaTools\Actions;

use Mahbub\SchemaTools\Queries\FetchPrimaryKeyColumns;
use Mahbub\SchemaTools\Queries\FetchTableColumns;
use Mahbub\SchemaTools\Queries\FetchViewDefinition;
use Mahbub\SchemaTools\Queries\ResolveObject;
use Mahbub\SchemaTools\Support\ConnectionDump;
use Mahbub\SchemaTools\Support\DumpResult;
use Mahbub\SchemaTools\Support\FixtureConnections;
use Mahbub\SchemaTools\Support\Manifest;
use Mahbub\SchemaTools\Support\SchemaFixtureParser;
use Throwable;

/**
 * Rebuilds the T-SQL fixtures from the live SQL Server the fixture-backed
 * connections point at, using the names in the curated manifest.
 *
 * Each manifest name is resolved against the catalog: base tables are
 * reconstructed from sys.columns / sys.identity_columns / sys.default_constraints
 * / sys.key_constraints, and views are pulled verbatim from sys.sql_modules. The
 * existing file's order is preserved so the git diff stays readable; a name the
 * source no longer has, or a fixture object no longer in the manifest, is dropped
 * with a warning.
 *
 * This action only reads the source and computes the fixture content; the command
 * decides whether to write it.
 *
 * @phpstan-import-type TableColumnRow from FetchTableColumns
 */
final readonly class DumpSourceSchema
{
    public function __construct(
        private FixtureConnections $connections,
        private Manifest $manifest,
        private SchemaFixtureParser $parser,
        private ResolveObject $resolveObject,
        private FetchTableColumns $fetchTableColumns,
        private FetchPrimaryKeyColumns $fetchPrimaryKeyColumns,
        private FetchViewDefinition $fetchViewDefinition,
    ) {}

    public function handle(): DumpResult
    {
        $manifest = $this->manifest->load();
        $dumps = [];

        foreach ($this->connections->all() as $connection) {
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

        if ($names === []) {
            return new ConnectionDump($connection, $schemaPath, $viewsPath, null, null, 0, 0, [], failed: false, skipped: true);
        }

        /** @var array<string, string> $tables Lowercase => canonical. */
        $tables = [];

        /** @var array<string, string> $views Lowercase => canonical. */
        $views = [];

        $warnings = [];

        try {
            foreach ($names as $name) {
                $object = $this->resolveObject->execute($connection, $name);

                if ($object === null) {
                    $warnings[] = "`{$name}` not found at source, skipped";

                    continue;
                }

                match (trim($object->type)) {
                    'U' => $tables[strtolower($object->name)] = $object->name,
                    'V' => $views[strtolower($object->name)] = $object->name,
                    default => $warnings[] = "`{$name}` is neither a table nor a view, skipped",
                };
            }

            $tableOrder = $this->orderSelection(array_keys($this->parser->parseTables($schemaPath)), $tables);
            $viewOrder = $this->orderSelection($this->parser->parseViews($viewsPath), $views);

            foreach ([...$tableOrder['dropped'], ...$viewOrder['dropped']] as $dropped) {
                $warnings[] = "`{$dropped}` in the fixture is no longer in the manifest, dropped";
            }

            $tableBlocks = array_map(fn (string $table): string => $this->reconstructTable($connection, $table), $tableOrder['ordered']);
            $viewBlocks = array_map(fn (string $view): string => $this->reconstructView($connection, $view), $viewOrder['ordered']);
        } catch (Throwable $throwable) {
            $warnings[] = "source query failed ({$throwable->getMessage()}), fixtures left untouched";

            return new ConnectionDump($connection, $schemaPath, $viewsPath, null, null, 0, 0, $warnings, failed: true, skipped: false);
        }

        return new ConnectionDump(
            connection: $connection,
            schemaPath: $schemaPath,
            viewsPath: $viewsPath,
            schemaContent: $tableBlocks === [] ? null : implode("\n\n", $tableBlocks) . "\n",
            viewsContent: $viewBlocks === [] ? null : implode("\n\n", $viewBlocks) . "\n",
            tableCount: count($tableBlocks),
            viewCount: count($viewBlocks),
            warnings: $warnings,
            failed: false,
            skipped: false,
        );
    }

    private function reconstructTable(string $connection, string $table): string
    {
        $lines = array_map($this->columnLine(...), $this->fetchTableColumns->execute($connection, $table));

        $primaryKey = $this->fetchPrimaryKeyColumns->execute($connection, $table);

        if ($primaryKey !== []) {
            $keyColumns = implode(', ', array_map($this->keyColumn(...), $primaryKey));
            $lines[] = "    PRIMARY KEY ({$keyColumns})";
        }

        return "CREATE TABLE [dbo].[{$table}] (\n" . implode(",\n", $lines) . "\n);";
    }

    /**
     * @param  object{name: string}  $row
     */
    private function keyColumn(object $row): string
    {
        return "[{$row->name}]";
    }

    private function reconstructView(string $connection, string $view): string
    {
        $definition = rtrim(rtrim((string) $this->fetchViewDefinition->execute($connection, $view)), ';');

        return "DROP VIEW IF EXISTS [dbo].[{$view}];\n\n{$definition};";
    }

    /**
     * @param  TableColumnRow  $column
     */
    private function columnLine(object $column): string
    {
        $line = "    [{$column->name}] " . $this->formatType(
            $column->type_name,
            (int) $column->max_length,
            (int) $column->numeric_precision,
            (int) $column->numeric_scale,
        );

        if ((int) $column->is_identity === 1) {
            $line .= ' IDENTITY(' . (int) $column->seed_value . ',' . (int) $column->increment_value . ')';
        } elseif ($column->collation_name !== null) {
            $line .= " COLLATE {$column->collation_name}";
        }

        $line .= (int) $column->is_nullable === 1 ? ' NULL' : ' NOT NULL';

        if ($column->default_definition !== null) {
            $line .= " DEFAULT {$column->default_definition}";
        }

        return $line;
    }

    private function formatType(string $typeName, int $maxLength, int $precision, int $scale): string
    {
        $type = strtolower($typeName);

        return match ($type) {
            'nvarchar', 'nchar' => $type . '(' . ($maxLength === -1 ? 'MAX' : (int) ($maxLength / 2)) . ')',
            'varchar', 'char', 'binary', 'varbinary' => $type . '(' . ($maxLength === -1 ? 'MAX' : $maxLength) . ')',
            'decimal', 'numeric' => "{$type}({$precision},{$scale})",
            default => $type,
        };
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
