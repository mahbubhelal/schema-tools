<?php

declare(strict_types=1);

namespace Mahbub\SchemaTools\Tests\Fixtures\Audit\Models;

use Illuminate\Database\Eloquent\Attributes\Connection;
use Illuminate\Database\Eloquent\Model;
use Mahbub\SchemaTools\Tests\Fixtures\Audit\Concerns\TabledByTrait;

/**
 * Takes its key from a `#[Table]` attribute on a trait.
 */
#[Connection('tcb')]
final class TraitTabled extends Model
{
    use TabledByTrait;
}
