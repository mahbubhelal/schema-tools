<?php

declare(strict_types=1);

namespace Mahbub\SchemaTools\Support;

use Illuminate\Support\Facades\Config;

/**
 * Reads and writes the manifest — the single source of truth for which source
 * tables and views the project relies on, per connection. It has two sections:
 * `manual`, curated by hand and left alone by every command, above
 * `generated`, which `schema:detect` rebuilds on each run.
 */
final class Manifest
{
    public function path(): string
    {
        return Config::string('schema-tools.manifest_path');
    }

    /**
     * Load the manifest. A missing file yields empty sections; a flat legacy
     * manifest (connection => names, no sections) is read as the generated
     * section and takes the two-section shape on the next write.
     */
    public function load(): ManifestData
    {
        $path = $this->path();

        if (!is_file($path)) {
            return new ManifestData(manual: [], generated: []);
        }

        /** @var array<string, mixed> */
        $raw = require $path;

        if (!array_key_exists('manual', $raw) && !array_key_exists('generated', $raw)) {
            /** @var array<string, list<string>> */
            $legacy = $raw;

            return new ManifestData(manual: [], generated: $legacy);
        }

        /** @var array<string, list<string>> */
        $manual = $raw['manual'] ?? [];

        /** @var array<string, list<string>> */
        $generated = $raw['generated'] ?? [];

        return new ManifestData(manual: $manual, generated: $generated);
    }

    /**
     * Serialise a manifest to a Pint-clean PHP file. When the file already has
     * a generated block where this writer puts it — last, closing right before
     * the final `];` — only that block is replaced, so the manual section
     * survives byte for byte, comments included. Otherwise the whole file is
     * written afresh, manual section above generated.
     */
    public function write(ManifestData $manifest): void
    {
        $path = $this->path();
        $generated = $this->section('generated', $manifest->generated);

        if (is_file($path)) {
            $spliced = preg_replace_callback(
                '/^    \'generated\' => \[(?:\],\n|\n.*?^    \],\n)(?=\];\s*\z)/ms',
                static fn (): string => $generated,
                (string) file_get_contents($path),
                1,
                $count,
            );

            if ($count === 1) {
                file_put_contents($path, $spliced);

                return;
            }
        }

        $contents = <<<PHP
            <?php

            declare(strict_types=1);

            /**
             * Source tables and views this project relies on, per database connection.
             *
             * `manual` is yours. List here what `php artisan schema:detect` cannot see —
             * a table used only through raw SQL outside the queries paths, by a seed, by
             * a test — and it stays exactly as written, comments included: no command
             * ever touches this section.
             *
             * `generated` belongs to `schema:detect`, which rebuilds it on every run from
             * the configured models, queries and views paths: new names are appended,
             * names no longer referenced are removed. Do not edit it by hand.
             *
             * `schema:dump` (what DDL to pull) and `schema:audit` (keeping the fixtures
             * and factories aligned with what the code uses) read both sections together.
             *
             * @return array{manual: array<string, list<string>>, generated: array<string, list<string>>}
             */

            return [
            {$this->section('manual', $manifest->manual)}{$generated}];

            PHP;

        file_put_contents($path, rtrim($contents, "\n") . "\n");
    }

    /**
     * @param  array<string, list<string>>  $names
     */
    private function section(string $key, array $names): string
    {
        if ($names === []) {
            return "    '{$key}' => [],\n";
        }

        $body = "    '{$key}' => [\n";

        foreach ($names as $connection => $list) {
            $body .= "        '{$connection}' => [\n";

            foreach ($list as $name) {
                $body .= "            '{$name}',\n";
            }

            $body .= "        ],\n";
        }

        return $body . "    ],\n";
    }
}
