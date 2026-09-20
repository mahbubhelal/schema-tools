<?php

declare(strict_types=1);

namespace Mahbub\SchemaTools\Dialects;

use Illuminate\Support\Facades\Config;
use Mahbub\SchemaTools\Queries\MySql\FetchCreateTable;
use Mahbub\SchemaTools\Queries\MySql\FetchCreateView;
use Mahbub\SchemaTools\Queries\MySql\ResolveObject;
use Mahbub\SchemaTools\Support\Identifier;
use Mahbub\SchemaTools\Support\ObjectKind;
use Mahbub\SchemaTools\Support\SchemaFixtureParser;
use Mahbub\SchemaTools\Support\SourceObject;

/**
 * MySQL and MariaDB: tables and views are copied verbatim from SHOW CREATE
 * TABLE / SHOW CREATE VIEW. AUTO_INCREMENT counters are stripped as noise, and
 * a view loses its DEFINER clause so it can be created by whichever user loads
 * the fixture.
 *
 * The schema fixture is shaped for Laravel's squashed-schema loading: it clears
 * the session sql_mode (source DDL may carry zero-date defaults MySQL 8 rejects
 * by default), disables foreign-key checks (a referenced table may come after
 * the table referencing it), and ends with a `migrations` table — pre-filled
 * from the connection's entry in `schema-tools.squashed_migrations` so those
 * migrations are marked as ran instead of being replayed on top of the fixture.
 */
final readonly class MySqlDialect implements Dialect
{
    private const string MIGRATIONS_TABLE = <<<'SQL'
        DROP TABLE IF EXISTS `migrations`;

        CREATE TABLE `migrations` (
          `id` int unsigned NOT NULL AUTO_INCREMENT,
          `migration` varchar(255) NOT NULL,
          `batch` int NOT NULL,
          PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        SQL;

    public function __construct(
        private ResolveObject $resolveObject,
        private FetchCreateTable $fetchCreateTable,
        private FetchCreateView $fetchCreateView,
    ) {}

    public function resolve(string $connection, string $name): ?SourceObject
    {
        $object = $this->resolveObject->execute($connection, $name);

        if ($object === null) {
            return null;
        }

        return new SourceObject($object->name, match ($object->type) {
            'BASE TABLE' => ObjectKind::Table,
            'VIEW' => ObjectKind::View,
            default => ObjectKind::Other,
        });
    }

    public function tableBlock(string $connection, string $table): string
    {
        $statement = (string) preg_replace(
            '/ AUTO_INCREMENT=\d+/',
            '',
            (string) $this->fetchCreateTable->execute($connection, $table),
        );

        return 'DROP TABLE IF EXISTS ' . Identifier::backtick($table) . ";\n\n{$statement};";
    }

    public function viewBlock(string $connection, string $view): string
    {
        $statement = (string) preg_replace(
            '/ DEFINER=(?:`[^`]*`@`[^`]*`|\S+)/',
            '',
            (string) $this->fetchCreateView->execute($connection, $view),
        );

        return 'DROP VIEW IF EXISTS ' . Identifier::backtick($view) . ";\n\n{$statement};";
    }

    public function schemaFixture(string $connection, array $blocks): string
    {
        $blocks[] = self::MIGRATIONS_TABLE;

        $ranMigrations = $this->ranMigrations($connection);

        if ($ranMigrations !== []) {
            $blocks[] = 'INSERT INTO `' . SchemaFixtureParser::MIGRATIONS_TABLE . "` (`migration`, `batch`) VALUES\n"
                . implode(",\n", $ranMigrations) . ';';
        }

        return "SET sql_mode = '';\nSET FOREIGN_KEY_CHECKS=0;\n\n" . implode("\n\n", $blocks) . "\n\nSET FOREIGN_KEY_CHECKS=1;\n";
    }

    public function viewsFixture(array $blocks): string
    {
        return implode("\n\n", $blocks) . "\n";
    }

    /**
     * The `migrations` rows to pre-fill, one per migration file in the directory
     * the connection is mapped to in `schema-tools.squashed_migrations`.
     *
     * @return list<string>
     */
    private function ranMigrations(string $connection): array
    {
        $directory = Config::array('schema-tools.squashed_migrations', [])[$connection] ?? null;

        if (!is_string($directory)) {
            return [];
        }

        $files = glob($directory . '/*_*.php');

        return array_map(
            static fn (string $file): string => "('" . basename($file, '.php') . "', 1)",
            $files === false ? [] : $files,
        );
    }
}
