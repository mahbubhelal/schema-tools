<?php

declare(strict_types=1);

use Mahbub\SchemaTools\Declarations\DeclarationReader;
use Mahbub\SchemaTools\Declarations\DeclarationStyle;
use Mahbub\SchemaTools\Declarations\OwnDeclarations;
use Mahbub\SchemaTools\Support\SourceParser;

function ownDeclarations(string $class): OwnDeclarations
{
    $node = (new SourceParser)->findClass(MODEL_PRELUDE . $class, 'App\\M');

    return (new DeclarationReader)->read($node);
}

it('reads the audited attributes and properties in source order, ignoring the rest', function (): void {
    $declarations = ownDeclarations(<<<'PHP'
        #[WithoutTimestamps]
        #[Unguarded]
        #[Connection('tcb'), Table('T')]
        final class M extends Model
        {
            public $incrementing = false;

            protected $table = 'T';

            private $keyType = 'string';

            protected $fillable = ['a'];

            public $primaryKey = null;
        }
        PHP);

    expect($declarations)
        ->attributes->toBe(['WithoutTimestamps', 'Connection', 'Table'])
        ->properties->toBe(['incrementing', 'table', 'keyType', 'primaryKey'])
        ->visibilities->toBe(['incrementing' => 'public', 'table' => 'protected', 'keyType' => 'private', 'primaryKey' => 'public'])
        ->nullKeyProperty->toBeTrue()
        ->stylingProperties()->toBe(['incrementing', 'table', 'keyType'])
        ->isMixed()->toBeTrue()
        ->style()->toBeNull()
        ->dominantStyle()->toBe(DeclarationStyle::Attributes);
})->group('need_review');

it('ignores a Table attribute that carries none of the audited arguments, and attributes outside Eloquent', function (): void {
    $declarations = ownDeclarations(<<<'PHP'
        #[\AllowDynamicProperties]
        #[Table(dateFormat: 'U')]
        #[Connection('tcb')]
        final class M extends Model {}
        PHP);

    expect($declarations->attributes)->toBe(['Connection']);
})->group('need_review');

it('resolves attribute names written fully qualified or aliased', function (): void {
    $declarations = ownDeclarations(<<<'PHP'
        #[\Illuminate\Database\Eloquent\Attributes\Connection('tcb')]
        #[Ts]
        final class M extends Model {}
        PHP);

    expect($declarations->attributes)->toBe(['Connection', 'WithoutTimestamps']);
})->group('need_review');

it('treats a primaryKey property without a default as null', function (): void {
    $declarations = ownDeclarations(<<<'PHP'
        #[Connection('tcb')]
        final class M extends Model
        {
            protected $primaryKey;
        }
        PHP);

    expect($declarations)
        ->nullKeyProperty->toBeTrue()
        ->isMixed()->toBeFalse()
        ->style()->toBe(DeclarationStyle::Attributes);
})->group('need_review');

it('tells the style of a class: :dataset', function (string $class, ?DeclarationStyle $style, ?DeclarationStyle $dominant): void {
    $declarations = ownDeclarations($class);

    expect($declarations)
        ->style()->toBe($style)
        ->dominantStyle()->toBe($dominant);
})->group('need_review')->with([
    'attributes only' => ['#[Connection(\'tcb\')] final class M extends Model {}', DeclarationStyle::Attributes, DeclarationStyle::Attributes],
    'properties only' => ['final class M extends Model { protected $connection = \'tcb\'; }', DeclarationStyle::Properties, DeclarationStyle::Properties],
    'nothing declared' => ['final class M extends Model {}', null, null],
    'more properties than attributes' => ['#[WithoutTimestamps] final class M extends Model { protected $connection = \'tcb\'; protected $table = \'T\'; }', null, DeclarationStyle::Properties],
    'a tie goes to attributes' => ['#[WithoutTimestamps] final class M extends Model { protected $connection = \'tcb\'; }', null, DeclarationStyle::Attributes],
]);
