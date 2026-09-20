<?php

declare(strict_types=1);

namespace Mahbub\SchemaTools\Support;

/**
 * What a manifest name resolved to at the source.
 */
enum ObjectKind
{
    case Table;
    case View;
    case Other;
}
