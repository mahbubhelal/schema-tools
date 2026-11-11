<?php

declare(strict_types=1);

namespace Mahbub\SchemaTools\Tests\Fixtures\QueryAttribution\Queries;

use Mahbub\SchemaTools\Tests\Fixtures\QueryAttribution\Support\GrantRunner;

final readonly class HelperModelQuery
{
    public function __construct(private GrantRunner $grantRunner) {}

    /**
     * @return list<object>
     */
    public function execute(): array
    {
        return $this->grantRunner->run('SELECT * FROM GrantRule');
    }
}
