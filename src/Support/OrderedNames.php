<?php

declare(strict_types=1);

namespace Mahbub\SchemaTools\Support;

final readonly class OrderedNames
{
    /**
     * @param  list<string>  $ordered  The selected names: those already in the fixture in file order, then new ones sorted.
     * @param  list<string>  $dropped  Names in the fixture that were not selected.
     */
    public function __construct(
        public array $ordered,
        public array $dropped,
    ) {}
}
