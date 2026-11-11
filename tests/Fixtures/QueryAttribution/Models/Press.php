<?php

declare(strict_types=1);

namespace Mahbub\SchemaTools\Tests\Fixtures\QueryAttribution\Models;

use Illuminate\Database\Eloquent\Model;

final class Press extends Model
{
    protected $connection = 'tcb';

    protected $table = 'press';
}
