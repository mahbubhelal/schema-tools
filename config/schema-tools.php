<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Schema fixture directory
    |--------------------------------------------------------------------------
    |
    | The directory holding the committed T-SQL fixtures. A connection is
    | considered "fixture-backed" — the universe every command works within —
    | when it has a `<connection>-schema.sql` file here. Views live alongside
    | in `<connection>-views.sql`.
    |
    */

    'schema_path' => database_path('schema'),

    /*
    |--------------------------------------------------------------------------
    | Table manifest
    |--------------------------------------------------------------------------
    |
    | The curated list of source tables and views this project relies on, per
    | connection. It is seeded by `schema:detect` and then hand-curated;
    | `schema:dump` and `schema:audit` read it. Returns
    | array<string, list<string>>.
    |
    */

    'manifest_path' => database_path('schema/source-tables.php'),

    /*
    |--------------------------------------------------------------------------
    | Scan paths
    |--------------------------------------------------------------------------
    |
    | Where `schema:detect` looks for the tables/views the code depends on, and
    | where `schema:audit` finds the models and factories it verifies against
    | the fixtures.
    |
    */

    'models_path' => app_path('Models'),

    'queries_path' => app_path('Queries'),

    'factories_path' => database_path('factories'),

];
