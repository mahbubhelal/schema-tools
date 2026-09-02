<?php

declare(strict_types=1);

namespace Mahbub\SchemaTools\Tests\Fixtures\CheckFactories\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use RuntimeException;

/**
 * @extends Factory<\Illuminate\Database\Eloquent\Model>
 */
final class ExplodingFactory extends Factory
{
    public function __construct()
    {
        throw new RuntimeException('cannot instantiate');
    }

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [];
    }
}
