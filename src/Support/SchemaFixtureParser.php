<?php

declare(strict_types=1);

namespace Mahbub\SchemaTools\Support;

final class SchemaFixtureParser
{
    /**
     * Laravel's own bookkeeping table, which a MySQL fixture carries so `migrate`
     * can load it as a squashed schema. It is not part of the source schema.
     */
    public const string MIGRATIONS_TABLE = 'migrations';

    /** Words that open an index or constraint line rather than a column. */
    private const array NOT_A_COLUMN = ['KEY', 'UNIQUE', 'CONSTRAINT', 'PRIMARY', 'INDEX', 'FULLTEXT', 'SPATIAL', 'FOREIGN', 'CHECK'];

    /**
     * Parse a `<connection>-schema.sql` fixture — T-SQL (`CREATE TABLE
     * [dbo].[x]`, or the `CREATE TABLE Db.dbo.x` a hand-written fixture may
     * use) or MySQL (`CREATE TABLE `x``) — into a table => shape map, keyed by
     * the table name exactly as written in the DDL. The migrations table is
     * left out, and a missing file yields an empty map.
     *
     * @return array<string, Table>
     */
    public function parseTables(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }

        $sql = (string) file_get_contents($path);
        $tables = [];

        preg_match_all(
            '/^CREATE TABLE (?:\[dbo\]\.\[([^\]]+)\]|`([^`]+)`|(?:\w+\.)?dbo\.(\w+))\s*\((.*?)^\)[^\n;]*;/ms',
            $sql,
            $matches,
            PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL,
        );

        foreach ($matches as [, $bracketed, $backticked, $bare, $body]) {
            $table = $bracketed ?? $backticked ?? $bare ?? '';

            if ($table === self::MIGRATIONS_TABLE) {
                continue;
            }

            $columns = [];
            $primaryKey = [];

            foreach (explode("\n", $body) as $line) {
                $line = trim($line);

                if ($line === '' || str_starts_with($line, '--')) {
                    continue;
                }

                if (preg_match('/^(?:CONSTRAINT\s+\S+\s+)?PRIMARY KEY\s*\((.+?)\)/i', $line, $pkMatch) === 1) {
                    $primaryKey = array_map(
                        static fn (string $column): string => trim($column, " \t[]`"),
                        explode(',', $pkMatch[1]),
                    );

                    continue;
                }

                if (preg_match('/^(?:\[([^\]]+)\]|`([^`]+)`|([A-Za-z_]\w*))\s+([A-Za-z0-9_]+(?:\s*\([^)]*\))?)/', $line, $columnMatch) !== 1) {
                    continue;
                }

                // The three name alternatives are exclusive, so exactly one is non-empty.
                $column = $columnMatch[1] . $columnMatch[2] . $columnMatch[3];

                if ($columnMatch[3] !== '' && in_array(strtoupper($column), self::NOT_A_COLUMN, true)) {
                    continue;
                }

                // A generated column is filled in by the server, so for the
                // purposes of "can an INSERT omit it" it behaves like a default.
                $columns[$column] = new Column(
                    type: strtolower(preg_replace('/\s+/', '', $columnMatch[4]) ?? $columnMatch[4]),
                    nullable: preg_match('/\bNOT\s+NULL\b/i', $line) !== 1,
                    hasDefault: preg_match('/\bDEFAULT\b|\bGENERATED ALWAYS\b/i', $line) === 1,
                    isIdentity: preg_match('/\bIDENTITY\s*\(|\bAUTO_INCREMENT\b/i', $line) === 1,
                );
            }

            $tables[$table] = new Table(columns: $columns, primaryKey: $primaryKey, identityIsKey: $backticked === null);
        }

        return $tables;
    }

    /**
     * View names declared in a `<connection>-views.sql` fixture — T-SQL
     * (`CREATE VIEW [dbo].[x]`, or the unbracketed `dbo.x` / `x` a hand-written
     * fixture may use) or MySQL (`CREATE VIEW `x``, with or without an
     * ALGORITHM clause). A missing file yields an empty list.
     *
     * @return list<string>
     */
    public function parseViews(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }

        preg_match_all(
            '/^CREATE(?:\s+ALGORITHM=\w+)?(?:\s+SQL SECURITY \w+)?\s+VIEW\s+(?:\[dbo\]\.\[([^\]]+)\]|`([^`]+)`|(?:dbo\.)?([A-Za-z_]\w*))/m',
            (string) file_get_contents($path),
            $matches,
            PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL,
        );

        return array_map(
            static fn (array $match): string => $match[1] ?? $match[2] ?? $match[3] ?? '',
            $matches,
        );
    }
}
