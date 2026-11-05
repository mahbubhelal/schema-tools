<?php

declare(strict_types=1);

use Mahbub\SchemaTools\Support\Glob;

it('returns the matches of a glob that ran', function (): void {
    expect(Glob::orFail(['/app/Models'], '/app/*'))->toBe(['/app/Models']);
})->group('need_review');

it('fails when a glob could not run', function (): void {
    expect(fn (): array => Glob::orFail(false, '/app/*'))
        ->toThrow(RuntimeException::class, 'Could not read the paths matching [/app/*].');
})->group('need_review');
