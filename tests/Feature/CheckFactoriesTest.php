<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Config;
use Mahbub\SchemaTools\Actions\CheckFactories;

it('checks every factory definition against the DDL and manifest', function (): void {
    Config::set('schema-tools.factories_path', dirname(__DIR__) . '/Fixtures/CheckFactories/Factories');
    $this->workspaceFile('tcb-schema.sql', FACTORY_SCHEMA);
    $this->workspaceFile('source-tables.php', "<?php return ['tcb' => ['FBad', 'FThrower', 'FNoSchemaTable']];");

    $result = resolve(CheckFactories::class)->handle();

    $byLocation = collect($result->factories)->keyBy('location');

    expect($byLocation['tcb.FBad']->issues)->toBe([
        'Note: nullable (nvarchar(50)) — must not be in definition(), move to a state',
        'Status: has a database default or is an identity — omitting it cannot error, drop from definition()',
        'fullname: case mismatch — the DDL declares `FullName`',
        'Bogus: not a column of tcb.FBad',
        "Age: 'text' (string) does not fit int",
        'BadClosure: closure could not be evaluated, type not checked',
        'NullCol: null value for NOT NULL nvarchar(50)',
        'Required1: required (nvarchar(50) NOT NULL, no default) but missing from definition()',
    ]);

    expect($byLocation['tcb.FUntracked']->issues)->toBe(['table `FUntracked` is not tracked in the manifest']);
    expect($byLocation['tcb.FNoSchemaTable']->issues)->toBe(['table `FNoSchemaTable` is not in tcb-schema.sql']);
    expect($byLocation['tcb.FThrower']->issues)->toBe(['definition() could not be evaluated: kaboom']);

    expect($result)
        ->factories->toHaveCount(4)
        ->checked->toBe(4)
        ->skippedConnections->toBe(['(default)']);
})->group('need_review');

it('returns nothing when the factories directory does not exist', function (): void {
    Config::set('schema-tools.factories_path', $this->workspace . '/does-not-exist');
    $this->workspaceFile('tcb-schema.sql', "CREATE TABLE [dbo].[FBad] (\n    [Id] int NOT NULL\n);");

    $result = resolve(CheckFactories::class)->handle();

    expect($result)
        ->checked->toBe(0)
        ->factories->toBe([])
        ->skippedConnections->toBe([]);
})->group('need_review');

it('checks a factory against a MySQL fixture, treating auto-increment and defaults as omittable', function (): void {
    Config::set('schema-tools.factories_path', [
        dirname(__DIR__) . '/Fixtures/CheckFactoriesMySql/Factories',
        $this->workspace . '/does-not-exist',
    ]);
    $this->workspaceFile('sugar-schema.sql', <<<'SQL'
        CREATE TABLE `contacts` (
          `id` char(36) NOT NULL,
          `seq` int NOT NULL AUTO_INCREMENT,
          `is_deleted` tinyint(1) NOT NULL,
          `date_entered` datetime NOT NULL,
          `status` varchar(20) NOT NULL DEFAULT 'new',
          `note` text,
          PRIMARY KEY (`id`)
        ) ENGINE=InnoDB;
        SQL);
    $this->workspaceFile('source-tables.php', "<?php return ['sugar' => ['contacts']];");

    $result = resolve(CheckFactories::class)->handle();

    expect($result)
        ->factories->toBe([])
        ->checked->toBe(1)
        ->skippedConnections->toBe([]);
})->group('need_review');
