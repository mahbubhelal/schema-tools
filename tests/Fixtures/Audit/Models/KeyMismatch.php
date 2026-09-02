<?php

declare(strict_types=1);

namespace Mahbub\SchemaTools\Tests\Fixtures\Audit\Models;

use Illuminate\Database\Eloquent\Model;

final class KeyMismatch extends Model
{
    public $incrementing = false;

    protected $connection = 'tcb';

    protected $table = 'KeyMismatch';

    protected $primaryKey = 'Code';

    protected $keyType = 'int';
}
