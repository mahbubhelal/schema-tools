<?php

declare(strict_types=1);

namespace Mahbub\SchemaTools\Commands;

use Illuminate\Console\Command;
use Mahbub\SchemaTools\Actions\AuditModels;
use Mahbub\SchemaTools\Actions\CheckFactories;
use Mahbub\SchemaTools\Support\FactoryCheckResult;
use Mahbub\SchemaTools\Support\ModelAuditResult;

final class AuditCommand extends Command
{
    protected $signature = 'schema:audit';

    protected $description = 'Audit the models and factories against the schema fixtures and manifest';

    public function handle(AuditModels $auditModels, CheckFactories $checkFactories): int
    {
        $models = $auditModels->handle();
        $factories = $checkFactories->handle();

        $this->reportModels($models);
        $this->newLine();
        $this->reportFactories($factories);

        return $models->passes() && $factories->passes() ? self::SUCCESS : self::FAILURE;
    }

    private function reportModels(ModelAuditResult $result): void
    {
        $this->info('Models');

        foreach ($result->models as $report) {
            if ($report->passes()) {
                $this->line("<info>OK</info>   {$report->subject}");

                continue;
            }

            $this->line("<fg=red>FAIL</> {$report->subject} [{$report->location}]");

            foreach ($report->issues as $issue) {
                $this->line("     - {$issue}");
            }
        }

        $this->newLine();

        foreach ($result->manifestIssues as $issue) {
            $this->line("  ! {$issue}");
        }

        $this->newLine();

        $summary = $result->issueCount() . ' model issue(s), ' . count($result->manifestIssues) . ' manifest issue(s).';

        if ($result->skipped > 0) {
            $summary .= " Skipped {$result->skipped} model(s) on non-fixture connections.";
        }

        $this->line($summary);
    }

    private function reportFactories(FactoryCheckResult $result): void
    {
        $this->info('Factories');

        foreach ($result->factories as $report) {
            $this->line("{$report->subject} → {$report->location}");

            foreach ($report->issues as $issue) {
                $this->line("    x {$issue}");
            }
        }

        $this->newLine();

        $summary = "Checked {$result->checked} factories, {$result->issueCount()} issue(s) found.";

        if ($result->skippedConnections !== []) {
            $summary .= ' Skipped connections without a fixture: ' . implode(', ', $result->skippedConnections) . '.';
        }

        $this->line($summary);
    }
}
