<?php

declare(strict_types=1);

namespace Mahbub\SchemaTools\Support;

final readonly class ConnectionDetection
{
    /**
     * @param  list<string>  $additions  Names newly detected and appended to the manifest.
     * @param  list<string>  $stale  Manifest names no longer found in code.
     */
    public function __construct(
        public string $connection,
        public int $total,
        public array $additions,
        public array $stale,
    ) {}
}
