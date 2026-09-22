<?php

declare(strict_types=1);

namespace Mahbub\SchemaTools\Tests\Fixtures\Audit\Models;

use Illuminate\Database\Eloquent\Model;
use Mahbub\SchemaTools\Tests\Fixtures\Audit\Concerns\ConnectsToTcb;

/**
 * Takes its connection from the attribute on a trait, which Eloquent consults
 * before walking up to the parent class.
 */
final class TraitConnected extends Model
{
    use ConnectsToTcb;

    protected $table = 'TraitConnected';

    protected $primaryKey = 'Id';
}
