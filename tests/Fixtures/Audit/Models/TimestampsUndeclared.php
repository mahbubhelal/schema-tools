<?php

declare(strict_types=1);

namespace Mahbub\SchemaTools\Tests\Fixtures\Audit\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Disables timestamps by overriding usesTimestamps() rather than declaring the
 * `$timestamps` property, so the audit flags the undeclared disable.
 */
final class TimestampsUndeclared extends Model
{
    protected $connection = 'tcb';

    protected $table = 'TimestampsUndeclared';

    protected $primaryKey = 'Id';

    #[\Override]
    public function usesTimestamps(): bool
    {
        return false;
    }
}
