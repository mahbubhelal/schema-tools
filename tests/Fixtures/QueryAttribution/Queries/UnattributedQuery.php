<?php

declare(strict_types=1);

namespace Mahbub\SchemaTools\Tests\Fixtures\QueryAttribution\Queries;

final class UnattributedQuery
{
    public function sql(): string
    {
        return 'SELECT * FROM SomethingElse s JOIN [dbo].[Other] o ON o.Id = s.Id';
    }
}
