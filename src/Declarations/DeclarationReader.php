<?php

declare(strict_types=1);

namespace Mahbub\SchemaTools\Declarations;

use PhpParser\Node\Attribute;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\PropertyItem;
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

    /**
     * The audited `#[Table]` arguments, in canonical order, each with the
     * property it stands for.
     */
    public const array TABLE_FIELDS = ['name' => 'table', 'key' => 'primaryKey', 'keyType' => 'keyType', 'incrementing' => 'incrementing', 'timestamps' => 'timestamps'];

    public function read(Class_ $class): OwnDeclarations
    {
        $attributes = [];

        foreach ($this->auditedAttributes($class) as $name => $attribute) {
            if ($this->declaresSchema($name, $attribute)) {
                $attributes[] = $name;
            }
        }

        $properties = [];
        $visibilities = [];
        $nullKeyProperty = false;

        foreach ($this->auditedProperties($class) as $name => [$property, $item]) {
            $properties[] = $name;
            $visibilities[$name] = $this->visibility($property);

            if ($name === 'primaryKey' && $this->isNull($item->default)) {
                $nullKeyProperty = true;
            }
        }

        return new OwnDeclarations($attributes, $properties, $visibilities, $nullKeyProperty);
    }

    /**
     * The class's audited Eloquent attributes, in source order, keyed by short
     * name.
     *
     * @return iterable<string, Attribute>
     */
    public function auditedAttributes(Class_ $class): iterable
    {
        foreach ($class->attrGroups as $group) {
            foreach ($group->attrs as $attribute) {
                $name = $this->attributeName($attribute);

                if ($name !== null) {
                    yield $name => $attribute;
                }
            }
        }
    }

    /**
     * The class's own audited property declarations, in source order, keyed by
     * property name, each with the statement that declares it.
     *
     * @return iterable<string, array{Property, PropertyItem}>
     */
    public function auditedProperties(Class_ $class): iterable
    {
        foreach ($class->stmts as $stmt) {
            if (!$stmt instanceof Property) {
                continue;
            }

            foreach ($stmt->props as $item) {
                $name = $item->name->toString();

                if (in_array($name, OwnDeclarations::PROPERTY_ORDER, true)) {
                    yield $name => [$stmt, $item];
                }
            }
        }
    }

    /**
     * The `#[Table]` arguments the audit knows, keyed by name; the first
     * positional argument is the table name.
     *
     * @return array<string, Expr>
     */
    public function tableArguments(Attribute $attribute): array
    {
        $arguments = [];

        foreach ($attribute->args as $position => $arg) {
            if ($arg->name instanceof Identifier) {
                $name = $arg->name->toString();
            } elseif ($position === 0) {
                $name = 'name';
            } else {
                continue;
            }

            if (array_key_exists($name, self::TABLE_FIELDS)) {
                $arguments[$name] = $arg->value;
            }
        }

        return $arguments;
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

    public function visibility(Property $property): Visibility
    {
        if ($property->isPrivate()) {
            return Visibility::Private;
        }

        return $property->isProtected() ? Visibility::Protected : Visibility::Public;
    }

    public function isNull(?Expr $expr): bool
    {
        return !$expr instanceof Expr || ($expr instanceof ConstFetch && strtolower($expr->name->toString()) === 'null');
    }

    /**
     * Whether an audited attribute declares schema metadata: any but a
     * `#[Table]` that carries none of the audited arguments (say only
     * `dateFormat`), which declares nothing the audit cares about.
     */
    private function declaresSchema(string $name, Attribute $attribute): bool
    {
        return $name !== 'Table' || $this->tableArguments($attribute) !== [];
    }
}
