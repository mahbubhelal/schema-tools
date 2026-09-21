<?php

declare(strict_types=1);

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Config;

beforeEach(function (): void {
    $this->connection = Mockery::mock(Connection::class);
});

it('reconstructs a table with every column shape and a primary key', function (): void {
    $this->workspaceFile('tcb-schema.sql', "CREATE TABLE [dbo].[Center] (\n    [CenterId] int NOT NULL\n);");
    $this->manifestFile(['tcb' => ['Center']]);

    $collation = 'SQL_Latin1_General_CP1_CI_AS';

    resolvesTo($this->connection, 'Center', (object) ['name' => 'Center', 'type' => 'U ']);

    tableColumns($this->connection, 'Center', [
        col('CenterId', 'int', isIdentity: 1, seed: 1, increment: 1),
        col('Name', 'nvarchar', maxLength: 510, collation: $collation),
        col('Bio', 'nvarchar', maxLength: -1, isNullable: 1, collation: $collation),
        col('Code', 'char', maxLength: 10, collation: $collation),
        col('Tag', 'nchar', maxLength: 20, collation: $collation),
        col('Data', 'varbinary', maxLength: -1),
        col('Blob', 'binary', maxLength: 16),
        col('Descr', 'varchar', maxLength: 100, collation: $collation),
        col('Amount', 'decimal', precision: 10, scale: 2, default: '((0))'),
        col('Flag', 'bit', isNullable: 1),
    ]);

    primaryKey($this->connection, 'Center', [(object) ['name' => 'CenterId']]);

    $dump = dumpActionFor($this->connection)->handle()->connections[0];

    $expected = <<<'SQL_WRAP'
    CREATE TABLE [dbo].[Center] (
        [CenterId] int IDENTITY(1,1) NOT NULL,
        [Name] nvarchar(255) COLLATE SQL_Latin1_General_CP1_CI_AS NOT NULL,
        [Bio] nvarchar(MAX) COLLATE SQL_Latin1_General_CP1_CI_AS NULL,
        [Code] char(10) COLLATE SQL_Latin1_General_CP1_CI_AS NOT NULL,
        [Tag] nchar(10) COLLATE SQL_Latin1_General_CP1_CI_AS NOT NULL,
        [Data] varbinary(MAX) NOT NULL,
        [Blob] binary(16) NOT NULL,
        [Descr] varchar(100) COLLATE SQL_Latin1_General_CP1_CI_AS NOT NULL,
        [Amount] decimal(10,2) NOT NULL DEFAULT ((0)),
        [Flag] bit NULL,
        PRIMARY KEY ([CenterId])
    );
    SQL_WRAP;

    expect($dump)
        ->schemaContent->toBe($expected . "\n")
        ->viewsContent->toBeNull()
        ->tableCount->toBe(1)
        ->viewCount->toBe(0)
        ->warnings->toBe([])
        ->failed->toBeFalse()
        ->skipped->toBeFalse();
})->group('need_review');

it('reconstructs a table without a primary key alongside a view', function (): void {
    $this->workspaceFile('tcb-schema.sql', "CREATE TABLE [dbo].[Log] (\n    [Message] nvarchar(10) NOT NULL\n);");
    $this->workspaceFile('tcb-views.sql', 'CREATE VIEW [dbo].[vCenter] AS SELECT 1 AS one;');
    $this->manifestFile(['tcb' => ['Log', 'vCenter']]);

    resolvesTo($this->connection, 'Log', (object) ['name' => 'Log', 'type' => 'U ']);
    resolvesTo($this->connection, 'vCenter', (object) ['name' => 'vCenter', 'type' => 'V ']);
    tableColumns($this->connection, 'Log', [col('Message', 'nvarchar', maxLength: 200)]);
    primaryKey($this->connection, 'Log', []);
    viewDefinition($this->connection, 'vCenter', 'CREATE VIEW [dbo].[vCenter] AS SELECT 1 AS one');

    $dump = dumpActionFor($this->connection)->handle()->connections[0];

    expect($dump)
        ->schemaContent->toBe("CREATE TABLE [dbo].[Log] (\n    [Message] nvarchar(100) NOT NULL\n);\n")
        ->viewsContent->toBe("DROP VIEW IF EXISTS [dbo].[vCenter];\n\nCREATE VIEW [dbo].[vCenter] AS SELECT 1 AS one;\n")
        ->tableCount->toBe(1)
        ->viewCount->toBe(1)
        ->warnings->toBe([]);
})->group('need_review');

it('warns and skips names that are missing or the wrong object type', function (): void {
    $this->workspaceFile('tcb-schema.sql', "CREATE TABLE [dbo].[Center] (\n    [CenterId] int NOT NULL\n);");
    $this->manifestFile(['tcb' => ['Missing', 'Proc', 'Center']]);

    resolvesTo($this->connection, 'Missing', null);
    resolvesTo($this->connection, 'Proc', (object) ['name' => 'Proc', 'type' => 'P ']);
    resolvesTo($this->connection, 'Center', (object) ['name' => 'Center', 'type' => 'U ']);
    tableColumns($this->connection, 'Center', [col('CenterId', 'int', isIdentity: 1, seed: 1, increment: 1)]);
    primaryKey($this->connection, 'Center', [(object) ['name' => 'CenterId']]);

    $dump = dumpActionFor($this->connection)->handle()->connections[0];

    expect($dump)
        ->warnings->toBe([
            '`Missing` not found at source, skipped',
            '`Proc` is neither a table nor a view, skipped',
        ])
        ->tableCount->toBe(1);
})->group('need_review');

