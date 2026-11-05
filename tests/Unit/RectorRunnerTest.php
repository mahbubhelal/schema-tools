<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Config;
use Mahbub\SchemaTools\Support\RectorRunner;

it('runs the project Rector from the project root over the model paths with the bundled config and the cache cleared', function (): void {
    $this->fakeRector();
    Config::set('schema-tools.paths.models', __DIR__ . '/../Fixtures/Audit/Models');

    $run = resolve(RectorRunner::class)->fix();

    expect($run)
        ->exitCode->toBe(0)
        ->succeeded()->toBeTrue()
        ->output->toBe(
            'process ' . __DIR__ . '/../Fixtures/Audit/Models --config ' . RectorRunner::configFile() . " --clear-cache --no-progress-bar\n"
            . 'cwd=' . $this->workspace,
        );
})->group('need_review');

it('reports a failing Rector run', function (): void {
    $this->fakeRector(2);
    Config::set('schema-tools.paths.models', __DIR__ . '/../Fixtures/Audit/Models');

    $run = resolve(RectorRunner::class)->fix();

    expect($run)
        ->exitCode->toBe(2)
        ->succeeded()->toBeFalse();
})->group('need_review');

it('does nothing when no model path exists', function (): void {
    $this->fakeRector();
    Config::set('schema-tools.paths.models', $this->workspace . '/nowhere');

    expect(resolve(RectorRunner::class)->fix())
        ->exitCode->toBe(0)
        ->output->toBe('No model paths to fix.');
})->group('need_review');

it('refuses to run without vendor/bin/rector in the project', function (): void {
    app()->setBasePath($this->workspace);

    expect(fn () => resolve(RectorRunner::class)->fix())
        ->toThrow(RuntimeException::class, 'Rector binary `' . $this->workspace . '/vendor/bin/rector` not found; install rector/rector.');
})->group('need_review');

it('ships its Rector config next to the package', function (): void {
    expect(RectorRunner::configFile())->toBe(dirname(__DIR__, 2) . '/rector-fix.php')
        ->and(RectorRunner::configFile())->toBeFile();
})->group('need_review');
