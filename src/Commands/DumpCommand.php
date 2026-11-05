<?php

declare(strict_types=1);

namespace Mahbub\SchemaTools\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\App;
use Mahbub\SchemaTools\Actions\DumpSourceSchema;
use Mahbub\SchemaTools\Support\ConnectionDump;
use Mahbub\SchemaTools\Support\DumpStatus;
use Mahbub\SchemaTools\Support\FixtureConnections;

/**
 * Pick the source database with Laravel's standard `--env` option:
 *   php artisan schema:dump                  # reads from .env
 *   php artisan schema:dump --env=staging    # reads from .env.staging
 *   php artisan schema:dump --env=production --dry-run
 *   php artisan schema:dump --env=staging --connection=sugar --connection=cid
 */
#[Description('Rebuild the schema fixtures from the source databases, using the names in the manifest')]
#[Signature('schema:dump
        {--dry-run : Read the source and preview the diff without writing the fixtures}
        {--connection=* : Only rebuild the fixtures of these connections}')]
final class DumpCommand extends Command
{
    public function handle(DumpSourceSchema $dumpSourceSchema, FixtureConnections $fixtureConnections): int
    {
        $write = $this->option('dry-run') !== true;
        $only = $this->onlyConnections();
        $unknownConnections = array_diff($only, $fixtureConnections->all());

        if ($unknownConnections !== []) {
            foreach ($unknownConnections as $unknownConnection) {
                $this->error("[{$unknownConnection}] is not a fixture-backed connection");
            }

            return self::FAILURE;
        }

        $this->line('Source environment: ' . App::environmentFile());
        $this->newLine();

        $dumpResult = $dumpSourceSchema->handle($only);

        foreach ($dumpResult->connections as $connectionDump) {
            $this->report($connectionDump, $write);
        }

        $this->newLine();

        if ($dumpResult->hasFailures()) {
            $this->error('Some fixtures could not be rebuilt; see the warnings above.');

            return self::FAILURE;
        }

        $this->line($write ? 'Fixtures written.' : 'Dry run — omit --dry-run to write the fixtures.');

        return self::SUCCESS;
    }

    private function report(ConnectionDump $connectionDump, bool $write): void
    {
        foreach ($connectionDump->warnings as $warning) {
            $this->warn("[{$connectionDump->connection}] {$warning}");
        }

        if ($connectionDump->status === DumpStatus::Skipped) {
            $this->line("[{$connectionDump->connection}] {$connectionDump->skipReason}, skipped");

            return;
        }

        if ($connectionDump->status === DumpStatus::Failed) {
            return;
        }

        $report = [];

        if ($connectionDump->schemaContent !== null) {
            $changed = $this->putFixture($connectionDump->schemaPath, $connectionDump->schemaContent, $write);
            $report[] = $connectionDump->tableCount . ' table(s)' . ($changed ? ' [changed]' : ' [unchanged]');
        }

        if ($connectionDump->viewsContent !== null) {
            $changed = $this->putFixture($connectionDump->viewsPath, $connectionDump->viewsContent, $write);
            $report[] = $connectionDump->viewCount . ' view(s)' . ($changed ? ' [changed]' : ' [unchanged]');
        }

        $verb = $write ? 'wrote' : 'would write';
        $this->line("[{$connectionDump->connection}] {$verb} " . implode(', ', $report));
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
