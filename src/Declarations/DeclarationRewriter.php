<?php

declare(strict_types=1);

namespace Mahbub\SchemaTools\Declarations;

use PhpParser\Modifiers;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Attribute;
use PhpParser\Node\AttributeGroup;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\PropertyItem;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassConst;
use PhpParser\Node\Stmt\Property;
use PhpParser\Node\Stmt\TraitUse;
use PhpParser\PrettyPrinter\Standard;

/**
 * Rewrites a class's own schema declarations so they satisfy the audit: every
 * value the DDL disagrees with is redeclared, the connection and a disabled
 * timestamps switch are declared when nothing in the hierarchy does, and all
 * of it is expressed in one style — the configured one, else the style the
 * class already leans to — in canonical order. Declarations that need no
 * change keep their nodes (and so their docblocks and `#[\Override]`), other
 * attributes and statements keep their places, and a class that already
 * passes is left untouched. An abstract model is only reordered: it may mix
 * styles, because a property it declares reaches every child while a
 * `#[Table]` it declares is hidden by any child's own `#[Table]`.
 */
final readonly class DeclarationRewriter
{
    private const array TABLE_FIELDS = ['name' => 'table', 'key' => 'primaryKey', 'keyType' => 'keyType', 'incrementing' => 'incrementing', 'timestamps' => 'timestamps'];

    private const array PROTECTED_PROPERTIES = ['connection', 'table', 'primaryKey', 'keyType'];

    private Standard $printer;

    public function __construct(
        private DeclarationReader $reader,
    ) {
        $this->printer = new Standard;
    }

    /**
     * Returns whether the class changed.
     */
    public function rewrite(Class_ $class, ?ModelFacts $facts, ?DeclarationStyle $configured): bool
    {
        $before = $this->printer->prettyPrint([$class]);

        $own = $this->reader->read($class);
        $target = $configured ?? $own->dominantStyle() ?? DeclarationStyle::Attributes;

        $desired = $this->applyFacts($this->currentValues($class), $facts);

        [$existingAttributes, $existingProperties] = $this->existingNodes($class);

        if ($target === DeclarationStyle::Attributes) {
            $attributes = $this->attributesFor($desired, $existingAttributes, $facts->inheritedTable ?? []);
            $properties = [];

            if (array_key_exists('primaryKey', $desired) && $this->reader->isNull($desired['primaryKey'])) {
                $properties['primaryKey'] = $this->property('primaryKey', $desired['primaryKey'], $existingProperties);
            }
        } else {
            $attributes = [];
            $properties = [];
            $table = $existingAttributes['Table'][0] ?? null;

            if ($table instanceof Attribute && $this->otherTableArguments($table) !== []) {
                $table->args = $this->otherTableArguments($table);
                $attributes['Table'] = $table;
            }

            foreach (OwnDeclarations::PROPERTY_ORDER as $field) {
                if (array_key_exists($field, $desired)) {
                    $properties[$field] = $this->property($field, $desired[$field], $existingProperties);
                }
            }
        }

        $class->attrGroups = $this->placeAttributes(array_values($class->attrGroups), $attributes);
        $class->stmts = $this->placeProperties(array_values($class->stmts), $properties);

        return $this->printer->prettyPrint([$class]) !== $before;
    }

    /**
     * Puts the class's own declarations in canonical order, each in the form
     * it already has. Returns whether the class changed.
     */
    public function reorder(Class_ $class): bool
    {
        $before = $this->printer->prettyPrint([$class]);

        [$existingAttributes, $existingProperties] = $this->existingNodes($class);

        $class->attrGroups = $this->placeAttributes(
            array_values($class->attrGroups),
            array_map(static fn (array $entry): Attribute => $entry[0], $existingAttributes),
        );
        $class->stmts = $this->placeProperties(
            array_values($class->stmts),
            array_map(static fn (array $entry): Property => $entry[0], $existingProperties),
        );

        return $this->printer->prettyPrint([$class]) !== $before;
    }

    /**
     * The values the class's own declarations carry, property over attribute
     * as Eloquent resolves them.
     *
     * @return array<string, Expr>
     */
    private function currentValues(Class_ $class): array
    {
        $values = [];

        foreach ($class->attrGroups as $group) {
            foreach ($group->attrs as $attribute) {
                switch ($this->reader->attributeName($attribute)) {
                    case 'Connection':
                        if (isset($attribute->args[0])) {
                            $values['connection'] = $attribute->args[0]->value;
                        }

                        break;
                    case 'Table':
                        foreach ($this->tableArguments($attribute) as $argument => $expr) {
                            $values[self::TABLE_FIELDS[$argument]] = $expr;
                        }

                        break;
                    case 'WithoutIncrementing':
                        $values['incrementing'] = $this->bool(false);

                        break;
                    case 'WithoutTimestamps':
                        $values['timestamps'] = $this->bool(false);

                        break;
                }
            }
        }

        foreach ($class->stmts as $stmt) {
            if (!$stmt instanceof Property) {
                continue;
            }

            foreach ($stmt->props as $item) {
                $name = $item->name->toString();

                if (!in_array($name, OwnDeclarations::PROPERTY_ORDER, true)) {
                    continue;
                }

                if ($item->default instanceof Expr) {
                    $values[$name] = $item->default;
                } elseif ($name === 'primaryKey') {
                    $values[$name] = $this->null();
                }
            }
        }

        return $values;
    }

    /**
     * @param  array<string, Expr>  $values
     * @return array<string, Expr>
     */
    private function applyFacts(array $values, ?ModelFacts $facts): array
    {
        if (!$facts instanceof ModelFacts) {
            return $values;
        }

        $effective = $facts->effective;

        if (!$effective->connectionDeclared) {
            $values['connection'] = new String_($effective->connection);
        }

        if (!$effective->timestamps && !$effective->timestampsDeclared) {
            $values['timestamps'] = $this->bool(false);
        }

        $expected = $facts->expected;

        if (!$expected instanceof Expectation) {
            return $values;
        }

        if ($effective->primaryKey !== $expected->primaryKey) {
            $values['primaryKey'] = $expected->primaryKey === null ? $this->null() : new String_($expected->primaryKey);

            if ($expected->primaryKey === null) {
                unset($values['keyType']);
            }
        }

        if ($expected->keyType !== null && $effective->keyType !== $expected->keyType) {
            $values['keyType'] = new String_($expected->keyType);
        }

        if ($effective->incrementing !== $expected->incrementing) {
            $values['incrementing'] = $this->bool($expected->incrementing);
        }

        if ($effective->timestamps !== $expected->timestamps) {
            $values['timestamps'] = $this->bool($expected->timestamps);
        }

        return $values;
    }

    /**
     * The class's own audited attribute and property nodes, keyed by attribute
     * short name and by property name, each remembering the index it sits at.
     *
     * @return array{array<string, array{Attribute, int}>, array<string, array{Property, int}>}
     */
    private function existingNodes(Class_ $class): array
    {
        $attributes = [];

        foreach ($class->attrGroups as $index => $group) {
            foreach ($group->attrs as $attribute) {
                $name = $this->reader->attributeName($attribute);

                if ($name !== null) {
                    $attributes[$name] = [$attribute, $index];
                }
            }
        }

        $properties = [];

        foreach ($class->stmts as $index => $stmt) {
            if (!$stmt instanceof Property) {
                continue;
            }

            foreach ($stmt->props as $item) {
                $name = $item->name->toString();

                if (in_array($name, OwnDeclarations::PROPERTY_ORDER, true)) {
                    $properties[$name] = [$stmt, $index];
                }
            }
        }

        return [$attributes, $properties];
    }

    /**
     * The attributes that express the desired values, keyed by short name in
     * canonical order; an existing node is reused so only its arguments move.
     * A `#[Table]` written here shadows any inherited one, so the inherited
     * arguments the class does not override are carried over into it.
     *
     * @param  array<string, Expr>  $desired
     * @param  array<string, array{Attribute, int}>  $existing
     * @param  array<string, string|bool>  $inheritedTable
     * @return array<string, Attribute>
     */
    private function attributesFor(array $desired, array $existing, array $inheritedTable): array
    {
        $attributes = [];

        if (isset($desired['connection'])) {
            $attribute = $existing['Connection'][0] ?? new Attribute(new FullyQualified(DeclarationReader::ATTRIBUTE_NAMESPACE . 'Connection'));
            $attribute->args = [new Arg($desired['connection'])];
            $attributes['Connection'] = $attribute;
        }

        $tableArguments = [];

        foreach (self::TABLE_FIELDS as $argument => $field) {
            if (!isset($desired[$field])) {
                continue;
            }

            if ($field === 'primaryKey' && $this->reader->isNull($desired[$field])) {
                continue;
            }

            if (in_array($field, ['incrementing', 'timestamps'], true) && !$this->isTrue($desired[$field])) {
                continue;
            }

            $tableArguments[$argument] = $desired[$field];
        }

        $table = $existing['Table'][0] ?? null;

        if ($tableArguments !== [] && !$table instanceof Attribute) {
            foreach ($inheritedTable as $argument => $value) {
                $field = self::TABLE_FIELDS[$argument];

                if (!isset($tableArguments[$argument]) && !isset($desired[$field])) {
                    $tableArguments[$argument] = is_bool($value) ? $this->bool($value) : new String_($value);
                }
            }

            uksort($tableArguments, static fn (string $a, string $b): int => array_search($a, DeclarationReader::TABLE_ARGUMENTS, true) <=> array_search($b, DeclarationReader::TABLE_ARGUMENTS, true));
        }

        $otherArguments = $table instanceof Attribute ? $this->otherTableArguments($table) : [];

        if ($tableArguments !== [] || $otherArguments !== []) {
            $namedName = $table instanceof Attribute && array_filter($table->args, static fn (Arg $arg): bool => $arg->name instanceof Identifier && $arg->name->toString() === 'name') !== [];
            $table ??= new Attribute(new FullyQualified(DeclarationReader::ATTRIBUTE_NAMESPACE . 'Table'));
            $args = [];

            foreach ($tableArguments as $argument => $expr) {
                $args[] = $argument === 'name' && !$namedName ? new Arg($expr) : new Arg($expr, name: new Identifier($argument));
            }

            $table->args = [...$args, ...$otherArguments];
            $attributes['Table'] = $table;
        }

        foreach (['incrementing' => 'WithoutIncrementing', 'timestamps' => 'WithoutTimestamps'] as $field => $name) {
            if (isset($desired[$field]) && !$this->isTrue($desired[$field])) {
                $attributes[$name] = $existing[$name][0] ?? new Attribute(new FullyQualified(DeclarationReader::ATTRIBUTE_NAMESPACE . $name));
            }
        }

        return $attributes;
    }

    /**
     * The `#[Table]` arguments the audit does not know, such as `dateFormat`.
     *
     * @return list<Arg>
     */
    private function otherTableArguments(Attribute $table): array
    {
        return array_values(array_filter(
            $table->args,
            static fn (Arg $arg): bool => $arg->name instanceof Identifier && !in_array($arg->name->toString(), DeclarationReader::TABLE_ARGUMENTS, true),
        ));
    }

    /**
     * @param  array<string, array{Property, int}>  $existing
     */
    private function property(string $field, Expr $value, array $existing): Property
    {
        $property = $existing[$field][0] ?? null;

        if ($property instanceof Property) {
            foreach ($property->props as $item) {
                if ($item->name->toString() === $field && (!$this->reader->isNull($item->default) || !$this->reader->isNull($value))) {
                    $item->default = $value;
                }
            }

            return $property;
        }

        $flags = in_array($field, self::PROTECTED_PROPERTIES, true) ? Modifiers::PROTECTED : Modifiers::PUBLIC;

        return new Property($flags, [new PropertyItem($field, $value)]);
    }

    /**
     * Lays the audited attributes back among the class's attribute groups:
     * every other attribute keeps its group and place, the audited ones that
     * were already there take the same slots in canonical order, and a new
     * one goes right after its canonical predecessor (or at the front).
     *
     * @param  list<AttributeGroup>  $groups
     * @param  array<string, Attribute>  $attributes
     * @return list<AttributeGroup>
     */
    private function placeAttributes(array $groups, array $attributes): array
    {
        $slots = [];
        $placed = [];

        foreach ($groups as $index => $group) {
            $others = [];

            foreach ($group->attrs as $attribute) {
                $name = $this->reader->attributeName($attribute);

                if ($name === null) {
                    $others[] = $attribute;
                } else {
                    $slots[$name] = $index;
                }
            }

            if ($others !== []) {
                $group->attrs = $others;
                $placed[] = [(float) $index, $group];
            }
        }

        foreach ($this->orderBySlot(array_keys($attributes), OwnDeclarations::ATTRIBUTE_ORDER, $slots, -1.0) as $name => $key) {
            $placed[] = [$key, new AttributeGroup([$attributes[$name]])];
        }

        return $this->sortedBySlot($placed);
    }

    /**
     * Lays the audited properties back among the class's statements the same
     * way, slot by slot within each visibility; a property with no slot to
     * follow lands after the trait uses and constants.
     *
     * @param  list<Stmt>  $stmts
     * @param  array<string, Property>  $properties
     * @return list<Stmt>
     */
    private function placeProperties(array $stmts, array $properties): array
    {
        $slots = [];
        $placed = [];
        $top = 0;

        foreach ($stmts as $index => $stmt) {
            if ($stmt instanceof TraitUse || $stmt instanceof ClassConst) {
                $top = $index + 1;
            }

            $audited = $stmt instanceof Property && in_array($stmt->props[0]->name->toString(), OwnDeclarations::PROPERTY_ORDER, true);

            if ($audited) {
                $slots[$stmt->props[0]->name->toString()] = $index;
            } else {
                $placed[] = [(float) $index, $stmt];
            }
        }

        $fallback = ($slots === [] ? $top : max($slots) + 1) - 0.5;

        foreach (['public', 'protected', 'private'] as $visibility) {
            $group = array_keys(array_filter($properties, fn (Property $property): bool => $this->reader->visibility($property) === $visibility));
            $groupSlots = array_intersect_key($slots, array_flip($group));
            $keys = $this->orderBySlot($group, OwnDeclarations::PROPERTY_ORDER, $groupSlots, $fallback);

            foreach ($keys as $name => $key) {
                $placed[] = [$key, $properties[$name]];
            }

            $fallback = max([$fallback, ...array_values($keys)]) + 0.01;
        }

        return $this->sortedBySlot($placed);
    }

    /**
     * @template TNode of Node
     *
     * @param  list<array{float, TNode}>  $placed
     * @return list<TNode>
     */
    private function sortedBySlot(array $placed): array
    {
        usort($placed, static fn (array $a, array $b): int => $a[0] <=> $b[0]);

        return array_map(static fn (array $entry): Node => $entry[1], $placed);
    }

    /**
     * Assigns each name a sort key: the names that already had a slot take
     * the slots, in canonical order, and a new name keys just after its
     * nearest canonical predecessor with a slot (just before its nearest
     * successor, or at the fallback, when it has none).
     *
     * @param  list<string>  $names
     * @param  list<string>  $order
     * @param  array<string, int>  $slots
     * @return array<string, float>
     */
    private function orderBySlot(array $names, array $order, array $slots, float $fallback): array
    {
        $canonical = array_values(array_filter($order, static fn (string $name): bool => in_array($name, $names, true)));
        $taken = array_values(array_intersect_key($slots, array_flip($canonical)));
        sort($taken);

        $keys = [];
        $existing = array_values(array_filter($canonical, static fn (string $name): bool => array_key_exists($name, $slots)));

        foreach ($existing as $position => $name) {
            $keys[$name] = (float) $taken[$position];
        }

        foreach ($canonical as $position => $name) {
            if (array_key_exists($name, $keys)) {
                continue;
            }

            $predecessor = null;

            for ($i = $position - 1; $i >= 0; $i--) {
                if (array_key_exists($canonical[$i], $keys)) {
                    $predecessor = $canonical[$i];

                    break;
                }
            }

            if ($predecessor !== null) {
                $keys[$name] = $keys[$predecessor] + 0.01;

                continue;
            }

            $successor = null;
            $counter = count($canonical);

            for ($i = $position + 1; $i < $counter; $i++) {
                if (array_key_exists($canonical[$i], $keys)) {
                    $successor = $canonical[$i];

                    break;
                }
            }

            $keys[$name] = $successor === null ? $fallback : $keys[$successor] - 0.5;
        }

        return $keys;
    }

    /**
     * The `#[Table]` arguments the audit knows, keyed by name; the first
     * positional argument is the table name.
     *
     * @return array<string, Expr>
     */
    private function tableArguments(Attribute $attribute): array
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

            if (in_array($name, DeclarationReader::TABLE_ARGUMENTS, true)) {
                $arguments[$name] = $arg->value;
            }
        }

        return $arguments;
    }

    private function isTrue(Expr $expr): bool
    {
        return $expr instanceof ConstFetch && strtolower($expr->name->toString()) === 'true';
    }

    private function bool(bool $value): ConstFetch
    {
        return new ConstFetch(new Name($value ? 'true' : 'false'));
    }

    private function null(): ConstFetch
    {
        return new ConstFetch(new Name('null'));
    }
}
