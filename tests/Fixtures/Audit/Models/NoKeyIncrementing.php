<?php

declare(strict_types=1);

namespace Mahbub\SchemaTools\Tests\Fixtures\Audit\Models;

use Illuminate\Database\Eloquent\Model;

final class NoKeyIncrementing extends Model
{
    public $timestamps = false;

    protected $connection = 'tcb';

    protected $table = 'HeapIncrementing';

    protected $primaryKey;
}
