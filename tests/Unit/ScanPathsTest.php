<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Config;
use Mahbub\SchemaTools\Support\ScanPaths;

beforeEach(function (): void {
    foreach (['modules/alpha/src/Models', 'modules/beta/src/Models', 'app/Models'] as $directory) {
        mkdir($this->workspace . '/' . $directory, 0o777, true);
    }
});

it('resolves a single directory', function (): void {
    Config::set('schema-tools.models_path', $this->workspace . '/app/Models');

    expect((new ScanPaths)->resolve('models_path'))->toBe([$this->workspace . '/app/Models']);
})->group('need_review');

it('expands a list of directories and glob patterns, without duplicates', function (): void {
    Config::set('schema-tools.models_path', [
        $this->workspace . '/app/Models',
        $this->workspace . '/modules/*/src/Models',
        $this->workspace . '/app/Models',
    ]);

    expect((new ScanPaths)->resolve('models_path'))->toBe([
        $this->workspace . '/app/Models',
        $this->workspace . '/modules/alpha/src/Models',
        $this->workspace . '/modules/beta/src/Models',
    ]);
})->group('need_review');

it('resolves nothing for :dataset', function (mixed $configured): void {
    Config::set('schema-tools.models_path', $configured);

    expect((new ScanPaths)->resolve('models_path'))->toBe([]);
})->with([
    'a directory that does not exist' => ['/nowhere/at/all'],
    'a pattern that matches only files' => [fn (): string => $this->workspaceFile('app/Models/User.php', '')],
    'an empty string' => [''],
    'entries that are not strings' => [[null, 42]],
])->group('need_review');
