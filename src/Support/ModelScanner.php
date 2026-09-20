<?php

declare(strict_types=1);

namespace Mahbub\SchemaTools\Support;

use Illuminate\Database\Eloquent\Model;
use ReflectionClass;
use Symfony\Component\Finder\Finder;
use Throwable;

/**
 * Discovers the concrete Eloquent models under a directory, reflecting and
 * instantiating each so its connection, table and key metadata can be read. A
 * class that cannot be autoloaded, reflected or instantiated is skipped. Files
 * are visited in name order so every report is deterministic.
 */
final class ModelScanner
{
    /**
     * @return list<ScannedModel>
     */
    public function scan(string $path): array
    {
        if (!is_dir($path)) {
            return [];
        }

        $models = [];

        foreach (Finder::create()->in($path)->files()->name('*.php')->sortByName() as $file) {
            $contents = $file->getContents();

            if (preg_match('/^namespace ([^;]+);/m', $contents, $namespaceMatch) !== 1) {
                continue;
            }

            $class = $namespaceMatch[1] . '\\' . $file->getBasename('.php');

            try {
                if (!class_exists($class)) {
                    continue;
                }

                $reflection = new ReflectionClass($class);

                if ($reflection->isAbstract() || !$reflection->isSubclassOf(Model::class)) {
                    continue;
                }

                $model = $reflection->newInstance();
            } catch (Throwable) {
                continue;
            }

            /** @var ReflectionClass<Model> $reflection */
            /** @var Model $model */
            $models[] = new ScannedModel($class, $reflection, $model, $contents);
        }

        return $models;
    }
}
