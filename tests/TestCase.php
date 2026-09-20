<?php

declare(strict_types=1);

namespace Mahbub\SchemaTools\Tests;

use Mahbub\SchemaTools\SchemaToolsServiceProvider;
use Orchestra\Testbench\TestCase as TestbenchTestCase;

abstract class TestCase extends TestbenchTestCase
{
    /**
     * A throwaway directory that every test may write schema fixtures and a
     * manifest into; `schema-tools.schema_path` and `manifest_path` point here.
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

        $app['config']->set('schema-tools.schema_path', $this->workspace);
        $app['config']->set('schema-tools.manifest_path', $this->workspace . '/source-tables.php');
        $app['config']->set('schema-tools.models_path', __DIR__ . '/Fixtures/Empty');
        $app['config']->set('schema-tools.queries_path', __DIR__ . '/Fixtures/Empty');
        $app['config']->set('schema-tools.factories_path', __DIR__ . '/Fixtures/Empty');
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

        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $directory . '/' . $entry;

            if (is_dir($path)) {
                $this->deleteDirectory($path);
            } else {
                unlink($path);
            }
        }

        rmdir($directory);
    }
}
