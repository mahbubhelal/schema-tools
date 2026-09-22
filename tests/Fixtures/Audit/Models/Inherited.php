<?php

declare(strict_types=1);

namespace Mahbub\SchemaTools\Tests\Fixtures\Audit\Models;

/**
 * Inherits its connection, incrementing and timestamps declarations from the
 * attributes on its abstract parent.
 */
final class Inherited extends AttributedBase
{
    protected $table = 'Inherited';

    protected $primaryKey = 'InheritedId';
}
