<?php

declare(strict_types=1);

namespace Mahbub\SchemaTools\Declarations;

/**
 * Judges a class's own declarations on form alone: one style, the configured
 * style when the project fixes one, and the canonical order — attributes as a
 * whole, properties within each visibility group, so a class-element sorter
 * that puts public before protected never fights the audit.
 */
final class DeclarationAuditor
{
    /**
     * @return list<string>
     */
    public function issues(OwnDeclarations $declarations, ?DeclarationStyle $configured): array
    {
        $issues = [];

        if ($declarations->isMixed()) {
            $issues[] = sprintf(
                'mixes attribute and property declarations: %s vs %s',
                $this->attributeList($declarations->attributes),
                $this->propertyList($declarations->stylingProperties()),
            );
        } elseif ($configured instanceof DeclarationStyle) {
            $style = $declarations->style();

            if ($style instanceof DeclarationStyle && $style !== $configured) {
                $issues[] = "declares with {$style->value} but `declaration_style` is {$configured->value}";
            }
        }

        $sortedAttributes = $this->sorted($declarations->attributes, OwnDeclarations::ATTRIBUTE_ORDER);

        if ($sortedAttributes !== $declarations->attributes) {
            $issues[] = sprintf(
                'attributes out of order: %s; expected %s',
                $this->attributeList($declarations->attributes),
                $this->attributeList($sortedAttributes),
            );
        }

        foreach (['public', 'protected', 'private'] as $visibility) {
            $group = array_values(array_filter(
                $declarations->properties,
                fn (string $property): bool => $declarations->visibilities[$property] === $visibility,
            ));
            $sortedGroup = $this->sorted($group, OwnDeclarations::PROPERTY_ORDER);

            if ($sortedGroup !== $group) {
                $issues[] = sprintf(
                    '%s properties out of order: %s; expected %s',
                    $visibility,
                    $this->propertyList($group),
                    $this->propertyList($sortedGroup),
                );
            }
        }

        return $issues;
    }

    /**
     * @param  list<string>  $names
     * @param  list<string>  $order
     * @return list<string>
     */
    private function sorted(array $names, array $order): array
    {
        $sorted = $names;
        usort($sorted, static fn (string $a, string $b): int => array_search($a, $order, true) <=> array_search($b, $order, true));

        return $sorted;
    }

    /**
     * @param  list<string>  $attributes
     */
    private function attributeList(array $attributes): string
    {
        return implode(', ', array_map(static fn (string $name): string => "#[{$name}]", $attributes));
    }

    /**
     * @param  list<string>  $properties
     */
    private function propertyList(array $properties): string
    {
        return implode(', ', array_map(static fn (string $name): string => "\${$name}", $properties));
    }
}
