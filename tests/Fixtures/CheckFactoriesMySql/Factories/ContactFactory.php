<?php

declare(strict_types=1);

namespace Mahbub\SchemaTools\Tests\Fixtures\CheckFactoriesMySql\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Mahbub\SchemaTools\Tests\Fixtures\CheckFactoriesMySql\Models\Contact;

/**
 * @extends Factory<Contact>
 */
final class ContactFactory extends Factory
{
    protected $model = Contact::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => 'abc',
            'is_deleted' => false,
            'date_entered' => '2020-01-01 10:00:00',
        ];
    }
}
