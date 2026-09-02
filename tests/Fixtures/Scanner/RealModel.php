<?php

declare(strict_types=1);

namespace Mahbub\SchemaTools\Tests\Fixtures\Scanner;

use Illuminate\Database\Eloquent\Model;

final class RealModel extends Model
{
    protected $connection = 'tcb';

    protected $table = 'Center';
}
