<?php

declare(strict_types=1);

namespace Mahbub\SchemaTools\Actions;

use Illuminate\Support\Facades\Config;
use Mahbub\SchemaTools\Support\ConnectionDetection;
use Mahbub\SchemaTools\Support\DetectionResult;
use Mahbub\SchemaTools\Support\FixtureConnections;
use Mahbub\SchemaTools\Support\Manifest;
use Mahbub\SchemaTools\Support\ModelScanner;
use Mahbub\SchemaTools\Support\SchemaFixtureParser;

/**
 * Detects the source tables and views the project relies on and reconciles them
 * with the curated manifest.
 *
 * Tables are discovered, project-wide, from three places:
 *   - every concrete Eloquent model on a fixture-backed connection (its own
 *     table, plus any relationship pivot declared with a `table:` named argument)
 *   - every table/view referenced by the raw SQL inside the queries path — a
 *     three-part `Database.dbo.Name` reference is routed to the connection that
 *     owns that database; a one/two-part name is attributed to the connection(s)
 *     the query file talks to via `DB::connection('...')`
 *   - every base table a committed `<connection>-views.sql` joins, so tables
 *     used only inside a view still get pulled
 *
 * The manifest is treated as the source of truth: existing entries and their
 * order are preserved, newly detected names are appended (sorted), and an entry
 * no longer found in code is reported but kept — never deleted automatically.
 */
final readonly class DetectSourceTables
{
    public function __construct(
        private FixtureConnections $connections,
        private Manifest $manifest,
        private ModelScanner $modelScanner,
        private SchemaFixtureParser $parser,
    ) {}

    public function handle(): DetectionResult
    {
        $connections = $this->connections->all();
        $databaseMap = $this->connections->databaseMap();

        /** @var array<string, list<string>> $detected */
        $detected = array_fill_keys($connections, []);

        /** @var array<string, string> $canonical Lowercase name => preferred casing. */
        $canonical = [];

        foreach ($this->modelScanner->scan(Config::string('schema-tools.models_path')) as $scanned) {
            $connection = $scanned->model->getConnectionName();

            if ($connection === null || !in_array($connection, $connections, true)) {
                continue;
            }

            $table = $scanned->model->getTable();
            $detected[$connection][] = $table;
            $canonical[strtolower($table)] ??= $table;

            if (preg_match_all("/table: '([^']+)'/", $scanned->contents, $pivotMatches) > 0) {
                foreach ($pivotMatches[1] as $pivot) {
                    $detected[$connection][] = $pivot;
                }
            }
        }

        foreach ($this->queryFiles() as $file) {
            $contents = (string) file_get_contents($file);

            preg_match_all("/DB::connection\('([^']+)'\)/", $contents, $connectionMatches);
            $fileConnections = array_values(array_intersect(array_unique($connectionMatches[1]), $connections));

            if ($fileConnections === []) {
                continue;
            }

            foreach ($this->tablesReferencedIn($this->sqlStringsOf($contents), $fileConnections, $databaseMap, $connections) as $connection => $names) {
                foreach ($names as $name) {
                    $detected[$connection][] = $name;
                }
            }
        }

        foreach ($connections as $connection) {
            $viewsPath = $this->connections->viewsFile($connection);

            if (!is_file($viewsPath)) {
                continue;
            }

            $viewSql = (string) preg_replace('/--[^\n]*/', '', (string) file_get_contents($viewsPath));

            foreach ($this->tablesReferencedIn($viewSql, [$connection], $databaseMap, $connections) as $viewConnection => $names) {
                foreach ($names as $name) {
                    $detected[$viewConnection][] = $name;
                }
            }
        }

        foreach ($connections as $connection) {
            foreach (array_keys($this->parser->parseTables($this->connections->schemaFile($connection))) as $name) {
                $canonical[strtolower($name)] ??= $name;
            }

            foreach ($this->parser->parseViews($this->connections->viewsFile($connection)) as $name) {
                $canonical[strtolower($name)] ??= $name;
            }
        }

        $canonicalise = static fn (string $name): string => $canonical[strtolower($name)] ?? $name;

        $manifest = $this->manifest->load();
        $summaries = [];

        foreach ($connections as $connection) {
            $detectedNames = [];

            foreach ($detected[$connection] as $name) {
                $detectedNames[strtolower($name)] ??= $canonicalise($name);
            }

            $existing = $manifest[$connection] ?? [];
            $existingKeys = array_map(strtolower(...), $existing);

            $newKeys = array_values(array_diff(array_keys($detectedNames), $existingKeys));
            sort($newKeys);

            $additions = array_map(static fn (string $key): string => $detectedNames[$key], $newKeys);

            $staleKeys = array_diff($existingKeys, array_keys($detectedNames));
            $stale = array_values(array_filter(
                $existing,
                static fn (string $name): bool => in_array(strtolower($name), $staleKeys, true),
            ));

            $manifest[$connection] = [...$existing, ...$additions];

            $summaries[] = new ConnectionDetection(
                connection: $connection,
                total: count($manifest[$connection]),
                additions: $additions,
                stale: $stale,
            );
        }

        return new DetectionResult(manifest: $manifest, connections: $summaries);
    }

    /**
     * @return list<string>
     */
    private function queryFiles(): array
    {
        $files = glob(Config::string('schema-tools.queries_path') . '/*.php');

        return $files === false ? [] : $files;
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
     * by the connection that owns them.
     *
     * @param  list<string>  $fileConnections
     * @param  array<string, string>  $databaseMap
     * @param  list<string>  $connections
     * @return array<string, list<string>>
     */
    private function tablesReferencedIn(string $sql, array $fileConnections, array $databaseMap, array $connections): array
    {
        preg_match_all(
            '/\b(?:FROM|JOIN|INTO|UPDATE)\s+((?:\[[^\]]+\]|[A-Za-z_#][\w$#]*)(?:\.(?:\[[^\]]+\]|[\w$#]*)){0,2})/i',
            $sql,
            $matches,
        );

        $found = [];

        foreach ($matches[1] as $reference) {
            $parts = array_values(array_filter(
                array_map(static fn (string $part): string => trim($part, '[]'), explode('.', $reference)),
                static fn (string $part): bool => $part !== '',
            ));

            if ($parts === []) {
                continue; // @codeCoverageIgnore
            }

            $name = end($parts);

            if (str_starts_with($name, '#')) {
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
