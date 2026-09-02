<?php

declare(strict_types=1);

namespace Mahbub\SchemaTools\Support;

final readonly class FactoryCheckResult
{
    /**
     * @param  list<Report>  $factories  Only the factories that have issues.
     * @param  int  $checked  Total factories evaluated.
     * @param  list<string>  $skippedConnections  Connections without a fixture.
     */
    public function __construct(
        public array $factories,
        public int $checked,
        public array $skippedConnections,
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
