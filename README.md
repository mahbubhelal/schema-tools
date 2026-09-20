# Schema Tools

Laravel Artisan commands that keep a project's **schema fixtures**, its **Eloquent
models**, and its **model factories** in agreement with the live source databases
— SQL Server and MySQL/MariaDB.

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

Each scan path may be one directory, a glob pattern, or a list of either — so a
modular layout is one setting:

```php
'models_path' => [app_path('Models'), base_path('app-modules/*/src/Models')],
'queries_path' => base_path('app-modules/*/src/Queries'),
'factories_path' => [database_path('factories'), base_path('app-modules/*/database/factories')],
```

## The manifest — `source-tables.php`

The manifest is the single source of truth for *which* tables and views the
project relies on, per connection:

```php
return [
    'tcb' => ['Center', 'press', 'vPress'],
    'sugar' => ['contacts', 'contacts_cstm', 'bhea_orders_cstm'],
];
```

It is **seeded** by `schema:detect` and then **hand-curated** — edit it freely.
`schema:dump` and `schema:audit` read it; neither re-derives the list itself.

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

The manifest is treated as the source of truth: existing entries and their order
are preserved, newly detected names are appended (sorted), and an entry no longer
found in code is **reported but kept** — never deleted automatically. It exits
non-zero when a stale entry remains.

```bash
php artisan schema:detect            # writes the reconciled manifest
php artisan schema:detect --dry-run  # preview only, writes nothing
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

### `php artisan schema:audit`

Verifies models and factories against the fixtures, and cross-checks the manifest.
Exits non-zero when anything is out of sync.

For every concrete model on a fixture-backed connection it checks that the
`connection`, `table`, `primaryKey`, `keyType`, `incrementing` and `timestamps`
declarations agree with the DDL. A model may declare no key (`$primaryKey = null`)
when its table has no primary key. A model mapped onto a view from the views fixture
has nothing to key on and nothing to time-stamp, so it must declare
`$primaryKey = null`, `$incrementing = false` and `$timestamps = false`. For every
factory it checks that `definition()` contains **exactly** the columns an `INSERT`
would be rejected without — a missing required column, a nullable column that
belongs in a state, a column with a database default (or auto-increment), a value
whose PHP type does not fit the SQL type, or a key the DDL does not have are all
reported. Finally it confirms every manifest name exists in a fixture, and every
fixture object appears in the manifest.

```bash
php artisan schema:audit
```

## Testing

```bash
composer test       # pest
composer types      # phpstan (level max)
composer check      # rector --dry-run, pint --test, phpstan, pest --coverage --min=100
```
