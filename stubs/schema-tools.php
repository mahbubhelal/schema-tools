<?php

declare(strict_types=1);

/*
 * mahbubhelal/schema-tools — read only by the package, so it stays out of
 * `config/` and production. Every key is optional; see the package README.
 */
return [

    /*
     * Per fixture-backed connection:
     *   sources              tables and views `schema:detect` cannot see (raw SQL
     *                        outside the queries paths, seeds, tests)
     *   squashed_migrations  MySQL only: the migrations directory the fixture
     *                        already contains, marked as ran by `schema:dump`
     *   hand_maintained      true when the fixtures are written by hand; audited,
     *                        never dumped
     */
    'connections' => [
        // 'sugar' => [
        //     'sources' => ['bhea_orders_cstm'],
        // ],
    ],

    /*
     * Defaults: database/schema, app/Models, app/Queries, database/factories.
     * Each scan path may be a directory, a glob or a list of either.
     */
    // 'paths' => [
    //     'models' => [app_path('Models'), base_path('app-modules/*/src/Models')],
    // ],

    // 'attributes' (default), 'properties', or null to allow either.
    // 'declaration_style' => 'attributes',

];
