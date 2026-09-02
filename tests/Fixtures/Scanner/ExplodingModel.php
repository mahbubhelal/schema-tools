<?php

declare(strict_types=1);

namespace Mahbub\SchemaTools\Tests\Fixtures\Scanner;

use Illuminate\Database\Eloquent\Model;
use RuntimeException;

final class ExplodingModel extends Model
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        throw new RuntimeException('cannot instantiate');
    }
}
