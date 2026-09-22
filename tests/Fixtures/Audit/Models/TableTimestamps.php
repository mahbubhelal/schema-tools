<?php

declare(strict_types=1);

namespace Mahbub\SchemaTools\Tests\Fixtures\Audit\Models;

use Illuminate\Database\Eloquent\Attributes\Connection;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Disables timestamps through the `#[Table]` attribute's `timestamps` argument.
 */
#[Connection('tcb')]
#[Table('TableTimestamps', timestamps: false)]
final class TableTimestamps extends Model {}
