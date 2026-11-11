<?php

declare(strict_types=1);

namespace Mahbub\SchemaTools\Support;

final readonly class DetectionResult
{
    /**
     * @param  ManifestData  $manifest  The reconciled manifest, ready to write.
     * @param  list<ConnectionDetection>  $connections  Per-connection detection summary.
     * @param  list<string>  $droppedConnections  Connections whose generated names were dropped because they no longer have a fixture.
     * @param  array<string, list<string>>  $unattributedQueries  Query file => the one/two-part names in its SQL that no connection could be found for.
     */
    public function __construct(
        public ManifestData $manifest,
        public array $connections,
        public array $droppedConnections,
        public array $unattributedQueries = [],
    ) {}

    /**
     * Whether writing the manifest would change its generated section.
     */
    public function hasChanges(): bool
    {
        if ($this->droppedConnections !== []) {
            return true;
        }

        return array_any($this->connections, fn (ConnectionDetection $connection): bool => $connection->additions !== [] || $connection->removed !== []);
    }
}
