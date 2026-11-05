<?php

declare(strict_types=1);

use Mahbub\SchemaTools\Support\Regex;

it('passes on the result of a regular expression that ran', function (): void {
    expect(Regex::orFail(preg_match('/b/', 'abc')))->toBe(1)
        ->and(Regex::orFail(preg_replace('/b/', 'x', 'abc')))->toBe('axc');
})->group('need_review');

it('fails when the regular expression could not run', function (): void {
    // A subject the pattern rejects only at its last character makes it backtrack past the limit.
    $backtrackLimit = ini_set('pcre.backtrack_limit', '1');

    try {
        expect(fn (): int => Regex::orFail(preg_match('/^a[a-z]+$/', 'a' . str_repeat('b', 5000) . 'C')))
            ->toThrow(RuntimeException::class, 'Regular expression failed: Backtrack limit exhausted');
    } finally {
        ini_set('pcre.backtrack_limit', (string) $backtrackLimit);
    }
})->group('need_review');

it('lets a regular expression that did not match pass', function (): void {
    preg_match('/z/', 'abc');

    Regex::failOnError();

    expect(preg_last_error())->toBe(PREG_NO_ERROR);
})->group('need_review');

it('fails after a regular expression that could not run', function (): void {
    // A subject the pattern rejects only at its last character makes it backtrack past the limit.
    $backtrackLimit = ini_set('pcre.backtrack_limit', '1');

    try {
        preg_match('/^a[a-z]+$/', 'a' . str_repeat('b', 5000) . 'C');

        expect(Regex::failOnError(...))
            ->toThrow(RuntimeException::class, 'Regular expression failed: Backtrack limit exhausted');
    } finally {
        ini_set('pcre.backtrack_limit', (string) $backtrackLimit);
    }
})->group('need_review');
