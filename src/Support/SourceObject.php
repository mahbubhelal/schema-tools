<?php

declare(strict_types=1);

namespace Mahbub\SchemaTools\Support;

final readonly class SourceObject
{
    /**
     * @param  string  $name  The name exactly as the source catalog spells it.
     */
    public function __construct(
        public string $name,
        public ObjectKind $kind,
    ) {}
}
