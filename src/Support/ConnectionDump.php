<?php

declare(strict_types=1);

namespace Mahbub\SchemaTools\Support;

final readonly class ConnectionDump
{
    /**
     * @param  string|null  $schemaContent  Reconstructed table DDL, or null when the connection has no tables.
     * @param  string|null  $viewsContent  Reconstructed view DDL, or null when the connection has no views.
     * @param  list<string>  $warnings  Not-found, wrong-type, dropped and source-failure notes.
     */
    private function __construct(
        public string $connection,
        public DumpStatus $status,
        public string $schemaPath,
        public string $viewsPath,
        public ?string $schemaContent,
        public ?string $viewsContent,
        public int $tableCount,
        public int $viewCount,
        public array $warnings,
        public ?string $skipReason,
    ) {}

    /**
     * @param  list<string>  $warnings
     */
    public static function dumped(
        string $connection,
        string $schemaPath,
        string $viewsPath,
        ?string $schemaContent,
        ?string $viewsContent,
        int $tableCount,
        int $viewCount,
        array $warnings,
    ): self {
        return new self($connection, DumpStatus::Dumped, $schemaPath, $viewsPath, $schemaContent, $viewsContent, $tableCount, $viewCount, $warnings, null);
    }

    public static function skipped(string $connection, string $schemaPath, string $viewsPath, string $reason): self
    {
        return new self($connection, DumpStatus::Skipped, $schemaPath, $viewsPath, null, null, 0, 0, [], $reason);
    }

    /**
     * @param  list<string>  $warnings  The last one says why the source could not be read.
     */
    public static function failed(string $connection, string $schemaPath, string $viewsPath, array $warnings): self
    {
        return new self($connection, DumpStatus::Failed, $schemaPath, $viewsPath, null, null, 0, 0, $warnings, null);
    }
}
