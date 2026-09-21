<?php

declare(strict_types=1);

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Config;

use function Pest\Laravel\artisan;

const GENERATED_CENTER = <<<'SQL'
    CREATE TABLE [dbo].[Center] (
        [CenterId] int IDENTITY(1,1) NOT NULL,
        [Name] nvarchar(100) NOT NULL,
        PRIMARY KEY ([CenterId])
    );

    SQL;

beforeEach(function (): void {
    $this->connection = Mockery::mock(Connection::class);
});

function stubCenter(Connection $connection): void
{
    resolvesTo($connection, 'Center', (object) ['name' => 'Center', 'type' => 'U ']);
    tableColumns($connection, 'Center', [
        col('CenterId', 'int', isIdentity: 1, seed: 1, increment: 1),
        col('Name', 'nvarchar', maxLength: 200),
    ]);
    primaryKey($connection, 'Center', [(object) ['name' => 'CenterId']]);
}

it('writes the fixtures and reports each object as changed', function (): void {
    $this->workspaceFile('tcb-schema.sql', "CREATE TABLE [dbo].[Center] (\n    [CenterId] int NOT NULL\n);\n");
    $this->manifestFile(['tcb' => ['Center', 'vCenter']]);

    stubCenter($this->connection);
    resolvesTo($this->connection, 'vCenter', (object) ['name' => 'vCenter', 'type' => 'V ']);
    viewDefinition($this->connection, 'vCenter', 'CREATE VIEW [dbo].[vCenter] AS SELECT 1 AS one');
    bindSourceQueries($this->connection);

    artisan('schema:dump')
        ->expectsOutputToContain('Source environment:')
        ->expectsOutputToContain('[tcb] wrote 1 table(s) [changed], 1 view(s) [changed]')
        ->expectsOutputToContain('Fixtures written.')
        ->assertExitCode(0);

    expect(file_get_contents($this->workspace . '/tcb-schema.sql'))->toBe(GENERATED_CENTER)
        ->and(file_get_contents($this->workspace . '/tcb-views.sql'))
        ->toBe("DROP VIEW IF EXISTS [dbo].[vCenter];\n\nCREATE VIEW [dbo].[vCenter] AS SELECT 1 AS one;\n");
})->group('need_review');

it('previews the diff without writing on --dry-run', function (): void {
    $original = "CREATE TABLE [dbo].[Center] (\n    [CenterId] int NOT NULL\n);\n";
    $this->workspaceFile('tcb-schema.sql', $original);
    $this->manifestFile(['tcb' => ['Center']]);

    stubCenter($this->connection);
    bindSourceQueries($this->connection);

    artisan('schema:dump --dry-run')
        ->expectsOutputToContain('[tcb] would write 1 table(s) [changed]')
        ->expectsOutputToContain('Dry run — omit --dry-run to write the fixtures.')
        ->assertExitCode(0);

    expect(file_get_contents($this->workspace . '/tcb-schema.sql'))->toBe($original);
})->group('need_review');

it('reports a fixture as unchanged when it already matches the source', function (): void {
    $this->workspaceFile('tcb-schema.sql', GENERATED_CENTER);
    $this->manifestFile(['tcb' => ['Center']]);

    stubCenter($this->connection);
    bindSourceQueries($this->connection);

    artisan('schema:dump')
        ->expectsOutputToContain('[tcb] wrote 1 table(s) [unchanged]')
        ->assertExitCode(0);
})->group('need_review');

it('dumps only the connections named with --connection and flags unknown ones', function (): void {
    $this->workspaceFile('tcb-schema.sql', '');
    $this->workspaceFile('tcbpermission-schema.sql', '');
    $this->manifestFile(['tcb' => ['Center'], 'tcbpermission' => ['MasterProduct']]);

    stubCenter($this->connection);
    bindSourceQueries($this->connection);

    artisan('schema:dump --connection=tcb --connection=nowhere')
        ->expectsOutputToContain('[nowhere] is not a fixture-backed connection, ignored')
        ->expectsOutputToContain('[tcb] wrote 1 table(s) [changed]')
        ->doesntExpectOutputToContain('[tcbpermission]')
        ->assertExitCode(0);

    expect(is_file($this->workspace . '/tcbpermission-schema.sql'))->toBeTrue()
        ->and(file_get_contents($this->workspace . '/tcbpermission-schema.sql'))->toBe('');
})->group('need_review');

it('reports a hand-maintained connection as skipped', function (): void {
    $this->workspaceFile('tcb-schema.sql', "CREATE TABLE [dbo].[Center] (\n    [CenterId] int NOT NULL\n);");
    $this->manifestFile(['tcb' => ['Center']]);
    Config::set('schema-tools.hand_maintained', ['tcb']);

    bindSourceQueries($this->connection);

    artisan('schema:dump')
        ->expectsOutputToContain('[tcb] fixtures are maintained by hand, skipped')
        ->assertExitCode(0);
})->group('need_review');

it('skips a connection with no manifest entries', function (): void {
    $this->workspaceFile('tcb-schema.sql', "CREATE TABLE [dbo].[Center] (\n    [CenterId] int NOT NULL\n);\n");

    bindSourceQueries($this->connection);

    artisan('schema:dump')
        ->expectsOutputToContain('[tcb] no manifest entries, skipped')
        ->expectsOutputToContain('Fixtures written.')
        ->assertExitCode(0);
})->group('need_review');

it('warns and leaves the fixture untouched when the source fails', function (): void {
    $original = "CREATE TABLE [dbo].[Center] (\n    [CenterId] int NOT NULL\n);\n";
    $this->workspaceFile('tcb-schema.sql', $original);
    $this->manifestFile(['tcb' => ['Center']]);

    $this->connection->shouldReceive('selectOne')
        ->withArgs(fn (string $sql, array $b): bool => str_contains($sql, 'sys.objects'))
        ->andThrow(new RuntimeException('source down'));
    bindSourceQueries($this->connection);

    artisan('schema:dump')
        ->expectsOutputToContain('[tcb] source query failed (source down), fixtures left untouched')
        ->assertExitCode(0);

    expect(file_get_contents($this->workspace . '/tcb-schema.sql'))->toBe($original);
})->group('need_review');
