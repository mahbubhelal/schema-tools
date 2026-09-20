<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Schema fixture directory
    |--------------------------------------------------------------------------
    |
    | The directory holding the committed fixtures. A connection is considered
    | "fixture-backed" — the universe every command works within — when it has
    | a `<connection>-schema.sql` file here. Views live alongside in
    | `<connection>-views.sql`. Which dialect a fixture is written in follows
    | the connection's driver: T-SQL for `sqlsrv`, MySQL for `mysql` and
    | `mariadb`.
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
    | the fixtures. Each setting is a directory, a glob pattern (so a modular
    | layout such as `app-modules/STAR/src/Models` can be named at once), or a
    | list of either; the queries paths are scanned recursively.
    |
    */

    'models_path' => app_path('Models'),

    'queries_path' => app_path('Queries'),

    'factories_path' => database_path('factories'),

    /*
    |--------------------------------------------------------------------------
    | Squashed migrations (MySQL fixtures only)
    |--------------------------------------------------------------------------
    |
    | Laravel loads a MySQL fixture as a squashed schema, then runs the
    | migrations it has not recorded yet. Map a connection to the directory of
    | migrations the fixture already contains and `schema:dump` pre-fills the
    | fixture's `migrations` table with them, so they are marked as ran instead
    | of being replayed on top of the dumped tables:
    |
    |     'scoring' => base_path('app-modules/scoring/database/migrations'),
    |
    */

    'squashed_migrations' => [],

    /*
    |--------------------------------------------------------------------------
    | Hand-maintained fixtures
    |--------------------------------------------------------------------------
    |
    | Connections whose fixtures are written by hand rather than dumped from a
    | source database. `schema:audit` still checks models, factories and the
    | manifest against them; `schema:dump` leaves them untouched.
    |
    */

    'hand_maintained' => [],

];
