<?php

declare(strict_types=1);

namespace Mahbub\SchemaTools\Actions;

use Closure;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\Sequence;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Config;
use Mahbub\SchemaTools\Support\ColumnType;
use Mahbub\SchemaTools\Support\FactoryCheckResult;
use Mahbub\SchemaTools\Support\FixtureConnections;
use Mahbub\SchemaTools\Support\Manifest;
use Mahbub\SchemaTools\Support\Regex;
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
        private FixtureConnections $fixtureConnections,
        private Manifest $manifest,
        private SchemaFixtureParser $schemaFixtureParser,
        private ScanPaths $scanPaths,
    ) {}

    public function handle(): FactoryCheckResult
    {
        $connections = $this->fixtureConnections->all();
        $manifestData = $this->manifest->load();

        /** @var array<string, array<string, Table>> */
        $schemas = [];

        foreach ($connections as $connection) {
            $schemas[$connection] = $this->schemaFixtureParser->parseTables($this->fixtureConnections->schemaFile($connection));
        }

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
            $ddl = $this->findTable($schemas[$connection], $table);

            $issues = [];

            if (!in_array(strtolower($table), array_map(strtolower(...), $manifestData->names($connection)), true)) {
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
     * order. A file without a namespace, or one that declares no class of its
     * own name, holds no factory; a factory that fails to load or construct
     * stops the check with its own error.
     *
     * @return list<Factory<Model>>
     */
    private function factories(): array
    {
        $directories = $this->scanPaths->resolve('factories');

        if ($directories === []) {
            return [];
        }

        $factories = [];

        foreach (Finder::create()->in($directories)->files()->name('*.php')->sortByName() as $file) {
            if (preg_match('/^namespace ([^;]+);/m', $file->getContents(), $namespaceMatch) !== 1) {
                Regex::failOnError();

                continue;
            }

            $class = $namespaceMatch[1] . '\\' . $file->getBasename('.php');

            if (!$this->isConcreteFactory($class)) {
                continue;
            }

            $factories[] = new ReflectionClass($class)->newInstance();
        }

        return $factories;
    }

    /**
     * @phpstan-assert-if-true class-string<Factory<Model>> $class
     */
    private function isConcreteFactory(string $class): bool
    {
        if (!class_exists($class)) {
            return false;
        }

        $reflection = new ReflectionClass($class);

        return !$reflection->isAbstract() && $reflection->isSubclassOf(Factory::class);
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
