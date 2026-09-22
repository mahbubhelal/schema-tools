<?php

declare(strict_types=1);

namespace Mahbub\SchemaTools\Tests\Fixtures\Audit\Models;

/**
 * Inherits a `#[Table]` attribute from its parent, which its own `#[Table]`
 * would shadow.
 */
final class TabledChild extends TabledBase
{
    protected $table = 'TabledChild';

    protected $primaryKey = 'Code';
}
