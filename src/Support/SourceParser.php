<?php

declare(strict_types=1);

namespace Mahbub\SchemaTools\Support;

use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\Class_;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\Parser;
use PhpParser\ParserFactory;

/**
 * Parses PHP source the way Rector hands it to a rule: names stay as written
 * and carry their resolution in the `resolvedName` attribute, classes their
 * `namespacedName`.
 */
final readonly class SourceParser
{
    private Parser $parser;

    private NodeTraverser $traverser;

    public function __construct()
    {
        $this->parser = (new ParserFactory)->createForNewestSupportedVersion();
        $this->traverser = new NodeTraverser(new NameResolver(null, ['preserveOriginalNames' => true, 'replaceNodes' => false]));
    }

    /**
     * @return list<Stmt>
     */
    public function parse(string $code): array
    {
        /** @var list<Stmt> */
        $statements = $this->traverser->traverse($this->parser->parse($code) ?? []);

        return $statements;
    }

    /**
     * The class declared under the given fully qualified name, if the source
     * declares it.
     */
    public function findClass(string $code, string $class): ?Class_
    {
        $node = (new NodeFinder)->findFirst(
            $this->parse($code),
            static fn ($node): bool => $node instanceof Class_ && $node->namespacedName?->toString() === $class,
        );

        return $node instanceof Class_ ? $node : null;
    }
}
