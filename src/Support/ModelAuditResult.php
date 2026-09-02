<?php

declare(strict_types=1);

namespace Mahbub\SchemaTools\Support;

final readonly class ModelAuditResult
{
    /**
     * @param  list<Report>  $models  One per audited model, passing or not.
     * @param  list<string>  $manifestIssues  Manifest/fixture disagreements.
     * @param  int  $skipped  Models on non-fixture connections, left unaudited.
     */
    public function __construct(
        public array $models,
        public array $manifestIssues,
        public int $skipped,
    ) {}

    public function issueCount(): int
    {
        return array_sum(array_map(static fn (Report $report): int => count($report->issues), $this->models));
    }

    public function passes(): bool
    {
        return $this->issueCount() === 0 && $this->manifestIssues === [];
    }
}
