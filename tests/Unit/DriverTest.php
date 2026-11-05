<?php

declare(strict_types=1);

use Mahbub\SchemaTools\Support\Driver;

it('picks the driver family of :dataset', function (mixed $connectionDriver, ?Driver $expected): void {
    expect(Driver::fromConnectionDriver($connectionDriver))->toBe($expected);
})->group('need_review')->with([
    'mysql' => ['mysql', Driver::MySql],
    'mariadb' => ['mariadb', Driver::MySql],
    'sqlsrv' => ['sqlsrv', Driver::SqlServer],
    'an unsupported driver' => ['pgsql', null],
    'a connection that is not configured' => [null, null],
]);
