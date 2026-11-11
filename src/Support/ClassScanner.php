<?php

declare(strict_types=1);

namespace Mahbub\SchemaTools\Support;

use ReflectionClass;
use Symfony\Component\Finder\Finder;

/**
 * Discovers the concrete subclasses of a base class declared under some
 * directories, one class per file. A file without a namespace, or one that
 * declares no class of its own name, declares none; a class that fails to load
 * stops the scan with its own error. Files are visited in name order so every
 * report is deterministic.
 */
final class ClassScanner
{
    /**
     * @template TParent of object
     *
     * @param  string|list<string>  $directories
     * @param  class-string<TParent>  $parent
     *
     * @return array<class-string<TParent>, string> Class => the source of the file declaring it.
     */
    public function concreteSubclasses(string|array $directories, string $parent): array
    {
        $classes = [];

        foreach (Finder::create()->in($directories)->files()->name('*.php')->sortByName() as $file) {
            $contents = $file->getContents();

            if (preg_match('/^namespace ([^;]+);/m', $contents, $namespaceMatch) !== 1) {
                Regex::failOnError();

                continue;
            }

            $class = $namespaceMatch[1] . '\\' . $file->getBasename('.php');

            if ($this->isConcreteSubclass($class, $parent)) {
                $classes[$class] = $contents;
            }
        }

        return $classes;
    }

    /**
     * @template TParent of object
     *
     * @param  class-string<TParent>  $parent
     *
     * @phpstan-assert-if-true class-string<TParent> $class
     */
    private function isConcreteSubclass(string $class, string $parent): bool
    {
        if (!class_exists($class)) {
            return false;
        }

        $reflection = new ReflectionClass($class);

        return !$reflection->isAbstract() && $reflection->isSubclassOf($parent);
    }
}
