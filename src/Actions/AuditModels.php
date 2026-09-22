<?php

declare(strict_types=1);

namespace Mahbub\SchemaTools\Actions;

use Illuminate\Support\Facades\Config;
use Mahbub\SchemaTools\Declarations\DeclarationAuditor;
use Mahbub\SchemaTools\Declarations\DeclarationReader;
use Mahbub\SchemaTools\Declarations\ModelFacts;
use Mahbub\SchemaTools\Support\FixtureModels;
use Mahbub\SchemaTools\Support\Manifest;
use Mahbub\SchemaTools\Support\ModelAuditResult;
use Mahbub\SchemaTools\Support\Report;
use Mahbub\SchemaTools\Support\SourceParser;
use Mahbub\SchemaTools\Support\Table;

/**
 * Verifies every concrete Eloquent model on a fixture-backed connection: its
 * connection, table, primaryKey, keyType, incrementing and timestamps against
 * the DDL (see ModelFactsResolver), and its own declarations for form — one
 * style, the configured style if any, canonical order (see DeclarationAuditor).
 * It then cross-checks the manifest: every listed name must exist in the
 * connection's schema or views fixture, and every fixture object must appear
 * in the manifest. A model on a connection without a fixture is not audited
 * but is reported as skipped.
 */
final readonly class AuditModels
{
    public function __construct(
        private FixtureModels $models,
        private Manifest $manifest,
        private SourceParser $sourceParser,
        private DeclarationReader $declarationReader,
        private DeclarationAuditor $declarationAuditor,
    ) {}

    public function handle(): ModelAuditResult
    {
        $reports = [];
        $skipped = [];
        $configured = $this->models->configuredStyle();

        foreach ($this->models->scanned() as $scanned) {
            $facts = $this->models->facts($scanned);

            if (!$facts instanceof ModelFacts) {
                $connection = $scanned->model->getConnectionName() ?? Config::string('database.default');
                $skipped[] = new Report($scanned->class, $connection . '.' . $scanned->model->getTable(), []);

                continue;
            }

            $issues = $facts->issues;
            $class = $this->sourceParser->findClass($scanned->contents, $scanned->class);

            if ($class instanceof \PhpParser\Node\Stmt\Class_) {
                array_push($issues, ...$this->declarationAuditor->issues($this->declarationReader->read($class), $configured));
            }

            $reports[] = new Report($scanned->class, "{$facts->effective->connection}.{$facts->effective->table}", $issues);
        }

        return new ModelAuditResult(
            models: $reports,
            manifestIssues: $this->manifestIssues($this->models->connections(), $this->models->tables(), $this->models->views()),
            skipped: $skipped,
        );
    }

    /**
     * @param  list<string>  $connections
     * @param  array<string, array<string, Table>>  $fixtures
     * @param  array<string, list<string>>  $views
     * @return list<string>
     */
    private function manifestIssues(array $connections, array $fixtures, array $views): array
    {
        $manifest = $this->manifest->load()->all();
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
