<?php

declare(strict_types=1);

use Mahbub\SchemaTools\Declarations\DeclarationReader;
use Mahbub\SchemaTools\Declarations\DeclarationRewriter;
use Mahbub\SchemaTools\Declarations\DeclarationStyle;
use Mahbub\SchemaTools\Declarations\Effective;
use Mahbub\SchemaTools\Declarations\Expectation;
use Mahbub\SchemaTools\Declarations\ModelFacts;
use Mahbub\SchemaTools\Support\SourceParser;
use PhpParser\PrettyPrinter\Standard;

/**
 * Rewrite the class and return [changed, printed class].
 *
 * @return array{bool, string}
 */
function rewritten(string $class, ?ModelFacts $facts, ?DeclarationStyle $configured): array
{
    $node = (new SourceParser)->findClass(MODEL_PRELUDE . $class, 'App\\M');
    $changed = (new DeclarationRewriter(new DeclarationReader))->rewrite($node, $facts, $configured);

    return [$changed, (new Standard)->prettyPrint([$node])];
}

/**
 * Facts for a model on `tcb.T` whose every resolved value is overridable.
 *
 * @param  array<string, mixed>  $effective
 * @param  array<string, string|bool>  $inheritedTable
 */
function facts(array $effective = [], ?Expectation $expected = null, array $inheritedTable = []): ModelFacts
{
    $resolved = new Effective(...[
        'connection' => 'tcb',
        'table' => 'T',
        'primaryKey' => 'Id',
        'keyType' => 'int',
        'incrementing' => true,
        'timestamps' => true,
        'connectionDeclared' => true,
        'timestampsDeclared' => true,
        ...$effective,
    ]);

    return new ModelFacts($resolved, $expected ?? new Expectation('Id', 'int', true, true), [], $inheritedTable);
}

it('leaves a class alone when it already satisfies the audit', function (): void {
    [$changed, $printed] = rewritten(<<<'PHP'
        #[Connection('tcb')]
        #[Unguarded]
        #[Table('T')]
        #[WithoutTimestamps]
        final class M extends Model
        {
            use HasFactory;

            protected $primaryKey = null;
        }
        PHP, facts(['primaryKey' => null, 'incrementing' => false, 'timestamps' => false], new Expectation(null, null, false, false)), null);

    expect($changed)->toBeFalse()
        ->and($printed)->toBe(<<<'PHP'
        #[Connection('tcb')]
        #[Unguarded]
        #[Table('T')]
        #[WithoutTimestamps]
        final class M extends Model
        {
            use HasFactory;
            protected $primaryKey = null;
        }
        PHP);
})->group('need_review');

it('converts property declarations to attributes, keeping the ones already there', function (): void {
    [$changed, $printed] = rewritten(<<<'PHP'
        #[WithoutTimestamps]
        final class M extends Model
        {
            use HasFactory;

            #[\Override]
            public $connection = 'cid';

            #[\Override]
            public $table = 'people';

            #[\Override]
            public $primaryKey = 'recordNumber';
        }
        PHP, facts(['connection' => 'cid', 'table' => 'people', 'primaryKey' => 'recordNumber', 'timestamps' => false], new Expectation('recordNumber', 'int', true, false)), DeclarationStyle::Attributes);

    expect($changed)->toBeTrue()
        ->and($printed)->toBe(<<<'PHP'
        #[\Illuminate\Database\Eloquent\Attributes\Connection('cid')]
        #[\Illuminate\Database\Eloquent\Attributes\Table('people', key: 'recordNumber')]
        #[WithoutTimestamps]
        final class M extends Model
        {
            use HasFactory;
        }
        PHP);
})->group('need_review');

it('converts attribute declarations to properties with Eloquent visibility', function (): void {
    [$changed, $printed] = rewritten(<<<'PHP'
        #[Connection('cid')]
        #[Unguarded]
        #[Table('people', key: 'recordNumber', keyType: 'string', dateFormat: 'U')]
        #[WithoutIncrementing]
        #[WithoutTimestamps]
        final class M extends Model
        {
            use HasFactory;
        }
        PHP, null, DeclarationStyle::Properties);

    expect($changed)->toBeTrue()
        ->and($printed)->toBe(<<<'PHP'
        #[Unguarded]
        #[Table(dateFormat: 'U')]
        final class M extends Model
        {
            use HasFactory;
            public $incrementing = false;
            public $timestamps = false;
            protected $connection = 'cid';
            protected $table = 'people';
            protected $primaryKey = 'recordNumber';
            protected $keyType = 'string';
        }
        PHP);
})->group('need_review');

