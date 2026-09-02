<?php

declare(strict_types=1);

use Mahbub\SchemaTools\Support\FixtureConnections;

it('derives the schema and views paths from the configured directory', function (): void {
    $connections = new FixtureConnections;

    expect($connections)
        ->schemaPath()->toBe($this->workspace)
        ->schemaFile('tcb')->toBe($this->workspace . '/tcb-schema.sql')
        ->viewsFile('tcb')->toBe($this->workspace . '/tcb-views.sql');
})->group('need_review');

it('discovers no connections when the schema directory is empty', function (): void {
    expect((new FixtureConnections)->all())->toBe([]);
})->group('need_review');

it('discovers a connection for every schema fixture file', function (): void {
    $this->workspaceFile('tcb-schema.sql', '');
    $this->workspaceFile('tcbpermission-schema.sql', '');

    expect((new FixtureConnections)->all())->toEqualCanonicalizing(['tcb', 'tcbpermission']);
})->group('need_review');

it('maps each source database name back to its connection', function (): void {
    $this->workspaceFile('tcb-schema.sql', '');
    $this->workspaceFile('tcbpermission-schema.sql', '');

    expect((new FixtureConnections)->databaseMap())->toEqualCanonicalizing([
        'tcb' => 'tcb',
        'tcbpermission' => 'tcbpermission',
    ]);
})->group('need_review');
