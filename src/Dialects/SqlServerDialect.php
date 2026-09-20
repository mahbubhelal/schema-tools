<?php

declare(strict_types=1);

namespace Mahbub\SchemaTools\Dialects;

use Mahbub\SchemaTools\Queries\SqlServer\FetchPrimaryKeyColumns;
use Mahbub\SchemaTools\Queries\SqlServer\FetchTableColumns;
use Mahbub\SchemaTools\Queries\SqlServer\FetchViewDefinition;
use Mahbub\SchemaTools\Queries\SqlServer\ResolveObject;
use Mahbub\SchemaTools\Support\ObjectKind;
use Mahbub\SchemaTools\Support\SourceObject;

/**
 * SQL Server: base tables are reconstructed from sys.columns /
 * sys.identity_columns / sys.default_constraints / sys.key_constraints into
 * T-SQL, and views are pulled verbatim from sys.sql_modules (with Windows line
 * endings normalised). The fixtures are plain statement lists the test loader
 * runs one by one.
 *
 * @phpstan-import-type TableColumnRow from FetchTableColumns
 */
final readonly class SqlServerDialect implements Dialect
{
    public function __construct(
        private ResolveObject $resolveObject,
        private FetchTableColumns $fetchTableColumns,
        private FetchPrimaryKeyColumns $fetchPrimaryKeyColumns,
        private FetchViewDefinition $fetchViewDefinition,
    ) {}

    public function resolve(string $connection, string $name): ?SourceObject
    {
        $object = $this->resolveObject->execute($connection, $name);

        if ($object === null) {
            return null;
        }

        return new SourceObject($object->name, match (trim($object->type)) {
            'U' => ObjectKind::Table,
            'V' => ObjectKind::View,
            default => ObjectKind::Other,
        });
    }

    public function tableBlock(string $connection, string $table): string
    {
        $lines = array_map($this->columnLine(...), $this->fetchTableColumns->execute($connection, $table));

        $primaryKey = $this->fetchPrimaryKeyColumns->execute($connection, $table);

        if ($primaryKey !== []) {
            $keyColumns = implode(', ', array_map($this->keyColumn(...), $primaryKey));
            $lines[] = "    PRIMARY KEY ({$keyColumns})";
        }

        return "CREATE TABLE [dbo].[{$table}] (\n" . implode(",\n", $lines) . "\n);";
    }

    public function viewBlock(string $connection, string $view): string
    {
        $definition = str_replace("\r\n", "\n", (string) $this->fetchViewDefinition->execute($connection, $view));
        $definition = rtrim(rtrim($definition), ';');

        return "DROP VIEW IF EXISTS [dbo].[{$view}];\n\n{$definition};";
    }

    public function schemaFixture(string $connection, array $blocks): string
    {
        return implode("\n\n", $blocks) . "\n";
    }

    public function viewsFixture(array $blocks): string
    {
        return implode("\n\n", $blocks) . "\n";
    }

    /**
     * @param  object{name: string}  $row
     */
    private function keyColumn(object $row): string
    {
        return "[{$row->name}]";
    }

    /**
     * @param  TableColumnRow  $column
     */
    private function columnLine(object $column): string
    {
        $line = "    [{$column->name}] " . $this->formatType(
            $column->type_name,
            (int) $column->max_length,
            (int) $column->numeric_precision,
            (int) $column->numeric_scale,
        );

        if ((int) $column->is_identity === 1) {
            $line .= ' IDENTITY(' . (int) $column->seed_value . ',' . (int) $column->increment_value . ')';
        } elseif ($column->collation_name !== null) {
            $line .= " COLLATE {$column->collation_name}";
        }

        $line .= (int) $column->is_nullable === 1 ? ' NULL' : ' NOT NULL';

        if ($column->default_definition !== null) {
            $line .= " DEFAULT {$column->default_definition}";
        }

        return $line;
    }

    private function formatType(string $typeName, int $maxLength, int $precision, int $scale): string
    {
        $type = strtolower($typeName);

        return match ($type) {
            'nvarchar', 'nchar' => $type . '(' . ($maxLength === -1 ? 'MAX' : (int) ($maxLength / 2)) . ')',
            'varchar', 'char', 'binary', 'varbinary' => $type . '(' . ($maxLength === -1 ? 'MAX' : $maxLength) . ')',
            'decimal', 'numeric' => "{$type}({$precision},{$scale})",
            default => $type,
        };
    }
}
