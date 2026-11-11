<?php

declare(strict_types=1);

namespace Mahbub\SchemaTools\Tests\Fixtures\QueryAttribution\Queries;

use Mahbub\SchemaTools\Tests\Fixtures\QueryAttribution\Support\FeedRunner;

final readonly class HelperQuery
{
    public function __construct(private FeedRunner $feedRunner) {}

    /**
     * @return list<object>
     */
    public function execute(): array
    {
        return $this->feedRunner->run(<<<'SQL'
            SELECT * FROM FeedItem fi
            JOIN #tempFeed tf ON tf.Id = fi.Id
            SQL);
    }
}
