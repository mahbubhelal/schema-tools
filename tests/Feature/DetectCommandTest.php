<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Config;

use function Pest\Laravel\artisan;

beforeEach(function (): void {
    Config::set('schema-tools.models_path', dirname(__DIR__) . '/Fixtures/Detect/Models');
    Config::set('schema-tools.queries_path', dirname(__DIR__) . '/Fixtures/Detect/Queries');

    $this->workspaceFile('tcb-schema.sql', "CREATE TABLE [dbo].[Center] (\n    [CenterId] int NOT NULL\n);\n\nCREATE TABLE [dbo].[press] (\n    [PressId] int NOT NULL\n);\n\nCREATE TABLE [dbo].[AuditLog] (\n    [AuditLogId] int NOT NULL\n);");
    $this->workspaceFile('tcbpermission-schema.sql', "CREATE TABLE [dbo].[MasterProduct] (\n    [Id] int NOT NULL\n);");
    $this->workspaceFile('tcb-views.sql', 'CREATE VIEW [dbo].[vReport] AS SELECT a.AuditLogId FROM AuditLog a;');
});

it('writes the reconciled manifest and fails while a stale entry remains', function (): void {
    $this->workspaceFile('source-tables.php', "<?php return ['tcb' => ['Center', 'GhostTable'], 'tcbpermission' => ['MasterProduct']];");

    artisan('schema:detect')
        ->expectsOutputToContain('[tcb] 6 name(s): 4 added, 1 not currently referenced in code')
        ->expectsOutputToContain('+ AuditLog')
        ->expectsOutputToContain('? GhostTable (in manifest, not detected in code — remove by hand if unused)')
        ->expectsOutputToContain('source-tables.php')
        ->assertExitCode(1);

    expect(require $this->workspace . '/source-tables.php')->toBe([
        'tcb' => ['Center', 'GhostTable', 'AuditLog', 'CenterProduct', 'Ephemeral', 'press'],
        'tcbpermission' => ['MasterProduct'],
    ]);
})->group('need_review');

it('previews without writing on --dry-run', function (): void {
    $original = "<?php return ['tcb' => ['Center', 'GhostTable'], 'tcbpermission' => ['MasterProduct']];";
    $this->workspaceFile('source-tables.php', $original);

    artisan('schema:detect --dry-run')
        ->expectsOutputToContain('Dry run — manifest not written.')
        ->assertExitCode(1);

    expect(file_get_contents($this->workspace . '/source-tables.php'))->toBe($original);
})->group('need_review');

it('succeeds when the manifest has no stale entries', function (): void {
    $this->workspaceFile('source-tables.php', "<?php return ['tcb' => ['Center'], 'tcbpermission' => ['MasterProduct']];");

    artisan('schema:detect')
        ->expectsOutputToContain('Wrote')
        ->assertExitCode(0);
})->group('need_review');
