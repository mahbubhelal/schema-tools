<?php

declare(strict_types=1);

namespace Mahbub\SchemaTools\Actions;

use Illuminate\Support\Facades\File;
use Mahbub\SchemaTools\Support\ConnectionDetection;
use Mahbub\SchemaTools\Support\DetectionResult;
use Mahbub\SchemaTools\Support\FixtureConnections;
use Mahbub\SchemaTools\Support\Manifest;
use Mahbub\SchemaTools\Support\ManifestData;
use Mahbub\SchemaTools\Support\ModelScanner;
use Mahbub\SchemaTools\Support\Regex;
use Mahbub\SchemaTools\Support\ScannedModel;
use Mahbub\SchemaTools\Support\ScanPaths;
use Mahbub\SchemaTools\Support\SchemaFixtureParser;
use Symfony\Component\Finder\Finder;

/**
 * Detects the source tables and views the project relies on and reconciles them
 * with the curated manifest.
 *
 * Tables are discovered, project-wide, from three places:
 *   - every concrete Eloquent model on a fixture-backed connection (its own
 *     table, plus any relationship pivot declared with a `table:` named argument)
 *   - every table/view referenced by the raw SQL inside the queries paths — a
 *     three-part `Database.dbo.Name` reference is routed to the connection that
 *     owns that database; a one/two-part name is attributed to the connection(s)
 *     the query file talks to via `DB::connection('...')` or a
 *     `$connection = '...'` property. Common table expressions and temp tables
 *     are not tables and are left out.
 *   - every base table a committed `<connection>-views.sql` joins, so tables
 *     used only inside a view still get pulled
 *
 * Only the manifest's `generated` section is reconciled: names still detected
 * keep their order, newly detected names are appended (sorted), names no longer
 * found in code are removed, and a connection without a fixture loses its
 * section. The `manual` section is never touched; a manual name the detector
 * finds anyway is reported as redundant.
 */
