<?php

declare(strict_types=1);

namespace Mahbub\SchemaTools\Commands;

use Illuminate\Console\Command;
use Mahbub\SchemaTools\Actions\DetectSourceTables;
use Mahbub\SchemaTools\Support\Manifest;

final class DetectCommand extends Command
{
    protected $signature = 'schema:detect {--dry-run : Preview the reconciled manifest without writing it}';

    protected $description = 'Detect the source tables and views the code relies on and reconcile them with the manifest';

    public function handle(DetectSourceTables $detectSourceTables, Manifest $manifest): int
    {
        $result = $detectSourceTables->handle();

        foreach ($result->connections as $connection) {
            $this->line(
                "[{$connection->connection}] {$connection->total} name(s): "
                . count($connection->additions) . ' added, '
                . count($connection->stale) . ' not currently referenced in code',
            );

            foreach ($connection->additions as $name) {
                $this->line("    + {$name}");
            }

            foreach ($connection->stale as $name) {
                $this->line("    ? {$name} (in manifest, not detected in code — remove by hand if unused)");
            }
        }

        if ($this->option('dry-run') === true) {
            $this->newLine();
            $this->line('Dry run — manifest not written.');

            return $result->hasStale() ? self::FAILURE : self::SUCCESS;
        }

        $manifest->write($result->manifest);

        $this->newLine();
        $this->line('Wrote ' . $this->relative($manifest->path()) . '.');

        return $result->hasStale() ? self::FAILURE : self::SUCCESS;
    }

    private function relative(string $path): string
    {
        return str_replace(base_path() . '/', '', $path);
    }
}
