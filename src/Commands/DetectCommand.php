<?php

declare(strict_types=1);

namespace Mahbub\SchemaTools\Commands;

use Illuminate\Console\Command;
use Mahbub\SchemaTools\Actions\DetectSourceTables;
use Mahbub\SchemaTools\Support\Manifest;

final class DetectCommand extends Command
{
    protected $signature = 'schema:detect {--dry-run : Preview the reconciled manifest without writing it; exits non-zero when the generated section is out of date}';

    protected $description = 'Detect the source tables and views the code relies on and rebuild the generated section of the manifest';

    public function handle(DetectSourceTables $detectSourceTables, Manifest $manifest): int
    {
        $result = $detectSourceTables->handle();

        foreach ($result->connections as $connection) {
            $heading = "[{$connection->connection}] {$connection->total} name(s)";

            if ($connection->manualCount > 0) {
                $heading .= ", {$connection->manualCount} under manual";
            }

            $this->line($heading . ': ' . count($connection->additions) . ' added, ' . count($connection->removed) . ' removed');

            foreach ($connection->additions as $name) {
                $this->line("    + {$name}");
            }

            foreach ($connection->removed as $name) {
                $this->line("    - {$name} (no longer referenced in code)");
            }

            foreach ($connection->redundantManual as $name) {
                $this->line("    ~ {$name} (also detected in code; the manual entry is redundant)");
            }
        }

        foreach ($result->droppedConnections as $connection) {
            $this->line("[{$connection}] has no fixture any more; its generated names were dropped");
        }

        $this->newLine();

        if ($this->option('dry-run') === true) {
            $this->line('Dry run — manifest not written.');

            if ($result->hasChanges()) {
                $this->warn('The generated section is out of date; run without --dry-run to rewrite it.');

                return self::FAILURE;
            }

            $this->line('The generated section is up to date.');

            return self::SUCCESS;
        }

        $manifest->write($result->manifest);
        $this->line('Wrote ' . $this->relative($manifest->path()) . '.');

        return self::SUCCESS;
    }

    private function relative(string $path): string
    {
        return str_replace(base_path() . '/', '', $path);
    }
}
