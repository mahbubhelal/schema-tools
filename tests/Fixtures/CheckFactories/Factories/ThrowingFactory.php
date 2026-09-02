<?php

declare(strict_types=1);

namespace Mahbub\SchemaTools\Tests\Fixtures\CheckFactories\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Mahbub\SchemaTools\Tests\Fixtures\CheckFactories\Models\FThrower;
use RuntimeException;

/**
 * @extends Factory<FThrower>
 */
final class ThrowingFactory extends Factory
{
    protected $model = FThrower::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        throw new RuntimeException('kaboom');
    }
}