it('redeclares the values the DDL disagrees with, in the style the class leans to', function (): void {
    [$changed, $printed] = rewritten(<<<'PHP'
        final class M extends Model
        {
            protected $connection = 'tcb';

            protected $table = 'T';

            protected $primaryKey = 'WrongId';
        }
        PHP, facts(['primaryKey' => 'WrongId', 'incrementing' => true], new Expectation('RealId', 'string', false, false)), null);

    expect($changed)->toBeTrue()
        ->and($printed)->toBe(<<<'PHP'
        final class M extends Model
        {
            protected $connection = 'tcb';
            protected $table = 'T';
            protected $primaryKey = 'RealId';
            protected $keyType = 'string';
            public $incrementing = false;
            public $timestamps = false;
        }
        PHP);
})->group('need_review');

it('declares the connection and disabled timestamps when nothing in the hierarchy does', function (): void {
    [$changed, $printed] = rewritten(<<<'PHP'
        final class M extends Model
        {
            protected $table = 'T';

            public function getConnectionName(): string
            {
                return 'tcb';
            }
        }
        PHP, facts(['timestamps' => false, 'connectionDeclared' => false, 'timestampsDeclared' => false], new Expectation('Id', 'int', true, false)), DeclarationStyle::Properties);

    expect($changed)->toBeTrue()
        ->and($printed)->toBe(<<<'PHP'
        final class M extends Model
        {
            protected $connection = 'tcb';
            protected $table = 'T';
            public $timestamps = false;
            public function getConnectionName(): string
            {
                return 'tcb';
            }
        }
        PHP);
})->group('need_review');

it('gives a view-backed model no key, no incrementing and no timestamps, the key as the one permitted property', function (): void {
    [$changed, $printed] = rewritten(<<<'PHP'
        #[Connection('tcb')]
        final class M extends Model
        {
            protected $table = 'vBlog';

            protected $keyType = 'string';
        }
        PHP, facts(['table' => 'vBlog', 'keyType' => 'string'], new Expectation(null, null, false, false)), DeclarationStyle::Attributes);

    expect($changed)->toBeTrue()
        ->and($printed)->toBe(<<<'PHP'
        #[Connection('tcb')]
        #[\Illuminate\Database\Eloquent\Attributes\Table('vBlog')]
        #[\Illuminate\Database\Eloquent\Attributes\WithoutIncrementing]
        #[\Illuminate\Database\Eloquent\Attributes\WithoutTimestamps]
        final class M extends Model
        {
            protected $primaryKey = null;
        }
        PHP);
})->group('need_review');

it('turns incrementing and timestamps back on through the Table attribute', function (): void {
    [$changed, $printed] = rewritten(<<<'PHP'
        #[Connection('tcb')]
        #[WithoutIncrementing]
        #[WithoutTimestamps]
        final class M extends Model {}
        PHP, facts(['incrementing' => false, 'timestamps' => false], new Expectation('Id', 'int', true, true)), null);

    expect($changed)->toBeTrue()
        ->and($printed)->toBe(<<<'PHP'
        #[Connection('tcb')]
        #[\Illuminate\Database\Eloquent\Attributes\Table(incrementing: true, timestamps: true)]
        final class M extends Model
        {
        }
        PHP);
})->group('need_review');

it('carries an inherited Table attribute\'s arguments into the one it adds, since the new one shadows it', function (): void {
    [$changed, $printed] = rewritten(<<<'PHP'
        final class M extends Base
        {
            protected $table = 'T';
        }
        PHP, facts(['keyType' => 'string'], new Expectation('Id', 'string', true, true), ['keyType' => 'string', 'timestamps' => false]), DeclarationStyle::Attributes);

    expect($changed)->toBeTrue()
        ->and($printed)->toBe(<<<'PHP'
        #[\Illuminate\Database\Eloquent\Attributes\Table('T', keyType: 'string', timestamps: false)]
        final class M extends Base
        {
        }
        PHP);
})->group('need_review');

