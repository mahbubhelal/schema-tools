<?php

declare(strict_types=1);

namespace Mahbub\SchemaTools\Declarations;

/**
 * The schema declarations a class makes in its own source, in source order:
 * the short names of the Eloquent attributes it carries and the names of the
 * properties it redeclares, with each property's visibility. A `$primaryKey`
 * set to null is the one property an attribute-styled model may keep, since no
 * attribute can express "no key"; it never counts towards the style. Only a
 * concrete model is held to one style: an abstract base may mix, since a
 * property it declares reaches every child while a `#[Table]` it declares is
 * hidden by any child's own `#[Table]`.
 */
final readonly class OwnDeclarations
{
    public const array ATTRIBUTE_ORDER = ['Connection', 'Table', 'WithoutIncrementing', 'WithoutTimestamps'];

    public const array PROPERTY_ORDER = ['connection', 'table', 'primaryKey', 'keyType', 'incrementing', 'timestamps'];

    /**
     * @param  list<string>  $attributes
     * @param  list<string>  $properties
     * @param  array<string, string>  $visibilities  Property name => public|protected|private.
     * @param  bool  $nullKeyProperty  Whether the `$primaryKey` property is present and null.
     */
    public function __construct(
        public array $attributes,
        public array $properties,
        public array $visibilities,
        public bool $nullKeyProperty,
    ) {}

    /**
     * The properties that count towards the style.
     *
     * @return list<string>
     */
    public function stylingProperties(): array
    {
        return array_values(array_filter(
            $this->properties,
            fn (string $property): bool => $property !== 'primaryKey' || !$this->nullKeyProperty,
        ));
    }

    public function isMixed(): bool
    {
        return $this->attributes !== [] && $this->stylingProperties() !== [];
    }

    /**
     * The style the class uses, or null when it declares nothing or mixes both.
     */
    public function style(): ?DeclarationStyle
    {
        if ($this->isMixed()) {
            return null;
        }

        if ($this->attributes !== []) {
            return DeclarationStyle::Attributes;
        }

        return $this->stylingProperties() !== [] ? DeclarationStyle::Properties : null;
    }

    /**
     * The style with more declarations, attributes winning a tie, or null when
     * the class declares nothing.
     */
    public function dominantStyle(): ?DeclarationStyle
    {
        $properties = count($this->stylingProperties());

        if (count($this->attributes) === 0 && $properties === 0) {
            return null;
        }

        return count($this->attributes) >= $properties ? DeclarationStyle::Attributes : DeclarationStyle::Properties;
    }
}
