<?php

declare(strict_types=1);

namespace Mahbub\SchemaTools\Declarations;

use Illuminate\Database\Eloquent\Attributes\Connection;
use Illuminate\Database\Eloquent\Attributes\Table as TableAttribute;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Model;
use Mahbub\SchemaTools\Support\ColumnType;
use Mahbub\SchemaTools\Support\ScannedModel;
use Mahbub\SchemaTools\Support\Table;
use ReflectionClass;

/**
 * Compares one instantiated model with the DDL of its table (or with the view
 * it maps onto) and states what it resolves to, what the DDL expects and the
 * issues between them. A model may declare no key when its table has none, a
 * view-backed model must declare no key, no incrementing and no timestamps,
 * and a declaration counts whether it is a redeclared property or one of
 * Eloquent's class attributes on the model, one of its traits or an ancestor.
 */
final class ModelFactsResolver
{
    /**
     * @param  Table|null  $ddl  The model's table in the schema fixture, null when the fixture does not define it.
     * @param  list<string>  $views
     */
    public function resolve(ScannedModel $scannedModel, string $connection, ?Table $ddl, array $views): ModelFacts
    {
        $model = $scannedModel->model;
        $reflection = $scannedModel->reflection;
        $table = $model->getTable();
        $keyName = $this->keyName($reflection, $model);

        $effective = new Effective(
            connection: $connection,
            table: $table,
            primaryKey: $keyName,
            keyType: $model->getKeyType(),
            incrementing: $model->getIncrementing(),
            timestamps: $model->usesTimestamps(),
            connectionDeclared: $this->declaresConnection($reflection),
            timestampsDeclared: $this->declaresTimestamps($reflection),
        );

        $inheritedTable = $this->inheritedTable($reflection);
        $issues = [];

        if (!$effective->connectionDeclared) {
            $issues[] = 'connection not declared on the model';
        }

        if (!$ddl instanceof Table) {
            if ($this->isView($views, $table)) {
                return new ModelFacts($effective, new Expectation(null, null, false, false), [...$issues, ...$this->viewIssues($effective)], $inheritedTable);
            }

            return new ModelFacts($effective, null, [...$issues, "table `{$table}` not found in {$connection}-schema.sql"], $inheritedTable);
        }

        $ddlPrimaryKey = $ddl->effectivePrimaryKey();
        $expectedKey = $keyName;
        $expectedKeyType = null;
        $expectedIncrementing = $effective->incrementing;

        if ($keyName === null) {
            if (count($ddlPrimaryKey) === 1) {
                $issues[] = "model declares no pk but DDL has pk `{$ddlPrimaryKey[0]}`";
            }

            if ($effective->incrementing) {
                $issues[] = 'model has no pk but incrementing is true';
                $expectedIncrementing = false;
            }
        } elseif (count($ddlPrimaryKey) === 0) {
            $issues[] = "DDL has NO primary key but model declares `{$keyName}`";
            $expectedKey = null;
            $expectedIncrementing = false;
        } elseif (count($ddlPrimaryKey) > 1) {
            $issues[] = 'DDL has COMPOSITE pk (' . implode(', ', $ddlPrimaryKey) . "); model getKeyName()=`{$keyName}`";
        }

        if (count($ddlPrimaryKey) === 1) {
            $ddlKeyName = $ddlPrimaryKey[0];
            $expectedKey = $ddlKeyName;

            if ($keyName !== null && $keyName !== $ddlKeyName) {
                $issues[] = "pk mismatch: DDL `{$ddlKeyName}` vs model `{$keyName}`";
            }

            $ddlKeyColumn = $ddl->column($ddlKeyName);
            $isIdentity = $ddlKeyColumn->isIdentity ?? false;
            $expectedIncrementing = $isIdentity;

            if ($keyName !== null && $effective->incrementing !== $isIdentity) {
                $issues[] = sprintf(
                    'incrementing mismatch: DDL %s vs model %s',
                    $isIdentity ? 'IDENTITY' : 'NOT identity',
                    $effective->incrementing ? 'true' : 'false',
                );
            }

            $ddlKeyType = $ddlKeyColumn->type ?? '';
            $expectedKeyType = ColumnType::keyType($ddlKeyType);

            if ($keyName !== null && $effective->keyType !== $expectedKeyType) {
                $issues[] = "keyType mismatch: DDL {$ddlKeyType} => `{$expectedKeyType}` vs model `{$effective->keyType}`";
            }
        }

        $hasTimestampColumns = array_key_exists($model->getCreatedAtColumn() ?? 'created_at', $ddl->columns)
            && array_key_exists($model->getUpdatedAtColumn() ?? 'updated_at', $ddl->columns);

        if ($effective->timestamps && !$hasTimestampColumns) {
            $issues[] = 'model uses timestamps but the DDL lacks the columns';
        }

        if (!$effective->timestamps && !$effective->timestampsDeclared) {
            $issues[] = 'timestamps disabled but not declared on the model';
        }

        $expected = new Expectation(
            primaryKey: $expectedKey,
            keyType: $expectedKeyType,
            incrementing: $expectedIncrementing,
            timestamps: $effective->timestamps && $hasTimestampColumns,
        );

        return new ModelFacts($effective, $expected, $issues, $inheritedTable);
    }

