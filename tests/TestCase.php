<?php

declare(strict_types=1);

namespace Mahbub\SchemaTools\Tests;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Mahbub\SchemaTools\SchemaToolsServiceProvider;
use Orchestra\Testbench\TestCase as TestbenchTestCase;

abstract class TestCase extends TestbenchTestCase
{
    /**
     * A throwaway directory that every test may write schema fixtures and the
     * generated manifest into; `schema-tools.paths.schema` points here.
     */
    protected string $workspace;

    #[\Override]
    protected function tearDown(): void
    {
        $this->deleteDirectory($this->workspace);

        parent::tearDown();
    }

    #[\Override]
    protected function getPackageProviders($app): array
    {
        return [
            SchemaToolsServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $this->workspace = sys_get_temp_dir() . '/schema-tools-test-' . bin2hex(random_bytes(8));
        mkdir($this->workspace, 0o777, true);

        $app['config']->set('database.connections.tcb', $this->sourceConnection('TCB'));
        $app['config']->set('database.connections.tcbpermission', $this->sourceConnection('TCBPermission'));
        $app['config']->set('database.connections.sugar', $this->sourceConnection('sugar', 'mysql'));
        $app['config']->set('database.connections.legacy', $this->sourceConnection('legacy', 'pgsql'));

        $app['config']->set('schema-tools.paths', [
            'schema' => $this->workspace,
            'models' => __DIR__ . '/Fixtures/Empty',
            'queries' => __DIR__ . '/Fixtures/Empty',
            'factories' => __DIR__ . '/Fixtures/Empty',
        ]);
    }

    /**
     * Write a file into the workspace, creating parent directories as needed,
     * and return its absolute path.
     */
    protected function workspaceFile(string $name, string $contents): string
    {
        $path = $this->workspace . '/' . $name;
        $directory = dirname($path);

        if (!is_dir($directory)) {
            mkdir($directory, 0o777, true);
        }

        file_put_contents($path, $contents);

        return $path;
    }

    /**
     * Make the workspace the project root and install a stand-in Rector binary
     * there that echoes its arguments and working directory, then exits with the
     * given code.
     */
    protected function fakeRector(int $exitCode = 0): void
    {
        $this->workspaceFile('vendor/bin/rector', <<<PHP
            <?php
            echo implode(' ', array_slice(\$argv, 1)), "\\n", 'cwd=', getcwd();
            exit({$exitCode});
            PHP);

        app()->setBasePath($this->workspace);
    }

    /**
     * Write the generated manifest into the workspace and list the manual names
     * as each connection's `sources`.
     *
     * @param  array<string, list<string>>  $generated
     * @param  array<string, list<string>>  $manual
     */
    protected function manifestFile(array $generated, array $manual = []): string
    {
        foreach ($manual as $connection => $sources) {
            Config::set("schema-tools.connections.{$connection}.sources", $sources);
        }

        return $this->workspaceFile('source-tables.php', '<?php return ' . var_export($generated, true) . ';');
    }

    /**
     * @return array<string, mixed>
     */
    private function sourceConnection(string $database, string $driver = 'sqlsrv'): array
    {
        return [
            'driver' => $driver,
            'host' => 'localhost',
            'port' => '1433',
            'database' => $database,
            'username' => 'sa',
            'password' => 'secret',
            'prefix' => '',
            'prefix_indexes' => true,
        ];
    }

    private function deleteDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $entries = scandir($directory);

        foreach ($entries === false ? [] : $entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $directory . '/' . $entry;

            if (is_dir($path)) {
                $this->deleteDirectory($path);
            } else {
                File::delete($path);
            }
        }

        rmdir($directory);
    }
}
