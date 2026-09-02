<?php

declare(strict_types=1);

namespace Mahbub\SchemaTools\Support;

final readonly class DumpResult
{
    /**
     * @param  list<ConnectionDump>  $connections
     */
    public function __construct(public array $connections) {}
}
