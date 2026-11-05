<?php

declare(strict_types=1);

arch()->preset()->php();
arch()->preset()->security();
arch()->preset()->laravel();

arch('classes are final')
    ->expect('Mahbub\SchemaTools')
    ->classes()
    ->toBeFinal()
    ->ignoring('Mahbub\SchemaTools\Tests');

arch('comparisons are strict')
    ->expect('Mahbub\SchemaTools')
    ->toUseStrictEquality();

arch('config is read through the typed facade, never the helper')
    ->expect('config')
    ->not->toBeUsed();

arch('nothing sleeps')
    ->expect(['sleep', 'usleep'])
    ->not->toBeUsed();

arch('actions are readonly')
    ->expect('Mahbub\SchemaTools\Actions')
    ->classes()
    ->toBeReadonly();

arch('actions do one thing, through handle()')
    ->expect('Mahbub\SchemaTools\Actions')
    ->toHaveMethod('handle')
    ->not->toHavePublicMethodsBesides(['__construct', 'handle']);

arch('queries run one statement, through execute()')
    ->expect('Mahbub\SchemaTools\Queries')
    ->toHaveMethod('execute')
    ->not->toHavePublicMethodsBesides(['__construct', 'execute']);
