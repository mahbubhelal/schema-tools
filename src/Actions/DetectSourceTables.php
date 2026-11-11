<?php

declare(strict_types=1);

namespace Mahbub\SchemaTools\Actions;

use Illuminate\Support\Facades\File;
use Mahbub\SchemaTools\Support\ConnectionDetection;
use Mahbub\SchemaTools\Support\DetectionResult;
use Mahbub\SchemaTools\Support\FixtureConnections;
use Mahbub\SchemaTools\Support\FixtureModels;
use Mahbub\SchemaTools\Support\Manifest;
use Mahbub\SchemaTools\Support\ManifestData;
use Mahbub\SchemaTools\Support\Regex;
use Mahbub\SchemaTools\Support\ScannedModel;
use Mahbub\SchemaTools\Support\ScanPaths;
use Mahbub\SchemaTools\Support\SourceParser;
use PhpParser\Node\Name;
use PhpParser\NodeFinder;
use ReflectionClass;
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
 *     the query file talks to: through `DB::connection('...')`, a
 *     `$connection = '...'` property, or a scanned model it references
 *     (`new Press()->getConnection()`). A file with none of those borrows them,
 *     one hop away, from the project classes it references (a helper that runs
 *     the SQL it is handed); classes under a `vendor` directory are not read.
 *     One/two-part names a file still cannot attribute are reported, not
 *     guessed. Common table expressions and temp tables are not tables and are
 *     left out.
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
        private FixtureModels $fixtureModels,
        private Manifest $manifest,
        private ScanPaths $scanPaths,
        private SourceParser $sourceParser,
    ) {}

    public function handle(): DetectionResult
    {
        $connections = $this->fixtureModels->connections();
        $databaseMap = $this->fixtureConnections->databaseMap();

        $modelsPerConnection = $this->modelsPerConnection();

        /** @var array<string, list<string>> */
        $detected = array_fill_keys($connections, []);

        $queryTables = $this->tablesInQueries($connections, $databaseMap, $this->modelConnections($modelsPerConnection));

        foreach ([$this->tablesOfModels($modelsPerConnection), $queryTables['tables'], $this->tablesInViews($connections, $databaseMap)] as $tablesPerConnection) {
            foreach ($tablesPerConnection as $connection => $names) {
                array_push($detected[$connection], ...$names);
            }
        }

        $canonical = $this->canonicalNames($modelsPerConnection);

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

        return new DetectionResult(
            manifest: $reconciled,
            connections: $summaries,
            droppedConnections: $droppedConnections,
            unattributedQueries: $queryTables['unattributed'],
        );
    }

    /**
     * @return array<string, list<ScannedModel>>
     */
    private function modelsPerConnection(): array
    {
        $models = [];

        foreach ($this->fixtureModels->scanned() as $scannedModel) {
            $connection = $scannedModel->model->getConnectionName();

            if ($this->fixtureModels->isFixtureBacked($connection)) {
                $models[$connection][] = $scannedModel;
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
     *
     * @return array<string, string>
     */
    private function canonicalNames(array $modelsPerConnection): array
    {
        $canonical = [];

        foreach ($modelsPerConnection as $scannedModels) {
            foreach ($scannedModels as $scannedModel) {
                $table = $scannedModel->model->getTable();
                $canonical[strtolower($table)] ??= $table;
            }
        }

        foreach ($this->fixtureNames() as $name) {
            $canonical[strtolower($name)] ??= $name;
        }

        return $canonical;
    }

    /**
     * Fully qualified model class => its connection, for the scanned models.
     *
     * @param  array<string, list<ScannedModel>>  $modelsPerConnection
     *
     * @return array<string, string>
     */
    private function modelConnections(array $modelsPerConnection): array
    {
        $modelConnections = [];

        foreach ($modelsPerConnection as $connection => $scannedModels) {
            foreach ($scannedModels as $scannedModel) {
                $modelConnections[$scannedModel->class] = $connection;
            }
        }

        return $modelConnections;
    }

    /**
     * The tables the raw SQL in the query files references, by the connection
     * that owns them, and per query file the names no connection was found for.
     *
     * @param  list<string>  $connections
     * @param  array<string, string>  $databaseMap
     * @param  array<string, string>  $modelConnections
     *
     * @return array{tables: array<string, list<string>>, unattributed: array<string, list<string>>}
     */
    private function tablesInQueries(array $connections, array $databaseMap, array $modelConnections): array
    {
        $found = [];
        $unattributed = [];

        foreach ($this->queryFiles() as $file) {
            $contents = File::get($file);
            $referencedClasses = $this->classesReferencedIn($contents);

            $fileConnections = $this->connectionsOf($contents, $referencedClasses, $modelConnections, $connections);

            if ($fileConnections === []) {
                $fileConnections = $this->connectionsOfHelpers($referencedClasses, $modelConnections, $connections);
            }

            $routed = $this->tablesReferencedIn($this->sqlStringsOf($contents), $fileConnections, $databaseMap);

            foreach ($routed['tables'] as $connection => $names) {
                $found[$connection] = [...$found[$connection] ?? [], ...$names];
            }

            if ($routed['unattributed'] !== []) {
                $unattributed[$file] = array_values(array_unique($routed['unattributed']));
            }
        }

        return ['tables' => $found, 'unattributed' => $unattributed];
    }

    /**
     * The fixture connections a PHP file talks to: those it names, and those of
     * the scanned models it references.
     *
     * @param  list<string>  $referencedClasses
     * @param  array<string, string>  $modelConnections
     * @param  list<string>  $connections
     *
     * @return list<string>
     */
    private function connectionsOf(string $php, array $referencedClasses, array $modelConnections, array $connections): array
    {
        $modelsConnections = array_values(array_intersect_key($modelConnections, array_flip($referencedClasses)));

        return array_values(array_intersect(array_unique([...$this->connectionsNamedIn($php), ...$modelsConnections]), $connections));
    }

    /**
     * The fixture connections of the project classes a query file references,
     * one hop away: the helper that runs the SQL it is handed. Models are left
     * out (their connection already counts), and so are classes under a
     * `vendor` directory and classes that cannot be loaded.
     *
     * @param  list<string>  $referencedClasses
     * @param  array<string, string>  $modelConnections
     * @param  list<string>  $connections
     *
     * @return list<string>
     */
    private function connectionsOfHelpers(array $referencedClasses, array $modelConnections, array $connections): array
    {
        $helperConnections = [];

        foreach ($referencedClasses as $class) {
            $helperFile = array_key_exists($class, $modelConnections) ? null : $this->projectFileOf($class);

            if ($helperFile === null) {
                continue;
            }

            $helperSource = File::get($helperFile);

            array_push($helperConnections, ...$this->connectionsOf($helperSource, $this->classesReferencedIn($helperSource), $modelConnections, $connections));
        }

        return array_values(array_unique($helperConnections));
    }

    /**
     * The fully qualified names of the classes a PHP file's code refers to.
     *
     * @return list<string>
     */
    private function classesReferencedIn(string $php): array
    {
        $classes = [];

        foreach ((new NodeFinder)->findInstanceOf($this->sourceParser->parse($php), Name::class) as $name) {
            $resolvedName = $name->getAttribute('resolvedName');

            if ($resolvedName instanceof Name) {
                $classes[] = $resolvedName->toString();
            }
        }

        return array_values(array_unique($classes));
    }

    /**
     * The file a class is declared in, unless it is someone else's code (under a
     * `vendor` directory) or cannot be loaded.
     */
    private function projectFileOf(string $class): ?string
    {
        if (!class_exists($class) && !interface_exists($class) && !trait_exists($class)) {
            return null;
        }

        $file = new ReflectionClass($class)->getFileName();

        if ($file === false || str_contains($file, DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR)) {
            return null;
        }

        return $file;
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

            foreach ($this->tablesReferencedIn(File::get($viewsPath), [$connection], $databaseMap)['tables'] as $viewConnection => $names) {
                $found[$viewConnection] = [...$found[$viewConnection] ?? [], ...$names];
            }
        }

        return $found;
    }

    /**
     * Every table and view name the fixtures define, as the DDL spells it.
     *
     * @return list<string>
     */
    private function fixtureNames(): array
    {
        $views = $this->fixtureModels->views();
        $names = [];

        foreach ($this->fixtureModels->tables() as $connection => $tables) {
            array_push($names, ...array_keys($tables), ...$views[$connection]);
        }

        return $names;
    }

    /**
     * The PHP files under the configured queries paths, recursively and in name
     * order, by their real path.
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
            $files[] = $file->getRealPath();
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
     * by the connection that owns them; the one/two-part names come back as
     * unattributed when there is no file connection to give them. Comments are
     * ignored, as are temp tables (`#name`) and the names a
     * `WITH name AS (...)` clause defines.
     *
     * @param  list<string>  $fileConnections
     * @param  array<string, string>  $databaseMap  Lowercase database name => the fixture-backed connection on it.
     *
     * @return array{tables: array<string, list<string>>, unattributed: list<string>}
     */
    private function tablesReferencedIn(string $sql, array $fileConnections, array $databaseMap): array
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
        $unattributed = [];

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

                if ($connection !== null) {
                    $found[$connection][] = $name;
                }

                continue;
            }

            if ($fileConnections === []) {
                $unattributed[] = $name;
            }

            foreach ($fileConnections as $connection) {
                $found[$connection][] = $name;
            }
        }

        return ['tables' => $found, 'unattributed' => $unattributed];
    }
}
