<?php

declare(strict_types=1);

namespace Mahbub\SchemaTools\Tests\Fixtures\Audit\Models;

use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Model;

/**
 * Declares with an attribute and with properties at once.
 */
#[WithoutTimestamps]
final class MixedStyle extends Model
{
    protected $connection = 'tcb';

    protected $table = 'MixedStyle';

    protected $primaryKey = 'MixedStyleId';
}