it('does not merge inherited Table arguments into a Table attribute the class already has', function (): void {
    [$changed, $printed] = rewritten(<<<'PHP'
        #[Table('T')]
        final class M extends Base {}
        PHP, facts(['primaryKey' => 'WrongId'], new Expectation('Id', 'int', true, true), ['keyType' => 'string']), null);

    expect($changed)->toBeTrue()
        ->and($printed)->toBe(<<<'PHP'
        #[Table('T', key: 'Id')]
        final class M extends Base
        {
        }
        PHP);
})->group('need_review');

it('reorders attributes within the slots they occupy, leaving other attributes in place', function (): void {
    [$changed, $printed] = rewritten(<<<'PHP'
        #[WithoutTimestamps]
        #[Unguarded]
        #[Connection('tcb')]
        final class M extends Model {}
        PHP, null, null);

    expect($changed)->toBeTrue()
        ->and($printed)->toBe(<<<'PHP'
        #[Connection('tcb')]
        #[Unguarded]
        #[WithoutTimestamps]
        final class M extends Model
        {
        }
        PHP);
})->group('need_review');

it('places a new attribute right after its canonical predecessor', function (): void {
    [$changed, $printed] = rewritten(<<<'PHP'
        #[Connection('sugar')]
        #[Unguarded]
        #[WithoutIncrementing]
        #[WithoutTimestamps]
        final class M extends Model
        {
            public $keyType = 'string';
        }
        PHP, null, null);

    expect($changed)->toBeTrue()
        ->and($printed)->toBe(<<<'PHP'
        #[Connection('sugar')]
        #[\Illuminate\Database\Eloquent\Attributes\Table(keyType: 'string')]
        #[Unguarded]
        #[WithoutIncrementing]
        #[WithoutTimestamps]
        final class M extends Model
        {
        }
        PHP);
})->group('need_review');

it('places a new attribute before its canonical successor when it has no predecessor', function (): void {
    [$changed, $printed] = rewritten(<<<'PHP'
        #[Unguarded]
        #[WithoutTimestamps]
        final class M extends Model
        {
            protected $table = 'T';
        }
        PHP, null, DeclarationStyle::Attributes);

    expect($changed)->toBeTrue()
        ->and($printed)->toBe(<<<'PHP'
        #[Unguarded]
        #[\Illuminate\Database\Eloquent\Attributes\Table('T')]
        #[WithoutTimestamps]
        final class M extends Model
        {
        }
        PHP);
})->group('need_review');

it('reorders properties within each visibility group', function (): void {
    [$changed, $printed] = rewritten(<<<'PHP'
        final class M extends Model
        {
            public $timestamps = false;

            public $incrementing = false;

            protected $table = 'T';

            protected $connection = 'tcb';

            private $keyType = 'string';
        }
        PHP, null, null);

    expect($changed)->toBeTrue()
        ->and($printed)->toBe(<<<'PHP'
        final class M extends Model
        {
            public $incrementing = false;
            public $timestamps = false;
            protected $connection = 'tcb';
            protected $table = 'T';
            private $keyType = 'string';
        }
        PHP);
})->group('need_review');

it('puts a first property after the trait uses and constants', function (): void {
    [$changed, $printed] = rewritten(<<<'PHP'
        final class M extends Model
        {
            use HasFactory;

            public const string FOO = 'foo';

            public function bar(): void {}
        }
        PHP, facts(['connectionDeclared' => false]), DeclarationStyle::Properties);

    expect($changed)->toBeTrue()
        ->and($printed)->toBe(<<<'PHP'
        final class M extends Model
        {
            use HasFactory;
            public const string FOO = 'foo';
            protected $connection = 'tcb';
            public function bar(): void
            {
            }
        }
        PHP);
})->group('need_review');

it('reuses an existing property node so its attributes and visibility survive a value change', function (): void {
    [$changed, $printed] = rewritten(<<<'PHP'
        final class M extends Model
        {
            #[\Override]
            public $primaryKey = 'WrongId', $other = 1;
        }
        PHP, facts(['primaryKey' => 'WrongId'], new Expectation('Id', 'int', true, true)), null);

    expect($changed)->toBeTrue()
        ->and($printed)->toBe(<<<'PHP'
        final class M extends Model
        {
            #[\Override]
            public $primaryKey = 'Id', $other = 1;
        }
        PHP);
})->group('need_review');

