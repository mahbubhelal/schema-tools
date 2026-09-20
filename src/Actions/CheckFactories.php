<?php

declare(strict_types=1);

namespace Mahbub\SchemaTools\Actions;

use Closure;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\Sequence;
use Illuminate\Database\Eloquent\Model;
use Mahbub\SchemaTools\Support\ColumnType;
use Mahbub\SchemaTools\Support\FactoryCheckResult;
use Mahbub\SchemaTools\Support\FixtureConnections;
use Mahbub\SchemaTools\Support\Manifest;
use Mahbub\SchemaTools\Support\Report;
use Mahbub\SchemaTools\Support\ScanPaths;
use Mahbub\SchemaTools\Support\SchemaFixtureParser;
use Mahbub\SchemaTools\Support\Table;
use ReflectionClass;
use Symfony\Component\Finder\Finder;
use Throwable;

/**
 * Checks every model factory on a fixture-backed connection against the DDL.
 * A base definition() must contain exactly the columns an INSERT would be
 * rejected without. Reported per factory:
 *   1. a required column (NOT NULL, no default, not identity/auto-increment) missing
 *   2. a nullable column present — belongs in a state
 *   3. a NOT NULL column with a database default present — omitting it cannot error
 *   4. a value whose PHP type does not fit the column's SQL type
 *   5. an entry for a column the DDL does not have
 *   6. a table not tracked in the manifest
 */
final readonly class CheckFactories
{
    public function __construct(
        private FixtureConnections $connections,
        private Manifest $manifest,
        private SchemaFixtureParser $parser,
        private ScanPaths $scanPaths,
    ) {}

    public function handle(): FactoryCheckResult
    {
        $connections = $this->connections->all();
        $manifest = $this->manifest->load();

        /** @var array<string, array<string, Table>> $schemas */
        $schemas = [];

        foreach ($connections as $connection) {
            $schemas[$connection] = $this->parser->parseTables($this->connections->schemaFile($connection));
        }

        $checked = 0;
        $reports = [];

        /** @var array<string, bool> $skippedConnections */
        $skippedConnections = [];

        foreach ($this->factories() as $factory) {
            $modelClass = $factory->modelName();
            /** @var Model $model */
            $model = new $modelClass;
            $connection = $model->getConnectionName();

            if ($connection === null || !in_array($connection, $connections, true)) {
                $skippedConnections[$connection ?? '(default)'] = true;

                continue;
            }

            $table = $model->getTable();
            $ddl = $this->findTable($schemas[$connection], $table);

            $issues = [];

            if (!in_array(strtolower($table), array_map(strtolower(...), $manifest[$connection] ?? []), true)) {
                $issues[] = "table `{$table}` is not tracked in the manifest";
            }

            if (!$ddl instanceof Table) {
                $issues[] = "table `{$table}` is not in {$connection}-schema.sql";
            } else {
                array_push($issues, ...$this->definitionIssues($factory, $ddl, $connection, $table));
            }

            $checked++;

            if ($issues !== []) {
                $reports[] = new Report($factory::class, "{$connection}.{$table}", $issues);
            }
        }

        return new FactoryCheckResult(
            factories: $reports,
            checked: $checked,
            skippedConnections: array_keys($skippedConnections),
        );
    }

    /**
     * The issues in a factory's definition() against its table's DDL.
     *
     * @param  Factory<Model>  $factory
     * @return list<string>
     */
    private function definitionIssues(Factory $factory, Table $ddl, string $connection, string $table): array
    {
        try {
            $definition = $factory->definition();
        } catch (Throwable $throwable) {
            return ['definition() could not be evaluated: ' . $throwable->getMessage()];
        }

        $issues = [];

        foreach ($definition as $column => $value) {
            if ($value instanceof Closure) {
                try {
                    $value = $value($definition);
                } catch (Throwable) {
                    $issues[] = "{$column}: closure could not be evaluated, type not checked";

                    continue;
                }
            }

            $ddlColumn = $ddl->canonicalName($column);

            if ($ddlColumn === null) {
                $issues[] = "{$column}: not a column of {$connection}.{$table}";

                continue;
            }

            if ($ddlColumn !== $column) {
                $issues[] = "{$column}: case mismatch — the DDL declares `{$ddlColumn}`";
            }

            $meta = $ddl->columns[$ddlColumn];

            if ($meta->nullable) {
                $issues[] = "{$column}: nullable ({$meta->type}) — must not be in definition(), move to a state";
            } elseif (!$meta->isRequired()) {
                $issues[] = "{$column}: has a database default or is an identity — omitting it cannot error, drop from definition()";
            }

            if ($value instanceof Factory || $value instanceof Sequence) {
                continue;
            }

            if ($value === null) {
                if (!$meta->nullable) {
                    $issues[] = "{$column}: null value for NOT NULL {$meta->type}";
                }

                continue;
            }

            if (!ColumnType::valueFits($value, $meta->type)) {
                $issues[] = "{$column}: " . ColumnType::describe($value) . ' does not fit ' . $meta->type;
            }
        }

        $definitionColumns = array_map(strtolower(...), array_keys($definition));

        foreach ($ddl->columns as $column => $meta) {
            if ($meta->isRequired() && !in_array(strtolower($column), $definitionColumns, true)) {
                $issues[] = "{$column}: required ({$meta->type} NOT NULL, no default) but missing from definition()";
            }
        }

        return $issues;
    }

    /**
     * The concrete factories under the configured factories paths, in name
     * order. A class that cannot be autoloaded, reflected or instantiated is
     * skipped.
     *
     * @return list<Factory<Model>>
     */
    private function factories(): array
    {
        $directories = $this->scanPaths->resolve('factories_path');

        if ($directories === []) {
            return [];
        }

        $factories = [];

        foreach (Finder::create()->in($directories)->files()->name('*.php')->sortByName() as $file) {
            if (preg_match('/^namespace ([^;]+);/m', $file->getContents(), $namespaceMatch) !== 1) {
                continue;
            }

            $class = $namespaceMatch[1] . '\\' . $file->getBasename('.php');

            try {
                if (!class_exists($class)) {
                    continue;
                }

                $reflection = new ReflectionClass($class);

                if ($reflection->isAbstract() || !$reflection->isSubclassOf(Factory::class)) {
                    continue;
                }

                /** @var Factory<Model> $factory */
                $factory = $reflection->newInstance();
            } catch (Throwable) {
                continue;
            }

            $factories[] = $factory;
        }

        return $factories;
    }

    /**
     * @param  array<string, Table>  $tables
     */
    private function findTable(array $tables, string $name): ?Table
    {
        foreach ($tables as $table => $shape) {
            if (strcasecmp($table, $name) === 0) {
                return $shape;
            }
        }

        return null;
    }
}
