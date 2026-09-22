<?php

declare(strict_types=1);

namespace Mahbub\SchemaTools\Declarations;

/**
 * What the DDL demands of a model: the key it must declare (null for a heap
 * or a view), the key type when there is a key, and whether incrementing and
 * timestamps must be on.
 */
final readonly class Expectation
{
    public function __construct(
        public ?string $primaryKey,
        public ?string $keyType,
        public bool $incrementing,
        public bool $timestamps,
    ) {}
}
