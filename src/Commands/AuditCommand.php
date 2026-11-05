<?php

declare(strict_types=1);

namespace Mahbub\SchemaTools\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Mahbub\SchemaTools\Actions\AuditModels;
use Mahbub\SchemaTools\Actions\CheckFactories;
use Mahbub\SchemaTools\Support\FactoryCheckResult;
use Mahbub\SchemaTools\Support\ModelAuditResult;
use Mahbub\SchemaTools\Support\RectorRunner;
use Mahbub\SchemaTools\Support\Report;

#[Description('Audit the models and factories against the schema fixtures and manifest')]
#[Signature('schema:audit {--fix : Rewrite the model declarations with the bundled Rector rule before auditing}')]
final class AuditCommand extends Command
{
    public function handle(AuditModels $auditModels, CheckFactories $checkFactories, RectorRunner $rectorRunner): int
    {
        if ($this->option('fix') === true) {
            $rectorRun = $rectorRunner->fix();

            $this->info('Rector');
            $this->line($rectorRun->output);
            $this->newLine();

            if (!$rectorRun->succeeded()) {
                $this->error("Rector exited with code {$rectorRun->exitCode}; the audit below reflects the models as they are.");
                $this->newLine();
            }
        }

        $modelAuditResult = $auditModels->handle();
        $factoryCheckResult = $checkFactories->handle();

        $this->reportModels($modelAuditResult);
        $this->newLine();
        $this->reportFactories($factoryCheckResult);

        return $modelAuditResult->passes() && $factoryCheckResult->passes() ? self::SUCCESS : self::FAILURE;
    }

    private function reportModels(ModelAuditResult $modelAuditResult): void
    {
        $this->info('Models');
        $this->reports($modelAuditResult->models, $modelAuditResult->skipped);
        $this->newLine();

        foreach ($modelAuditResult->manifestIssues as $issue) {
            $this->line("  ! {$issue}");
        }

        $this->newLine();

        $summary = $modelAuditResult->issueCount() . ' model issue(s), ' . count($modelAuditResult->manifestIssues) . ' manifest issue(s).';

        if ($modelAuditResult->skipped !== []) {
            $summary .= ' Skipped ' . count($modelAuditResult->skipped) . ' model(s) on non-fixture connections.';
        }

        $this->line($summary);
    }

    private function reportFactories(FactoryCheckResult $factoryCheckResult): void
    {
        $this->info('Factories');
        $this->reports($factoryCheckResult->factories, $factoryCheckResult->skipped);
        $this->newLine();

        $summary = 'Checked ' . count($factoryCheckResult->factories) . ' factories, ' . $factoryCheckResult->issueCount() . ' issue(s) found.';

        if ($factoryCheckResult->skipped !== []) {
            $summary .= ' Skipped ' . count($factoryCheckResult->skipped) . ' ' . Str::plural('factory', count($factoryCheckResult->skipped)) . ' on non-fixture connections.';
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
