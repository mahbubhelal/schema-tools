<?php

declare(strict_types=1);

use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Mahbub\SchemaTools\Actions\DumpSourceSchema;
use Mahbub\SchemaTools\Dialects\Dialects;
use Mahbub\SchemaTools\Dialects\MySqlDialect;
use Mahbub\SchemaTools\Dialects\SqlServerDialect;
use Mahbub\SchemaTools\Queries\MySql\FetchCreateTable;
use Mahbub\SchemaTools\Queries\MySql\FetchCreateView;
use Mahbub\SchemaTools\Queries\MySql\ResolveObject as ResolveMySqlObject;
use Mahbub\SchemaTools\Queries\SqlServer\FetchPrimaryKeyColumns;
use Mahbub\SchemaTools\Queries\SqlServer\FetchTableColumns;
use Mahbub\SchemaTools\Queries\SqlServer\FetchViewDefinition;
use Mahbub\SchemaTools\Queries\SqlServer\ResolveObject;
use Mahbub\SchemaTools\Support\FixtureConnections;
use Mahbub\SchemaTools\Support\Manifest;
use Mahbub\SchemaTools\Support\SchemaFixtureParser;

const AUDIT_SCHEMA = <<<'SQL'
    CREATE TABLE [dbo].[Passing] (
        [PassingId] int IDENTITY(1,1) NOT NULL,
        [created_at] datetime NULL,
        [updated_at] datetime NULL,
        PRIMARY KEY ([PassingId])
    );

    CREATE TABLE [dbo].[Undeclared] (
        [id] int IDENTITY(1,1) NOT NULL,
        [created_at] datetime NULL,
        [updated_at] datetime NULL,
        PRIMARY KEY ([id])
    );

    CREATE TABLE [dbo].[NoPk] (
        [Name] nvarchar(50) NOT NULL,
        [created_at] datetime NULL,
        [updated_at] datetime NULL
    );

    CREATE TABLE [dbo].[Composite] (
        [LeftId] int NOT NULL,
        [RightId] int NOT NULL,
        [created_at] datetime NULL,
        [updated_at] datetime NULL,
        CONSTRAINT [PK_Composite] PRIMARY KEY ([LeftId], [RightId])
    );

    CREATE TABLE [dbo].[PkMismatch] (
        [RealId] int IDENTITY(1,1) NOT NULL,
        [created_at] datetime NULL,
        [updated_at] datetime NULL,
        PRIMARY KEY ([RealId])
    );

    CREATE TABLE [dbo].[IncMismatch] (
        [IncId] int IDENTITY(1,1) NOT NULL,
        [created_at] datetime NULL,
        [updated_at] datetime NULL,
        PRIMARY KEY ([IncId])
    );

    CREATE TABLE [dbo].[KeyMismatch] (
        [Code] varchar(20) NOT NULL,
        [created_at] datetime NULL,
        [updated_at] datetime NULL,
        PRIMARY KEY ([Code])
    );

    CREATE TABLE [dbo].[TimestampsMissing] (
        [Id] int IDENTITY(1,1) NOT NULL,
        PRIMARY KEY ([Id])
    );

    CREATE TABLE [dbo].[TimestampsUndeclared] (
        [Id] int IDENTITY(1,1) NOT NULL,
        PRIMARY KEY ([Id])
    );

    CREATE TABLE [dbo].[Heap] (
        [Name] nvarchar(50) NOT NULL
    );

    CREATE TABLE [dbo].[HeapIncrementing] (
        [Name] nvarchar(50) NOT NULL
    );

    CREATE TABLE [dbo].[KeyedHeap] (
        [KeyedHeapId] int NOT NULL,
        PRIMARY KEY ([KeyedHeapId])
    );

    CREATE TABLE [dbo].[Attributed] (
        [AttributedId] int IDENTITY(1,1) NOT NULL,
        PRIMARY KEY ([AttributedId])
    );

    CREATE TABLE [dbo].[Inherited] (
        [InheritedId] int NOT NULL,
        PRIMARY KEY ([InheritedId])
    );

    CREATE TABLE [dbo].[TableTimestamps] (
        [id] int IDENTITY(1,1) NOT NULL,
        PRIMARY KEY ([id])
    );

    CREATE TABLE [dbo].[TraitConnected] (
        [Id] int IDENTITY(1,1) NOT NULL,
        [created_at] datetime NULL,
        [updated_at] datetime NULL,
        PRIMARY KEY ([Id])
    );

    CREATE TABLE [dbo].[MixedStyle] (
        [MixedStyleId] int IDENTITY(1,1) NOT NULL,
        PRIMARY KEY ([MixedStyleId])
    );

    CREATE TABLE [dbo].[Unordered] (
        [UnorderedId] int NOT NULL,
        PRIMARY KEY ([UnorderedId])
    );

    CREATE TABLE [dbo].[TraitTabled] (
        [TraitTabledId] int IDENTITY(1,1) NOT NULL,
        [created_at] datetime NULL,
        [updated_at] datetime NULL,
        PRIMARY KEY ([TraitTabledId])
    );

    CREATE TABLE [dbo].[TabledChild] (
        [Code] varchar(20) NOT NULL,
        PRIMARY KEY ([Code])
    );
    SQL;

