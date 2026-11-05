<?php

declare(strict_types=1);

namespace Mahbub\SchemaTools\Declarations;

enum Visibility: string
{
    case Public = 'public';
    case Protected = 'protected';
    case Private = 'private';
}
