<?php

declare(strict_types=1);

namespace Mahbub\SchemaTools\Tests\Fixtures\Audit\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Declares everything correctly, in the wrong order.
 */
final class Unordered extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $table = 'Unordered';

    protected $connection = 'tcb';

    protected $primaryKey = 'UnorderedId';
}
