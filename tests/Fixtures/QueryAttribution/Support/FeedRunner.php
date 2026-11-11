<?php

declare(strict_types=1);

namespace Mahbub\SchemaTools\Tests\Fixtures\QueryAttribution\Support;

use Illuminate\Support\Facades\DB;

final class FeedRunner
{
    /**
     * @return list<object>
     */
    public function run(string $sql): array
    {
        /** @var list<object> */
        return DB::connection('tcb')->select($sql);
    }
}
