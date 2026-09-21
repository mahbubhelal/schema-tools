<?php

declare(strict_types=1);

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Config;

const CONTACTS_CREATE_TABLE = <<<'SQL'
    CREATE TABLE `contacts` (
      `id` char(36) NOT NULL,
      `seq` int NOT NULL AUTO_INCREMENT,
      PRIMARY KEY (`id`),
      UNIQUE KEY `seq` (`seq`)
    ) ENGINE=InnoDB AUTO_INCREMENT=4242 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    SQL;

const MYSQL_FIXTURE_HEAD = <<<'SQL'
    SET sql_mode = '';
    SET FOREIGN_KEY_CHECKS=0;

    DROP TABLE IF EXISTS `contacts`;

    CREATE TABLE `contacts` (
      `id` char(36) NOT NULL,
      `seq` int NOT NULL AUTO_INCREMENT,
      PRIMARY KEY (`id`),
      UNIQUE KEY `seq` (`seq`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

    DROP TABLE IF EXISTS `migrations`;

    CREATE TABLE `migrations` (
      `id` int unsigned NOT NULL AUTO_INCREMENT,
      `migration` varchar(255) NOT NULL,
      `batch` int NOT NULL,
      PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    SQL;

beforeEach(function (): void {
    $this->connection = Mockery::mock(Connection::class);
});

it('copies a MySQL table and view verbatim, minus the auto-increment counter and the view definer', function (): void {
    $this->workspaceFile('sugar-schema.sql', '');
    $this->manifestFile(['sugar' => ['contacts', 'missing', 'perf_thing', 'vcontacts']]);

    mysqlResolvesTo($this->connection, 'contacts', (object) ['name' => 'contacts', 'type' => 'BASE TABLE']);
    mysqlResolvesTo($this->connection, 'missing', null);
    mysqlResolvesTo($this->connection, 'perf_thing', (object) ['name' => 'perf_thing', 'type' => 'SYSTEM VIEW']);
    mysqlResolvesTo($this->connection, 'vcontacts', (object) ['name' => 'vcontacts', 'type' => 'VIEW']);
    createTable($this->connection, 'contacts', CONTACTS_CREATE_TABLE);
    createView($this->connection, 'vcontacts', 'CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`%.%.%.%` SQL SECURITY DEFINER VIEW `vcontacts` AS select `c`.`id` AS `id` from `contacts` `c`');

    $dump = dumpActionFor($this->connection, 'sugar')->handle()->connections[0];

    expect($dump)
        ->schemaContent->toBe(MYSQL_FIXTURE_HEAD . "\n\nSET FOREIGN_KEY_CHECKS=1;\n")
        ->viewsContent->toBe("DROP VIEW IF EXISTS `vcontacts`;\n\nCREATE ALGORITHM=UNDEFINED SQL SECURITY DEFINER VIEW `vcontacts` AS select `c`.`id` AS `id` from `contacts` `c`;\n")
        ->tableCount->toBe(1)
        ->viewCount->toBe(1)
        ->warnings->toBe([
            '`missing` not found at source, skipped',
            '`perf_thing` is neither a table nor a view, skipped',
        ])
        ->failed->toBeFalse();
})->group('need_review');

it('pre-fills the migrations table from the connection\'s squashed migrations directory', function (): void {
    $this->workspaceFile('sugar-schema.sql', '');
    $this->manifestFile(['sugar' => ['contacts']]);
    $this->workspaceFile('migrations/2024_01_01_000000_create_contacts.php', '');
    $this->workspaceFile('migrations/2024_02_01_000000_add_seq.php', '');
    $this->workspaceFile('migrations/notes.txt', '');
    Config::set('schema-tools.squashed_migrations', ['sugar' => $this->workspace . '/migrations']);

    mysqlResolvesTo($this->connection, 'contacts', (object) ['name' => 'contacts', 'type' => 'BASE TABLE']);
    createTable($this->connection, 'contacts', CONTACTS_CREATE_TABLE);

    $dump = dumpActionFor($this->connection, 'sugar')->handle()->connections[0];

    expect($dump->schemaContent)->toBe(
        MYSQL_FIXTURE_HEAD
        . "\n\nINSERT INTO `migrations` (`migration`, `batch`) VALUES\n"
        . "('2024_01_01_000000_create_contacts', 1),\n"
        . "('2024_02_01_000000_add_seq', 1);"
        . "\n\nSET FOREIGN_KEY_CHECKS=1;\n",
    );
})->group('need_review');

it('keeps the existing order of a MySQL fixture and ignores its migrations table', function (): void {
    $this->workspaceFile('sugar-schema.sql', MYSQL_FIXTURE_HEAD . "\n\nSET FOREIGN_KEY_CHECKS=1;\n");
    $this->manifestFile(['sugar' => ['accounts', 'contacts']]);

    foreach (['accounts', 'contacts'] as $table) {
        mysqlResolvesTo($this->connection, $table, (object) ['name' => $table, 'type' => 'BASE TABLE']);
        createTable($this->connection, $table, "CREATE TABLE `{$table}` (\n  `id` int NOT NULL\n) ENGINE=InnoDB");
    }

    $dump = dumpActionFor($this->connection, 'sugar')->handle()->connections[0];

    expect($dump)
        ->warnings->toBe([])
        ->schemaContent->toStartWith("SET sql_mode = '';\nSET FOREIGN_KEY_CHECKS=0;\n\nDROP TABLE IF EXISTS `contacts`;\n\nCREATE TABLE `contacts` (\n  `id` int NOT NULL\n) ENGINE=InnoDB;\n\nDROP TABLE IF EXISTS `accounts`;");
})->group('need_review');

it('leaves the fixtures of :dataset untouched with a warning', function (string $connection, string $driver): void {
    $this->workspaceFile("{$connection}-schema.sql", 'CREATE TABLE `x` (`id` int);');
    $this->manifestFile([$connection => ['x']]);

    $dump = dumpActionFor($this->connection, $connection)->handle()->connections[0];

    expect($dump)
        ->failed->toBeTrue()
        ->schemaContent->toBeNull()
        ->warnings->toBe(["driver `{$driver}` is not supported (mysql, mariadb, sqlsrv), fixtures left untouched"]);
})->with([
    'a connection on an unsupported driver' => ['legacy', 'pgsql'],
    'a connection that is not configured' => ['nowhere', '?'],
])->group('need_review');
