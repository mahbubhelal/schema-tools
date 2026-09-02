<?php

declare(strict_types=1);

namespace Mahbub\SchemaTools\Support;

final readonly class Report
{
    /**
     * @param  string  $subject  The class under audit.
     * @param  string  $location  The connection.table it maps to.
     * @param  list<string>  $issues  Empty when the subject passes.
     */
    public function __construct(
        public string $subject,
        public string $location,
        public array $issues,
    ) {}

    public function passes(): bool
    {
        return $this->issues === [];
    }
}
