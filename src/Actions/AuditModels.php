<?php

declare(strict_types=1);

namespace Mahbub\SchemaTools\Actions;

use Illuminate\Support\Facades\Config;
use Mahbub\SchemaTools\Support\ColumnType;
use Mahbub\SchemaTools\Support\FixtureConnections;
use Mahbub\SchemaTools\Support\Manifest;
use Mahbub\SchemaTools\Support\ModelAuditResult;
use Mahbub\SchemaTools\Support\ModelScanner;
use Mahbub\SchemaTools\Support\Report;
use Mahbub\SchemaTools\Support\SchemaFixtureParser;
use Mahbub\SchemaTools\Support\Table;

/**
 * Verifies every concrete Eloquent model on a fixture-backed connection declares
 * its connection, table, primaryKey, keyType, incrementing and timestamps
 * correctly against the T-SQL DDL, then cross-checks the manifest: every listed
 * name must exist in the connection's schema or views fixture, and every fixture
 * table must appear in the manifest.
 */
final readonly class AuditModels
{
    public function __construct(
        private FixtureConnections $connections,
        private Manifest $manifest,
        private ModelScanner $modelScanner,
        private SchemaFixtureParser $parser,
    ) {}

    public function handle(): ModelAuditResult
    {
        $connections = $this->connections->all();

        /** @var array<string, array<string, Table>> $fixtures */
        $fixtures = [];

        foreach ($connections as $connection) {
            $fixtures[$connection] = $this->parser->parseTables($this->connections->schemaFile($connection));
        }

        $reports = [];
        $skipped = 0;

        foreach ($this->modelScanner->scan(Config::string('schema-tools.models_path')) as $scanned) {
            $model = $scanned->model;
            $connection = $model->getConnectionName();

            if ($connection === null || !in_array($connection, $connections, true)) {
                $skipped++;

                continue;
            }

            $declaresConnection = !str_starts_with(
                $scanned->reflection->getProperty('connection')->getDeclaringClass()->getName(),
                'Illuminate\\',
            );
            $declaresTimestamps = !str_starts_with(
                $scanned->reflection->getProperty('timestamps')->getDeclaringClass()->getName(),
                'Illuminate\\',
            );

            $table = $model->getTable();
            $ddl = $this->findTable($fixtures[$connection], $table);

            $issues = [];

            if (!$declaresConnection) {
                $issues[] = 'connection not declared on the model';
            }

            if (!$ddl instanceof Table) {
                $issues[] = "table `{$table}` not found in {$connection}-schema.sql";
                $reports[] = new Report($scanned->class, "{$connection}.{$table}", $issues);

                continue;
            }

            $keyName = $model->getKeyName();
            $pk = $ddl->effectivePrimaryKey();

            if (count($pk) === 0) {
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

            if (!$model->usesTimestamps() && !$declaresTimestamps) {
                $issues[] = 'timestamps disabled but not declared on the model';
            }

            $reports[] = new Report($scanned->class, "{$connection}.{$table}", $issues);
        }

        return new ModelAuditResult(
            models: $reports,
            manifestIssues: $this->manifestIssues($connections, $fixtures),
            skipped: $skipped,
        );
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
     * @return list<string>
     */
    private function manifestIssues(array $connections, array $fixtures): array
    {
        $manifest = $this->manifest->load();
        $issues = [];

        foreach ($connections as $connection) {
            $listed = $manifest[$connection] ?? [];
            $listedKeys = array_map(strtolower(...), $listed);

            $tables = array_keys($fixtures[$connection]);
            $views = $this->parser->parseViews($this->connections->viewsFile($connection));
            $fixtureKeys = array_map(strtolower(...), [...$tables, ...$views]);

            foreach ($listed as $name) {
                if (!in_array(strtolower($name), $fixtureKeys, true)) {
                    $issues[] = "[{$connection}] manifest lists `{$name}` but it is in neither the schema nor the views fixture";
                }
            }

            foreach ([...$tables, ...$views] as $name) {
                if (!in_array(strtolower($name), $listedKeys, true)) {
                    $issues[] = "[{$connection}] fixture defines `{$name}` but it is absent from the manifest (stale?)";
                }
            }
        }

        return $issues;
    }
}
