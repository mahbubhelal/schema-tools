<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Config;
use Mahbub\SchemaTools\Actions\AuditModels;
use Mahbub\SchemaTools\Support\Report;
use Mahbub\SchemaTools\Tests\Fixtures\Audit\Models\SkippedModel;

it('audits every model against the DDL, flagging each kind of drift', function (): void {
    Config::set('schema-tools.models_path', __DIR__ . '/../Fixtures/Audit/Models');
    $this->workspaceFile('tcb-schema.sql', AUDIT_SCHEMA);
    $this->manifestFile(['tcb' => AUDIT_TABLES]);

    $result = resolve(AuditModels::class)->handle();

    $issues = collect($result->models)->keyBy('location')->map(fn ($report) => $report->issues);

    // Reports follow the model files' name order, which the scanner fixes.
    expect($issues->all())->toBe([
        'tcb.Attributed' => [],
        'tcb.Composite' => ['DDL has COMPOSITE pk (LeftId, RightId); model getKeyName()=`LeftId`'],
        'tcb.IncMismatch' => ['incrementing mismatch: DDL IDENTITY vs model false'],
        'tcb.Inherited' => [],
        'tcb.KeyMismatch' => ['keyType mismatch: DDL varchar(20) => `string` vs model `int`'],
        'tcb.NotInSchema' => ['table `NotInSchema` not found in tcb-schema.sql'],
        'tcb.MixedStyle' => ['mixes attribute and property declarations: #[WithoutTimestamps] vs $connection, $table, $primaryKey'],
        'tcb.KeyedHeap' => ['model declares no pk but DDL has pk `KeyedHeapId`'],
        'tcb.HeapIncrementing' => ['model has no pk but incrementing is true'],
        'tcb.Heap' => [],
        'tcb.NoPk' => ['DDL has NO primary key but model declares `NoPkId`'],
        'tcb.Passing' => [],
        'tcb.PkMismatch' => ['pk mismatch: DDL `RealId` vs model `WrongId`'],
        'tcb.TableTimestamps' => [],
        'tcb.TabledChild' => [],
        'tcb.TimestampsMissing' => ['model uses timestamps but the DDL lacks the columns'],
        'tcb.TimestampsUndeclared' => ['timestamps disabled but not declared on the model'],
        'tcb.TraitConnected' => [],
        'tcb.TraitTabled' => [],
        'tcb.Undeclared' => ['connection not declared on the model'],
        'tcb.Unordered' => [
            'public properties out of order: $timestamps, $incrementing; expected $incrementing, $timestamps',
            'protected properties out of order: $table, $connection, $primaryKey; expected $connection, $table, $primaryKey',
        ],
    ]);

    expect($result)
        ->skipped->toEqual([new Report(SkippedModel::class, 'other.Whatever', [])])
        ->manifestIssues->toBe([])
        ->and($result->passes())->toBeFalse();
})->group('need_review');

it('holds every model to the configured declaration style', function (): void {
    Config::set('schema-tools.models_path', __DIR__ . '/../Fixtures/Audit/Models');
    Config::set('schema-tools.declaration_style', 'properties');
    $this->workspaceFile('tcb-schema.sql', AUDIT_SCHEMA);
    $this->manifestFile(['tcb' => AUDIT_TABLES]);

    $result = resolve(AuditModels::class)->handle();

    $issues = collect($result->models)->keyBy('location')->map(fn ($report) => $report->issues);

    expect($issues->all())
        ->{'tcb.Attributed'}->toBe(['declares with attributes but `declaration_style` is properties'])
        ->{'tcb.TableTimestamps'}->toBe(['declares with attributes but `declaration_style` is properties'])
        ->{'tcb.Passing'}->toBe([])
        ->{'tcb.MixedStyle'}->toBe(['mixes attribute and property declarations: #[WithoutTimestamps] vs $connection, $table, $primaryKey']);
})->group('need_review');

it('reports manifest entries and fixture objects that disagree', function (): void {
    Config::set('schema-tools.models_path', __DIR__ . '/../Fixtures/Empty');
    $this->workspaceFile('tcb-schema.sql', "CREATE TABLE [dbo].[Center] (\n    [CenterId] int NOT NULL\n);\n\nCREATE TABLE [dbo].[Orphan] (\n    [Id] int NOT NULL\n);");
    $this->workspaceFile('tcb-views.sql', 'CREATE VIEW [dbo].[vX] AS SELECT 1 AS one;');
    $this->manifestFile(['tcb' => ['Center', 'vX', 'Ghost']]);

    $result = resolve(AuditModels::class)->handle();

    expect($result->manifestIssues)->toBe([
        '[tcb] manifest lists `Ghost` but it is in neither the schema nor the views fixture',
        '[tcb] fixture defines `Orphan` but it is absent from the manifest (stale?)',
    ]);
})->group('need_review');

it('audits a view-backed model for the no-key, no-increment, no-timestamps shape', function (): void {
    Config::set('schema-tools.models_path', __DIR__ . '/../Fixtures/Views/Models');
    $this->workspaceFile('tcb-schema.sql', "CREATE TABLE [dbo].[Center] (\n    [CenterId] int NOT NULL\n);");
    $this->workspaceFile('tcb-views.sql', implode("\n\n", array_map(
        static fn (string $view): string => "CREATE VIEW [dbo].[{$view}] AS SELECT 1 AS one;",
        ['vOk', 'vKeyed', 'vInc', 'vTs'],
    )));
    $this->manifestFile(['tcb' => ['Center', 'vOk', 'vKeyed', 'vInc', 'vTs']]);

    $result = resolve(AuditModels::class)->handle();

    $issues = collect($result->models)->keyBy('location')->map(fn ($report) => $report->issues);

    expect($issues->all())->toBe([
        'tcb.vInc' => ['view-backed model must declare `$incrementing = false`'],
        'tcb.vKeyed' => ['view-backed model must declare `$primaryKey = null`'],
        'tcb.vOk' => [],
        'tcb.vTs' => ['view-backed model must declare `$timestamps = false`'],
    ]);

    expect($result)
        ->manifestIssues->toBe([])
        ->skipped->toBe([]);
})->group('need_review');

it('scans every configured models path, including glob patterns', function (): void {
    Config::set('schema-tools.models_path', [
        __DIR__ . '/../Fixtures/Audit/Models',
        __DIR__ . '/../Fixtures/Vie*/Models',
    ]);
    $this->workspaceFile('tcb-schema.sql', AUDIT_SCHEMA);
    $this->workspaceFile('tcb-views.sql', 'CREATE VIEW [dbo].[vOk] AS SELECT 1 AS one;');
    $this->manifestFile(['tcb' => [...AUDIT_TABLES, 'vOk']]);

    $locations = collect(resolve(AuditModels::class)->handle()->models)->pluck('location');

    expect($locations)->toContain('tcb.Passing', 'tcb.vOk');
})->group('need_review');
