<?php

declare(strict_types=1);

namespace Mahbub\SchemaTools\Declarations;

/**
 * What an instantiated model resolves to, wherever the values come from — its
 * own declarations, a parent, a trait or Eloquent's defaults — plus whether the
 * connection and the timestamps switch are declared at all.
 */
final readonly class Effective
{
    public function __construct(
        public string $connection,
        public string $table,
        public ?string $primaryKey,
        public string $keyType,
        public bool $incrementing,
        public bool $timestamps,
        public bool $connectionDeclared,
        public bool $timestampsDeclared,
    ) {}
}
