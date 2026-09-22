<?php

declare(strict_types=1);

namespace Mahbub\SchemaTools\Support;

final readonly class RectorRun
{
    public function __construct(
        public int $exitCode,
        public string $output,
    ) {}

    public function succeeded(): bool
    {
        return $this->exitCode === 0;
    }
}
