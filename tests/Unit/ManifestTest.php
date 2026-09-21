<?php

declare(strict_types=1);

use Mahbub\SchemaTools\Support\Manifest;
use Mahbub\SchemaTools\Support\ManifestData;

it('exposes the configured manifest path', function (): void {
    expect((new Manifest)->path())->toBe($this->workspace . '/source-tables.php');
})->group('need_review');

it('loads empty sections when the manifest file is absent', function (): void {
    expect((new Manifest)->load())->toEqual(new ManifestData(manual: [], generated: []));
})->group('need_review');

it('loads the manual and generated sections', function (): void {
    $this->manifestFile(generated: ['tcb' => ['Center']], manual: ['tcb' => ['Extra']]);

    expect((new Manifest)->load())->toEqual(new ManifestData(manual: ['tcb' => ['Extra']], generated: ['tcb' => ['Center']]));
})->group('need_review');

it('reads a flat legacy manifest as the generated section', function (): void {
    $this->workspaceFile('source-tables.php', "<?php return ['tcb' => ['Center'], 'sugar' => ['contacts']];");

    expect((new Manifest)->load())->toEqual(new ManifestData(manual: [], generated: ['tcb' => ['Center'], 'sugar' => ['contacts']]));
})->group('need_review');

it('merges both sections per connection, manual first and without case-insensitive duplicates', function (): void {
    $manifest = new ManifestData(
        manual: ['tcb' => ['Extra', 'center']],
        generated: ['tcb' => ['Center', 'press'], 'sugar' => ['contacts']],
    );

    expect($manifest)
        ->all()->toBe(['tcb' => ['Extra', 'center', 'press'], 'sugar' => ['contacts']])
        ->names('tcb')->toBe(['Extra', 'center', 'press'])
        ->names('none')->toBe([]);
})->group('need_review');

it('writes a fresh manifest with the manual section above the generated one', function (): void {
    $manifest = new Manifest;
    $data = new ManifestData(
        manual: ['sugar' => ['bhea_orders_cstm']],
        generated: ['tcb' => ['Center', 'press'], 'tcbpermission' => ['MasterProduct']],
    );

    $manifest->write($data);

    $contents = (string) file_get_contents($manifest->path());

    expect($manifest->load())->toEqual($data)
        ->and($contents)
        ->toContain('declare(strict_types=1);')
        ->toContain("return [\n    'manual' => [\n        'sugar' => [\n            'bhea_orders_cstm',\n        ],\n    ],\n    'generated' => [\n        'tcb' => [\n            'Center',\n            'press',\n        ],\n        'tcbpermission' => [\n            'MasterProduct',\n        ],\n    ],\n];\n");
})->group('need_review');

it('writes an empty section as a one-liner', function (): void {
    $manifest = new Manifest;

    $manifest->write(new ManifestData(manual: [], generated: []));

    expect((string) file_get_contents($manifest->path()))->toContain("return [\n    'manual' => [],\n    'generated' => [],\n];\n")
        ->and($manifest->load())->toEqual(new ManifestData(manual: [], generated: []));
})->group('need_review');

it('replaces only the generated block, keeping the manual section and its comments verbatim', function (): void {
    $manifest = new Manifest;
    $manifest->write(new ManifestData(manual: ['sugar' => ['bhea_orders_cstm']], generated: ['tcb' => ['Center']]));
    $annotated = str_replace(
        "        'sugar' => [\n",
        "        // raw SQL in the nightly report\n        'sugar' => [\n",
        (string) file_get_contents($manifest->path()),
    );
    file_put_contents($manifest->path(), $annotated);

    $manifest->write(new ManifestData(manual: ['sugar' => ['bhea_orders_cstm']], generated: ['tcb' => ['Center', 'press']]));

    expect((string) file_get_contents($manifest->path()))
        ->toContain("        // raw SQL in the nightly report\n        'sugar' => [\n")
        ->toContain("    'generated' => [\n        'tcb' => [\n            'Center',\n            'press',\n        ],\n    ],\n];\n")
        ->and($manifest->load())->toEqual(new ManifestData(manual: ['sugar' => ['bhea_orders_cstm']], generated: ['tcb' => ['Center', 'press']]));
})->group('need_review');

it('replaces an empty generated one-liner in place as well', function (): void {
    $manifest = new Manifest;
    $manifest->write(new ManifestData(manual: ['sugar' => ['bhea_orders_cstm']], generated: []));
    $annotated = str_replace("    'manual' => [\n", "    // keep me\n    'manual' => [\n", (string) file_get_contents($manifest->path()));
    file_put_contents($manifest->path(), $annotated);

    $manifest->write(new ManifestData(manual: ['sugar' => ['bhea_orders_cstm']], generated: ['tcb' => ['Center']]));

    expect((string) file_get_contents($manifest->path()))
        ->toContain("    // keep me\n    'manual' => [\n")
        ->toContain("    'generated' => [\n        'tcb' => [\n            'Center',\n        ],\n    ],\n];\n");
})->group('need_review');

it('rewrites the whole file when the generated block is not where it writes it', function (): void {
    $this->workspaceFile('source-tables.php', "<?php\n\n// legacy, flat\nreturn ['tcb' => ['Center']];\n");
    $manifest = new Manifest;

    $manifest->write(new ManifestData(manual: [], generated: ['tcb' => ['Center', 'press']]));

    expect((string) file_get_contents($manifest->path()))
        ->not->toContain('// legacy, flat')
        ->toContain("    'manual' => [],\n")
        ->and($manifest->load())->toEqual(new ManifestData(manual: [], generated: ['tcb' => ['Center', 'press']]));
})->group('need_review');
