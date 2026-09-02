<?php

declare(strict_types=1);

use Mahbub\SchemaTools\Support\ConnectionDetection;
use Mahbub\SchemaTools\Support\DetectionResult;
use Mahbub\SchemaTools\Support\FactoryCheckResult;
use Mahbub\SchemaTools\Support\ModelAuditResult;
use Mahbub\SchemaTools\Support\Report;

it('reports a passing report as having no issues', function (): void {
    expect((new Report('App\\Models\\Center', 'tcb.Center', []))->passes())->toBeTrue()
        ->and((new Report('App\\Models\\Center', 'tcb.Center', ['pk mismatch']))->passes())->toBeFalse();
})->group('need_review');

it('detects stale manifest entries only when a connection has some', function (): void {
    $withStale = new DetectionResult(
        manifest: [],
        connections: [new ConnectionDetection('tcb', 3, [], ['Ghost'])],
    );

    $withoutStale = new DetectionResult(
        manifest: [],
        connections: [new ConnectionDetection('tcb', 3, ['New'], [])],
    );

    expect($withStale->hasStale())->toBeTrue()
        ->and($withoutStale->hasStale())->toBeFalse();
})->group('need_review');

it('sums model issues and passes only when clean', function (): void {
    $clean = new ModelAuditResult(
        models: [new Report('A', 'tcb.A', [])],
        manifestIssues: [],
        skipped: 0,
    );

    $dirtyModels = new ModelAuditResult(
        models: [new Report('A', 'tcb.A', ['one', 'two'])],
        manifestIssues: [],
        skipped: 0,
    );

    $dirtyManifest = new ModelAuditResult(
        models: [new Report('A', 'tcb.A', [])],
        manifestIssues: ['stale'],
        skipped: 0,
    );

    expect($clean->issueCount())->toBe(0)
        ->and($clean->passes())->toBeTrue()
        ->and($dirtyModels->issueCount())->toBe(2)
        ->and($dirtyModels->passes())->toBeFalse()
        ->and($dirtyManifest->issueCount())->toBe(0)
        ->and($dirtyManifest->passes())->toBeFalse();
})->group('need_review');

it('sums factory issues and passes only when clean', function (): void {
    $clean = new FactoryCheckResult(factories: [], checked: 2, skippedConnections: []);

    $dirty = new FactoryCheckResult(
        factories: [new Report('F', 'tcb.A', ['bad', 'worse'])],
        checked: 2,
        skippedConnections: [],
    );

    expect($clean->issueCount())->toBe(0)
        ->and($clean->passes())->toBeTrue()
        ->and($dirty->issueCount())->toBe(2)
        ->and($dirty->passes())->toBeFalse();
})->group('need_review');
