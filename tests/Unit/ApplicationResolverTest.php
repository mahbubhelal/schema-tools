<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Mahbub\SchemaTools\Support\ApplicationResolver;

/**
 * Run the callback with no Laravel application bound and the bootstrap file
 * pointed at the given path, restoring both afterwards.
 */
function withoutBoundApplication(Closure $callback, ?string $bootstrap): void
{
    $application = Container::getInstance();
    Container::setInstance(new Container);
    putenv($bootstrap === null ? 'SCHEMA_TOOLS_BOOTSTRAP' : "SCHEMA_TOOLS_BOOTSTRAP={$bootstrap}");

    try {
        $callback();
    } finally {
        Container::setInstance($application);
        putenv('SCHEMA_TOOLS_BOOTSTRAP');
    }
}

it('returns the application already bound and bootstrapped', function (): void {
    $resolver = new ApplicationResolver;

    expect($resolver->resolve())->toBe($this->app)
        ->and($resolver->resolve())->toBe($this->app);
})->group('need_review');

it('boots the application the bootstrap file returns when none is bound', function (): void {
    $GLOBALS['schemaToolsTestApplication'] = $this->app;
    $bootstrap = $this->workspaceFile('bootstrap/app.php', '<?php return $GLOBALS[\'schemaToolsTestApplication\'];');

    withoutBoundApplication(function (): void {
        expect((new ApplicationResolver)->resolve())->toBe($this->app);
    }, $bootstrap);
})->group('need_review');

it('refuses a bootstrap file that does not return an application', function (): void {
    $bootstrap = $this->workspaceFile('bootstrap/app.php', '<?php return 1;');

    withoutBoundApplication(function () use ($bootstrap): void {
        expect(fn (): Illuminate\Contracts\Foundation\Application => (new ApplicationResolver)->resolve())
            ->toThrow(RuntimeException::class, "`{$bootstrap}` did not return a Laravel application.");
    }, $bootstrap);
})->group('need_review');

it('refuses a bootstrap file that does not exist', function (): void {
    withoutBoundApplication(function (): void {
        expect(fn (): Illuminate\Contracts\Foundation\Application => (new ApplicationResolver)->resolve())
            ->toThrow(RuntimeException::class, 'Cannot boot Laravel: `' . getcwd() . '/bootstrap/app.php` does not exist.');
    }, null);
})->group('need_review');