/**
 * The namespace and imports every declaration test's class snippet is parsed
 * under, so attribute names resolve the way they do in a real model file.
 */
const MODEL_PRELUDE = <<<'PHP'
    <?php

    namespace App;

    use Illuminate\Database\Eloquent\Attributes\Connection;
    use Illuminate\Database\Eloquent\Attributes\Table;
    use Illuminate\Database\Eloquent\Attributes\Unguarded;
    use Illuminate\Database\Eloquent\Attributes\WithoutIncrementing;
    use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps as Ts;
    use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
    use Illuminate\Database\Eloquent\Factories\HasFactory;
    use Illuminate\Database\Eloquent\Model;

    PHP;

const AUDIT_TABLES = [
    'Passing', 'Undeclared', 'NoPk', 'Composite', 'PkMismatch',
    'IncMismatch', 'KeyMismatch', 'TimestampsMissing', 'TimestampsUndeclared',
    'Heap', 'HeapIncrementing', 'KeyedHeap',
    'Attributed', 'Inherited', 'TableTimestamps', 'TraitConnected', 'MixedStyle', 'Unordered', 'TraitTabled', 'TabledChild',
];

const FACTORY_SCHEMA = <<<'SQL'
    CREATE TABLE [dbo].[FBad] (
        [Id] int IDENTITY(1,1) NOT NULL,
        [Note] nvarchar(50) NULL,
        [Status] int NOT NULL DEFAULT ((0)),
        [FullName] nvarchar(50) NOT NULL,
        [Age] int NOT NULL,
        [Required1] nvarchar(50) NOT NULL,
        [NullCol] nvarchar(50) NOT NULL,
        [OwnerId] int NOT NULL,
        PRIMARY KEY ([Id])
    );

    CREATE TABLE [dbo].[FUntracked] (
        [Id] int IDENTITY(1,1) NOT NULL,
        [Name] nvarchar(50) NOT NULL,
        PRIMARY KEY ([Id])
    );

    CREATE TABLE [dbo].[FThrower] (
        [Id] int IDENTITY(1,1) NOT NULL,
        [Name] nvarchar(50) NOT NULL,
        PRIMARY KEY ([Id])
    );

    CREATE TABLE [dbo].[FPassing] (
        [Id] int IDENTITY(1,1) NOT NULL,
        [Name] nvarchar(50) NOT NULL,
        PRIMARY KEY ([Id])
    );
    SQL;

/**
 * Build a source-table column row exactly as FetchTableColumns yields it.
 */
function col(
    string $name,
    string $typeName,
    int $maxLength = 4,
    int $precision = 0,
    int $scale = 0,
    int $isNullable = 0,
    int $isIdentity = 0,
    ?string $collation = null,
    ?int $seed = null,
    ?int $increment = null,
    ?string $default = null,
): object {
    return (object) [
        'name' => $name,
        'type_name' => $typeName,
        'max_length' => $maxLength,
        'numeric_precision' => $precision,
        'numeric_scale' => $scale,
        'is_nullable' => $isNullable,
        'is_identity' => $isIdentity,
        'collation_name' => $collation,
        'seed_value' => $seed,
        'increment_value' => $increment,
        'default_definition' => $default,
    ];
}

