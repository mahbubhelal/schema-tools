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

it('rebuilds the generated section, reports every change and writes the manifest', function (): void {
    $this->manifestFile(
        generated: ['tcb' => ['Center', 'GhostTable'], 'gone' => ['Whatever'], 'tcbpermission' => ['MasterProduct']],
        manual: ['tcb' => ['press']],
    );

    artisan('schema:detect')
        ->expectsOutputToContain('[tcb] 5 name(s), 1 under manual: 4 added, 1 removed')
        ->expectsOutputToContain('+ AuditLog')
        ->expectsOutputToContain('- GhostTable (no longer referenced in code)')
        ->expectsOutputToContain('~ press (also detected in code; the manual entry is redundant)')
        ->expectsOutputToContain('[gone] has no fixture any more; its generated names were dropped')
        ->expectsOutputToContain('Wrote')
        ->assertExitCode(0);

    expect(require $this->workspace . '/source-tables.php')->toBe([
        'manual' => ['tcb' => ['press']],
        'generated' => [
            'tcb' => ['Center', 'AuditLog', 'CenterProduct', 'Ephemeral', 'press'],
            'tcbpermission' => ['MasterProduct'],
        ],
    ]);
})->group('need_review');

it('previews without writing on --dry-run and fails while the generated section is out of date', function (): void {
    $original = "<?php return ['manual' => [], 'generated' => ['tcb' => ['Center', 'GhostTable'], 'tcbpermission' => ['MasterProduct']]];";
    $this->workspaceFile('source-tables.php', $original);

    artisan('schema:detect --dry-run')
        ->expectsOutputToContain('Dry run — manifest not written.')
        ->expectsOutputToContain('The generated section is out of date; run without --dry-run to rewrite it.')
        ->assertExitCode(1);

    expect(file_get_contents($this->workspace . '/source-tables.php'))->toBe($original);
})->group('need_review');

it('succeeds on --dry-run when the generated section is up to date', function (): void {
    $this->manifestFile(generated: [
        'tcb' => ['Center', 'AuditLog', 'CenterProduct', 'Ephemeral', 'press'],
        'tcbpermission' => ['MasterProduct'],
    ]);

    artisan('schema:detect --dry-run')
        ->expectsOutputToContain('[tcb] 5 name(s): 0 added, 0 removed')
        ->expectsOutputToContain('The generated section is up to date.')
        ->assertExitCode(0);
})->group('need_review');
