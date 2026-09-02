<?php

declare(strict_types=1);

namespace Mahbub\SchemaTools\Support;

use Illuminate\Database\Eloquent\Model;
use ReflectionClass;

final readonly class ScannedModel
{
    /**
     * @param  ReflectionClass<Model>  $reflection
     */
    public function __construct(
        public string $class,
        public ReflectionClass $reflection,
        public Model $model,
        public string $contents,
    ) {}
}
