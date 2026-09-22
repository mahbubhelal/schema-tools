<?php

declare(strict_types=1);

namespace Mahbub\SchemaTools\Declarations;

/**
 * Everything known about one model on a fixture-backed connection: what it
 * resolves to, what the DDL expects (null when the table is missing from the
 * fixture, so nothing can be expected), the issues that comparison found, and
 * the arguments of the nearest `#[Table]` attribute a trait or an ancestor
 * carries — which the model's own `#[Table]` would shadow entirely, so a
 * rewrite that adds one has to carry them over.
 */
final readonly class ModelFacts
{
    /**
     * @param  list<string>  $issues
     * @param  array<string, string|bool>  $inheritedTable  name, key, keyType, incrementing, timestamps — the non-null ones.
     */
    public function __construct(
        public Effective $effective,
        public ?Expectation $expected,
        public array $issues,
        public array $inheritedTable = [],
    ) {}
}