it('drops a fixture object that is no longer in the manifest', function (): void {
    $this->workspaceFile('tcb-schema.sql', "CREATE TABLE [dbo].[Center] (\n    [CenterId] int NOT NULL\n);\n\nCREATE TABLE [dbo].[Legacy] (\n    [Id] int NOT NULL\n);");
    $this->manifestFile(['tcb' => ['Center']]);

    resolvesTo($this->connection, 'Center', (object) ['name' => 'Center', 'type' => 'U ']);
    tableColumns($this->connection, 'Center', [col('CenterId', 'int', isIdentity: 1, seed: 1, increment: 1)]);
    primaryKey($this->connection, 'Center', [(object) ['name' => 'CenterId']]);

    $dump = dumpActionFor($this->connection)->handle()->connections[0];

    expect($dump)
        ->warnings->toBe(['`Legacy` in the fixture is no longer in the manifest, dropped'])
        ->tableCount->toBe(1);
})->group('need_review');

it('preserves the existing fixture order and appends new tables sorted', function (): void {
    $this->workspaceFile('tcb-schema.sql', "CREATE TABLE [dbo].[Zebra] (\n    [Col] int NOT NULL\n);\n\nCREATE TABLE [dbo].[Center] (\n    [Col] int NOT NULL\n);");
    $this->manifestFile(['tcb' => ['Center', 'Zebra', 'Alpha']]);

    foreach (['Center', 'Zebra', 'Alpha'] as $table) {
        resolvesTo($this->connection, $table, (object) ['name' => $table, 'type' => 'U ']);
        tableColumns($this->connection, $table, [col('Col', 'int')]);
        primaryKey($this->connection, $table, []);
    }

    $dump = dumpActionFor($this->connection)->handle()->connections[0];

    $block = static fn (string $table): string => "CREATE TABLE [dbo].[{$table}] (\n    [Col] int NOT NULL\n);";

    expect($dump)
        ->schemaContent->toBe($block('Zebra') . "\n\n" . $block('Center') . "\n\n" . $block('Alpha') . "\n")
        ->tableCount->toBe(3);
})->group('need_review');

it('skips a connection that has no manifest entries', function (): void {
    $this->workspaceFile('tcb-schema.sql', "CREATE TABLE [dbo].[Center] (\n    [CenterId] int NOT NULL\n);");

    $dump = dumpActionFor($this->connection)->handle()->connections[0];

    expect($dump)
        ->skipped->toBeTrue()
        ->schemaContent->toBeNull()
        ->viewsContent->toBeNull()
        ->tableCount->toBe(0)
        ->warnings->toBe([]);
})->group('need_review');

it('leaves the fixtures untouched when a source query fails', function (): void {
    $this->workspaceFile('tcb-schema.sql', "CREATE TABLE [dbo].[Center] (\n    [CenterId] int NOT NULL\n);");
    $this->manifestFile(['tcb' => ['Center']]);

    $this->connection->shouldReceive('selectOne')
        ->withArgs(fn (string $sql, array $b): bool => str_contains($sql, 'sys.objects'))
        ->andThrow(new RuntimeException('source down'));

    $dump = dumpActionFor($this->connection)->handle()->connections[0];

    expect($dump)
        ->failed->toBeTrue()
        ->warnings->toBe(['source query failed (source down), fixtures left untouched'])
        ->schemaContent->toBeNull()
        ->tableCount->toBe(0);
})->group('need_review');

it('normalises Windows line endings in a verbatim view definition', function (): void {
    $this->workspaceFile('tcb-schema.sql', '');
    $this->manifestFile(['tcb' => ['vCenter']]);

    resolvesTo($this->connection, 'vCenter', (object) ['name' => 'vCenter', 'type' => 'V ']);
    viewDefinition($this->connection, 'vCenter', "CREATE VIEW [dbo].[vCenter]\r\nAS\r\nSELECT 1 AS one\r\n");

    $dump = dumpActionFor($this->connection)->handle()->connections[0];

    expect($dump->viewsContent)->toBe("DROP VIEW IF EXISTS [dbo].[vCenter];\n\nCREATE VIEW [dbo].[vCenter]\nAS\nSELECT 1 AS one;\n");
})->group('need_review');

it('restricts a run to the requested connections', function (): void {
    $this->workspaceFile('tcb-schema.sql', '');
    $this->workspaceFile('tcbpermission-schema.sql', '');
    $this->manifestFile(['tcb' => ['Center'], 'tcbpermission' => ['MasterProduct']]);

    stubCenter($this->connection);

    $connections = dumpActionFor($this->connection)->handle(['tcb'])->connections;

    expect($connections)->toHaveCount(1)
        ->and($connections[0]->connection)->toBe('tcb')
        ->and($connections[0]->tableCount)->toBe(1);
})->group('need_review');

it('skips a hand-maintained connection without touching the source', function (): void {
    $this->workspaceFile('tcb-schema.sql', "CREATE TABLE [dbo].[Center] (\n    [CenterId] int NOT NULL\n);");
    $this->manifestFile(['tcb' => ['Center']]);
    Config::set('schema-tools.hand_maintained', ['tcb']);

    $dump = dumpActionFor($this->connection)->handle()->connections[0];

    expect($dump)
        ->skipped->toBeTrue()
        ->skipReason->toBe('fixtures are maintained by hand')
        ->schemaContent->toBeNull()
        ->warnings->toBe([]);
})->group('need_review');
