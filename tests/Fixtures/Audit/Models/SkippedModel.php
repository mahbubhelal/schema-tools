<?php

declare(strict_types=1);

namespace Mahbub\SchemaTools\Tests\Fixtures\Audit\Models;

use Illuminate\Database\Eloquent\Model;

final class SkippedModel extends Model
{
    protected $connection = 'other';

    protected $table = 'Whatever';
}
