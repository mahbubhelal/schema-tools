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

const MYSQL_SCHEMA = <<<'SQL'
    SET sql_mode = '';
    SET FOREIGN_KEY_CHECKS=0;

    DROP TABLE IF EXISTS `contacts`;

    CREATE TABLE `contacts` (
      `id` char(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
      `seq` int unsigned NOT NULL AUTO_INCREMENT,
      `note` text COLLATE utf8mb4_general_ci,
      `amount` decimal(26,6) DEFAULT '0.000000',
      `is_deleted` tinyint(1) NOT NULL DEFAULT '0',
      `total` decimal(10,2) GENERATED ALWAYS AS (`amount` * 2) STORED,
      PRIMARY KEY (`id`),
      UNIQUE KEY `seq` (`seq`),
      KEY `is_deleted_index` (`is_deleted`),
      CONSTRAINT `fk_contacts_owner` FOREIGN KEY (`id`) REFERENCES `owners` (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

    DROP TABLE IF EXISTS `bridge`;

    CREATE TABLE `bridge` (
      `left_id` int NOT NULL,
      `right_id` int NOT NULL,
      PRIMARY KEY (`left_id`,`right_id`) USING BTREE
    ) ENGINE=InnoDB;

    DROP TABLE IF EXISTS `migrations`;

    CREATE TABLE `migrations` (
      `id` int unsigned NOT NULL AUTO_INCREMENT,
      PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

    SET FOREIGN_KEY_CHECKS=1;
    SQL;

const MYSQL_VIEWS = <<<'SQL_WRAP'
DROP VIEW IF EXISTS `vcontacts`;

CREATE ALGORITHM=UNDEFINED SQL SECURITY DEFINER VIEW `vcontacts` AS select 1 AS `one`;

CREATE VIEW `plain` AS select 2 AS `two`;
SQL_WRAP;

const HANDWRITTEN_SCHEMA = <<<'SQL_WRAP'
CREATE TABLE Tcb.dbo.MasterProduct (
    MasterProduct_ID int NOT NULL,
    Title nvarchar(250) COLLATE SQL_Latin1_General_CP1_CI_AS NULL,
    [Type] nvarchar(100) COLLATE SQL_Latin1_General_CP1_CI_AS NULL,
    DescriptionApproved bit DEFAULT 0 NOT NULL,
    INDEX IX_Title NONCLUSTERED (Title),
    CONSTRAINT FK_owner FOREIGN KEY (MasterProduct_ID) REFERENCES dbo.Owner (Id),
    CONSTRAINT PK_MasterProduct PRIMARY KEY (MasterProduct_ID, [Type])
);

CREATE TABLE dbo.Link (
    LeftId int NOT NULL,
    RightId int NOT NULL
);
SQL_WRAP;

const HANDWRITTEN_VIEWS = <<<'SQL'
    DROP VIEW IF EXISTS vConference;

    CREATE VIEW vConference AS
    SELECT 1 AS one;

    CREATE VIEW dbo.vBlog AS SELECT 2 AS two;
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

it('parses a MySQL fixture, leaving out keys, constraints and the migrations table', function (): void {
    $path = $this->workspaceFile('sugar-schema.sql', MYSQL_SCHEMA);

    $tables = (new SchemaFixtureParser)->parseTables($path);

    expect(array_keys($tables))->toBe(['contacts', 'bridge']);

    expect($tables['contacts'])
        ->primaryKey->toBe(['id'])
        ->columns->toHaveKeys(['id', 'seq', 'note', 'amount', 'is_deleted', 'total'])
        ->columns->toHaveCount(6);

    expect($tables['contacts']->columns['id'])
        ->type->toBe('char(36)')
        ->nullable->toBeFalse()
        ->hasDefault->toBeFalse()
        ->isIdentity->toBeFalse();

    expect($tables['contacts']->columns['seq'])
        ->type->toBe('int')
        ->isIdentity->toBeTrue();

    expect($tables['contacts']->columns['note'])
        ->type->toBe('text')
        ->nullable->toBeTrue();

    expect($tables['contacts']->columns['amount'])
        ->type->toBe('decimal(26,6)')
        ->nullable->toBeTrue()
        ->hasDefault->toBeTrue();

    expect($tables['contacts']->columns['is_deleted'])
        ->type->toBe('tinyint(1)')
        ->nullable->toBeFalse()
        ->hasDefault->toBeTrue();

    expect($tables['contacts']->columns['total'])
        ->hasDefault->toBeTrue();

    expect($tables['bridge']->primaryKey)->toBe(['left_id', 'right_id']);
})->group('need_review');

it('parses the view names from a MySQL views fixture', function (): void {
    $path = $this->workspaceFile('sugar-views.sql', MYSQL_VIEWS);

    expect((new SchemaFixtureParser)->parseViews($path))->toBe(['vcontacts', 'plain']);
})->group('need_review');

it('parses the view names from a hand-written T-SQL views fixture without brackets', function (): void {
    $path = $this->workspaceFile('tcb-views.sql', HANDWRITTEN_VIEWS);

    expect((new SchemaFixtureParser)->parseViews($path))->toBe(['vConference', 'vBlog']);
})->group('need_review');

it('parses a hand-written T-SQL fixture with three-part names and bare columns', function (): void {
    $path = $this->workspaceFile('tcb-schema.sql', HANDWRITTEN_SCHEMA);

    $tables = (new SchemaFixtureParser)->parseTables($path);

    expect(array_keys($tables))->toBe(['MasterProduct', 'Link']);

    expect($tables['MasterProduct'])
        ->primaryKey->toBe(['MasterProduct_ID', 'Type'])
        ->identityIsKey->toBeTrue()
        ->columns->toHaveKeys(['MasterProduct_ID', 'Title', 'Type', 'DescriptionApproved'])
        ->columns->toHaveCount(4);

    expect($tables['MasterProduct']->columns['DescriptionApproved'])
        ->type->toBe('bit')
        ->nullable->toBeFalse()
        ->hasDefault->toBeTrue();

    expect($tables['Link'])
        ->primaryKey->toBe([])
        ->columns->toHaveKeys(['LeftId', 'RightId']);
})->group('need_review');

it('marks identity as a surrogate key for T-SQL tables but not for MySQL tables', function (): void {
    $parser = new SchemaFixtureParser;

    $tsql = $parser->parseTables($this->workspaceFile('tcb-schema.sql', CENTER_SCHEMA));
    $mysql = $parser->parseTables($this->workspaceFile('sugar-schema.sql', MYSQL_SCHEMA));

    expect($tsql['Center']->identityIsKey)->toBeTrue()
        ->and($mysql['contacts']->identityIsKey)->toBeFalse();
})->group('need_review');
