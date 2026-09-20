<?php

declare(strict_types=1);

namespace Mahbub\SchemaTools\Support;

use Illuminate\Support\Facades\Config;

/**
 * Resolves a configured scan path to the directories that currently exist. A
 * setting may be one directory, a glob pattern (so every module's models
 * directory can be named at once), or a list of either.
 */
final class ScanPaths
{
    /**
     * @return list<string>
     */
    public function resolve(string $key): array
    {
        $configured = Config::get("schema-tools.{$key}");
        $patterns = is_array($configured) ? $configured : [$configured];
        $directories = [];

        foreach ($patterns as $pattern) {
            if (!is_string($pattern) || $pattern === '') {
                continue;
            }

            $matches = glob($pattern, GLOB_ONLYDIR);

            foreach ($matches === false ? [] : $matches as $directory) {
                $directories[] = $directory;
            }
        }

        return array_values(array_unique($directories));
    }
}
