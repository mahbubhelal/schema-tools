<?php

declare(strict_types=1);

namespace Mahbub\SchemaTools\Tests\Fixtures\Detect\Queries;

final class NoConnectionQuery
{
    public function sql(): string
    {
        return 'SELECT * FROM SomethingElse';
    }
}
