<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Config;

use function Pest\Laravel\artisan;

it('reports model and factory drift and fails', function (): void {
    Config::set('schema-tools.models_path', dirname(__DIR__) . '/Fixtures/Audit/Models');
    Config::set('schema-tools.factories_path', dirname(__DIR__) . '/Fixtures/CheckFactories/Factories');

    $this->workspaceFile('tcb-schema.sql', AUDIT_SCHEMA . "\n\n" . FACTORY_SCHEMA);
    $this->workspaceFile('source-tables.php', '<?php return ' . var_export(['tcb' => AUDIT_TABLES], true) . ';');

    artisan('schema:audit')
        ->expectsOutputToContain('Passing')
        ->expectsOutputToContain('connection not declared on the model')
        ->expectsOutputToContain('is absent from the manifest')
        ->expectsOutputToContain('Skipped 1 model(s) on non-fixture connections.')
        ->expectsOutputToContain('is not tracked in the manifest')
        ->expectsOutputToContain('Skipped connections without a fixture: (default)')
        ->assertExitCode(1);
})->group('need_review');

it('passes cleanly when nothing is out of sync', function (): void {
    Config::set('schema-tools.models_path', dirname(__DIR__) . '/Fixtures/Empty');
    Config::set('schema-tools.factories_path', dirname(__DIR__) . '/Fixtures/Empty');

    artisan('schema:audit')
        ->expectsOutputToContain('0 model issue(s), 0 manifest issue(s).')
        ->expectsOutputToContain('Checked 0 factories, 0 issue(s) found.')
        ->assertExitCode(0);
})->group('need_review');
