<?php

declare(strict_types=1);

namespace Mahbub\SchemaTools\Tests\Fixtures\Audit\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Resolves to a fixture connection through getConnectionName() without declaring
 * the `$connection` property, so the audit flags the missing declaration.
 */
final class Undeclared extends Model
{
    protected $table = 'Undeclared';

    #[\Override]
    public function getConnectionName(): string
    {
        return 'tcb';
    }
}
