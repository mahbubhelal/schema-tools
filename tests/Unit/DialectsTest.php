<?php

declare(strict_types=1);

use Mahbub\SchemaTools\Dialects\Dialects;
use Mahbub\SchemaTools\Dialects\MySqlDialect;
use Mahbub\SchemaTools\Dialects\SqlServerDialect;

it('picks the dialect for :dataset', function (string $connection, ?string $expected): void {
    $dialect = resolve(Dialects::class)->forConnection($connection);

    if ($expected === null) {
        expect($dialect)->toBeNull();
    } else {
        expect($dialect)->toBeInstanceOf($expected);
    }
})->with([
    'a mysql connection' => ['sugar', MySqlDialect::class],
    'a sqlsrv connection' => ['tcb', SqlServerDialect::class],
    'an unsupported driver' => ['legacy', null],
])->group('need_review');
