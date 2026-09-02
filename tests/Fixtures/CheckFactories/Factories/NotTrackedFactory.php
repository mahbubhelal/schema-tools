<?php

declare(strict_types=1);

namespace Mahbub\SchemaTools\Tests\Fixtures\CheckFactories\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Mahbub\SchemaTools\Tests\Fixtures\CheckFactories\Models\FUntracked;

/**
 * @extends Factory<FUntracked>
 */
final class NotTrackedFactory extends Factory
{
    protected $model = FUntracked::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['Name' => 'x'];
    }
}
