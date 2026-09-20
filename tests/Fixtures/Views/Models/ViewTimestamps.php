<?php

declare(strict_types=1);

namespace Mahbub\SchemaTools\Tests\Fixtures\Views\Models;

use Illuminate\Database\Eloquent\Model;

final class ViewTimestamps extends Model
{
    public $incrementing = false;

    protected $connection = 'tcb';

    protected $table = 'vTs';

    protected $primaryKey;
}
