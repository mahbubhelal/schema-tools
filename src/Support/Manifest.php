<?php

declare(strict_types=1);

namespace Mahbub\SchemaTools\Support;

use Illuminate\Support\Facades\Config;

/**
 * Reads and writes the curated table manifest — the single source of truth for
 * which source tables and views the project relies on, per connection.
 */
final class Manifest
{
    public function path(): string
    {
        return Config::string('schema-tools.manifest_path');
    }

    /**
     * Load the manifest. A missing file yields an empty map.
     *
     * @return array<string, list<string>>
     */
    public function load(): array
    {
        $path = $this->path();

        if (!is_file($path)) {
            return [];
        }

        /** @var array<string, list<string>> */
        return require $path;
    }

    /**
     * Serialise a manifest back to a Pint-clean PHP file.
     *
     * @param  array<string, list<string>>  $manifest
     */
    public function write(array $manifest): void
    {
        $body = '';

        foreach ($manifest as $connection => $names) {
            $body .= "    '{$connection}' => [\n";

            foreach ($names as $name) {
                $body .= "        '{$name}',\n";
            }

            $body .= "    ],\n";
        }

        $contents = <<<PHP
            <?php

            declare(strict_types=1);

            /**
             * Source tables and views this project relies on, per database connection.
             *
             * Seeded by `php artisan schema:detect` (which scans the configured models and
             * queries paths) and then hand-curated — edit freely. Re-running the detector
             * preserves your order and additions and only warns about entries it can no
             * longer find in code; it never deletes on your behalf.
             *
             * Consumed by `schema:dump` (what DDL to pull) and `schema:audit` (keeping the
             * schema fixtures and factories aligned with what the code actually uses).
             *
             * @return array<string, list<string>>
             */

            return [
            {$body}];

            PHP;

        file_put_contents($this->path(), rtrim($contents, "\n") . "\n");
    }
}
