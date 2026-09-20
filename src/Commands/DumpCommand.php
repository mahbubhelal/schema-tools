<?php

declare(strict_types=1);

namespace Mahbub\SchemaTools\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\App;
use Mahbub\SchemaTools\Actions\DumpSourceSchema;
use Mahbub\SchemaTools\Support\ConnectionDump;
use Mahbub\SchemaTools\Support\FixtureConnections;

/**
 * Pick the source database with Laravel's standard `--env` option:
 *   php artisan schema:dump                  # reads from .env
 *   php artisan schema:dump --env=staging    # reads from .env.staging
 *   php artisan schema:dump --env=production --dry-run
 *   php artisan schema:dump --env=staging --connection=sugar --connection=cid
 */
final class DumpCommand extends Command
{
    protected $signature = 'schema:dump
        {--dry-run : Read the source and preview the diff without writing the fixtures}
        {--connection=* : Only rebuild the fixtures of these connections}';

    protected $description = 'Rebuild the schema fixtures from the source databases, using the names in the manifest';

    public function handle(DumpSourceSchema $dumpSourceSchema, FixtureConnections $connections): int
    {
        $write = $this->option('dry-run') !== true;
        $only = $this->onlyConnections();

        $this->line('Source environment: ' . App::environmentFile());
        $this->newLine();

        foreach (array_diff($only, $connections->all()) as $unknown) {
            $this->warn("[{$unknown}] is not a fixture-backed connection, ignored");
        }

        foreach ($dumpSourceSchema->handle($only)->connections as $connection) {
            $this->report($connection, $write);
        }

        $this->newLine();
        $this->line($write ? 'Fixtures written.' : 'Dry run — omit --dry-run to write the fixtures.');

        return self::SUCCESS;
    }

    private function report(ConnectionDump $dump, bool $write): void
    {
        foreach ($dump->warnings as $warning) {
            $this->warn("[{$dump->connection}] {$warning}");
        }

        if ($dump->skipped) {
            $this->line("[{$dump->connection}] {$dump->skipReason}, skipped");

            return;
        }

        if ($dump->failed) {
            return;
        }

        $report = [];

        if ($dump->schemaContent !== null) {
            $changed = $this->putFixture($dump->schemaPath, $dump->schemaContent, $write);
            $report[] = $dump->tableCount . ' table(s)' . ($changed ? ' [changed]' : ' [unchanged]');
        }

        if ($dump->viewsContent !== null) {
            $changed = $this->putFixture($dump->viewsPath, $dump->viewsContent, $write);
            $report[] = $dump->viewCount . ' view(s)' . ($changed ? ' [changed]' : ' [unchanged]');
        }

        $verb = $write ? 'wrote' : 'would write';
        $this->line("[{$dump->connection}] {$verb} " . implode(', ', $report));
    }

    /**
     * @return list<string>
     */
    private function onlyConnections(): array
    {
        $only = [];

        foreach ((array) $this->option('connection') as $connection) {
            if (is_string($connection) && $connection !== '') {
                $only[] = $connection;
            }
        }

        return $only;
    }

    private function putFixture(string $path, string $contents, bool $write): bool
    {
        $changed = !is_file($path) || file_get_contents($path) !== $contents;

        if ($write && $changed) {
            file_put_contents($path, $contents);
        }

        return $changed;
    }
}