final readonly class DetectSourceTables
{
    public function __construct(
        private FixtureConnections $fixtureConnections,
        private Manifest $manifest,
        private ModelScanner $modelScanner,
        private SchemaFixtureParser $schemaFixtureParser,
        private ScanPaths $scanPaths,
    ) {}

    public function handle(): DetectionResult
    {
        $connections = $this->fixtureConnections->all();
        $databaseMap = $this->fixtureConnections->databaseMap();

        $modelsPerConnection = $this->modelsPerConnection($connections);

        /** @var array<string, list<string>> */
        $detected = array_fill_keys($connections, []);

        foreach ([$this->tablesOfModels($modelsPerConnection), $this->tablesInQueries($connections, $databaseMap), $this->tablesInViews($connections, $databaseMap)] as $tablesPerConnection) {
            foreach ($tablesPerConnection as $connection => $names) {
                array_push($detected[$connection], ...$names);
            }
        }

        $canonical = $this->canonicalNames($modelsPerConnection, $connections);

        $canonicalise = static fn (string $name): string => $canonical[strtolower($name)] ?? $name;

        $manifestData = $this->manifest->load();
        $generated = array_intersect_key($manifestData->generated, array_fill_keys($connections, true));
        $droppedConnections = array_values(array_diff(array_keys($manifestData->generated), $connections));

        /** @var array<string, array{additions: list<string>, removed: list<string>, redundant: list<string>}> */
        $changes = [];

        foreach ($connections as $connection) {
            /** @var array<string, string> Lowercase => preferred casing. */
            $detectedNames = [];

            foreach ($detected[$connection] as $name) {
                $detectedNames[strtolower($name)] ??= $canonicalise($name);
            }

            $isDetected = static fn (string $name): bool => array_key_exists(strtolower($name), $detectedNames);

            $existing = $generated[$connection] ?? [];
            $kept = array_values(array_filter($existing, $isDetected));
            $removed = array_values(array_filter($existing, static fn (string $name): bool => !$isDetected($name)));

            $newKeys = array_values(array_diff(array_keys($detectedNames), array_map(strtolower(...), $existing)));
            sort($newKeys);

            $additions = array_map(static fn (string $key): string => $detectedNames[$key], $newKeys);

            $generated[$connection] = [...$kept, ...$additions];

            $changes[$connection] = [
                'additions' => $additions,
                'removed' => $removed,
                'redundant' => array_values(array_filter($manifestData->manual[$connection] ?? [], $isDetected)),
            ];
        }

        $reconciled = new ManifestData(manual: $manifestData->manual, generated: $generated);
        $summaries = [];

        foreach ($connections as $connection) {
            $summaries[] = new ConnectionDetection(
                connection: $connection,
                total: count($reconciled->names($connection)),
                manualCount: count($manifestData->manual[$connection] ?? []),
                additions: $changes[$connection]['additions'],
                removed: $changes[$connection]['removed'],
                redundantManual: $changes[$connection]['redundant'],
            );
        }

        return new DetectionResult(manifest: $reconciled, connections: $summaries, droppedConnections: $droppedConnections);
    }

    /**
     * @param  list<string>  $connections
     *
     * @return array<string, list<ScannedModel>>
     */
    private function modelsPerConnection(array $connections): array
    {
        $models = [];

        foreach ($this->scanPaths->resolve('models') as $path) {
            foreach ($this->modelScanner->scan($path) as $scannedModel) {
                $connection = $scannedModel->model->getConnectionName();

                if ($connection !== null && in_array($connection, $connections, true)) {
                    $models[$connection][] = $scannedModel;
                }
            }
        }

        return $models;
    }

    /**
     * Each model's own table plus any relationship pivot it declares with a
     * `table:` named argument.
     *
     * @param  array<string, list<ScannedModel>>  $modelsPerConnection
     *
     * @return array<string, list<string>>
     */
    private function tablesOfModels(array $modelsPerConnection): array
    {
        $tables = [];

        foreach ($modelsPerConnection as $connection => $scannedModels) {
            foreach ($scannedModels as $scannedModel) {
                $tables[$connection][] = $scannedModel->model->getTable();

                Regex::orFail(preg_match_all("/table: '([^']+)'/", $scannedModel->contents, $pivotMatches));

                array_push($tables[$connection], ...$pivotMatches[1]);
            }
        }

        return $tables;
    }

    /**
     * Lowercase name => preferred casing: a model's table name wins, then the
     * name as the fixture DDL spells it.
     *
     * @param  array<string, list<ScannedModel>>  $modelsPerConnection
     * @param  list<string>  $connections
     *
     * @return array<string, string>
     */
    private function canonicalNames(array $modelsPerConnection, array $connections): array
    {
        $canonical = [];

        foreach ($modelsPerConnection as $scannedModels) {
            foreach ($scannedModels as $scannedModel) {
                $table = $scannedModel->model->getTable();
                $canonical[strtolower($table)] ??= $table;
            }
        }

        foreach ($this->fixtureNames($connections) as $name) {
            $canonical[strtolower($name)] ??= $name;
        }

        return $canonical;
    }

    /**
     * The tables the raw SQL in the query files references, by the connection
     * that owns them.
     *
     * @param  list<string>  $connections
     * @param  array<string, string>  $databaseMap
     *
     * @return array<string, list<string>>
     */
    private function tablesInQueries(array $connections, array $databaseMap): array
    {
        $found = [];

        foreach ($this->queryFiles() as $file) {
            $contents = File::get($file);
            $fileConnections = array_values(array_intersect($this->connectionsNamedIn($contents), $connections));

            if ($fileConnections === []) {
                continue;
            }

            foreach ($this->tablesReferencedIn($this->sqlStringsOf($contents), $fileConnections, $databaseMap, $connections) as $connection => $names) {
                $found[$connection] = [...$found[$connection] ?? [], ...$names];
            }
        }

        return $found;
    }

    /**
     * The base tables the committed views fixtures join, by the connection
     * that owns them.
     *
     * @param  list<string>  $connections
     * @param  array<string, string>  $databaseMap
     *
     * @return array<string, list<string>>
     */
    private function tablesInViews(array $connections, array $databaseMap): array
    {
        $found = [];

        foreach ($connections as $connection) {
            $viewsPath = $this->fixtureConnections->viewsFile($connection);

            if (!is_file($viewsPath)) {
                continue;
            }

            foreach ($this->tablesReferencedIn(File::get($viewsPath), [$connection], $databaseMap, $connections) as $viewConnection => $names) {
                $found[$viewConnection] = [...$found[$viewConnection] ?? [], ...$names];
            }
        }

        return $found;
    }

    /**
     * Every table and view name the fixtures define, as the DDL spells it.
     *
     * @param  list<string>  $connections
     *
     * @return list<string>
     */
    private function fixtureNames(array $connections): array
    {
        $names = [];

        foreach ($connections as $connection) {
            array_push(
                $names,
                ...array_keys($this->schemaFixtureParser->parseTables($this->fixtureConnections->schemaFile($connection))),
                ...$this->schemaFixtureParser->parseViews($this->fixtureConnections->viewsFile($connection)),
            );
        }

        return $names;
    }

    /**
     * The PHP files under the configured queries paths, recursively and in name
     * order.
     *
     * @return list<string>
     */
    private function queryFiles(): array
    {
        $directories = $this->scanPaths->resolve('queries');

        if ($directories === []) {
            return [];
        }

        $files = [];

        foreach (Finder::create()->in($directories)->files()->name('*.php')->sortByName() as $file) {
            $files[] = $file->getPathname();
        }

        return $files;
    }

    /**
     * The connections a query file talks to, named through `DB::connection('x')`
     * or a `$connection = 'x'` property.
     *
     * @return list<string>
     */
    private function connectionsNamedIn(string $php): array
    {
        Regex::orFail(preg_match_all("/DB::connection\\('([^']+)'\\)|\\\$connection\\s*=\\s*'([^']+)'/", $php, $matches));

        return array_values(array_unique(array_filter(
            [...$matches[1], ...$matches[2]],
            static fn (string $name): bool => $name !== '',
        )));
    }

    /**
     * Every string literal (including heredoc bodies) in a PHP file, concatenated
     * — so table references are read from the SQL and never from comments or code.
     */
    private function sqlStringsOf(string $php): string
    {
        $strings = [];

        foreach (token_get_all($php) as $token) {
            if (is_array($token) && in_array($token[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE, T_INLINE_HTML], true)) {
                $strings[] = $token[1];
            }
        }

        return implode("\n", $strings);
    }

    /**
     * The tables named after a FROM/JOIN/INTO/UPDATE in a block of SQL, grouped
     * by the connection that owns them. Comments are ignored, as are temp tables
     * (`#name`) and the names a `WITH name AS (...)` clause defines.
     *
     * @param  list<string>  $fileConnections
     * @param  array<string, string>  $databaseMap
     * @param  list<string>  $connections
     *
     * @return array<string, list<string>>
     */
    private function tablesReferencedIn(string $sql, array $fileConnections, array $databaseMap, array $connections): array
    {
        $sql = Regex::orFail(preg_replace(['~--[^\n]*~', '~/\*.*?\*/~s'], '', $sql));

        Regex::orFail(preg_match_all('/\b([A-Za-z_]\w*)\s+AS\s*\(/i', $sql, $expressionMatches));
        $commonTableExpressions = array_map(strtolower(...), $expressionMatches[1]);

        Regex::orFail(preg_match_all(
            '/\b(?:FROM|JOIN|INTO|UPDATE)\s+((?:\[[^\]]+\]|`[^`]+`|[A-Za-z_#][\w$#]*)(?:\.(?:\[[^\]]+\]|`[^`]+`|[\w$#]*)){0,2})/i',
            $sql,
            $matches,
        ));

        $found = [];

        foreach ($matches[1] as $reference) {
            $parts = array_values(array_filter(
                array_map(static fn (string $part): string => trim($part, '[]`'), explode('.', $reference)),
                static fn (string $part): bool => $part !== '',
            ));

            if ($parts === []) {
                continue;
            }

            $name = end($parts);

            if (str_starts_with($name, '#') || in_array(strtolower($name), $commonTableExpressions, true)) {
                continue;
            }

            if (count($parts) >= 3) {
                $connection = $databaseMap[strtolower($parts[0])] ?? null;

                if ($connection !== null && in_array($connection, $connections, true)) {
                    $found[$connection][] = $name;
                }

                continue;
            }

            foreach ($fileConnections as $connection) {
                $found[$connection][] = $name;
            }
        }

        return $found;
    }
}
