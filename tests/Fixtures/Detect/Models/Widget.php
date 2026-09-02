<?php

declare(strict_types=1);

namespace Mahbub\SchemaTools\Tests\Fixtures\Detect\Models;

use Illuminate\Database\Eloquent\Model;

final class Widget extends Model
{
    protected $connection = 'other';

    protected $table = 'Widget';
}
