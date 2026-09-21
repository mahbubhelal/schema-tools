<?php

declare(strict_types=1);

namespace Mahbub\SchemaTools\Support;

final readonly class FactoryCheckResult
{
    /**
     * @param  list<Report>  $factories  One per checked factory, passing or not.
     * @param  list<Report>  $skipped  Factories whose model is on a non-fixture connection, left unchecked.
     */
    public function __construct(
        public array $factories,
        public array $skipped,
    ) {}

    public function issueCount(): int
    {
        return array_sum(array_map(static fn (Report $report): int => count($report->issues), $this->factories));
    }

    public function passes(): bool
    {
        return $this->issueCount() === 0;
    }
}
