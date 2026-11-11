<?php

declare(strict_types=1);

namespace Mahbub\SchemaTools\Support;

/**
 * The two sections of the manifest: `manual`, the names curated by hand and
 * never touched by the tools, and `generated`, the names `schema:detect`
 * rebuilds on every run. Consumers read both together.
 */
final readonly class ManifestData
{
    /**
     * @param  array<string, list<string>>  $manual  Connection => hand-listed names.
     * @param  array<string, list<string>>  $generated  Connection => detected names.
     */
    public function __construct(
        public array $manual,
        public array $generated,
    ) {}

    /**
     * Every name per connection, manual first, without case-insensitive
     * duplicates — a name listed in both sections counts once, as written
     * under manual.
     *
     * @return array<string, list<string>>
     */
    public function all(): array
    {
        /** @var array<string, array<string, string>> Connection => lowercase name => first spelling. */
        $all = [];

        foreach ([$this->manual, $this->generated] as $section) {
            foreach ($section as $connection => $names) {
                $all[$connection] ??= [];

                foreach ($names as $name) {
                    $all[$connection][strtolower($name)] ??= $name;
                }
            }
        }

        return array_map(array_values(...), $all);
    }

    /**
     * @return list<string>
     */
    public function names(string $connection): array
    {
        return $this->all()[$connection] ?? [];
    }
}
