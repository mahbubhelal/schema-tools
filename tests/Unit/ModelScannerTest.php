<?php

declare(strict_types=1);

use Mahbub\SchemaTools\Support\ModelScanner;
use Mahbub\SchemaTools\Tests\Fixtures\Scanner\RealModel;

it('returns only concrete Eloquent models, skipping everything else', function (): void {
    $scanned = (new ModelScanner)->scan(__DIR__ . '/../Fixtures/Scanner');

    expect($scanned)->toHaveCount(1)
        ->{0}->scoped(fn ($scannedModel) => $scannedModel
        ->class->toBe(RealModel::class)
        ->model->toBeInstanceOf(RealModel::class)
        ->contents->toContain('class RealModel'));
})->group('need_review');

it('fails on a model that cannot be instantiated', function (): void {
    expect(fn (): array => (new ModelScanner)->scan(__DIR__ . '/../Fixtures/ScannerExploding'))
        ->toThrow(RuntimeException::class, 'cannot instantiate');
})->group('need_review');
