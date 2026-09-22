<?php

declare(strict_types=1);

namespace Mahbub\SchemaTools\Support;

use Illuminate\Support\Facades\Config;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Runs the project's Rector binary over the configured model paths with the
 * package's own config, which registers nothing but DeclareModelSchemaRector.
 * The cache is cleared every run because the rule's outcome depends on the
 * fixtures, which Rector's cache does not watch.
 */
final readonly class RectorRunner
{
    public function __construct(
        private ScanPaths $scanPaths,
    ) {}

    public function fix(): RectorRun
    {
        $binary = Config::string('schema-tools.rector_binary');

        if (!is_file($binary)) {
            throw new RuntimeException("Rector binary `{$binary}` not found; install rector/rector or set `schema-tools.rector_binary`.");
        }

        $paths = $this->scanPaths->resolve('models_path');

        if ($paths === []) {
            return new RectorRun(0, 'No model paths to fix.');
        }

        $process = new Process(
            command: [PHP_BINARY, $binary, 'process', ...$paths, '--config', self::configFile(), '--clear-cache', '--no-progress-bar'],
            cwd: base_path(),
            env: ['SCHEMA_TOOLS_BOOTSTRAP' => base_path('bootstrap/app.php')],
            timeout: null,
        );
        $process->run();

        return new RectorRun($process->getExitCode() ?? 1, trim($process->getOutput() . $process->getErrorOutput()));
    }

    public static function configFile(): string
    {
        return dirname(__DIR__, 2) . '/rector-fix.php';
    }
}
