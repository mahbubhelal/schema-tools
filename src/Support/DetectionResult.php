<?php

declare(strict_types=1);

namespace Mahbub\SchemaTools\Support;

final readonly class DetectionResult
{
    /**
     * @param  array<string, list<string>>  $manifest  The merged manifest, ready to write.
     * @param  list<ConnectionDetection>  $connections  Per-connection detection summary.
     */
    public function __construct(
        public array $manifest,
        public array $connections,
    ) {}

    public function hasStale(): bool
    {
        foreach ($this->connections as $connection) {
            if ($connection->stale !== []) {
                return true;
            }
        }

        return false;
    }
}
