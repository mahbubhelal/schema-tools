<?php

declare(strict_types=1);

use Mahbub\SchemaTools\Declarations\DeclarationAuditor;
use Mahbub\SchemaTools\Declarations\DeclarationStyle;

it('judges the form of a class: :dataset', function (string $class, ?DeclarationStyle $configured, array $issues): void {
    $declarations = ownDeclarations($class);

    expect((new DeclarationAuditor)->issues($declarations, $configured))->toBe($issues);
})->group('need_review')->with([
    'attributes in canonical order' => [
        "#[Connection('tcb')] #[Unguarded] #[Table('T')] #[WithoutIncrementing] #[WithoutTimestamps] final class M extends Model {}",
        null,
        [],
    ],
    'properties in canonical order within each visibility' => [
        "final class M extends Model { public \$incrementing = false; public \$timestamps = false; protected \$connection = 'tcb'; protected \$table = 'T'; protected \$primaryKey = 'Id'; protected \$keyType = 'string'; }",
        null,
        [],
    ],
    'mixed styles' => [
        "#[WithoutTimestamps] final class M extends Model { protected \$connection = 'tcb'; protected \$table = 'T'; }",
        null,
        ['mixes attribute and property declarations: #[WithoutTimestamps] vs $connection, $table'],
    ],
    'a null primaryKey property does not mix with attributes' => [
        "#[Connection('tcb')] final class M extends Model { protected \$primaryKey = null; }",
        DeclarationStyle::Attributes,
        [],
    ],
    'attributes out of order' => [
        "#[WithoutTimestamps] #[Unguarded] #[Connection('tcb')] final class M extends Model {}",
        null,
        ['attributes out of order: #[WithoutTimestamps], #[Connection]; expected #[Connection], #[WithoutTimestamps]'],
    ],
    'properties out of order in one visibility' => [
        "final class M extends Model { public \$timestamps = false; public \$incrementing = false; protected \$connection = 'tcb'; protected \$table = 'T'; }",
        null,
        ['public properties out of order: $timestamps, $incrementing; expected $incrementing, $timestamps'],
    ],
    'properties in canonical order across visibilities are not compared' => [
        "final class M extends Model { public \$timestamps = false; protected \$connection = 'tcb'; private \$table = 'T'; }",
        null,
        [],
    ],
    'the configured style matches' => [
        "#[Connection('tcb')] final class M extends Model {}",
        DeclarationStyle::Attributes,
        [],
    ],
    'the configured style differs' => [
        "final class M extends Model { protected \$connection = 'tcb'; }",
        DeclarationStyle::Attributes,
        ['declares with properties but `declaration_style` is attributes'],
    ],
    'nothing declared satisfies any configured style' => [
        'final class M extends Model {}',
        DeclarationStyle::Properties,
        [],
    ],
    'mixed styles report the mix, not the configured style' => [
        "#[WithoutTimestamps] final class M extends Model { protected \$connection = 'tcb'; }",
        DeclarationStyle::Properties,
        ['mixes attribute and property declarations: #[WithoutTimestamps] vs $connection'],
    ],
]);
