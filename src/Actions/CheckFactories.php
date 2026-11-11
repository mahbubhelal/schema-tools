<?php

declare(strict_types=1);

namespace Mahbub\SchemaTools\Actions;

use Closure;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\Sequence;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Config;
use Mahbub\SchemaTools\Support\ClassScanner;
use Mahbub\SchemaTools\Support\ColumnType;
use Mahbub\SchemaTools\Support\FactoryCheckResult;
use Mahbub\SchemaTools\Support\FixtureModels;
use Mahbub\SchemaTools\Support\Manifest;
use Mahbub\SchemaTools\Support\Report;
use Mahbub\SchemaTools\Support\ScanPaths;
use Mahbub\SchemaTools\Support\Table;
use Throwable;

/**
 * Checks every model factory on a fixture-backed connection against the DDL.
 * A base definition() must contain exactly the columns an INSERT would be
 * rejected without. Every checked factory is reported, passing or not, and a
 * factory whose model is on a connection without a fixture is reported as
 * skipped. Issues per factory:
 *   1. a required column (NOT NULL, no default, not identity/auto-increment) missing
 *   2. a nullable column present — belongs in a state
 *   3. a NOT NULL column with a database default, or an identity, present — omitting it cannot error
 *   4. a value whose PHP type does not fit the column's SQL type
 *   5. an entry for a column the DDL does not have
 *   6. a table not tracked in the manifest
 */
final readonly class CheckFactories
{
    public function __construct(
        private FixtureModels $fixtureModels,
        private Manifest $manifest,
        private ScanPaths $scanPaths,
        private ClassScanner $classScanner,
    ) {}

    public function handle(): FactoryCheckResult
    {
        $connections = $this->fixtureModels->connections();
        $manifestNames = $this->manifest->load()->all();

        $reports = [];
        $skipped = [];

        foreach ($this->factories() as $factory) {
            $modelClass = $factory->modelName();
            /** @var Model */
            $model = new $modelClass;
            $connection = $model->getConnectionName();

            if ($connection === null || !in_array($connection, $connections, true)) {
                $skipped[] = new Report($factory::class, ($connection ?? Config::string('database.default')) . '.' . $model->getTable(), []);

                continue;
            }

            $table = $model->getTable();
            $ddl = $this->fixtureModels->table($connection, $table);
            $tracked = array_any($manifestNames[$connection] ?? [], static fn (string $name): bool => strcasecmp($name, $table) === 0);

            $issues = [];

            if (!$tracked) {
                $issues[] = "table `{$table}` is not tracked in the manifest";
            }

            if (!$ddl instanceof Table) {
                $issues[] = "table `{$table}` is not in {$connection}-schema.sql";
            } else {
                array_push($issues, ...$this->definitionIssues($factory, $ddl, $connection, $table));
            }

            $reports[] = new Report($factory::class, "{$connection}.{$table}", $issues);
        }

        return new FactoryCheckResult(factories: $reports, skipped: $skipped);
    }

    /**
     * @param  Factory<Model>  $factory
     *
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
            array_push($issues, ...$this->definitionEntryIssues($column, $value, $definition, $ddl, $connection, $table));
        }

        $definitionColumns = array_map(strtolower(...), array_keys($definition));

        foreach ($ddl->columns as $column => $ddlColumn) {
            if ($ddlColumn->isRequired() && !in_array(strtolower($column), $definitionColumns, true)) {
                $issues[] = "{$column}: required ({$ddlColumn->type} NOT NULL, no default) but missing from definition()";
            }
        }

        return $issues;
    }

    /**
     * @param  array<string, mixed>  $definition
     *
     * @return list<string>
     */
    private function definitionEntryIssues(string $column, mixed $value, array $definition, Table $ddl, string $connection, string $table): array
    {
        if ($value instanceof Closure) {
            try {
                $value = $value($definition);
            } catch (Throwable) {
                return ["{$column}: closure could not be evaluated, type not checked"];
            }
        }

        $columnName = $ddl->canonicalName($column);

        if ($columnName === null) {
            return ["{$column}: not a column of {$connection}.{$table}"];
        }

        $issues = [];

        if ($columnName !== $column) {
            $issues[] = "{$column}: case mismatch — the DDL declares `{$columnName}`";
        }

        $ddlColumn = $ddl->columns[$columnName];

        if ($ddlColumn->nullable) {
            $issues[] = "{$column}: nullable ({$ddlColumn->type}) — must not be in definition(), move to a state";
        } elseif ($ddlColumn->isIdentity) {
            $issues[] = "{$column}: is an identity the server assigns — drop from definition()";
        } elseif ($ddlColumn->hasDefault) {
            $issues[] = "{$column}: has a database default — omitting it cannot error, drop from definition()";
        }

        if ($value instanceof Factory || $value instanceof Sequence) {
            return $issues;
        }

        if ($value === null) {
            return $ddlColumn->nullable ? $issues : [...$issues, "{$column}: null value for NOT NULL {$ddlColumn->type}"];
        }

        if (!ColumnType::valueFits($value, $ddlColumn->type)) {
            $issues[] = "{$column}: " . ColumnType::describe($value) . ' does not fit ' . $ddlColumn->type;
        }

        return $issues;
    }

    /**
     * The concrete factories under the configured factories paths, in name
     * order (see ClassScanner); a factory that fails to construct stops the
     * check with its own error.
     *
     * @return list<Factory<Model>>
     */
    private function factories(): array
    {
        $directories = $this->scanPaths->resolve('factories');

        if ($directories === []) {
            return [];
        }

        return array_map(
            static fn (string $class): Factory => new $class,
            array_keys($this->classScanner->concreteSubclasses($directories, Factory::class)),
        );
    }
}
