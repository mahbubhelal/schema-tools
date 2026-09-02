<?php

declare(strict_types=1);

namespace Mahbub\SchemaTools\Tests\Fixtures\Audit\Models;

use Illuminate\Database\Eloquent\Model;

final class MissingTable extends Model
{
    protected $connection = 'tcb';

    protected $table = 'NotInSchema';
}
