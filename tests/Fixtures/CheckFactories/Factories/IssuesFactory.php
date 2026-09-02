<?php

declare(strict_types=1);

namespace Mahbub\SchemaTools\Tests\Fixtures\CheckFactories\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\Sequence;
use Mahbub\SchemaTools\Tests\Fixtures\CheckFactories\Models\FBad;
use RuntimeException;

/**
 * @extends Factory<FBad>
 */
final class IssuesFactory extends Factory
{
    protected $model = FBad::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'Note' => 'x',
            'Status' => 5,
            'fullname' => 'y',
            'Bogus' => 'z',
            'Age' => 'text',
            'OwnerId' => new Sequence(1, 2),
            'BadClosure' => fn (): string => throw new RuntimeException('boom'),
            'NullCol' => null,
        ];
    }
}
