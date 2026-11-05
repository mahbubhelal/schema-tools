<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Illuminate\Contracts\Foundation\Application;
use Mahbub\SchemaTools\Support\ApplicationResolver;

/**
 * Run the callback with no Laravel application bound and the given directory
 * as the working directory, restoring both afterwards.
 */
function withoutBoundApplication(Closure $callback, string $workingDirectory): void
{
    $application = Container::getInstance();
    $previousDirectory = (string) getcwd();
    Container::setInstance(new Container);
    chdir($workingDirectory);

    try {
        $callback();
    } finally {
        Container::setInstance($application);
        chdir($previousDirectory);
    }
}

it('returns the application already bound and bootstrapped', function (): void {
    $resolver = new ApplicationResolver;

    expect($resolver->resolve())->toBe($this->app)
        ->and($resolver->resolve())->toBe($this->app);
})->group('need_review');

it('boots the application the bootstrap file under the working directory returns when none is bound', function (): void {
    $GLOBALS['schemaToolsTestApplication'] = $this->app;
    $this->workspaceFile('bootstrap/app.php', '<?php return $GLOBALS[\'schemaToolsTestApplication\'];');

    withoutBoundApplication(function (): void {
        expect((new ApplicationResolver)->resolve())->toBe($this->app);
    }, $this->workspace);
})->group('need_review');

it('refuses a bootstrap file that does not return an application', function (): void {
    $bootstrap = $this->workspaceFile('bootstrap/app.php', '<?php return 1;');

    withoutBoundApplication(function () use ($bootstrap): void {
        expect(fn (): Application => (new ApplicationResolver)->resolve())
            ->toThrow(RuntimeException::class, "`{$bootstrap}` did not return a Laravel application.");
    }, $this->workspace);
})->group('need_review');

it('refuses a bootstrap file that does not exist', function (): void {
    withoutBoundApplication(function (): void {
        expect(fn (): Application => (new ApplicationResolver)->resolve())
            ->toThrow(RuntimeException::class, 'Cannot boot Laravel: `' . $this->workspace . '/bootstrap/app.php` does not exist. Run Rector from the project root.');
    }, $this->workspace);
})->group('need_review');
