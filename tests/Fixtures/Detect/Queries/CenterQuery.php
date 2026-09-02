<?php

declare(strict_types=1);

namespace Mahbub\SchemaTools\Tests\Fixtures\Detect\Queries;

use Illuminate\Support\Facades\DB;

final class CenterQuery
{
    /**
     * @return list<object>
     */
    public function execute(): array
    {
        return DB::connection('tcb')->select(<<<'SQL'
            SELECT *
            FROM Center c
            JOIN [dbo].[press] p ON p.CenterId = c.CenterId
            JOIN TCBPermission.dbo.MasterProduct mp ON mp.Id = c.ProductId
            JOIN OtherDb.dbo.Thing t ON t.Id = c.ThingId
            LEFT JOIN #tempResults tr ON tr.CenterId = c.CenterId
            CROSS JOIN Ephemeral e
            SQL);
    }
}
