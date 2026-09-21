<?php

declare(strict_types=1);

namespace Mahbub\SchemaTools\Support;

final readonly class DetectionResult
{
    /**
     * @param  ManifestData  $manifest  The reconciled manifest, ready to write.
     * @param  list<ConnectionDetection>  $connections  Per-connection detection summary.
     * @param  list<string>  $droppedConnections  Connections whose generated names were dropped because they no longer have a fixture.
     */
    public function __construct(
        public ManifestData $manifest,
        public array $connections,
        public array $droppedConnections,
    ) {}

    /**
     * Whether writing the manifest would change its generated section.
     */
    public function hasChanges(): bool
    {
        if ($this->droppedConnections !== []) {
            return true;
        }

        foreach ($this->connections as $connection) {
            if ($connection->additions !== [] || $connection->removed !== []) {
                return true;
            }
        }

        return false;
    }
}
