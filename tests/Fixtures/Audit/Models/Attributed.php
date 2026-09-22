<?php

declare(strict_types=1);

namespace Mahbub\SchemaTools\Tests\Fixtures\Audit\Models;

use Illuminate\Database\Eloquent\Attributes\Connection;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Model;

/**
 * Declares everything through Eloquent's class attributes instead of
 * properties, so the audit must read the attributes to see the declarations.
 */
#[Connection('tcb')]
#[Table('Attributed', key: 'AttributedId')]
#[WithoutTimestamps]
final class Attributed extends Model {}
