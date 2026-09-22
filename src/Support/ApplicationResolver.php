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
 * Rector process it is booted from the project's `bootstrap/app.php` — the
 * file `SCHEMA_TOOLS_BOOTSTRAP` names, else the one under the working
 * directory.
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

        $bootstrap = getenv('SCHEMA_TOOLS_BOOTSTRAP');
        $bootstrap = is_string($bootstrap) && $bootstrap !== '' ? $bootstrap : getcwd() . '/bootstrap/app.php';

        if (!is_file($bootstrap)) {
            throw new RuntimeException("Cannot boot Laravel: `{$bootstrap}` does not exist. Run Rector from the project root or set SCHEMA_TOOLS_BOOTSTRAP.");
        }

        $application = require $bootstrap;

        if (!$application instanceof Application) {
            throw new RuntimeException("`{$bootstrap}` did not return a Laravel application.");
        }

        $application->make(Kernel::class)->bootstrap();

        return $this->application = $application;
    }
}
