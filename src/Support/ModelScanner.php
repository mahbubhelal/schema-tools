<?php

declare(strict_types=1);

namespace Mahbub\SchemaTools\Support;

use Illuminate\Database\Eloquent\Model;
use ReflectionClass;
use Symfony\Component\Finder\Finder;

/**
 * Discovers the concrete Eloquent models under a directory, reflecting and
 * instantiating each so its connection, table and key metadata can be read. A
 * file without a namespace, or one that declares no class of its own name, is
 * not a model; a model that fails to load or construct stops the scan with its
 * own error. Files are visited in name order so every report is deterministic.
 */
final class ModelScanner
{
    /**
     * @return list<ScannedModel>
     */
    public function scan(string $path): array
    {
        $models = [];

        foreach (Finder::create()->in($path)->files()->name('*.php')->sortByName() as $file) {
            $contents = $file->getContents();

            if (preg_match('/^namespace ([^;]+);/m', $contents, $namespaceMatch) !== 1) {
                Regex::failOnError();

                continue;
            }

            $class = $namespaceMatch[1] . '\\' . $file->getBasename('.php');

            if (!$this->isConcreteModel($class)) {
                continue;
            }

            $reflection = new ReflectionClass($class);

            $models[] = new ScannedModel($class, $reflection, $reflection->newInstance(), $contents);
        }

        return $models;
    }

    /**
     * @phpstan-assert-if-true class-string<Model> $class
     */
    private function isConcreteModel(string $class): bool
    {
        if (!class_exists($class)) {
            return false;
        }

        $reflection = new ReflectionClass($class);

        return !$reflection->isAbstract() && $reflection->isSubclassOf(Model::class);
    }
}
