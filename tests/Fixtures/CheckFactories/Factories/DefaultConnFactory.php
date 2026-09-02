<?php

declare(strict_types=1);

namespace Mahbub\SchemaTools\Tests\Fixtures\CheckFactories\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Mahbub\SchemaTools\Tests\Fixtures\CheckFactories\Models\FDefaulter;

/**
 * @extends Factory<FDefaulter>
 */
final class DefaultConnFactory extends Factory
{
    protected $model = FDefaulter::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['Name' => 'x'];
    }
}
