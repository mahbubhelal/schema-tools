<?php

declare(strict_types=1);

namespace Mahbub\SchemaTools\Tests\Fixtures\Detect\Models;

use Illuminate\Database\Eloquent\Model;

final class Product extends Model
{
    protected $connection = 'tcbpermission';

    protected $table = 'MasterProduct';
}
