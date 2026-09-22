<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Config;
use Mahbub\SchemaTools\Declarations\DeclarationStyle;
use Mahbub\SchemaTools\Support\FixtureModels;
use Mahbub\SchemaTools\Tests\Fixtures\Audit\Models\Inherited;
use Mahbub\SchemaTools\Tests\Fixtures\Audit\Models\Passing;
use Mahbub\SchemaTools\Tests\Fixtures\Audit\Models\SkippedModel;
use Mahbub\SchemaTools\Tests\Fixtures\Audit\Models\TabledChild;
use Mahbub\SchemaTools\Tests\Fixtures\Audit\Models\TraitTabled;

beforeEach(function (): void {
    Config::set('schema-tools.models_path', __DIR__ . '/../Fixtures/Audit/Models');
    $this->workspaceFile('tcb-schema.sql', AUDIT_SCHEMA);
});

it('resolves the facts of a model by class name', function (): void {
    $facts = resolve(FixtureModels::class)->factsFor(Passing::class);

    expect($facts)->not->toBeNull()
        ->effective->table->toBe('Passing')
        ->issues->toBe([]);
})->group('need_review');

it('carries the Table attribute a model inherits: :dataset', function (string $class, array $inheritedTable): void {
    expect(resolve(FixtureModels::class)->factsFor($class)?->inheritedTable)->toBe($inheritedTable);
})->group('need_review')->with([
    'from a trait' => [TraitTabled::class, ['name' => 'TraitTabled', 'key' => 'TraitTabledId']],
    'from a parent' => [TabledChild::class, ['keyType' => 'string']],
    'none when no ancestor has one' => [Inherited::class, []],
]);

it('has no facts for a model on a connection without a fixture, nor for an unknown class', function (): void {
    $models = resolve(FixtureModels::class);

    expect($models)
        ->factsFor(SkippedModel::class)->toBeNull()
        ->factsFor('App\\Models\\Nope')->toBeNull();
})->group('need_review');

it('is shared across the container and resolves each model once', function (): void {
    $models = resolve(FixtureModels::class);

    expect(resolve(FixtureModels::class))->toBe($models)
        ->and($models->factsFor(Passing::class))->toBe($models->factsFor(Passing::class));
})->group('need_review');

it('reads the configured declaration style: :dataset', function (?string $configured, ?DeclarationStyle $style): void {
    Config::set('schema-tools.declaration_style', $configured);

    expect(resolve(FixtureModels::class)->configuredStyle())->toBe($style);
})->group('need_review')->with([
    'attributes' => ['attributes', DeclarationStyle::Attributes],
    'properties' => ['properties', DeclarationStyle::Properties],
    'either' => [null, null],
]);
