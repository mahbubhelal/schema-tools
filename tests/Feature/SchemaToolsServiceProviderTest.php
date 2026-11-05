<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\File;
use Mahbub\SchemaTools\SchemaToolsServiceProvider;

afterEach(function (): void {
    File::delete(base_path('schema-tools.php'));
});

it('registers the schema commands', function (): void {
    $commands = array_keys(resolve(Kernel::class)->all());

    expect($commands)->toContain('schema:detect', 'schema:dump', 'schema:audit');
})->group('need_review');

it('falls back to the defaults without a schema-tools.php', function (): void {
    new SchemaToolsServiceProvider(app())->register();

    expect(config('schema-tools'))->toBe([
        'connections' => [],
        'paths' => [
            'schema' => database_path('schema'),
            'models' => app_path('Models'),
            'queries' => app_path('Queries'),
            'factories' => database_path('factories'),
        ],
        'declaration_style' => 'attributes',
    ]);
})->group('need_review');

it('lays the project root schema-tools.php over the defaults, paths key by key', function (): void {
    File::put(base_path('schema-tools.php'), "<?php return ['connections' => ['sugar' => ['sources' => ['contacts']]], 'paths' => ['models' => '/app/Domain'], 'declaration_style' => null];");

    new SchemaToolsServiceProvider(app())->register();

    expect(config('schema-tools'))->toBe([
        'connections' => ['sugar' => ['sources' => ['contacts']]],
        'paths' => [
            'schema' => database_path('schema'),
            'models' => '/app/Domain',
            'queries' => app_path('Queries'),
            'factories' => database_path('factories'),
        ],
        'declaration_style' => null,
    ]);
})->group('need_review');

it('rejects a schema-tools.php that does not return an array', function (): void {
    File::put(base_path('schema-tools.php'), '<?php return 1;');

    expect(fn () => new SchemaToolsServiceProvider(app())->register())
        ->toThrow(RuntimeException::class, '`' . base_path('schema-tools.php') . '` must return an array.');
})->group('need_review');

it('rejects a schema-tools.php whose paths is not an array', function (): void {
    File::put(base_path('schema-tools.php'), "<?php return ['paths' => '/app/Models'];");

    expect(fn () => new SchemaToolsServiceProvider(app())->register())
        ->toThrow(InvalidArgumentException::class, '`paths` in `' . base_path('schema-tools.php') . '` must be an array.');
})->group('need_review');

it('publishes the stub to the project root', function (): void {
    expect(SchemaToolsServiceProvider::pathsToPublish(SchemaToolsServiceProvider::class, 'schema-tools-config'))
        ->toBe([dirname(__DIR__, 2) . '/src/../stubs/schema-tools.php' => base_path('schema-tools.php')]);
})->group('need_review');

it('ships a stub that loads as an array', function (): void {
    expect(require dirname(__DIR__, 2) . '/stubs/schema-tools.php')->toBe(['connections' => []]);
})->group('need_review');

it('registers nothing when not running in the console', function (): void {
    $app = Mockery::mock(Application::class);
    $app->shouldReceive('runningInConsole')->once()->andReturnFalse();

    $provider = new SchemaToolsServiceProvider($app);

    $provider->boot();
})->group('need_review');
