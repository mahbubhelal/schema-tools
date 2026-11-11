<?php

declare(strict_types=1);

namespace Mahbub\SchemaTools\Tests\Fixtures\QueryAttribution\Queries;

final class ThreePartOnlyQuery
{
    public function sql(): string
    {
        return 'SELECT * FROM TCBPermission.dbo.MasterProduct';
    }
}
