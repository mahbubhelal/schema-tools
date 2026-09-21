<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Config;
use Mahbub\SchemaTools\Actions\DetectSourceTables;
use Mahbub\SchemaTools\Support\DetectionResult;
use Mahbub\SchemaTools\Support\ManifestData;

function detect(): DetectionResult
{
    return resolve(DetectSourceTables::class)->handle();
}

beforeEach(function (): void {
    Config::set('schema-tools.models_path', dirname(__DIR__) . '/Fixtures/Detect/Models');
    Config::set('schema-tools.queries_path', dirname(__DIR__) . '/Fixtures/Detect/Queries');

    $this->workspaceFile('tcb-schema.sql', <<<'SQL'
        CREATE TABLE [dbo].[Center] (
            [CenterId] int NOT NULL
        );

        CREATE TABLE [dbo].[press] (
            [PressId] int NOT NULL
        );

        CREATE TABLE [dbo].[AuditLog] (
            [AuditLogId] int NOT NULL
        );
        SQL);

    $this->workspaceFile('tcbpermission-schema.sql', "CREATE TABLE [dbo].[MasterProduct] (\n    [Id] int NOT NULL\n);");

    $this->workspaceFile('tcb-views.sql', <<<'SQL'
        DROP VIEW IF EXISTS [dbo].[vReport];

        CREATE VIEW [dbo].[vReport] AS
            SELECT a.AuditLogId FROM AuditLog a;
        SQL);
});

it('detects tables from models, pivots, queries and views, and rebuilds the generated section', function (): void {
    $this->manifestFile(generated: ['tcb' => ['Center', 'GhostTable'], 'tcbpermission' => ['MasterProduct']]);

    $result = detect();

    expect($result->manifest)->toEqual(new ManifestData(manual: [], generated: [
        'tcb' => ['Center', 'AuditLog', 'CenterProduct', 'Ephemeral', 'press'],
        'tcbpermission' => ['MasterProduct'],
    ]));

    $tcb = collect($result->connections)->firstWhere('connection', 'tcb');
    $tcbpermission = collect($result->connections)->firstWhere('connection', 'tcbpermission');

    expect($tcb)
        ->total->toBe(5)
        ->manualCount->toBe(0)
        ->additions->toBe(['AuditLog', 'CenterProduct', 'Ephemeral', 'press'])
        ->removed->toBe(['GhostTable'])
        ->redundantManual->toBe([]);

    expect($tcbpermission)
        ->total->toBe(1)
        ->additions->toBe([])
        ->removed->toBe([]);

    expect($result)
        ->droppedConnections->toBe([])
        ->hasChanges()->toBeTrue();
})->group('need_review');

it('leaves the manual section untouched and flags a manual name it detects anyway', function (): void {
    $this->manifestFile(
        generated: ['tcb' => ['Center'], 'tcbpermission' => ['MasterProduct']],
        manual: ['tcb' => ['NightlyReport', 'press'], 'legacy' => ['old_table']],
    );

    $result = detect();

    expect($result->manifest)
        ->manual->toBe(['tcb' => ['NightlyReport', 'press'], 'legacy' => ['old_table']])
        ->generated->toBe([
            'tcb' => ['Center', 'AuditLog', 'CenterProduct', 'Ephemeral', 'press'],
            'tcbpermission' => ['MasterProduct'],
        ]);

    expect(collect($result->connections)->firstWhere('connection', 'tcb'))
        ->total->toBe(6)
        ->manualCount->toBe(2)
        ->removed->toBe([])
        ->redundantManual->toBe(['press']);
})->group('need_review');

it('drops the generated section of a connection that no longer has a fixture', function (): void {
    $this->manifestFile(generated: [
        'tcb' => ['Center', 'AuditLog', 'CenterProduct', 'Ephemeral', 'press'],
        'gone' => ['Whatever'],
        'tcbpermission' => ['MasterProduct'],
    ]);

    $result = detect();

    expect($result)
        ->manifest->generated->toBe([
            'tcb' => ['Center', 'AuditLog', 'CenterProduct', 'Ephemeral', 'press'],
            'tcbpermission' => ['MasterProduct'],
        ])
        ->droppedConnections->toBe(['gone'])
        ->hasChanges()->toBeTrue();
})->group('need_review');

it('routes a three-part cross-database reference to the owning connection', function (): void {
    $this->manifestFile(generated: ['tcb' => [], 'tcbpermission' => []]);

    $tcbpermission = collect(detect()->connections)->firstWhere('connection', 'tcbpermission');

    expect($tcbpermission->additions)->toContain('MasterProduct');
})->group('need_review');

it('reports no changes when the generated section is up to date', function (): void {
    $this->manifestFile(generated: [
        'tcb' => ['Center', 'AuditLog', 'CenterProduct', 'Ephemeral', 'press'],
        'tcbpermission' => ['MasterProduct'],
    ]);

    expect(detect()->hasChanges())->toBeFalse();
})->group('need_review');

it('detects MySQL query tables through a connection property, past comments, backticks, CTEs and derived tables', function (): void {
    Config::set('schema-tools.models_path', dirname(__DIR__) . '/Fixtures/DetectMySql/Models');
    Config::set('schema-tools.queries_path', dirname(__DIR__) . '/Fixtures/DetectMySql/Queries');
    $this->workspaceFile('sugar-schema.sql', "CREATE TABLE `contacts` (\n  `id` int NOT NULL\n) ENGINE=InnoDB;");
    $this->manifestFile(generated: ['sugar' => []]);

    $sugar = collect(detect()->connections)->firstWhere('connection', 'sugar');

    expect($sugar->additions)->toBe(['accounts', 'accounts_contacts', 'contacts', 'users']);
})->group('need_review');

it('scans every configured models and queries path, including glob patterns', function (): void {
    Config::set('schema-tools.models_path', [
        dirname(__DIR__) . '/Fixtures/Detect/Models',
        dirname(__DIR__) . '/Fixtures/DetectMySql/Models',
    ]);
    Config::set('schema-tools.queries_path', dirname(__DIR__) . '/Fixtures/Detect*/Queries');
    $this->workspaceFile('sugar-schema.sql', '');
    $this->manifestFile(generated: ['tcb' => [], 'sugar' => []]);

    $result = detect();

    $tcb = collect($result->connections)->firstWhere('connection', 'tcb');
    $sugar = collect($result->connections)->firstWhere('connection', 'sugar');

    expect($tcb->additions)->toBe(['AuditLog', 'Center', 'CenterProduct', 'Ephemeral', 'press'])
        ->and($sugar->additions)->toBe(['accounts', 'accounts_contacts', 'contacts', 'users']);
})->group('need_review');

it('detects nothing from queries when no queries path exists', function (): void {
    Config::set('schema-tools.queries_path', $this->workspace . '/no-queries');
    $this->manifestFile(generated: ['tcb' => []]);

    $tcb = collect(detect()->connections)->firstWhere('connection', 'tcb');

    expect($tcb->additions)->toBe(['AuditLog', 'Center', 'CenterProduct']);
})->group('need_review');
