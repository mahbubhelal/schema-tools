<?php

declare(strict_types=1);

namespace Mahbub\SchemaTools\Tests\Fixtures\Views\Models;

use Illuminate\Database\Eloquent\Model;

final class ViewIncrementing extends Model
{
    public $timestamps = false;

    protected $connection = 'tcb';

    protected $table = 'vInc';

    protected $primaryKey;
}
