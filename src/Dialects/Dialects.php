<?php

declare(strict_types=1);

namespace Mahbub\SchemaTools\Dialects;

use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Config;
use Mahbub\SchemaTools\Support\Driver;

/**
 * Picks the dialect a connection's configured driver calls for.
 */
final readonly class Dialects
{
    public function __construct(
        private Container $container,
    ) {}

    public function forConnection(string $connection): ?Dialect
    {
        $driver = Driver::fromConnectionDriver(Config::get("database.connections.{$connection}.driver"));

        if (!$driver instanceof Driver) {
            return null;
        }

        /** @var Dialect */
        $dialect = $this->container->make($driver->dialect());

        return $dialect;
    }
}
