<?php

declare(strict_types=1);

namespace Mahbub\SchemaTools\Tests\Fixtures\Audit\Models;

use Illuminate\Database\Eloquent\Model;

final class NoKeyButDdlHasPk extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    protected $connection = 'tcb';

    protected $table = 'KeyedHeap';

    protected $primaryKey;
}
