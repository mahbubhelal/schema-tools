<?php

declare(strict_types=1);

namespace Mahbub\SchemaTools\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Mahbub\SchemaTools\Actions\DetectSourceTables;
use Mahbub\SchemaTools\Support\Manifest;

#[Description('Detect the source tables and views the code relies on and rebuild the generated section of the manifest')]
#[Signature('schema:detect {--dry-run : Preview the reconciled manifest without writing it; exits non-zero when the generated section is out of date or a query file names tables without a connection}')]
final class DetectCommand extends Command
{
    public function handle(DetectSourceTables $detectSourceTables, Manifest $manifest): int
    {
        $detectionResult = $detectSourceTables->handle();

        foreach ($detectionResult->connections as $connectionDetection) {
            $heading = "[{$connectionDetection->connection}] {$connectionDetection->total} name(s)";

            if ($connectionDetection->manualCount > 0) {
                $heading .= ", {$connectionDetection->manualCount} under manual";
            }

            $this->line($heading . ': ' . count($connectionDetection->additions) . ' added, ' . count($connectionDetection->removed) . ' removed');

            foreach ($connectionDetection->additions as $name) {
                $this->line("    + {$name}");
            }

            foreach ($connectionDetection->removed as $name) {
                $this->line("    - {$name} (no longer referenced in code)");
            }

            foreach ($connectionDetection->redundantManual as $name) {
                $this->line("    ~ {$name} (also detected in code; the manual entry is redundant)");
            }
        }

        foreach ($detectionResult->droppedConnections as $connection) {
            $this->line("[{$connection}] has no fixture any more; its generated names were dropped");
        }

        if ($detectionResult->unattributedQueries !== []) {
            $this->newLine();
            $this->warn('Some query files name tables without a connection; name one (DB::connection(), a model) or list the tables under sources:');

            foreach ($detectionResult->unattributedQueries as $file => $names) {
                $this->line('    ! ' . $this->relative($file) . ': ' . implode(', ', $names));
            }
        }

        $this->newLine();

        if ($this->option('dry-run') === true) {
            $this->line('Dry run — manifest not written.');

            if ($detectionResult->hasChanges()) {
                $this->warn('The generated section is out of date; run without --dry-run to rewrite it.');
            } else {
                $this->line('The generated section is up to date.');
            }

            return $detectionResult->hasChanges() || $detectionResult->unattributedQueries !== [] ? self::FAILURE : self::SUCCESS;
        }

        $manifest->write($detectionResult->manifest);
        $this->line('Wrote ' . $this->relative($manifest->path()) . '.');

        return self::SUCCESS;
    }

    private function relative(string $path): string
    {
        return str_replace(base_path() . '/', '', $path);
    }
}
