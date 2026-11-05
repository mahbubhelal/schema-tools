<?php

declare(strict_types=1);

namespace Mahbub\SchemaTools\Support;

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
        $binary = base_path('vendor/bin/rector');

        if (!is_file($binary)) {
            throw new RuntimeException("Rector binary `{$binary}` not found; install rector/rector.");
        }

        $paths = $this->scanPaths->resolve('models');

        if ($paths === []) {
            return new RectorRun(0, 'No model paths to fix.');
        }

        $process = new Process(
            command: [PHP_BINARY, $binary, 'process', ...$paths, '--config', self::configFile(), '--clear-cache', '--no-progress-bar'],
            cwd: base_path(),
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
