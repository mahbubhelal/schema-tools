<?php

declare(strict_types=1);

namespace Mahbub\SchemaTools\Tests\Fixtures\QueryAttribution\Models;

use Illuminate\Database\Eloquent\Model;

final class Permission extends Model
{
    protected $connection = 'tcbpermission';

    protected $table = 'Permission';
}
