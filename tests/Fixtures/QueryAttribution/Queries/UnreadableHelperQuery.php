<?php

declare(strict_types=1);

namespace Mahbub\SchemaTools\Tests\Fixtures\QueryAttribution\Queries;

use ArrayObject;
use Mahbub\SchemaTools\Tests\Fixtures\QueryAttribution\Support\MissingRunner;

final readonly class UnreadableHelperQuery
{
    public function __construct(private MissingRunner $missingRunner) {}

    /**
     * @return ArrayObject<int, object>
     */
    public function execute(): ArrayObject
    {
        return new ArrayObject($this->missingRunner->run('SELECT * FROM UnreadableFed'));
    }
}
