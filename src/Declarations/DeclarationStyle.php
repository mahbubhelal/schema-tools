<?php

declare(strict_types=1);

namespace Mahbub\SchemaTools\Declarations;

/**
 * The two ways a model may declare its schema metadata: Eloquent's class
 * attributes (`#[Connection]`, `#[Table]`, `#[WithoutIncrementing]`,
 * `#[WithoutTimestamps]`) or the classic properties (`$connection`, `$table`,
 * `$primaryKey`, `$keyType`, `$incrementing`, `$timestamps`). A model uses one
 * or the other, never both.
 */
enum DeclarationStyle: string
{
    case Attributes = 'attributes';
    case Properties = 'properties';
}
