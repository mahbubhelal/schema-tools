<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Config;
use Mahbub\SchemaTools\Actions\DetectSourceTables;
use Mahbub\SchemaTools\Support\DetectionResult;

use function Pest\Laravel\artisan;

function detectAttribution(): DetectionResult
{
    return resolve(DetectSourceTables::class)->handle();
}

/**
 * @return list<string>
 */
function generatedNames(DetectionResult $detectionResult, string $connection): array
{
    return $detectionResult->manifest->generated[$connection] ?? [];
}

beforeEach(function (): void {
    Config::set('schema-tools.paths.models', __DIR__ . '/../Fixtures/QueryAttribution/Models');
    Config::set('schema-tools.paths.queries', __DIR__ . '/../Fixtures/QueryAttribution/Queries');

    $this->workspaceFile('tcb-schema.sql', "CREATE TABLE [dbo].[press] (\n    [PressId] int NOT NULL\n);");
    $this->workspaceFile('tcbpermission-schema.sql', "CREATE TABLE [dbo].[Permission] (\n    [Id] int NOT NULL\n);");
    $this->manifestFile(generated: []);
});

it('attributes a query file\'s SQL to the connection of a model it runs it on', function (): void {
    expect(generatedNames(detectAttribution(), 'tcb'))->toContain('BannerLink');
})->group('need_review');

it('attributes SQL passed to a helper to the connection the helper names', function (): void {
    expect(generatedNames(detectAttribution(), 'tcb'))->toContain('FeedItem');
})->group('need_review');

it('attributes SQL passed to a helper to the connection of a model the helper uses', function (): void {
    expect(generatedNames(detectAttribution(), 'tcbpermission'))->toContain('GrantRule');
})->group('need_review');

it('keeps a query file\'s own connection over the connections of the helpers it uses', function (): void {
    $result = detectAttribution();

    expect(generatedNames($result, 'tcb'))->toContain('OwnTable')
        ->and(generatedNames($result, 'tcbpermission'))->not->toContain('OwnTable');
})->group('need_review');

it('routes the three-part names of a file without a connection to the connection owning their database', function (): void {
    expect(generatedNames(detectAttribution(), 'tcbpermission'))->toContain('MasterProduct');
})->group('need_review');

it('does not look into helpers under a vendor directory', function (): void {
    $result = detectAttribution();

    expect(generatedNames($result, 'tcb'))->not->toContain('VendorFed')
        ->and($result->unattributedQueries)->toHaveKey(realpath(__DIR__ . '/../Fixtures/QueryAttribution/Queries/VendorHelperQuery.php'));
})->group('need_review');

it('does not look into helpers it cannot read: missing or built into PHP', function (): void {
    expect(detectAttribution()->unattributedQueries)->toHaveKey(realpath(__DIR__ . '/../Fixtures/QueryAttribution/Queries/UnreadableHelperQuery.php'));
})->group('need_review');

it('reports the query files whose one- and two-part names it cannot attribute', function (): void {
    $queries = realpath(__DIR__ . '/../Fixtures/QueryAttribution/Queries');

    expect(detectAttribution()->unattributedQueries)->toBe([
        "{$queries}/UnattributedQuery.php" => ['SomethingElse', 'Other'],
        "{$queries}/UnreadableHelperQuery.php" => ['UnreadableFed'],
        "{$queries}/VendorHelperQuery.php" => ['VendorFed'],
    ]);
})->group('need_review');

it('detects exactly the attributable names', function (): void {
    $result = detectAttribution();

    expect(generatedNames($result, 'tcb'))->toBe(['BannerLink', 'FeedItem', 'OwnTable', 'press'])
        ->and(generatedNames($result, 'tcbpermission'))->toBe(['GrantRule', 'MasterProduct', 'Permission']);
})->group('need_review');

it('lists the unattributed query files and fails --dry-run on them', function (): void {
    artisan('schema:detect --dry-run')
        ->expectsOutputToContain('UnattributedQuery.php: SomethingElse, Other')
        ->expectsOutputToContain('Some query files name tables without a connection')
        ->assertExitCode(1);
})->group('need_review');

it('writes the manifest but still lists the unattributed query files', function (): void {
    artisan('schema:detect')
        ->expectsOutputToContain('UnattributedQuery.php: SomethingElse, Other')
        ->expectsOutputToContain('Wrote')
        ->assertExitCode(0);
})->group('need_review');
