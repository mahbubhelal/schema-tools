<?php

declare(strict_types=1);

namespace Mahbub\SchemaTools\Dialects;

use Mahbub\SchemaTools\Support\SourceObject;

/**
 * Everything that differs per database driver when a fixture is rebuilt: how a
 * manifest name is resolved at the source, how one table or view becomes a
 * fixture block, and how the blocks are assembled into a loadable file.
 */
interface Dialect
{
    /**
     * The catalog entry for a manifest name, or null when the source has none.
     */
    public function resolve(string $connection, string $name): ?SourceObject;

    public function tableBlock(string $connection, string $table): string;

    public function viewBlock(string $connection, string $view): string;

    /**
     * @param  list<string>  $blocks
     */
    public function schemaFixture(string $connection, array $blocks): string;

    /**
     * @param  list<string>  $blocks
     */
    public function viewsFixture(array $blocks): string;
}
