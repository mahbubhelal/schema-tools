# Schema Tools

Laravel Artisan commands and a Rector rule that keep a project's **schema
fixtures**, its **Eloquent models**, and its **model factories** in agreement with
the live source databases — SQL Server and MySQL/MariaDB.

It is built for projects that commit a `database/schema/<connection>-schema.sql`
(and optional `<connection>-views.sql`) fixture per non-default connection —
rebuilt from a real database — and want those fixtures, the models mapped onto
them, and the factories that insert into them to stay honest as the schema drifts.

Everything operates on the **fixture-backed connections**: the ones that ship a
`<connection>-schema.sql` file in the configured schema directory. The dialect a
fixture is written in follows the connection's driver: T-SQL for `sqlsrv`, MySQL
for `mysql` and `mariadb`.

## Installation

```bash
composer require --dev mahbubhelal/schema-tools
```

Requires PHP 8.3, Laravel 13 (the Eloquent class attributes the audit understands
arrived there) and Rector 2, which the package pulls in for `schema:audit --fix`.
The service provider is auto-discovered. Publish the config if you need to change
any of the default paths:

```bash
php artisan vendor:publish --tag=schema-tools-config
```

## Configuration

`config/schema-tools.php` (all paths have sensible Laravel defaults):

| Key | Default | What it points at |
| --- | --- | --- |
| `schema_path` | `database_path('schema')` | The committed `*-schema.sql` / `*-views.sql` fixtures. A connection is "fixture-backed" when it has a `<connection>-schema.sql` here. |
| `manifest_path` | `database_path('schema/source-tables.php')` | The curated table/view manifest. |
| `models_path` | `app_path('Models')` | Where `schema:detect` and `schema:audit` find models. |
| `queries_path` | `app_path('Queries')` | Where `schema:detect` scans raw SQL for table references, recursively. |
| `factories_path` | `database_path('factories')` | Where `schema:audit` finds factories. |
| `squashed_migrations` | `[]` | MySQL only: connection => migrations directory the fixture already contains (see `schema:dump`). |
| `hand_maintained` | `[]` | Connections whose fixtures are written by hand: audited like any other, never dumped. |
| `declaration_style` | `null` | `attributes` or `properties` to hold every model to one style; `null` allows either (never mixed within a model). |
| `rector_binary` | `base_path('vendor/bin/rector')` | The Rector executable `schema:audit --fix` runs. |

Each scan path may be one directory, a glob pattern, or a list of either — so a
modular layout is one setting:

```php
'models_path' => [app_path('Models'), base_path('app-modules/*/src/Models')],
'queries_path' => base_path('app-modules/*/src/Queries'),
'factories_path' => [database_path('factories'), base_path('app-modules/*/database/factories')],
```

## The manifest — `source-tables.php`

The manifest is the single source of truth for *which* tables and views the
project relies on, per connection. It has two sections:

```php
return [
    'manual' => [
        // raw SQL in the nightly report job
        'sugar' => ['bhea_orders_cstm'],
    ],
    'generated' => [
        'tcb' => ['Center', 'press', 'vPress'],
        'sugar' => ['contacts', 'contacts_cstm'],
    ],
];
```

`manual` is yours: list there what `schema:detect` cannot see, and it is kept
exactly as written, comments included — no command ever touches it. `generated`
belongs to `schema:detect`, which rebuilds it on every run; do not edit it by hand.
`schema:dump` and `schema:audit` read both sections together (a name in both
counts once). A flat legacy manifest (connection => names, no sections) is read
as `generated` and takes the two-section shape the next time `schema:detect`
writes — move any hand-added names under `manual` before that run, or they are
dropped as unreferenced.

## Commands

### `php artisan schema:detect`

Scans the models paths, the queries paths, and the committed `*-views.sql` for the
tables and views the code depends on, and reconciles them into the manifest.

Tables are discovered from three places:

- every concrete Eloquent model on a fixture-backed connection (its own table,
  plus any relationship pivot declared with a `table:` named argument);
- every table/view referenced by the raw SQL inside the queries paths — a
  three-part `Database.dbo.Name` reference is routed to the connection that owns
  that database; a one/two-part name is attributed to the connection(s) the query
  file talks to via `DB::connection('...')` or a `$connection = '...'` property.
  Comments, temp tables (`#name`) and the names a `WITH name AS (...)` clause
  defines are not tables and are ignored;
- every base table a committed `<connection>-views.sql` joins, so tables used only
  inside a view still get pulled.

Only the `generated` section is rebuilt: names still detected keep their order,
newly detected names are appended (sorted), names no longer referenced are
removed, and a connection without a fixture loses its section. The `manual`
section is left alone; a manual name the detector finds anyway is flagged as
redundant. When the file already has a generated block, only that block is
replaced, so the manual section survives byte for byte.

```bash
php artisan schema:detect            # rebuilds the generated section and writes the manifest
php artisan schema:detect --dry-run  # preview only; exits non-zero when generated is out of date
```

### `php artisan schema:dump`

Rebuilds the fixtures from the live database each fixture-backed connection points
at, using the names in the manifest. Each name is resolved against the source
catalog and rebuilt by the connection's dialect; tables go to
`<connection>-schema.sql`, views to `<connection>-views.sql`. The existing file's
object order is preserved so the git diff stays readable.

- **SQL Server**: base tables are reconstructed from `sys.columns` /
  `sys.identity_columns` / `sys.default_constraints` / `sys.key_constraints`;
  views are pulled verbatim from `sys.sql_modules`.
