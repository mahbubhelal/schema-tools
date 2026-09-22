<?php

declare(strict_types=1);

use Mahbub\SchemaTools\Support\ModelScanner;
use Mahbub\SchemaTools\Support\ScannedModel;
use Mahbub\SchemaTools\Tests\Fixtures\Scanner\RealModel;

it('returns an empty list for a directory that does not exist', function (): void {
    expect((new ModelScanner)->scan($this->workspace . '/does-not-exist'))->toBe([]);
})->group('need_review');

it('returns only concrete Eloquent models, skipping everything else', function (): void {
    $scanned = (new ModelScanner)->scan(__DIR__ . '/../Fixtures/Scanner');

    expect($scanned)->toHaveCount(1)
        ->and($scanned[0])->toBeInstanceOf(ScannedModel::class)
        ->and($scanned[0]->class)->toBe(RealModel::class)
        ->and($scanned[0]->model)->toBeInstanceOf(RealModel::class)
        ->and($scanned[0]->contents)->toContain('class RealModel');
})->group('need_review');
