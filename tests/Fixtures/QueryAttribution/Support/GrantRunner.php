<?php

declare(strict_types=1);

namespace Mahbub\SchemaTools\Tests\Fixtures\QueryAttribution\Support;

use Mahbub\SchemaTools\Tests\Fixtures\QueryAttribution\Models\Permission;

final class GrantRunner
{
    /**
     * @return list<object>
     */
    public function run(string $sql): array
    {
        /** @var list<object> */
        return new Permission()->getConnection()->select($sql);
    }
}
