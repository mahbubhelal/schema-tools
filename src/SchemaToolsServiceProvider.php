<?php

declare(strict_types=1);

namespace Mahbub\SchemaTools;

use Illuminate\Support\ServiceProvider;
use Mahbub\SchemaTools\Commands\AuditCommand;
use Mahbub\SchemaTools\Commands\DetectCommand;
use Mahbub\SchemaTools\Commands\DumpCommand;

final class SchemaToolsServiceProvider extends ServiceProvider
{
    #[\Override]
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/schema-tools.php', 'schema-tools');
    }

    public function boot(): void
    {
        if (!$this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__ . '/../config/schema-tools.php' => config_path('schema-tools.php'),
        ], 'schema-tools-config');

        $this->commands([
            DetectCommand::class,
            DumpCommand::class,
            AuditCommand::class,
        ]);
    }
}
