<?php

declare(strict_types=1);

use Mahbub\SchemaTools\Support\Column;

it('treats a column as required only when :dataset', function (bool $nullable, bool $hasDefault, bool $isIdentity, bool $required): void {
    $column = new Column(type: 'int', nullable: $nullable, hasDefault: $hasDefault, isIdentity: $isIdentity);

    expect($column->isRequired())->toBe($required);
})->group('need_review')->with([
    'NOT NULL, no default, not identity' => [false, false, false, true],
    'nullable' => [true, false, false, false],
    'has a database default' => [false, true, false, false],
    'is an identity' => [false, false, true, false],
]);
