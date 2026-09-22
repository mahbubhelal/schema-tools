<?php

declare(strict_types=1);

namespace Mahbub\SchemaTools\Tests\Fixtures\Audit\Models;

use Illuminate\Database\Eloquent\Attributes\Connection;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Model;

/**
 * An abstract base that mixes styles, as a base may, with its attributes out
 * of order.
 */
#[WithoutTimestamps]
#[Connection('tcb')]
abstract class MixedBase extends Model
{
    public $keyType = 'string';
}
