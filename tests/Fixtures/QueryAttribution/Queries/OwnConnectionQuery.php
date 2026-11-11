<?php

declare(strict_types=1);

namespace Mahbub\SchemaTools\Tests\Fixtures\QueryAttribution\Queries;

use Illuminate\Support\Facades\DB;
use Mahbub\SchemaTools\Tests\Fixtures\QueryAttribution\Support\GrantRunner;

final readonly class OwnConnectionQuery
{
    public function __construct(private GrantRunner $grantRunner) {}

    /**
     * @return list<object>
     */
    public function execute(): array
    {
        $this->grantRunner->run('SELECT 1');

        return DB::connection('tcb')->select('SELECT * FROM OwnTable');
    }
}
