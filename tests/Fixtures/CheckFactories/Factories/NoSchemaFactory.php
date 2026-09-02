<?php

declare(strict_types=1);

namespace Mahbub\SchemaTools\Tests\Fixtures\CheckFactories\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Mahbub\SchemaTools\Tests\Fixtures\CheckFactories\Models\FNoSchema;

/**
 * @extends Factory<FNoSchema>
 */
final class NoSchemaFactory extends Factory
{
    protected $model = FNoSchema::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['Name' => 'x'];
    }
}
