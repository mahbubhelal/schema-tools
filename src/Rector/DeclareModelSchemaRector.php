<?php

declare(strict_types=1);

namespace Mahbub\SchemaTools\Rector;

use Illuminate\Database\Eloquent\Model;
use Mahbub\SchemaTools\Declarations\DeclarationRewriter;
use Mahbub\SchemaTools\Support\ApplicationResolver;
use Mahbub\SchemaTools\Support\FixtureModels;
use PhpParser\Node;
use PhpParser\Node\Stmt\Class_;
use Rector\Rector\AbstractRector;
use Throwable;

/**
 * Rewrites every Eloquent model so `schema:audit` passes: values the DDL
 * disagrees with are redeclared, missing declarations added, and the class's
 * own declarations expressed in one style in canonical order. A concrete
 * model on a fixture-backed connection is fixed against its DDL; a concrete
 * model on a connection without a fixture is only brought into form; an
 * abstract model is only reordered, since it may mix styles. The rule reads
 * the fixtures and `config/schema-tools.php` through the project's Laravel
 * application, booted from `bootstrap/app.php` when Rector runs on its own.
 */
final class DeclareModelSchemaRector extends AbstractRector
{
    public function __construct(
        private readonly DeclarationRewriter $rewriter,
        private readonly ApplicationResolver $applications,
    ) {}

    /**
     * @return array<class-string<Node>>
     */
    public function getNodeTypes(): array
    {
        return [Class_::class];
    }

    public function refactor(Node $node): ?Node
    {
        if (!$node instanceof Class_ || !isset($node->namespacedName)) {
            return null;
        }

        $class = $node->namespacedName->toString();

        if (!$this->isModel($class)) {
            return null;
        }

        if ($node->isAbstract()) {
            return $this->rewriter->reorder($node) ? $node : null;
        }

        $models = $this->applications->resolve()->make(FixtureModels::class);

        return $this->rewriter->rewrite($node, $models->factsFor($class), $models->configuredStyle()) ? $node : null;
    }

    private function isModel(string $class): bool
    {
        try {
            return is_subclass_of($class, Model::class);
        } catch (Throwable) {
            return false;
        }
    }
}
