<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Foundation\Application;
use Mahbub\SchemaTools\SchemaToolsServiceProvider;

it('registers the schema commands and merges its config', function (): void {
    $commands = array_keys(resolve(Kernel::class)->all());

    expect($commands)->toContain('schema:detect', 'schema:dump', 'schema:audit')
        ->and(config('schema-tools'))
        ->toHaveKeys(['schema_path', 'manifest_path', 'models_path', 'queries_path', 'factories_path']);
})->group('need_review');

it('registers nothing when not running in the console', function (): void {
    $app = Mockery::mock(Application::class);
    $app->shouldReceive('runningInConsole')->once()->andReturnFalse();

    $provider = new SchemaToolsServiceProvider($app);

    expect($provider->boot())->toBeNull();
})->group('need_review');
