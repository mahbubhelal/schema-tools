<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Config;
use Mahbub\SchemaTools\Support\Driver;

it('resolves the driver family of :dataset', function (string $connection, ?Driver $expected): void {
    Config::set('database.connections.maria.driver', 'mariadb');

    expect(Driver::forConnection($connection))->toBe($expected);
})->with([
    'a mysql connection' => ['sugar', Driver::MySql],
    'a mariadb connection' => ['maria', Driver::MySql],
    'a sqlsrv connection' => ['tcb', Driver::SqlServer],
    'a connection on an unsupported driver' => ['legacy', null],
    'a connection that is not configured' => ['nowhere', null],
])->group('need_review');
