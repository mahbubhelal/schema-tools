<?php

declare(strict_types=1);

use Mahbub\SchemaTools\Support\ColumnType;

enum ColumnTypeStatus: string
{
    case Active = 'active';
}

it('lists the SQL string types', function (): void {
    expect(ColumnType::stringTypes())->toBe([
        'char', 'varchar', 'nchar', 'nvarchar', 'text', 'ntext', 'uniqueidentifier',
        'tinytext', 'mediumtext', 'longtext', 'enum', 'set', 'binary', 'varbinary',
    ]);
})->group('need_review');

it('maps :dataset to key type `:expected`', function (string $type, string $expected): void {
    expect(ColumnType::keyType($type))->toBe($expected);
})->with([
    'varchar is a string key' => ['varchar', 'string'],
    'nvarchar with length suffix is a string key' => ['nvarchar(255)', 'string'],
    'uniqueidentifier is a string key' => ['uniqueidentifier', 'string'],
    'int is an int key' => ['int', 'int'],
    'bigint is an int key' => ['bigint', 'int'],
    'a MySQL enum is a string key' => ["enum('a','b')", 'string'],
    'a MySQL binary uuid is a string key' => ['binary(16)', 'string'],
    'unknown type falls back to an int key' => ['xml', 'int'],
])->group('need_review');

it(':dataset', function (mixed $value, string $type, bool $fits): void {
    expect(ColumnType::valueFits($value, $type))->toBe($fits);
})->with([
    'bit accepts a bool' => [true, 'bit', true],
    'bit accepts an int' => [1, 'bit', true],
    'bit rejects a string' => ['1', 'bit', false],

    'int accepts an int' => [5, 'int', true],
    'bigint accepts an int' => [5, 'bigint', true],
    'int rejects a numeric string' => ['5', 'int', false],
    'int rejects a float' => [1.5, 'int', false],

    'decimal accepts a float' => [1.5, 'decimal(10,2)', true],
    'money accepts an int' => [5, 'money', true],
    'float accepts a numeric string' => ['1.5', 'float', true],
    'decimal rejects a non-numeric string' => ['abc', 'decimal', false],
    'decimal rejects a bool' => [true, 'decimal', false],

    'date accepts a date string' => ['2020-01-01', 'date', true],
    'datetime accepts a DateTimeInterface' => [new DateTimeImmutable, 'datetime', true],
    'date rejects an unparseable string' => ['not-a-date', 'date', false],
    'date rejects an int' => [5, 'date', false],

    'nvarchar accepts a string' => ['hi', 'nvarchar(50)', true],
    'varchar accepts a Stringable' => [new Illuminate\Support\Stringable('hi'), 'varchar', true],
    'nvarchar rejects an int' => [5, 'nvarchar', false],

    'uniqueidentifier accepts a string' => ['a-guid', 'uniqueidentifier', true],
    'uniqueidentifier rejects an int' => [5, 'uniqueidentifier', false],

    'MySQL tinyint(1) accepts a bool' => [false, 'tinyint(1)', true],
    'MySQL tinyint(1) accepts an int' => [0, 'tinyint(1)', true],
    'MySQL tinyint(1) rejects a string' => ['0', 'tinyint(1)', false],
    'MySQL tinyint accepts an int' => [5, 'tinyint', true],
    'MySQL mediumint rejects a string' => ['5', 'mediumint', false],
    'MySQL year accepts an int' => [2024, 'year', true],
    'MySQL double accepts a float' => [1.5, 'double', true],
    'MySQL timestamp accepts a date string' => ['2020-01-01 10:00:00', 'timestamp', true],
    'MySQL longtext accepts a string' => ['hi', 'longtext', true],
    'MySQL enum accepts a string' => ['a', "enum('a','b')", true],
    'MySQL enum rejects an int' => [1, "enum('a','b')", false],
    'MySQL json accepts an array' => [['k' => 'v'], 'json', true],
    'MySQL json accepts a string' => ['{}', 'json', true],
    'MySQL json rejects an int' => [1, 'json', false],
    'a backed enum is judged by its backing value' => [ColumnTypeStatus::Active, 'varchar(20)', true],
    'a backed enum backing value must still fit' => [ColumnTypeStatus::Active, 'int', false],

    'an unknown type accepts anything' => [['array'], 'geography', true],
])->group('need_review');

it('describes :dataset', function (mixed $value, string $described): void {
    expect(ColumnType::describe($value))->toBe($described);
})->with([
    'a short string' => ['hello', "'hello' (string)"],
    'a long string is truncated at 37 chars' => [
        str_repeat('a', 41),
        "'" . str_repeat('a', 37) . "...' (string)",
    ],
    'true' => [true, 'true (bool)'],
    'false' => [false, 'false (bool)'],
    'an int' => [5, '5 (int)'],
    'a float' => [1.5, '1.5 (float)'],
    'a non-scalar renders its debug type' => [['a'], 'array'],
])->group('need_review');
