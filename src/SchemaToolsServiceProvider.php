<?php

declare(strict_types=1);

namespace Mahbub\SchemaTools;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use Mahbub\SchemaTools\Commands\AuditCommand;
use Mahbub\SchemaTools\Commands\DetectCommand;
use Mahbub\SchemaTools\Commands\DumpCommand;
use Mahbub\SchemaTools\Support\FixtureModels;
use RuntimeException;

final class SchemaToolsServiceProvider extends ServiceProvider
{
    #[\Override]
    public function register(): void
    {
        Config::set('schema-tools', $this->configuration(base_path('schema-tools.php')));

        $this->app->singleton(FixtureModels::class);
    }

    public function boot(): void
    {
        if (!$this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__ . '/../stubs/schema-tools.php' => base_path('schema-tools.php'),
        ], 'schema-tools-config');

        $this->commands([
            DetectCommand::class,
            DumpCommand::class,
            AuditCommand::class,
        ]);
    }

    /**
     * The package defaults with the project's `schema-tools.php` laid over
     * them: `paths` key by key, every other key whole. The file lives in the
     * project root rather than `config/`, so only this dev-only package ever
     * loads it.
     *
     * @return array<mixed>
     */
    private function configuration(string $file): array
    {
        $defaults = [
            'connections' => [],
            'paths' => [
                'schema' => database_path('schema'),
                'models' => app_path('Models'),
                'queries' => app_path('Queries'),
                'factories' => database_path('factories'),
            ],
            'declaration_style' => 'attributes',
        ];

        if (!is_file($file)) {
            return $defaults;
        }

        $project = require $file;

        if (!is_array($project)) {
            throw new RuntimeException("`{$file}` must return an array.");
        }

        $paths = $project['paths'] ?? [];

        if (!is_array($paths)) {
            throw new InvalidArgumentException("`paths` in `{$file}` must be an array.");
        }

        return [
            ...$defaults,
            ...$project,
            'paths' => [...$defaults['paths'], ...$paths],
        ];
    }
}
