<?php

declare(strict_types=1);

namespace Mahbub\SchemaTools\Declarations;

use PhpParser\Node\Attribute;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\Property;

/**
 * Reads a class node's own schema declarations. Attribute names are resolved
 * through the `resolvedName` attribute the name resolver leaves behind, so an
 * imported, aliased or fully qualified name all identify the same attribute.
 */
final class DeclarationReader
{
    public const string ATTRIBUTE_NAMESPACE = 'Illuminate\\Database\\Eloquent\\Attributes\\';

    public const array TABLE_ARGUMENTS = ['name', 'key', 'keyType', 'incrementing', 'timestamps'];

    public function read(Class_ $class): OwnDeclarations
    {
        $attributes = [];

        foreach ($class->attrGroups as $group) {
            foreach ($group->attrs as $attribute) {
                $name = $this->declaredName($attribute);

                if ($name !== null) {
                    $attributes[] = $name;
                }
            }
        }

        $properties = [];
        $visibilities = [];
        $nullKeyProperty = false;

        foreach ($class->stmts as $stmt) {
            if (!$stmt instanceof Property) {
                continue;
            }

            foreach ($stmt->props as $item) {
                $name = $item->name->toString();

                if (!in_array($name, OwnDeclarations::PROPERTY_ORDER, true)) {
                    continue;
                }

                $properties[] = $name;
                $visibilities[$name] = $this->visibility($stmt);

                if ($name === 'primaryKey' && $this->isNull($item->default)) {
                    $nullKeyProperty = true;
                }
            }
        }

        return new OwnDeclarations($attributes, $properties, $visibilities, $nullKeyProperty);
    }

    /**
     * The short name of the attribute when it declares schema metadata: any
     * audited attribute but a `#[Table]` that carries none of the audited
     * arguments (say only `dateFormat`), which declares nothing the audit
     * cares about.
     */
    public function declaredName(Attribute $attribute): ?string
    {
        $name = $this->attributeName($attribute);

        if ($name !== 'Table') {
            return $name;
        }

        foreach ($attribute->args as $position => $arg) {
            if ($arg->name instanceof Identifier ? in_array($arg->name->toString(), self::TABLE_ARGUMENTS, true) : $position === 0) {
                return $name;
            }
        }

        return null;
    }

    /**
     * The short name of an audited Eloquent attribute, or null for any other
     * attribute.
     */
    public function attributeName(Attribute $attribute): ?string
    {
        $resolved = $attribute->name->getAttribute('resolvedName');
        $name = ($resolved instanceof Name ? $resolved : $attribute->name)->toString();

        if (!str_starts_with($name, self::ATTRIBUTE_NAMESPACE)) {
            return null;
        }

        $short = substr($name, strlen(self::ATTRIBUTE_NAMESPACE));

        return in_array($short, OwnDeclarations::ATTRIBUTE_ORDER, true) ? $short : null;
    }

    public function visibility(Property $property): string
    {
        if ($property->isPrivate()) {
            return 'private';
        }

        return $property->isProtected() ? 'protected' : 'public';
    }

    public function isNull(?Expr $expr): bool
    {
        return !$expr instanceof Expr || ($expr instanceof ConstFetch && strtolower($expr->name->toString()) === 'null');
    }
}
