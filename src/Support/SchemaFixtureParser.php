<?php

declare(strict_types=1);

namespace Mahbub\SchemaTools\Support;

use Illuminate\Support\Facades\File;

final class SchemaFixtureParser
{
    /**
     * Laravel's own bookkeeping table, which a MySQL fixture carries so `migrate`
     * can load it as a squashed schema. It is not part of the source schema.
     */
    public const string MIGRATIONS_TABLE = 'migrations';

    private const array INDEX_OR_CONSTRAINT_WORDS = ['KEY', 'UNIQUE', 'CONSTRAINT', 'PRIMARY', 'INDEX', 'FULLTEXT', 'SPATIAL', 'FOREIGN', 'CHECK'];

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

        $sql = File::get($path);
        $tables = [];

        Regex::orFail(preg_match_all(
            '/^CREATE TABLE (?:\[dbo\]\.\[([^\]]+)\]|`([^`]+)`|(?:\w+\.)?dbo\.(\w+))\s*\((.*?)^\)[^\n;]*;/ms',
            $sql,
            $matches,
            PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL,
        ));

        foreach ($matches as [, $bracketed, $backticked, $bare, $body]) {
            $table = $bracketed ?? $backticked ?? $bare ?? '';

            if ($table === self::MIGRATIONS_TABLE) {
                continue;
            }

            $primaryKey = $this->primaryKey($body);

            $tables[$table] = new Table(columns: $this->columns($body, $primaryKey), primaryKey: $primaryKey, identityIsKey: $backticked === null);
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

        Regex::orFail(preg_match_all(
            '/^CREATE(?:\s+ALGORITHM=\w+)?(?:\s+SQL SECURITY \w+)?\s+VIEW\s+(?:\[dbo\]\.\[([^\]]+)\]|`([^`]+)`|(?:dbo\.)?([A-Za-z_]\w*))/m',
            File::get($path),
            $matches,
            PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL,
        ));

        return array_map(
            static fn (array $match): string => $match[1] ?? $match[2] ?? $match[3] ?? '',
            $matches,
        );
    }

    /**
     * @param  list<string>  $primaryKey
     *
     * @return array<string, Column>
     */
    private function columns(string $body, array $primaryKey): array
    {
        $keyColumns = array_map(strtolower(...), $primaryKey);
        $columns = [];

        foreach (explode("\n", $body) as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '--')) {
                continue;
            }

            if (preg_match('/^(?:\[([^\]]+)\]|`([^`]+)`|([A-Za-z_]\w*))\s+([A-Za-z0-9_]+(?:\s*\([^)]*\))?)/', $line, $columnMatch, PREG_UNMATCHED_AS_NULL) !== 1) {
                Regex::failOnError();

                continue;
            }

            [, $bracketedColumn, $backtickedColumn, $bareWord, $columnType] = $columnMatch;

            if ($bareWord !== null && in_array(strtoupper($bareWord), self::INDEX_OR_CONSTRAINT_WORDS, true)) {
                continue;
            }

            $column = $bracketedColumn ?? $backtickedColumn ?? $bareWord ?? '';
            $type = Regex::orFail(preg_replace('/\s+/', '', $columnType));

            // A primary-key column is NOT NULL even when its own line does
            // not say so: both dialects imply it, and a hand-written fixture
            // may leave the nullability out. A generated column is filled in
            // by the server, so for the purposes of "can an INSERT omit it"
            // it behaves like a default.
            $columns[$column] = new Column(
                type: strtolower($type),
                nullable: Regex::orFail(preg_match('/\bNOT\s+NULL\b/i', $line)) !== 1 && !in_array(strtolower($column), $keyColumns, true),
                hasDefault: Regex::orFail(preg_match('/\bDEFAULT\b|\bGENERATED ALWAYS\b/i', $line)) === 1,
                isIdentity: Regex::orFail(preg_match('/\bIDENTITY\s*\(|\bAUTO_INCREMENT\b/i', $line)) === 1,
            );
        }

        return $columns;
    }

    /**
     * The columns of the table's PRIMARY KEY clause, in key order and as the
     * DDL writes them; empty when the table declares none.
     *
     * @return list<string>
     */
    private function primaryKey(string $body): array
    {
        if (preg_match('/^\s*(?:CONSTRAINT\s+\S+\s+)?PRIMARY KEY\s*\((.+?)\)/im', $body, $match) !== 1) {
            Regex::failOnError();

            return [];
        }

        return array_map(
            static fn (string $column): string => trim($column, " \t[]`"),
            explode(',', $match[1]),
        );
    }
}
