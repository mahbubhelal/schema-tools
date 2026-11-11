<?php

declare(strict_types=1);

namespace Mahbub\SchemaTools\Tests\Fixtures\QueryAttribution\Queries;

use Mahbub\SchemaTools\Tests\Fixtures\QueryAttribution\Models\Press;

final class ModelConnectionQuery
{
    /**
     * @return list<object>
     */
    public function execute(): array
    {
        return new Press()->getConnection()->select('SELECT * FROM BannerLink');
    }
}
