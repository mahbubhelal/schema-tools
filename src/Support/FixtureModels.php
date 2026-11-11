<?php

declare(strict_types=1);

namespace Mahbub\SchemaTools\Support;

use Illuminate\Support\Facades\Config;
use InvalidArgumentException;
use Mahbub\SchemaTools\Declarations\DeclarationStyle;
use Mahbub\SchemaTools\Declarations\ModelFacts;
use Mahbub\SchemaTools\Declarations\ModelFactsResolver;

/**
 * The models under the configured paths together with the parsed fixtures of
 * every fixture-backed connection, resolved once and shared by the audit, the
 * factory check, the detector and the Rector rule. A model on a connection
 * without a fixture has no facts.
 */
final class FixtureModels
{
    /** @var list<string>|null */
    private ?array $connections = null;

    /** @var array<string, array<string, Table>>|null */
    private ?array $tables = null;

    /** @var array<string, list<string>>|null */
    private ?array $views = null;

    /** @var list<ScannedModel>|null */
    private ?array $scanned = null;

    /** @var array<string, ModelFacts|null> */
    private array $facts = [];

    public function __construct(
        private readonly FixtureConnections $fixtureConnections,
        private readonly SchemaFixtureParser $schemaFixtureParser,
        private readonly ScanPaths $scanPaths,
        private readonly ModelScanner $modelScanner,
        private readonly ModelFactsResolver $modelFactsResolver,
    ) {}

    /**
     * @return list<string>
     */
    public function connections(): array
    {
        return $this->connections ??= $this->fixtureConnections->all();
    }

    /**
     * Whether a model's connection name is one of the fixture-backed
     * connections; a model that names none never is.
     *
     * @phpstan-assert-if-true =string $connection
     */
    public function isFixtureBacked(?string $connection): bool
    {
        return $connection !== null && in_array($connection, $this->connections(), true);
    }

    /**
     * @return array<string, array<string, Table>>
     */
    public function tables(): array
    {
        if ($this->tables === null) {
            $this->tables = [];

            foreach ($this->connections() as $connection) {
                $this->tables[$connection] = $this->schemaFixtureParser->parseTables($this->fixtureConnections->schemaFile($connection));
            }
        }

        return $this->tables;
    }

    /**
     * The schema fixture's DDL for a table of a fixture-backed connection,
     * matched case-insensitively; null when the fixture does not define it.
     */
    public function table(string $connection, string $name): ?Table
    {
        foreach ($this->tables()[$connection] as $table => $shape) {
            if (strcasecmp($table, $name) === 0) {
                return $shape;
            }
        }

        return null;
    }

    /**
     * @return array<string, list<string>>
     */
    public function views(): array
    {
        if ($this->views === null) {
            $this->views = [];

            foreach ($this->connections() as $connection) {
                $this->views[$connection] = $this->schemaFixtureParser->parseViews($this->fixtureConnections->viewsFile($connection));
            }
        }

        return $this->views;
    }

    /**
     * @return list<ScannedModel>
     */
    public function scanned(): array
    {
        if ($this->scanned === null) {
            $this->scanned = [];

            foreach ($this->scanPaths->resolve('models') as $path) {
                array_push($this->scanned, ...$this->modelScanner->scan($path));
            }
        }

        return $this->scanned;
    }

    public function facts(ScannedModel $scannedModel): ?ModelFacts
    {
        if (array_key_exists($scannedModel->class, $this->facts)) {
            return $this->facts[$scannedModel->class];
        }

        $connection = $scannedModel->model->getConnectionName();

        if (!$this->isFixtureBacked($connection)) {
            return $this->facts[$scannedModel->class] = null;
        }

        $ddl = $this->table($connection, $scannedModel->model->getTable());

        return $this->facts[$scannedModel->class] = $this->modelFactsResolver->resolve($scannedModel, $connection, $ddl, $this->views()[$connection]);
    }

    public function factsFor(string $class): ?ModelFacts
    {
        foreach ($this->scanned() as $scannedModel) {
            if ($scannedModel->class === $class) {
                return $this->facts($scannedModel);
            }
        }

        return null;
    }

    /**
     * The style `declaration_style` fixes for the project, or null when either
     * is allowed.
     */
    public function configuredStyle(): ?DeclarationStyle
    {
        $configured = Config::get('schema-tools.declaration_style');

        if ($configured === null) {
            return null;
        }

        if (!is_string($configured) || !DeclarationStyle::tryFrom($configured) instanceof DeclarationStyle) {
            throw new InvalidArgumentException("`declaration_style` in schema-tools.php must be 'attributes', 'properties' or null.");
        }

        return DeclarationStyle::from($configured);
    }
}
