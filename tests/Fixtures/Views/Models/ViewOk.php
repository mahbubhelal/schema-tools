<?php

declare(strict_types=1);

namespace Mahbub\SchemaTools\Tests\Fixtures\Views\Models;

use Illuminate\Database\Eloquent\Model;

final class ViewOk extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    protected $connection = 'tcb';

    protected $table = 'vOk';

    protected $primaryKey;
}
