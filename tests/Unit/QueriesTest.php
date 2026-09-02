<?php

declare(strict_types=1);

use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Mahbub\SchemaTools\Queries\FetchPrimaryKeyColumns;
use Mahbub\SchemaTools\Queries\FetchTableColumns;
use Mahbub\SchemaTools\Queries\FetchViewDefinition;
use Mahbub\SchemaTools\Queries\ResolveObject;

/**
 * @param  list<object>  $rows
 */
function fakeSelect(string $method, string $expectedSqlNeedle, mixed $rows): DatabaseManager
{
    $connection = Mockery::mock(Connection::class);
    $connection->shouldReceive($method)
        ->once()
        ->withArgs(fn (string $sql, array $bindings): bool => str_contains($sql, $expectedSqlNeedle) && $bindings === ['dbo.Center'])
        ->andReturn($rows);

    $database = Mockery::mock(DatabaseManager::class);
    $database->shouldReceive('connection')->once()->with('tcb')->andReturn($connection);

    return $database;
}

it('fetches a table\'s columns from sys.columns, bound to the schema-qualified name', function (): void {
    $rows = [(object) ['name' => 'CenterId', 'type_name' => 'int']];

    $query = new FetchTableColumns(fakeSelect('select', 'sys.columns', $rows));

    expect($query->execute('tcb', 'Center'))->toBe($rows);
})->group('need_review');

it('fetches the primary-key columns from sys.key_constraints', function (): void {
    $rows = [(object) ['name' => 'CenterId']];

    $query = new FetchPrimaryKeyColumns(fakeSelect('select', 'sys.key_constraints', $rows));

    expect($query->execute('tcb', 'Center'))->toBe($rows);
})->group('need_review');

it('returns a view definition from sys.sql_modules', function (): void {
    $query = new FetchViewDefinition(fakeSelect('selectOne', 'sys.sql_modules', (object) ['definition' => 'CREATE VIEW x']));

    expect($query->execute('tcb', 'Center'))->toBe('CREATE VIEW x');
})->group('need_review');

it('returns null when the source has no such view', function (): void {
    $query = new FetchViewDefinition(fakeSelect('selectOne', 'sys.sql_modules', null));

    expect($query->execute('tcb', 'Center'))->toBeNull();
})->group('need_review');

it('resolves an object to its catalog name and type', function (): void {
    $query = new ResolveObject(fakeSelect('selectOne', 'sys.objects', (object) ['name' => 'Center', 'type' => 'U ']));

    expect($query->execute('tcb', 'Center'))->toEqual((object) ['name' => 'Center', 'type' => 'U ']);
})->group('need_review');

it('resolves to null when the source has no such object', function (): void {
    $query = new ResolveObject(fakeSelect('selectOne', 'sys.objects', null));

    expect($query->execute('tcb', 'Center'))->toBeNull();
})->group('need_review');