- **MySQL / MariaDB**: tables and views are copied verbatim from
  `SHOW CREATE TABLE` / `SHOW CREATE VIEW`. `AUTO_INCREMENT` counters are
  stripped as noise and a view loses its `DEFINER` clause, so whichever user
  loads the fixture can create it. The schema fixture is shaped for Laravel's
  squashed-schema loading: it clears the session `sql_mode`, disables
  foreign-key checks, and ends with a `migrations` table. Map a connection in
  `squashed_migrations` to the directory of migrations the fixture already
  contains and that table is pre-filled with them, so `migrate` marks them as
  ran instead of replaying them on top of the dumped tables.

Connections listed in `hand_maintained` are skipped. The source database is
chosen with Laravel's standard `--env` option, and `--connection` restricts a run:

```bash
php artisan schema:dump                                 # reads from .env
php artisan schema:dump --dry-run                       # preview the diff, writes nothing
php artisan schema:dump --env=staging                   # reads from .env.staging
php artisan schema:dump --env=staging --connection=sugar # one connection only
```

### `php artisan schema:audit [--fix]`

Verifies models and factories against the fixtures, and cross-checks the manifest.
Exits non-zero when anything is out of sync.

For every concrete model on a fixture-backed connection it checks that the
`connection`, `table`, `primaryKey`, `keyType`, `incrementing` and `timestamps`
declarations agree with the DDL. A declaration may be a property or one of
Eloquent's class attributes (`#[Connection]`, `#[Table]`, `#[WithoutIncrementing]`,
`#[WithoutTimestamps]`), on the model itself, on one of its traits or on an
ancestor. A model may declare no key (`$primaryKey = null`) when its table has no
primary key. A model mapped onto a view from the views fixture has nothing to key
on and nothing to time-stamp, so it must declare no key, `$incrementing = false`
and `$timestamps = false` (or the matching attributes).

It also holds each model's own declarations to a form:

- **One style per model.** A model declares with attributes or with properties,
  never both. `$primaryKey = null` is the one property an attribute-styled model
  may keep, since no attribute can say "no key". A `#[Table]` that carries none
  of the audited arguments (say only `dateFormat`) counts for neither style.
  Only concrete models are held to this: an abstract base may mix, because a
  property it declares (`$keyType = 'string'`) reaches every child, while a
  `#[Table]` it declares is hidden by any child's own `#[Table]` — Eloquent
  takes the first `#[Table]` it finds and never merges them.
- **The configured style**, when `declaration_style` fixes one for the project.
- **Canonical order.** Attributes: `#[Connection]`, `#[Table]`,
  `#[WithoutIncrementing]`, `#[WithoutTimestamps]` (other attributes may sit
  anywhere between). Properties: `$connection`, `$table`, `$primaryKey`,
  `$keyType`, `$incrementing`, `$timestamps` — compared within each visibility
  group, so a class-element sorter that puts public before protected never
  fights the audit.

For every factory it checks that `definition()` contains **exactly** the columns
an `INSERT` would be rejected without — a missing required column, a nullable
column that belongs in a state, a column with a database default (or
auto-increment), a value whose PHP type does not fit the SQL type, or a key the
DDL does not have are all reported. Finally it confirms every manifest name
exists in a fixture, and every fixture object appears in the manifest. The report
lists every model and factory by name — `OK`, `FAIL` with its issues, or `SKIP`
for one whose connection has no fixture — so nothing goes unmentioned.

```bash
php artisan schema:audit
php artisan schema:audit --fix   # rewrite the models first, then audit
```

`--fix` runs the project's Rector binary over the model paths with the package's
own config (`rector-fix.php`), which registers nothing but
`DeclareModelSchemaRector`, then audits what is left. Rector's cache is cleared
each run because the rule's outcome depends on the fixtures, which the cache does
not watch. Run your formatter afterwards — Rector does not restore the blank
lines between rewritten properties.

### `DeclareModelSchemaRector`

The Rector rule behind `--fix`, usable on its own from the project's `rector.php`
(`->withRules([DeclareModelSchemaRector::class])`). For every Eloquent model it
rewrites the class's own declarations so the audit passes:

- values the DDL disagrees with are redeclared (`primaryKey`, `keyType`,
  `incrementing`, `timestamps`; a view-backed model gets no key, no incrementing,
  no timestamps);
- a connection or a disabled timestamps switch declared nowhere in the hierarchy
  is declared;
- everything is expressed in one style — the configured `declaration_style`,
  else the style the class already leans to (attributes win a tie) — in canonical
  order.

Declarations that need no change keep their nodes, docblocks and `#[\Override]`
included; other attributes and statements keep their places; a model that already
passes is left untouched. A model on a connection without a fixture is only
brought into form; an abstract model is only reordered, its mixed style kept. A
`#[Table]` the rule adds carries over the arguments of any `#[Table]` the model
inherits, since its own would shadow them. A composite primary key and a table missing from the fixture are
reported by the audit but never rewritten.

The rule reads the fixtures and `config/schema-tools.php` through the project's
Laravel application: inside Artisan it is the running one, inside a bare Rector
process it is booted from `bootstrap/app.php` under the working directory (or the
file `SCHEMA_TOOLS_BOOTSTRAP` names). Names the rule introduces are written fully
qualified; `rector-fix.php` imports them, and so does a formatter with
`fully_qualified_strict_types` + `import_symbols` when the rule runs from your own
`rector.php`.

## Testing

```bash
composer test       # pest
composer types      # phpstan (level max)
composer check      # rector --dry-run, pint --test, phpstan, pest --coverage --min=100
```
