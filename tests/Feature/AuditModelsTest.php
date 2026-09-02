<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Config;
use Mahbub\SchemaTools\Actions\AuditModels;

it('audits every model against the DDL, flagging each kind of drift', function (): void {
    Config::set('schema-tools.models_path', dirname(__DIR__) . '/Fixtures/Audit/Models');
    $this->workspaceFile('tcb-schema.sql', AUDIT_SCHEMA);
    $this->workspaceFile('source-tables.php', '<?php return ' . var_export(['tcb' => AUDIT_TABLES], true) . ';');

    $result = resolve(AuditModels::class)->handle();

    $issues = collect($result->models)->keyBy('location')->map(fn ($report) => $report->issues);

    expect($issues->all())->toBe([
        'tcb.Passing' => [],
        'tcb.Undeclared' => ['connection not declared on the model'],
        'tcb.NotInSchema' => ['table `NotInSchema` not found in tcb-schema.sql'],
        'tcb.NoPk' => ['DDL has NO primary key but model declares `NoPkId`'],
        'tcb.Composite' => ['DDL has COMPOSITE pk (LeftId, RightId); model getKeyName()=`LeftId`'],
        'tcb.PkMismatch' => ['pk mismatch: DDL `RealId` vs model `WrongId`'],
        'tcb.IncMismatch' => ['incrementing mismatch: DDL IDENTITY vs model false'],
        'tcb.KeyMismatch' => ['keyType mismatch: DDL varchar(20) => `string` vs model `int`'],
        'tcb.TimestampsMissing' => ['model uses timestamps but the DDL lacks the columns'],
        'tcb.TimestampsUndeclared' => ['timestamps disabled but not declared on the model'],
    ]);

    expect($result)
        ->skipped->toBe(1)
        ->manifestIssues->toBe([])
        ->and($result->passes())->toBeFalse();
})->group('need_review');

it('reports manifest entries and fixture objects that disagree', function (): void {
    Config::set('schema-tools.models_path', dirname(__DIR__) . '/Fixtures/Empty');
    $this->workspaceFile('tcb-schema.sql', "CREATE TABLE [dbo].[Center] (\n    [CenterId] int NOT NULL\n);\n\nCREATE TABLE [dbo].[Orphan] (\n    [Id] int NOT NULL\n);");
    $this->workspaceFile('tcb-views.sql', 'CREATE VIEW [dbo].[vX] AS SELECT 1 AS one;');
    $this->workspaceFile('source-tables.php', "<?php return ['tcb' => ['Center', 'vX', 'Ghost']];");

    $result = resolve(AuditModels::class)->handle();

    expect($result->manifestIssues)->toBe([
        '[tcb] manifest lists `Ghost` but it is in neither the schema nor the views fixture',
        '[tcb] fixture defines `Orphan` but it is absent from the manifest (stale?)',
    ]);
})->group('need_review');