    /**
     * The arguments of the `#[Table]` attribute the model inherits from a
     * trait or an ancestor, excluding one on the class itself.
     *
     * @param  ReflectionClass<Model>  $reflection
     *
     * @return array<string, string|bool>
     */
    private function inheritedTable(ReflectionClass $reflection): array
    {
        $table = $this->firstAttribute($reflection->getTraits(), TableAttribute::class);
        $parent = $reflection->getParentClass();

        if ($table === null && $parent instanceof ReflectionClass) {
            $table = $this->classAttribute($parent, TableAttribute::class);
        }

        if (!$table instanceof TableAttribute) {
            return [];
        }

        return array_filter([
            'name' => $table->name,
            'key' => $table->key,
            'keyType' => $table->keyType,
            'incrementing' => $table->incrementing,
            'timestamps' => $table->timestamps,
        ], static fn (string|bool|null $value): bool => $value !== null);
    }

    /**
     * @param  ReflectionClass<Model>  $reflection
     */
    private function declaresConnection(ReflectionClass $reflection): bool
    {
        return $this->declaresProperty($reflection, 'connection')
            || $this->classAttribute($reflection, Connection::class) !== null;
    }

    /**
     * @param  ReflectionClass<Model>  $reflection
     */
    private function declaresTimestamps(ReflectionClass $reflection): bool
    {
        if ($this->declaresProperty($reflection, 'timestamps')) {
            return true;
        }

        if ($this->classAttribute($reflection, WithoutTimestamps::class) !== null) {
            return true;
        }

        $table = $this->classAttribute($reflection, TableAttribute::class);

        return $table instanceof TableAttribute && $table->timestamps !== null;
    }

    /**
     * Whether the model (or a parent of its own) redeclares the property,
     * rather than inheriting Eloquent's default.
     *
     * @param  ReflectionClass<Model>  $reflection
     */
    private function declaresProperty(ReflectionClass $reflection, string $property): bool
    {
        return !str_starts_with($reflection->getProperty($property)->getDeclaringClass()->getName(), 'Illuminate\\');
    }

    /**
     * The first instance of a class attribute found the way Eloquent resolves
     * it: on the class itself, then on its traits, then up the parent chain.
     *
     * @template TAttribute of object
     *
     * @param  ReflectionClass<Model>  $reflection
     * @param  class-string<TAttribute>  $attribute
     *
     * @return TAttribute|null
     */
    private function classAttribute(ReflectionClass $reflection, string $attribute): ?object
    {
        $class = $reflection;

        do {
            $instance = $this->firstAttribute([$class, ...$class->getTraits()], $attribute);

            if ($instance !== null) {
                return $instance;
            }
        } while ($class = $class->getParentClass());

        return null;
    }

    /**
     * The instance of the first of the classes or traits that carries the
     * attribute.
     *
     * @template TAttribute of object
     *
     * @param  array<ReflectionClass<object>>  $candidates
     * @param  class-string<TAttribute>  $attribute
     *
     * @return TAttribute|null
     */
    private function firstAttribute(array $candidates, string $attribute): ?object
    {
        foreach ($candidates as $candidate) {
            $attributes = $candidate->getAttributes($attribute);

            if ($attributes !== []) {
                return $attributes[0]->newInstance();
            }
        }

        return null;
    }

    /**
     * The key the model declares, or null when it declares none. Eloquent types
     * getKeyName() as a string, so the property itself is consulted.
     *
     * @param  ReflectionClass<Model>  $reflection
     */
    private function keyName(ReflectionClass $reflection, Model $model): ?string
    {
        $declared = $reflection->getProperty('primaryKey')->getValue($model);

        return is_string($declared) && $declared !== '' ? $model->getKeyName() : null;
    }

    /**
     * @param  list<string>  $views
     */
    private function isView(array $views, string $table): bool
    {
        return in_array(strtolower($table), array_map(strtolower(...), $views), true);
    }

    /**
     * A model mapped onto a view has nothing to key on and nothing to
     * time-stamp, and must say so — Eloquent's defaults assume an
     * auto-incrementing `id` and `created_at` / `updated_at` columns.
     *
     * @return list<string>
     */
    private function viewIssues(Effective $effective): array
    {
        $issues = [];

        if ($effective->primaryKey !== null) {
            $issues[] = 'view-backed model must declare `$primaryKey = null`';
        }

        if ($effective->incrementing) {
            $issues[] = 'view-backed model must declare `$incrementing = false`';
        }

        if ($effective->timestamps) {
            $issues[] = 'view-backed model must declare `$timestamps = false`';
        }

        return $issues;
    }
}
