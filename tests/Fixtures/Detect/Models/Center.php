<?php

declare(strict_types=1);

namespace Mahbub\SchemaTools\Tests\Fixtures\Detect\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

final class Center extends Model
{
    protected $connection = 'tcb';

    protected $table = 'Center';

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, table: 'CenterProduct');
    }
}
