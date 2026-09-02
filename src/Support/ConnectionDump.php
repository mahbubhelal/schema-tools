<?php

declare(strict_types=1);

namespace Mahbub\SchemaTools\Support;

final readonly class ConnectionDump
{
    /**
     * @param  string|null  $schemaContent  Reconstructed table DDL, or null when the connection has no tables.
     * @param  string|null  $viewsContent  Reconstructed view DDL, or null when the connection has no views.
     * @param  list<string>  $warnings  Not-found, wrong-type, dropped and source-failure notes.
     * @param  bool  $failed  A source query failed; the fixtures must be left untouched.
     * @param  bool  $skipped  The connection has no manifest entries.
     */
    public function __construct(
        public string $connection,
        public string $schemaPath,
        public string $viewsPath,
        public ?string $schemaContent,
        public ?string $viewsContent,
        public int $tableCount,
        public int $viewCount,
        public array $warnings,
        public bool $failed,
        public bool $skipped,
    ) {}
}
