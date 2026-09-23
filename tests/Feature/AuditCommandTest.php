<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Config;
use Mahbub\SchemaTools\Tests\Fixtures\Audit\Models\Passing;
use Mahbub\SchemaTools\Tests\Fixtures\Audit\Models\SkippedModel;
use Mahbub\SchemaTools\Tests\Fixtures\CheckFactories\Factories\DefaultConnFactory;
use Mahbub\SchemaTools\Tests\Fixtures\CheckFactories\Factories\NotTrackedFactory;
use Mahbub\SchemaTools\Tests\Fixtures\CheckFactories\Factories\PassingFactory;

use function Pest\Laravel\artisan;

it('reports model and factory drift and fails', function (): void {
    Config::set('schema-tools.models_path', __DIR__ . '/../Fixtures/Audit/Models');
    Config::set('schema-tools.factories_path', __DIR__ . '/../Fixtures/CheckFactories/Factories');
    Config::set('schema-tools.declaration_style');

    $this->workspaceFile('tcb-schema.sql', AUDIT_SCHEMA . "\n\n" . FACTORY_SCHEMA);
    $this->manifestFile(['tcb' => [...AUDIT_TABLES, 'FPassing']]);

    artisan('schema:audit')
        ->expectsOutputToContain('OK   ' . Passing::class)
        ->expectsOutputToContain('connection not declared on the model')
        ->expectsOutputToContain('SKIP ' . SkippedModel::class . ' [other.Whatever]')
        ->expectsOutputToContain('is absent from the manifest')
        ->expectsOutputToContain('Skipped 1 model(s) on non-fixture connections.')
        ->expectsOutputToContain('OK   ' . PassingFactory::class)
        ->expectsOutputToContain('FAIL ' . NotTrackedFactory::class . ' [tcb.FUntracked]')
        ->expectsOutputToContain('is not tracked in the manifest')
        ->expectsOutputToContain('SKIP ' . DefaultConnFactory::class . ' [' . Config::string('database.default') . '.FDefaulter]')
        ->expectsOutputToContain('Skipped 1 factory on non-fixture connections.')
        ->assertExitCode(1);
})->group('need_review');

it('passes cleanly when nothing is out of sync', function (): void {
    Config::set('schema-tools.models_path', __DIR__ . '/../Fixtures/Empty');
    Config::set('schema-tools.factories_path', __DIR__ . '/../Fixtures/Empty');

    artisan('schema:audit')
        ->expectsOutputToContain('0 model issue(s), 0 manifest issue(s).')
        ->expectsOutputToContain('Checked 0 factories, 0 issue(s) found.')
        ->assertExitCode(0);
})->group('need_review');

it('rewrites the models with Rector before auditing when asked to fix', function (): void {
    Config::set('schema-tools.rector_binary', fakeRector());
    Config::set('schema-tools.models_path', __DIR__ . '/../Fixtures/Audit/Models');
    Config::set('schema-tools.factories_path', __DIR__ . '/../Fixtures/Empty');
    $this->workspaceFile('tcb-schema.sql', AUDIT_SCHEMA);
    $this->manifestFile(['tcb' => AUDIT_TABLES]);

    artisan('schema:audit --fix')
        ->expectsOutputToContain('Rector')
        ->expectsOutputToContain('process ' . __DIR__ . '/../Fixtures/Audit/Models --config')
        ->expectsOutputToContain('connection not declared on the model')
        ->assertExitCode(1);
})->group('need_review');

it('audits cleanly after a fix run that finds nothing to do', function (): void {
    Config::set('schema-tools.rector_binary', fakeRector());
    Config::set('schema-tools.models_path', $this->workspace . '/nowhere');
    Config::set('schema-tools.factories_path', __DIR__ . '/../Fixtures/Empty');

    artisan('schema:audit --fix')
        ->expectsOutputToContain('No model paths to fix.')
        ->expectsOutputToContain('0 model issue(s), 0 manifest issue(s).')
        ->assertExitCode(0);
})->group('need_review');

it('audits anyway and says so when Rector fails', function (): void {
    Config::set('schema-tools.rector_binary', fakeRector(1));
    Config::set('schema-tools.models_path', __DIR__ . '/../Fixtures/Audit/Models');
    Config::set('schema-tools.factories_path', __DIR__ . '/../Fixtures/Empty');
    $this->workspaceFile('tcb-schema.sql', AUDIT_SCHEMA);
    $this->manifestFile(['tcb' => AUDIT_TABLES]);

    artisan('schema:audit --fix')
        ->expectsOutputToContain('Rector exited with code 1; the audit below reflects the models as they are.')
        ->expectsOutputToContain('connection not declared on the model')
        ->assertExitCode(1);
})->group('need_review');
