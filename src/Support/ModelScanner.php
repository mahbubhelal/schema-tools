<?php

declare(strict_types=1);

namespace Mahbub\SchemaTools\Support;

use Illuminate\Database\Eloquent\Model;
use ReflectionClass;

/**
 * Discovers the concrete Eloquent models under a directory (see ClassScanner),
 * reflecting and instantiating each so its connection, table and key metadata
 * can be read. A model that fails to construct stops the scan with its own
 * error.
 */
final readonly class ModelScanner
{
    public function __construct(
        private ClassScanner $classScanner,
    ) {}

    /**
     * @return list<ScannedModel>
     */
    public function scan(string $path): array
    {
        $models = [];

        foreach ($this->classScanner->concreteSubclasses($path, Model::class) as $class => $contents) {
            $reflection = new ReflectionClass($class);

            $models[] = new ScannedModel($class, $reflection, $reflection->newInstance(), $contents);
        }

        return $models;
    }
}
