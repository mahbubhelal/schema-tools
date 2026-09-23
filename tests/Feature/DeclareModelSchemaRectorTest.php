<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Config;
use Mahbub\SchemaTools\Rector\DeclareModelSchemaRector;
use Mahbub\SchemaTools\Support\Report;
use Mahbub\SchemaTools\Support\SourceParser;
use Mahbub\SchemaTools\Tests\Fixtures\Audit\Models\AttributedBase;
use Mahbub\SchemaTools\Tests\Fixtures\Audit\Models\MixedBase;
use Mahbub\SchemaTools\Tests\Fixtures\Audit\Models\Passing;
use Mahbub\SchemaTools\Tests\Fixtures\Audit\Models\PkMismatch;
use Mahbub\SchemaTools\Tests\Fixtures\Audit\Models\SkippedModel;
use Mahbub\SchemaTools\Tests\Fixtures\Audit\Models\Unordered;
use PhpParser\Node;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\Nop;
use PhpParser\PrettyPrinter\Standard;

beforeEach(function (): void {
    Config::set('schema-tools.models_path', __DIR__ . '/../Fixtures/Audit/Models');
    Config::set('schema-tools.declaration_style');
    $this->workspaceFile('tcb-schema.sql', AUDIT_SCHEMA);
});

/**
 * The class node of a loaded class, parsed from its own file.
 */
function classNodeOf(string $class): Class_
{
    $file = (new ReflectionClass($class))->getFileName();

    return (new SourceParser)->findClass(file_get_contents($file), $class);
}

function refactored(Node $node): ?string
{
    $result = resolve(DeclareModelSchemaRector::class)->refactor($node);

    return $result instanceof Node ? (new Standard)->prettyPrint([$result]) : null;
}

it('handles class nodes', function (): void {
    expect(resolve(DeclareModelSchemaRector::class)->getNodeTypes())->toBe([Class_::class]);
})->group('need_review');

it('fixes a model against its DDL', function (): void {
    expect(refactored(classNodeOf(PkMismatch::class)))->toContain("protected \$primaryKey = 'RealId';");
})->group('need_review');

it('brings a model on a connection without a fixture into form only', function (): void {
    $node = classNodeOf(SkippedModel::class);
    $node->stmts = array_reverse($node->stmts);

    expect(refactored($node))->toBe(<<<'PHP'
        final class SkippedModel extends Model
        {
            protected $connection = 'other';
            protected $table = 'Whatever';
        }
        PHP);
})->group('need_review');

it('reorders a model that only has its declarations out of order', function (): void {
    expect(refactored(classNodeOf(Unordered::class)))->toBe(<<<'PHP'
        /**
         * Declares everything correctly, in the wrong order.
         */
        final class Unordered extends Model
        {
            public $incrementing = false;
            public $timestamps = false;
            protected $connection = 'tcb';
            protected $table = 'Unordered';
            protected $primaryKey = 'UnorderedId';
        }
        PHP);
})->group('need_review');

it('only reorders an abstract base, leaving its mixed style as it is', function (): void {
    expect(refactored(classNodeOf(MixedBase::class)))->toBe(<<<'PHP'
        /**
         * An abstract base that mixes styles, as a base may, with its attributes out
         * of order.
         */
        #[Connection('tcb')]
        #[WithoutTimestamps]
        abstract class MixedBase extends Model
        {
            public $keyType = 'string';
        }
        PHP);
})->group('need_review');

it('leaves alone: :dataset', function (Node $node): void {
    expect(refactored($node))->toBeNull();
})->group('need_review')->with([
    'a model that passes' => fn (): Class_ => classNodeOf(Passing::class),
    'an abstract model in form' => fn (): Class_ => classNodeOf(AttributedBase::class),
    'a class that is no model' => fn (): Class_ => classNodeOf(Report::class),
    'a class that cannot be loaded' => fn (): ?\PhpParser\Node\Stmt\Class_ => (new SourceParser)->findClass("<?php\nnamespace Mahbub\\SchemaTools\\Tests\\Fixtures\\Rector;\nclass Broken {}", 'Mahbub\\SchemaTools\\Tests\\Fixtures\\Rector\\Broken'),
    'a class without a resolved name' => fn (): Class_ => new Class_('Anonymous'),
    'a node that is no class' => fn (): Nop => new Nop,
]);
