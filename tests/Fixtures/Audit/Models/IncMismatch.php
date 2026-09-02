<?php

declare(strict_types=1);

namespace Mahbub\SchemaTools\Tests\Fixtures\Audit\Models;

use Illuminate\Database\Eloquent\Model;

final class IncMismatch extends Model
{
    public $incrementing = false;

    protected $connection = 'tcb';

    protected $table = 'IncMismatch';

    protected $primaryKey = 'IncId';
}
