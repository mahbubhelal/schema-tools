<?php

declare(strict_types=1);

namespace Mahbub\SchemaTools\Tests\Fixtures\CheckFactories\Models;

use Illuminate\Database\Eloquent\Model;

final class FPassing extends Model
{
    protected $connection = 'tcb';

    protected $table = 'FPassing';
}
