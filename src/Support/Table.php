<?php

declare(strict_types=1);

namespace Mahbub\SchemaTools\Support;

final readonly class Table
{
    /**
     * @param  array<string, Column>  $columns  Column name (DDL casing) => shape.
     * @param  list<string>  $primaryKey  Primary-key column names, in key order.
     */
    public function __construct(
        public array $columns,
        public array $primaryKey,
    ) {}

    public function hasColumn(string $name): bool
    {
        return $this->canonicalName($name) !== null;
    }

    public function column(string $name): ?Column
    {
        $canonical = $this->canonicalName($name);

        return $canonical === null ? null : $this->columns[$canonical];
    }

    /**
     * The column name exactly as the DDL declares it, matched case-insensitively;
     * null when the table has no such column.
     */
    public function canonicalName(string $name): ?string
    {
        foreach (array_keys($this->columns) as $column) {
            if (strcasecmp($column, $name) === 0) {
                return $column;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public function identityColumns(): array
    {
        return array_keys(array_filter(
            $this->columns,
            static fn (Column $column): bool => $column->isIdentity,
        ));
    }

    /**
     * The effective primary key: the declared PRIMARY KEY, or — for a legacy
     * table that carries a lone IDENTITY column without a formal constraint —
     * that identity column as its surrogate key.
     *
     * @return list<string>
     */
    public function effectivePrimaryKey(): array
    {
        if ($this->primaryKey !== []) {
            return $this->primaryKey;
        }

        $identities = $this->identityColumns();

        return count($identities) === 1 ? $identities : [];
    }
}
