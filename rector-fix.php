<?php

declare(strict_types=1);

use Mahbub\SchemaTools\Rector\DeclareModelSchemaRector;
use Rector\Config\RectorConfig;

/*
 * The config `schema:audit --fix` runs Rector with. Only the model paths from
 * `config/schema-tools.php` are processed, so nothing else in the project is
 * touched; names the rule introduces are imported, docblocks left alone.
 */
return RectorConfig::configure()
    ->withRules([DeclareModelSchemaRector::class])
    ->withImportNames(importDocBlockNames: false, importShortClasses: false)
    ->withoutParallel();
