<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Config;
use Mahbub\SchemaTools\Support\RectorRunner;

/**
 * A stand-in for the Rector binary that echoes its arguments and the
 * bootstrap it was told about, then exits with the given code.
 */
function fakeRector(int $exitCode = 0): string
{
    return test()->workspaceFile('rector', <<<PHP
        <?php
        echo implode(' ', array_slice(\$argv, 1)), "\\n", 'bootstrap=', getenv('SCHEMA_TOOLS_BOOTSTRAP');
        exit({$exitCode});
        PHP);
}

it('runs Rector over the model paths with the bundled config, the cache cleared and the bootstrap named', function (): void {
    Config::set('schema-tools.rector_binary', fakeRector());
    Config::set('schema-tools.models_path', __DIR__ . '/../Fixtures/Audit/Models');

    $run = resolve(RectorRunner::class)->fix();

    expect($run)
        ->exitCode->toBe(0)
        ->succeeded()->toBeTrue()
        ->output->toBe(
            'process ' . __DIR__ . '/../Fixtures/Audit/Models --config ' . RectorRunner::configFile() . " --clear-cache --no-progress-bar\n"
            . 'bootstrap=' . base_path('bootstrap/app.php'),
        );
})->group('need_review');

it('reports a failing Rector run', function (): void {
    Config::set('schema-tools.rector_binary', fakeRector(2));
    Config::set('schema-tools.models_path', __DIR__ . '/../Fixtures/Audit/Models');

    $run = resolve(RectorRunner::class)->fix();

    expect($run)
        ->exitCode->toBe(2)
        ->succeeded()->toBeFalse();
})->group('need_review');

it('does nothing when no model path exists', function (): void {
    Config::set('schema-tools.rector_binary', fakeRector());
    Config::set('schema-tools.models_path', $this->workspace . '/nowhere');

    expect(resolve(RectorRunner::class)->fix())
        ->exitCode->toBe(0)
        ->output->toBe('No model paths to fix.');
})->group('need_review');

it('refuses to run without a Rector binary', function (): void {
    Config::set('schema-tools.rector_binary', $this->workspace . '/missing');

    expect(fn () => resolve(RectorRunner::class)->fix())
        ->toThrow(RuntimeException::class, 'Rector binary `' . $this->workspace . '/missing` not found');
})->group('need_review');

it('ships its Rector config next to the package', function (): void {
    expect(RectorRunner::configFile())->toBe(dirname(__DIR__, 2) . '/rector-fix.php')
        ->and(is_file(RectorRunner::configFile()))->toBeTrue();
})->group('need_review');
