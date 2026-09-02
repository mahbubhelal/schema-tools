<?php

declare(strict_types=1);

namespace Mahbub\SchemaTools\Support;

final class SchemaFixtureParser
{
    /**
     * Parse a T-SQL `<connection>-schema.sql` fixture into a table => shape map,
     * keyed by the table name exactly as written in the DDL. A missing file
     * yields an empty map.
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

        preg_match_all('/^CREATE TABLE \[dbo\]\.\[([^\]]+)\]\s*\((.*?)^\);/ms', $sql, $matches, PREG_SET_ORDER);

        foreach ($matches as [, $table, $body]) {
            $columns = [];
            $primaryKey = [];

            foreach (explode("\n", $body) as $line) {
                $line = trim($line);

                if ($line === '' || str_starts_with($line, '--')) {
                    continue;
                }

                if (preg_match('/^(?:CONSTRAINT\s+\S+\s+)?PRIMARY KEY\s*\((.+)\)/i', $line, $pkMatch) === 1) {
                    preg_match_all('/\[([^\]]+)\]/', $pkMatch[1], $pkColumns);
                    $primaryKey = $pkColumns[1];

                    continue;
                }

                if (preg_match('/^\[([^\]]+)\]\s+([A-Za-z0-9_]+(?:\s*\([^)]*\))?)/', $line, $columnMatch) !== 1) {
                    continue;
                }

                $columns[$columnMatch[1]] = new Column(
                    type: strtolower(preg_replace('/\s+/', '', $columnMatch[2]) ?? $columnMatch[2]),
                    nullable: preg_match('/\bNOT\s+NULL\b/i', $line) !== 1,
                    hasDefault: preg_match('/\bDEFAULT\b/i', $line) === 1,
                    isIdentity: preg_match('/\bIDENTITY\s*\(/i', $line) === 1,
                );
            }

            $tables[$table] = new Table(columns: $columns, primaryKey: $primaryKey);
        }

        return $tables;
    }

    /**
     * View names declared in a T-SQL `<connection>-views.sql` fixture. A missing
     * file yields an empty list.
     *
     * @return list<string>
     */
    public function parseViews(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }

        preg_match_all('/^CREATE VIEW \[dbo\]\.\[([^\]]+)\]/m', (string) file_get_contents($path), $matches);

        return $matches[1];
    }
}
