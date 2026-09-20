<?php

declare(strict_types=1);

use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Mahbub\SchemaTools\Queries\MySql\FetchCreateTable;
use Mahbub\SchemaTools\Queries\MySql\FetchCreateView;
use Mahbub\SchemaTools\Queries\MySql\ResolveObject;
use Mahbub\SchemaTools\Support\Identifier;

/**
 * A `sugar` DatabaseManager whose connection answers one selectOne() call.
 */
function fakeMySqlSelectOne(Closure $matches, mixed $row): DatabaseManager
{
    $connection = Mockery::mock(Connection::class);
    $connection->shouldReceive('selectOne')->once()->withArgs($matches)->andReturn($row);

    $database = Mockery::mock(DatabaseManager::class);
    $database->shouldReceive('connection')->once()->with('sugar')->andReturn($connection);

    return $database;
}

it('resolves a name through information_schema, bound to the bare name', function (): void {
    $row = (object) ['name' => 'contacts', 'type' => 'BASE TABLE'];
    $database = fakeMySqlSelectOne(
        fn (string $sql, array $bindings = []): bool => str_contains($sql, 'information_schema.TABLES') && $bindings === ['contacts'],
        $row,
    );

    expect((new ResolveObject($database))->execute('sugar', 'contacts'))->toBe($row);
})->group('need_review');

it('resolves to null when the database has no such table or view', function (): void {
    $database = fakeMySqlSelectOne(fn (string $sql, array $bindings = []): bool => $bindings === ['ghost'], null);

    expect((new ResolveObject($database))->execute('sugar', 'ghost'))->toBeNull();
})->group('need_review');

it('returns the CREATE TABLE statement from SHOW CREATE TABLE', function (): void {
    $database = fakeMySqlSelectOne(
        fn (string $sql): bool => $sql === 'SHOW CREATE TABLE `contacts`',
        (object) ['Table' => 'contacts', 'Create Table' => 'CREATE TABLE `contacts` (`id` int)'],
    );

    expect((new FetchCreateTable($database))->execute('sugar', 'contacts'))->toBe('CREATE TABLE `contacts` (`id` int)');
})->group('need_review');

it('returns null when SHOW CREATE TABLE answers with :dataset', function (mixed $row): void {
    $database = fakeMySqlSelectOne(fn (string $sql): bool => $sql === 'SHOW CREATE TABLE `contacts`', $row);

    expect((new FetchCreateTable($database))->execute('sugar', 'contacts'))->toBeNull();
})->with([
    'no row' => [null],
    'a view row instead of a table row' => [(object) ['View' => 'contacts', 'Create View' => 'CREATE VIEW ...']],
])->group('need_review');

it('returns the CREATE VIEW statement from SHOW CREATE VIEW', function (): void {
    $database = fakeMySqlSelectOne(
        fn (string $sql): bool => $sql === 'SHOW CREATE VIEW `vcontacts`',
        (object) ['View' => 'vcontacts', 'Create View' => 'CREATE VIEW `vcontacts` AS select 1'],
    );

    expect((new FetchCreateView($database))->execute('sugar', 'vcontacts'))->toBe('CREATE VIEW `vcontacts` AS select 1');
})->group('need_review');

it('returns null when SHOW CREATE VIEW answers with no row', function (): void {
    $database = fakeMySqlSelectOne(fn (string $sql): bool => $sql === 'SHOW CREATE VIEW `vcontacts`', null);

    expect((new FetchCreateView($database))->execute('sugar', 'vcontacts'))->toBeNull();
})->group('need_review');

it('quotes a MySQL identifier, escaping embedded backticks', function (): void {
    expect(Identifier::backtick('plain'))->toBe('`plain`')
        ->and(Identifier::backtick('we`ird'))->toBe('`we``ird`');
})->group('need_review');
