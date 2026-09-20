<?php

declare(strict_types=1);

namespace Mahbub\SchemaTools\Actions;

use Illuminate\Database\Eloquent\Model;
use Mahbub\SchemaTools\Support\ColumnType;
use Mahbub\SchemaTools\Support\FixtureConnections;
use Mahbub\SchemaTools\Support\Manifest;
use Mahbub\SchemaTools\Support\ModelAuditResult;
use Mahbub\SchemaTools\Support\ModelScanner;
use Mahbub\SchemaTools\Support\Report;
use Mahbub\SchemaTools\Support\ScanPaths;
use Mahbub\SchemaTools\Support\SchemaFixtureParser;
use Mahbub\SchemaTools\Support\Table;
use ReflectionClass;

/**
 * Verifies every concrete Eloquent model on a fixture-backed connection declares
 * its connection, table, primaryKey, keyType, incrementing and timestamps
 * correctly against the DDL — a model may declare no key when its table has no
 * primary key, and a model mapped onto a view in the views fixture must declare
 * no key, no incrementing and no timestamps — then cross-checks the
 * manifest: every listed name must exist in the connection's schema or views
 * fixture, and every fixture object must appear in the manifest.
 */
final readonly class AuditModels
{
    public function __construct(
        private FixtureConnections $connections,
        private Manifest $manifest,
        private ModelScanner $modelScanner,
        private SchemaFixtureParser $parser,
        private ScanPaths $scanPaths,
    ) {}

    public function handle(): ModelAuditResult
    {
        $connections = $this->connections->all();

        /** @var array<string, array<string, Table>> $fixtures */
        $fixtures = [];

        /** @var array<string, list<string>> $views */
        $views = [];

        foreach ($connections as $connection) {
            $fixtures[$connection] = $this->parser->parseTables($this->connections->schemaFile($connection));
            $views[$connection] = $this->parser->parseViews($this->connections->viewsFile($connection));
        }

        $reports = [];
        $skipped = 0;

        foreach ($this->scanPaths->resolve('models_path') as $path) {
            foreach ($this->modelScanner->scan($path) as $scanned) {
                $model = $scanned->model;
                $connection = $model->getConnectionName();

                if ($connection === null || !in_array($connection, $connections, true)) {
                    $skipped++;

                    continue;
                }

                $table = $model->getTable();
                $ddl = $this->findTable($fixtures[$connection], $table);

                $issues = [];

                if (!$this->declares($scanned->reflection, 'connection')) {
                    $issues[] = 'connection not declared on the model';
                }

                if (!$ddl instanceof Table) {
                    if ($this->isView($views[$connection], $table)) {
                        array_push($issues, ...$this->viewIssues($scanned->reflection, $model));
                    } else {
                        $issues[] = "table `{$table}` not found in {$connection}-schema.sql";
                    }

                    $reports[] = new Report($scanned->class, "{$connection}.{$table}", $issues);

                    continue;
                }

                $keyName = $this->keyName($scanned->reflection, $model);
                $pk = $ddl->effectivePrimaryKey();

                if ($keyName === null) {
                    if (count($pk) === 1) {
                        $issues[] = "model declares no pk but DDL has pk `{$pk[0]}`";
                    }

                    if ($model->getIncrementing()) {
                        $issues[] = 'model has no pk but incrementing is true';
                    }
                } elseif (count($pk) === 0) {
                    $issues[] = "DDL has NO primary key but model declares `{$keyName}`";
                } elseif (count($pk) > 1) {
                    $issues[] = 'DDL has COMPOSITE pk (' . implode(', ', $pk) . "); model getKeyName()=`{$keyName}`";
                } else {
                    $actualPk = $pk[0];

                    if ($keyName !== $actualPk) {
                        $issues[] = "pk mismatch: DDL `{$actualPk}` vs model `{$keyName}`";
                    }

                    $pkColumn = $ddl->column($actualPk);
                    $isIdentity = $pkColumn->isIdentity ?? false;

                    if ($model->getIncrementing() !== $isIdentity) {
                        $issues[] = sprintf(
                            'incrementing mismatch: DDL %s vs model %s',
                            $isIdentity ? 'IDENTITY' : 'NOT identity',
                            $model->getIncrementing() ? 'true' : 'false',
                        );
                    }

                    $pkType = $pkColumn->type ?? '';
                    $expectedKeyType = ColumnType::keyType($pkType);

                    if ($model->getKeyType() !== $expectedKeyType) {
                        $issues[] = "keyType mismatch: DDL {$pkType} => `{$expectedKeyType}` vs model `{$model->getKeyType()}`";
                    }
                }

                $hasTimestampColumns = array_key_exists($model->getCreatedAtColumn() ?? 'created_at', $ddl->columns)
                    && array_key_exists($model->getUpdatedAtColumn() ?? 'updated_at', $ddl->columns);

                if ($model->usesTimestamps() && !$hasTimestampColumns) {
                    $issues[] = 'model uses timestamps but the DDL lacks the columns';
                }

                if (!$model->usesTimestamps() && !$this->declares($scanned->reflection, 'timestamps')) {
                    $issues[] = 'timestamps disabled but not declared on the model';
                }

                $reports[] = new Report($scanned->class, "{$connection}.{$table}", $issues);
            }
        }

        return new ModelAuditResult(
            models: $reports,
            manifestIssues: $this->manifestIssues($connections, $fixtures, $views),
            skipped: $skipped,
        );
    }

    /**
     * Whether the model (or a parent of its own) declares the property, rather
     * than inheriting Eloquent's default.
     *
     * @param  ReflectionClass<Model>  $reflection
     */
    private function declares(ReflectionClass $reflection, string $property): bool
    {
        return !str_starts_with($reflection->getProperty($property)->getDeclaringClass()->getName(), 'Illuminate\\');
    }

    /**
     * The key the model declares, or null when it declares none. Eloquent types
     * getKeyName() as a string, so the property itself is consulted.
     *
     * @param  ReflectionClass<Model>  $reflection
     */
    private function keyName(ReflectionClass $reflection, Model $model): ?string
    {
        $declared = $reflection->getProperty('primaryKey')->getValue($model);

        return is_string($declared) && $declared !== '' ? $model->getKeyName() : null;
    }

    /**
     * @param  list<string>  $views
     */
    private function isView(array $views, string $table): bool
    {
        return in_array(strtolower($table), array_map(strtolower(...), $views), true);
    }

    /**
     * A model mapped onto a view has nothing to key on and nothing to
     * time-stamp, and must say so — Eloquent's defaults assume an
     * auto-incrementing `id` and `created_at` / `updated_at` columns.
     *
     * @param  ReflectionClass<Model>  $reflection
     * @return list<string>
     */
    private function viewIssues(ReflectionClass $reflection, Model $model): array
    {
        $issues = [];

        if ($this->keyName($reflection, $model) !== null) {
            $issues[] = 'view-backed model must declare `$primaryKey = null`';
        }

        if ($model->getIncrementing()) {
            $issues[] = 'view-backed model must declare `$incrementing = false`';
        }

        if ($model->usesTimestamps()) {
            $issues[] = 'view-backed model must declare `$timestamps = false`';
        }

        return $issues;
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

    /**
     * @param  list<string>  $connections
     * @param  array<string, array<string, Table>>  $fixtures
     * @param  array<string, list<string>>  $views
     * @return list<string>
     */
    private function manifestIssues(array $connections, array $fixtures, array $views): array
    {
        $manifest = $this->manifest->load();
        $issues = [];

        foreach ($connections as $connection) {
            $listed = $manifest[$connection] ?? [];
            $listedKeys = array_map(strtolower(...), $listed);

            $defined = [...array_keys($fixtures[$connection]), ...$views[$connection]];
            $definedKeys = array_map(strtolower(...), $defined);

            foreach ($listed as $name) {
                if (!in_array(strtolower($name), $definedKeys, true)) {
                    $issues[] = "[{$connection}] manifest lists `{$name}` but it is in neither the schema nor the views fixture";
                }
            }

            foreach ($defined as $name) {
                if (!in_array(strtolower($name), $listedKeys, true)) {
                    $issues[] = "[{$connection}] fixture defines `{$name}` but it is absent from the manifest (stale?)";
                }
            }
        }

        return $issues;
    }
}
