<?php

declare(strict_types=1);

namespace Mahbub\SchemaTools\Support;

use Illuminate\Container\Container;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Foundation\Application;
use RuntimeException;

/**
 * The Laravel application the Rector rule reads its config and fixtures
 * through. Inside Artisan or a test it is the one already bound; inside a bare
 * Rector process it is booted from the `bootstrap/app.php` under the
 * working directory, the project root.
 */
final class ApplicationResolver
{
    private ?Application $application = null;

    public function resolve(): Application
    {
        if ($this->application instanceof Application) {
            return $this->application;
        }

        $bound = Container::getInstance();

        if ($bound instanceof Application && $bound->hasBeenBootstrapped()) {
            return $this->application = $bound;
        }

        $bootstrap = getcwd() . '/bootstrap/app.php';

        if (!is_file($bootstrap)) {
            throw new RuntimeException("Cannot boot Laravel: `{$bootstrap}` does not exist. Run Rector from the project root.");
        }

        $application = require $bootstrap;

        if (!$application instanceof Application) {
            throw new RuntimeException("`{$bootstrap}` did not return a Laravel application.");
        }

        $application->make(Kernel::class)->bootstrap();

        return $this->application = $application;
    }
}
