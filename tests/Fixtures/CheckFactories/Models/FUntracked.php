<?php

declare(strict_types=1);

namespace Mahbub\SchemaTools\Tests\Fixtures\CheckFactories\Models;

use Illuminate\Database\Eloquent\Model;

final class FUntracked extends Model
{
    protected $connection = 'tcb';

    protected $table = 'FUntracked';
}
