<?php

declare(strict_types=1);

namespace Mahbub\SchemaTools\Support;

final readonly class Column
{
    public function __construct(
        public string $type,
        public bool $nullable,
        public bool $hasDefault,
        public bool $isIdentity,
    ) {}

    /**
     * A column an INSERT would be rejected without: NOT NULL, no database
     * default, and not an identity the server fills in.
     */
    public function isRequired(): bool
    {
        return !$this->nullable && !$this->hasDefault && !$this->isIdentity;
    }
}