it('drops the key type when the DDL turns out to have no key', function (): void {
    [$changed, $printed] = rewritten(<<<'PHP'
        final class M extends Model
        {
            protected $primaryKey = 'Id';

            protected $keyType = 'string';
        }
        PHP, facts(['keyType' => 'string'], new Expectation(null, null, false, true)), null);

    expect($changed)->toBeTrue()
        ->and($printed)->toBe(<<<'PHP'
        final class M extends Model
        {
            protected $primaryKey = null;
            public $incrementing = false;
        }
        PHP);
})->group('need_review');

it('keeps a defaultless primaryKey property as the null key without touching it', function (): void {
    [$changed, $printed] = rewritten(<<<'PHP'
        #[Connection('tcb')]
        final class M extends Model
        {
            protected $primaryKey;
        }
        PHP, facts(['primaryKey' => null, 'incrementing' => false], new Expectation(null, null, false, true)), null);

    expect($changed)->toBeFalse()
        ->and($printed)->toContain('protected $primaryKey;');
})->group('need_review');

it('keeps a Table name written as a named argument named', function (): void {
    [$changed, $printed] = rewritten(<<<'PHP'
        #[Connection('sugar')]
        #[Table(name: 'bhea_councils')]
        final class M extends Model {}
        PHP, facts(['table' => 'bhea_councils', 'keyType' => 'int'], new Expectation('Id', 'string', true, true)), null);

    expect($changed)->toBeTrue()
        ->and($printed)->toBe(<<<'PHP'
        #[Connection('sugar')]
        #[Table(name: 'bhea_councils', keyType: 'string')]
        final class M extends Model
        {
        }
        PHP);
})->group('need_review');

it('reads only the first positional Table argument as the name', function (): void {
    [, $printed] = rewritten(<<<'PHP'
        #[Table('T', 'stray')]
        final class M extends Model {}
        PHP, null, DeclarationStyle::Properties);

    expect($printed)->toBe(<<<'PHP'
        final class M extends Model
        {
            protected $table = 'T';
        }
        PHP);
})->group('need_review');

it('does not redeclare anything when the DDL cannot be expected', function (): void {
    [$changed] = rewritten(<<<'PHP'
        #[Connection('tcb')]
        final class M extends Model {}
        PHP, new ModelFacts(facts()->effective, null, ['table `T` not found in tcb-schema.sql']), null);

    expect($changed)->toBeFalse();
})->group('need_review');

/**
 * Reorder the class and return [changed, printed class].
 *
 * @return array{bool, string}
 */
function reordered(string $class): array
{
    $node = (new SourceParser)->findClass(MODEL_PRELUDE . $class, 'App\\M');
    $changed = (new DeclarationRewriter(new DeclarationReader))->reorder($node);

    return [$changed, (new Standard)->prettyPrint([$node])];
}

it('reorders an abstract base in place, mixed style and all', function (): void {
    [$changed, $printed] = reordered(<<<'PHP'
        #[WithoutTimestamps]
        #[Unguarded]
        #[Connection('sugar')]
        #[WithoutIncrementing]
        abstract class M extends Model
        {
            use HasFactory;

            #[\Override]
            public $timestamps = false;

            #[\Override]
            public $keyType = 'string';

            protected $connection = 'sugar';
        }
        PHP);

    expect($changed)->toBeTrue()
        ->and($printed)->toBe(<<<'PHP'
        #[Connection('sugar')]
        #[Unguarded]
        #[WithoutIncrementing]
        #[WithoutTimestamps]
        abstract class M extends Model
        {
            use HasFactory;
            #[\Override]
            public $keyType = 'string';
            #[\Override]
            public $timestamps = false;
            protected $connection = 'sugar';
        }
        PHP);
})->group('need_review');

it('leaves an ordered abstract base alone', function (): void {
    [$changed] = reordered(<<<'PHP'
        #[Connection('sugar')]
        #[Unguarded]
        #[WithoutIncrementing]
        #[WithoutTimestamps]
        abstract class M extends Model
        {
            public $keyType = 'string';
        }
        PHP);

    expect($changed)->toBeFalse();
})->group('need_review');
