# Schema Tools

Laravel Artisan commands that keep a project's SQL Server **schema fixtures**, its
**Eloquent models**, and its **model factories** in agreement with the live source
databases.

It is built for projects that commit a `database/schema/<connection>-schema.sql`
(and optional `<connection>-views.sql`) fixture per non-default connection —
reconstructed from a real SQL Server — and want those fixtures, the models mapped
onto them, and the factories that insert into them to stay honest as the schema
drifts.

Everything operates on the **fixture-backed connections**: the ones that ship a
`<connection>-schema.sql` file in the configured schema directory.

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
| `queries_path` | `app_path('Queries')` | Where `schema:detect` scans raw SQL for table references. |
| `factories_path` | `database_path('factories')` | Where `schema:audit` finds factories. |

## The manifest — `source-tables.php`

The manifest is the single source of truth for *which* tables and views the
project relies on, per connection:

```php
return [
    'tcb' => ['Center', 'press', 'vPress'],
    'tcbpermission' => ['MasterProduct'],
];
```

It is **seeded** by `schema:detect` and then **hand-curated** — edit it freely.
`schema:dump` and `schema:audit` read it; neither re-derives the list itself.

## Commands

### `php artisan schema:detect`

Scans `models_path`, `queries_path`, and the committed `*-views.sql` for the
tables and views the code depends on, and reconciles them into the manifest.

Tables are discovered from three places:

- every concrete Eloquent model on a fixture-backed connection (its own table,
  plus any relationship pivot declared with a `table:` named argument);
- every table/view referenced by the raw SQL inside `queries_path` — a three-part
  `Database.dbo.Name` reference is routed to the connection that owns that
  database; a one/two-part name is attributed to the connection(s) the query file
  talks to via `DB::connection('...')`;
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

Rebuilds the T-SQL fixtures from the live SQL Server the fixture-backed
connections point at, using the names in the manifest. Base tables are
reconstructed from `sys.columns` / `sys.identity_columns` /
`sys.default_constraints` / `sys.key_constraints`; views are pulled verbatim from
`sys.sql_modules`. The existing file's object order is preserved so the git diff
stays readable.

The source database is chosen with Laravel's standard `--env` option:

```bash
php artisan schema:dump                     # reads from .env
php artisan schema:dump --dry-run           # preview the diff, writes nothing
php artisan schema:dump --env=staging       # reads from .env.staging
```

### `php artisan schema:audit`

Verifies models and factories against the fixtures, and cross-checks the manifest.
Exits non-zero when anything is out of sync.

For every concrete model on a fixture-backed connection it checks that the
`connection`, `table`, `primaryKey`, `keyType`, `incrementing` and `timestamps`
declarations agree with the DDL. For every factory it checks that `definition()`
contains **exactly** the columns an `INSERT` would be rejected without — a missing
required column, a nullable column that belongs in a state, a column with a
database default, a value whose PHP type does not fit the SQL type, or a key the
DDL does not have are all reported. Finally it confirms every manifest name exists
in a fixture, and every fixture object appears in the manifest.

```bash
php artisan schema:audit
```

## Testing

```bash
composer test       # pest
composer types      # phpstan (level max)
composer check      # rector --dry-run, pint --test, phpstan, pest --coverage --min=100
```
