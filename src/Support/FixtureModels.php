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
 * every fixture-backed connection, resolved once and shared by the audit and
 * the Rector rule. A model on a connection without a fixture has no facts.
 */
final class FixtureModels
{
    /** @var list<string>|null */
    private ?array $connections = null;

    /** @var array<string, array<string, Table>> */
    private array $tables = [];

    /** @var array<string, list<string>> */
    private array $views = [];

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
        if ($this->connections === null) {
            $this->connections = $this->fixtureConnections->all();

            foreach ($this->connections as $connection) {
                $this->tables[$connection] = $this->schemaFixtureParser->parseTables($this->fixtureConnections->schemaFile($connection));
                $this->views[$connection] = $this->schemaFixtureParser->parseViews($this->fixtureConnections->viewsFile($connection));
            }
        }

        return $this->connections;
    }

    /**
     * @return array<string, array<string, Table>>
     */
    public function tables(): array
    {
        $this->connections();

        return $this->tables;
    }

    /**
     * @return array<string, list<string>>
     */
    public function views(): array
    {
        $this->connections();

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

        if ($connection === null || !in_array($connection, $this->connections(), true)) {
            return $this->facts[$scannedModel->class] = null;
        }

        return $this->facts[$scannedModel->class] = $this->modelFactsResolver->resolve($scannedModel, $connection, $this->tables[$connection], $this->views[$connection]);
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
