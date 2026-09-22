<?php

declare(strict_types=1);

namespace Mahbub\SchemaTools\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Mahbub\SchemaTools\Actions\AuditModels;
use Mahbub\SchemaTools\Actions\CheckFactories;
use Mahbub\SchemaTools\Support\FactoryCheckResult;
use Mahbub\SchemaTools\Support\ModelAuditResult;
use Mahbub\SchemaTools\Support\RectorRunner;
use Mahbub\SchemaTools\Support\Report;

final class AuditCommand extends Command
{
    protected $signature = 'schema:audit {--fix : Rewrite the model declarations with the bundled Rector rule before auditing}';

    protected $description = 'Audit the models and factories against the schema fixtures and manifest';

    public function handle(AuditModels $auditModels, CheckFactories $checkFactories, RectorRunner $rectorRunner): int
    {
        if ($this->option('fix') === true) {
            $run = $rectorRunner->fix();

            $this->info('Rector');
            $this->line($run->output);
            $this->newLine();

            if (!$run->succeeded()) {
                $this->error("Rector exited with code {$run->exitCode}; the audit below reflects the models as they are.");
                $this->newLine();
            }
        }

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
        $this->reports($result->models, $result->skipped);
        $this->newLine();

        foreach ($result->manifestIssues as $issue) {
            $this->line("  ! {$issue}");
        }

        $this->newLine();

        $summary = $result->issueCount() . ' model issue(s), ' . count($result->manifestIssues) . ' manifest issue(s).';

        if ($result->skipped !== []) {
            $summary .= ' Skipped ' . count($result->skipped) . ' model(s) on non-fixture connections.';
        }

        $this->line($summary);
    }

    private function reportFactories(FactoryCheckResult $result): void
    {
        $this->info('Factories');
        $this->reports($result->factories, $result->skipped);
        $this->newLine();

        $summary = 'Checked ' . count($result->factories) . ' factories, ' . $result->issueCount() . ' issue(s) found.';

        if ($result->skipped !== []) {
            $summary .= ' Skipped ' . count($result->skipped) . ' ' . Str::plural('factory', count($result->skipped)) . ' on non-fixture connections.';
        }

        $this->line($summary);
    }

    /**
     * One line per subject: OK, FAIL followed by its issues, or SKIP for a
     * subject whose connection has no fixture, so nothing goes unmentioned.
     *
     * @param  list<Report>  $reports
     * @param  list<Report>  $skipped
     */
    private function reports(array $reports, array $skipped): void
    {
        foreach ($reports as $report) {
            if ($report->passes()) {
                $this->line("<info>OK</info>   {$report->subject}");

                continue;
            }

            $this->line("<fg=red>FAIL</> {$report->subject} [{$report->location}]");

            foreach ($report->issues as $issue) {
                $this->line("     - {$issue}");
            }
        }

        foreach ($skipped as $report) {
            $this->line("<comment>SKIP</comment> {$report->subject} [{$report->location}]");
        }
    }
}
