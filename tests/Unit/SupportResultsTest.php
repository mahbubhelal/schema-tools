<?php

declare(strict_types=1);

use Mahbub\SchemaTools\Support\ConnectionDetection;
use Mahbub\SchemaTools\Support\DetectionResult;
use Mahbub\SchemaTools\Support\FactoryCheckResult;
use Mahbub\SchemaTools\Support\ManifestData;
use Mahbub\SchemaTools\Support\ModelAuditResult;
use Mahbub\SchemaTools\Support\Report;

it('reports a passing report as having no issues', function (): void {
    expect((new Report('App\\Models\\Center', 'tcb.Center', []))->passes())->toBeTrue()
        ->and((new Report('App\\Models\\Center', 'tcb.Center', ['pk mismatch']))->passes())->toBeFalse();
})->group('need_review');

it('reports changes when a name was added or removed or a connection was dropped', function (): void {
    $manifest = new ManifestData(manual: [], generated: []);

    $added = new DetectionResult($manifest, [new ConnectionDetection('tcb', 3, 0, ['New'], [], [])], []);
    $removed = new DetectionResult($manifest, [new ConnectionDetection('tcb', 3, 0, [], ['Ghost'], [])], []);
    $dropped = new DetectionResult($manifest, [], ['gone']);
    $inSync = new DetectionResult($manifest, [new ConnectionDetection('tcb', 3, 1, [], [], ['Center'])], []);

    expect($added->hasChanges())->toBeTrue()
        ->and($removed->hasChanges())->toBeTrue()
        ->and($dropped->hasChanges())->toBeTrue()
        ->and($inSync->hasChanges())->toBeFalse();
})->group('need_review');

it('sums model issues and passes only when clean', function (): void {
    $clean = new ModelAuditResult(
        models: [new Report('A', 'tcb.A', [])],
        manifestIssues: [],
        skipped: [new Report('S', 'other.S', [])],
    );

    $dirtyModels = new ModelAuditResult(
        models: [new Report('A', 'tcb.A', ['one', 'two'])],
        manifestIssues: [],
        skipped: [],
    );

    $dirtyManifest = new ModelAuditResult(
        models: [new Report('A', 'tcb.A', [])],
        manifestIssues: ['stale'],
        skipped: [],
    );

    expect($clean->issueCount())->toBe(0)
        ->and($clean->passes())->toBeTrue()
        ->and($dirtyModels->issueCount())->toBe(2)
        ->and($dirtyModels->passes())->toBeFalse()
        ->and($dirtyManifest->issueCount())->toBe(0)
        ->and($dirtyManifest->passes())->toBeFalse();
})->group('need_review');

it('sums factory issues and passes only when clean', function (): void {
    $clean = new FactoryCheckResult(factories: [new Report('F', 'tcb.A', [])], skipped: [new Report('G', 'other.B', [])]);

    $dirty = new FactoryCheckResult(
        factories: [new Report('F', 'tcb.A', ['bad', 'worse'])],
        skipped: [],
    );

    expect($clean->issueCount())->toBe(0)
        ->and($clean->passes())->toBeTrue()
        ->and($dirty->issueCount())->toBe(2)
        ->and($dirty->passes())->toBeFalse();
})->group('need_review');
