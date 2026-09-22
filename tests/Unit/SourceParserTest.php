<?php

declare(strict_types=1);

use Mahbub\SchemaTools\Support\SourceParser;
use PhpParser\Node\Stmt\Class_;

it('finds a class by its fully qualified name, with names resolved the way Rector leaves them', function (): void {
    $class = (new SourceParser)->findClass(MODEL_PRELUDE . "#[Ts]\nfinal class M extends Model {}", 'App\\M');

    expect($class)->toBeInstanceOf(Class_::class)
        ->and($class->attrGroups[0]->attrs[0]->name->toString())->toBe('Ts')
        ->and($class->attrGroups[0]->attrs[0]->name->getAttribute('resolvedName')->toString())->toBe(Illuminate\Database\Eloquent\Attributes\WithoutTimestamps::class);
})->group('need_review');

it('finds nothing when the source declares no such class', function (): void {
    expect((new SourceParser)->findClass(MODEL_PRELUDE . 'final class Other extends Model {}', 'App\\M'))->toBeNull();
})->group('need_review');
