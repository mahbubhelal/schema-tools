<?php

declare(strict_types=1);

namespace Mahbub\SchemaTools\Tests\Fixtures\QueryAttribution\Queries;

use Mahbub\SchemaTools\Tests\Fixtures\QueryAttribution\vendor\Acme\VendorRunner;

final readonly class VendorHelperQuery
{
    public function __construct(private VendorRunner $vendorRunner) {}

    /**
     * @return list<object>
     */
    public function execute(): array
    {
        return $this->vendorRunner->run('SELECT * FROM VendorFed');
    }
}
