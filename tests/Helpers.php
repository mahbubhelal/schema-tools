<?php

declare(strict_types=1);

use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Mahbub\SchemaTools\Actions\DumpSourceSchema;
use Mahbub\SchemaTools\Queries\FetchPrimaryKeyColumns;
use Mahbub\SchemaTools\Queries\FetchTableColumns;
use Mahbub\SchemaTools\Queries\FetchViewDefinition;
use Mahbub\SchemaTools\Queries\ResolveObject;
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
    SQL;

const AUDIT_TABLES = [
    'Passing', 'Undeclared', 'NoPk', 'Composite', 'PkMismatch',
    'IncMismatch', 'KeyMismatch', 'TimestampsMissing', 'TimestampsUndeclared',
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
 * A DatabaseManager whose `tcb` connection is the given mocked source connection.
 */
function sourceDatabaseFor(Connection $connection): DatabaseManager
{
    $database = Mockery::mock(DatabaseManager::class);
    $database->shouldReceive('connection')->with('tcb')->andReturn($connection);

    return $database;
}

/**
 * A DumpSourceSchema wired to real queries backed by the given source connection.
 */
function dumpActionFor(Connection $connection): DumpSourceSchema
{
    $database = sourceDatabaseFor($connection);

    return new DumpSourceSchema(
        new FixtureConnections,
        new Manifest,
        new SchemaFixtureParser,
        new ResolveObject($database),
        new FetchTableColumns($database),
        new FetchPrimaryKeyColumns($database),
        new FetchViewDefinition($database),
    );
}

/**
 * Bind the four source queries — backed by the given mocked connection — into the
 * container, so a command's DumpSourceSchema resolves against them.
 */
function bindSourceQueries(Connection $connection): void
{
    $database = sourceDatabaseFor($connection);

    app()->instance(ResolveObject::class, new ResolveObject($database));
    app()->instance(FetchTableColumns::class, new FetchTableColumns($database));
    app()->instance(FetchPrimaryKeyColumns::class, new FetchPrimaryKeyColumns($database));
    app()->instance(FetchViewDefinition::class, new FetchViewDefinition($database));
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
