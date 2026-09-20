<?php

declare(strict_types=1);

use Mahbub\SchemaTools\Support\Column;
use Mahbub\SchemaTools\Support\Table;

function tableUnderTest(): Table
{
    return new Table(
        columns: [
            'CenterId' => new Column('int', false, false, true),
            'Name' => new Column('nvarchar(255)', false, false, false),
        ],
        primaryKey: ['CenterId'],
    );
}

it('resolves columns case-insensitively to their DDL casing', function (): void {
    $table = tableUnderTest();

    expect($table)
        ->hasColumn('centerid')->toBeTrue()
        ->hasColumn('missing')->toBeFalse()
        ->canonicalName('name')->toBe('Name')
        ->canonicalName('nope')->toBeNull();
})->group('need_review');

it('returns the column shape, or null when absent', function (): void {
    $table = tableUnderTest();

    expect($table->column('name'))->toBeInstanceOf(Column::class)
        ->and($table->column('name')->type)->toBe('nvarchar(255)')
        ->and($table->column('missing'))->toBeNull();
})->group('need_review');

it('lists the identity columns', function (): void {
    expect(tableUnderTest()->identityColumns())->toBe(['CenterId']);
})->group('need_review');

it('resolves the effective primary key when :dataset', function (Table $table, array $expected): void {
    expect($table->effectivePrimaryKey())->toBe($expected);
})->with([
    'a formal PRIMARY KEY is declared' => [
        new Table(['Id' => new Column('int', false, false, true)], ['Id']),
        ['Id'],
    ],
    'no constraint but a lone identity surrogates as the key' => [
        new Table(['Id' => new Column('int', false, false, true)], []),
        ['Id'],
    ],
    'no constraint and no identity has no key' => [
        new Table(['Name' => new Column('nvarchar(50)', false, false, false)], []),
        [],
    ],
    'no constraint and two identities is ambiguous, no key' => [
        new Table([
            'A' => new Column('int', false, false, true),
            'B' => new Column('int', false, false, true),
        ], []),
        [],
    ],
    'a lone MySQL auto-increment without a constraint is not a key' => [
        new Table(['Id' => new Column('int', false, false, true)], [], identityIsKey: false),
        [],
    ],
])->group('need_review');