/**
 * A DatabaseManager whose named connection is the given mocked source connection.
 */
function sourceDatabaseFor(Connection $connection, string $name = 'tcb'): DatabaseManager
{
    $database = Mockery::mock(DatabaseManager::class);
    $database->shouldReceive('connection')->with($name)->andReturn($connection);

    return $database;
}

/**
 * Both dialects wired to real queries backed by the given database manager.
 */
function dialectsFor(DatabaseManager $database): Dialects
{
    return new Dialects(
        new MySqlDialect(new ResolveMySqlObject($database), new FetchCreateTable($database), new FetchCreateView($database)),
        new SqlServerDialect(
            new ResolveObject($database),
            new FetchTableColumns($database),
            new FetchPrimaryKeyColumns($database),
            new FetchViewDefinition($database),
        ),
    );
}

/**
 * A DumpSourceSchema wired to real queries backed by the given source connection.
 */
function dumpActionFor(Connection $connection, string $name = 'tcb'): DumpSourceSchema
{
    return new DumpSourceSchema(
        new FixtureConnections,
        new Manifest,
        new SchemaFixtureParser,
        dialectsFor(sourceDatabaseFor($connection, $name)),
    );
}

/**
 * Bind the dialects — backed by the given mocked connection — into the
 * container, so a command's DumpSourceSchema resolves against them.
 */
function bindSourceQueries(Connection $connection, string $name = 'tcb'): void
{
    app()->instance(Dialects::class, dialectsFor(sourceDatabaseFor($connection, $name)));
}

function resolvesTo(Connection $connection, string $name, ?object $object): void
{
    $connection->shouldReceive('selectOne')
        ->withArgs(fn (string $sql, array $bindings): bool => str_contains($sql, 'sys.objects') && $bindings === ['dbo.' . $name])
        ->andReturn($object);
}

/**
 * @param  list<object>  $rows
 */
function tableColumns(Connection $connection, string $table, array $rows): void
{
    $connection->shouldReceive('select')
        ->withArgs(fn (string $sql, array $b): bool => str_contains($sql, 'FROM sys.columns') && $b === ['dbo.' . $table])
        ->andReturn($rows);
}

/**
 * @param  list<object>  $rows
 */
function primaryKey(Connection $connection, string $table, array $rows): void
{
    $connection->shouldReceive('select')
        ->withArgs(fn (string $sql, array $b): bool => str_contains($sql, 'sys.key_constraints') && $b === ['dbo.' . $table])
        ->andReturn($rows);
}

function viewDefinition(Connection $connection, string $view, string $definition): void
{
    $connection->shouldReceive('selectOne')
        ->withArgs(fn (string $sql, array $b): bool => str_contains($sql, 'sys.sql_modules') && $b === ['dbo.' . $view])
        ->andReturn((object) ['definition' => $definition]);
}

function mysqlResolvesTo(Connection $connection, string $name, ?object $object): void
{
    $connection->shouldReceive('selectOne')
        ->withArgs(fn (string $sql, array $bindings = []): bool => str_contains($sql, 'information_schema.TABLES') && $bindings === [$name])
        ->andReturn($object);
}

/**
 * Stub SHOW CREATE TABLE for a MySQL table; a null statement yields a row that
 * carries no `Create Table` column, as MySQL answers for a view.
 */
function createTable(Connection $connection, string $table, ?string $statement): void
{
    $connection->shouldReceive('selectOne')
        ->with('SHOW CREATE TABLE `' . $table . '`')
        ->andReturn((object) ($statement === null ? ['View' => $table] : ['Table' => $table, 'Create Table' => $statement]));
}

function createView(Connection $connection, string $view, ?string $statement): void
{
    $connection->shouldReceive('selectOne')
        ->with('SHOW CREATE VIEW `' . $view . '`')
        ->andReturn($statement === null ? null : (object) ['View' => $view, 'Create View' => $statement]);
}
