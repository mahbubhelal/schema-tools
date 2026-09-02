<?php

declare(strict_types=1);

use Mahbub\SchemaTools\Support\SchemaFixtureParser;

const CENTER_SCHEMA = <<<'SQL'
    CREATE TABLE [dbo].[Center] (
        -- a leading comment

        [CenterId] int IDENTITY(1,1) NOT NULL,
        [Name] nvarchar(255) NOT NULL,
        [Note] nvarchar(max) NULL,
        [Status] int NOT NULL DEFAULT ((0)),
        INDEX [IX_Center_Status] NONCLUSTERED ([Status]),
        PRIMARY KEY ([CenterId])
    );

    CREATE TABLE [dbo].[Bridge] (
        [LeftId] int NOT NULL,
        [RightId] int NOT NULL,
        CONSTRAINT [PK_Bridge] PRIMARY KEY ([LeftId], [RightId])
    );
    SQL;

const CENTER_VIEWS = <<<'SQL'
    DROP VIEW IF EXISTS [dbo].[vCenter];

    CREATE VIEW [dbo].[vCenter] AS
        SELECT c.CenterId FROM [dbo].[Center] c;

    DROP VIEW IF EXISTS [dbo].[vBridge];

    CREATE VIEW [dbo].[vBridge] AS SELECT 1 AS one;
    SQL;

it('parses tables, columns, flags and primary keys from the DDL', function (): void {
    $path = $this->workspaceFile('tcb-schema.sql', CENTER_SCHEMA);

    $tables = (new SchemaFixtureParser)->parseTables($path);

    expect(array_keys($tables))->toBe(['Center', 'Bridge']);

    expect($tables['Center'])
        ->primaryKey->toBe(['CenterId'])
        ->columns->toHaveKeys(['CenterId', 'Name', 'Note', 'Status'])
        ->columns->not->toHaveKey('IX_Center_Status');

    expect($tables['Center']->columns['CenterId'])
        ->type->toBe('int')
        ->nullable->toBeFalse()
        ->hasDefault->toBeFalse()
        ->isIdentity->toBeTrue();

    expect($tables['Center']->columns['Note'])
        ->type->toBe('nvarchar(max)')
        ->nullable->toBeTrue();

    expect($tables['Center']->columns['Status'])
        ->hasDefault->toBeTrue()
        ->nullable->toBeFalse();

    expect($tables['Bridge']->primaryKey)->toBe(['LeftId', 'RightId']);
})->group('need_review');

it('parses the view names from the views fixture', function (): void {
    $path = $this->workspaceFile('tcb-views.sql', CENTER_VIEWS);

    expect((new SchemaFixtureParser)->parseViews($path))->toBe(['vCenter', 'vBridge']);
})->group('need_review');

it('yields an empty result for a missing schema or views file', function (): void {
    $parser = new SchemaFixtureParser;

    expect($parser->parseTables($this->workspace . '/missing-schema.sql'))->toBe([])
        ->and($parser->parseViews($this->workspace . '/missing-views.sql'))->toBe([]);
})->group('need_review');
