<?php

declare(strict_types=1);

namespace Mahbub\SchemaTools\Tests\Fixtures\CheckFactoriesMySql\Models;

use Illuminate\Database\Eloquent\Model;

final class Contact extends Model
{
    protected $connection = 'sugar';

    protected $table = 'contacts';
}
