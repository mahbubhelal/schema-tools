<?php

declare(strict_types=1);

namespace Mahbub\SchemaTools\Support;

final readonly class ConnectionDetection
{
    /**
     * @param  int  $total  Names the connection ends up with across both sections.
     * @param  int  $manualCount  Names listed under manual.
     * @param  list<string>  $additions  Newly detected names appended to generated.
     * @param  list<string>  $removed  Generated names no longer found in code, dropped.
     * @param  list<string>  $redundantManual  Manual names the detector finds anyway.
     */
    public function __construct(
        public string $connection,
        public int $total,
        public int $manualCount,
        public array $additions,
        public array $removed,
        public array $redundantManual,
    ) {}
}
