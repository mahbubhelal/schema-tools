<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Config;
use Mahbub\SchemaTools\Actions\DetectSourceTables;
use Mahbub\SchemaTools\Support\DetectionResult;

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

it('detects tables from models, pivots, queries and views, and reconciles the manifest', function (): void {
    $this->workspaceFile('source-tables.php', "<?php return ['tcb' => ['Center', 'GhostTable'], 'tcbpermission' => ['MasterProduct']];");

    $result = detect();

    expect($result->manifest)->toBe([
        'tcb' => ['Center', 'GhostTable', 'AuditLog', 'CenterProduct', 'Ephemeral', 'press'],
        'tcbpermission' => ['MasterProduct'],
    ]);

    $tcb = collect($result->connections)->firstWhere('connection', 'tcb');
    $tcbpermission = collect($result->connections)->firstWhere('connection', 'tcbpermission');

    expect($tcb)
        ->total->toBe(6)
        ->additions->toBe(['AuditLog', 'CenterProduct', 'Ephemeral', 'press'])
        ->stale->toBe(['GhostTable']);

    expect($tcbpermission)
        ->total->toBe(1)
        ->additions->toBe([])
        ->stale->toBe([]);

    expect($result->hasStale())->toBeTrue();
})->group('need_review');

it('routes a three-part cross-database reference to the owning connection', function (): void {
    $this->workspaceFile('source-tables.php', "<?php return ['tcb' => [], 'tcbpermission' => []];");

    $tcbpermission = collect(detect()->connections)->firstWhere('connection', 'tcbpermission');

    expect($tcbpermission->additions)->toContain('MasterProduct');
})->group('need_review');

it('leaves the manifest with no stale entries when everything is still referenced', function (): void {
    $this->workspaceFile('source-tables.php', "<?php return ['tcb' => ['Center'], 'tcbpermission' => ['MasterProduct']];");

    expect(detect()->hasStale())->toBeFalse();
})->group('need_review');
