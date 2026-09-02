<?php

declare(strict_types=1);

use Mahbub\SchemaTools\Support\Manifest;

it('exposes the configured manifest path', function (): void {
    expect((new Manifest)->path())->toBe($this->workspace . '/source-tables.php');
})->group('need_review');

it('loads an empty map when the manifest file is absent', function (): void {
    expect((new Manifest)->load())->toBe([]);
})->group('need_review');

it('writes a manifest that round-trips back to the same array', function (): void {
    $manifest = new Manifest;

    $manifest->write([
        'tcb' => ['Center', 'press'],
        'tcbpermission' => ['MasterProduct'],
    ]);

    expect($manifest->load())->toBe([
        'tcb' => ['Center', 'press'],
        'tcbpermission' => ['MasterProduct'],
    ]);

    $contents = (string) file_get_contents($manifest->path());

    expect($contents)
        ->toContain('declare(strict_types=1);')
        ->toContain("return [\n")
        ->toContain("    'tcb' => [\n")
        ->toContain("        'Center',\n")
        ->toContain("    'tcbpermission' => [\n");
})->group('need_review');
