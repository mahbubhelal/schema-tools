<?php

declare(strict_types=1);

namespace Mahbub\SchemaTools\Support;

use Illuminate\Support\Facades\Config;
use InvalidArgumentException;

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
        $directories = [];

        foreach ($this->patterns($key) as $pattern) {
            array_push($directories, ...Glob::orFail(glob($pattern, GLOB_ONLYDIR), $pattern));
        }

        return array_values(array_unique($directories));
    }

    /**
     * @return list<string>
     */
    private function patterns(string $key): array
    {
        $configured = Config::get("schema-tools.paths.{$key}");
        $patterns = [];

        foreach (is_array($configured) ? $configured : [$configured] as $pattern) {
            if (!is_string($pattern) || $pattern === '') {
                throw new InvalidArgumentException("`paths.{$key}` in schema-tools.php must be a path or glob pattern, or a list of them.");
            }

            $patterns[] = $pattern;
        }

        return $patterns;
    }
}
