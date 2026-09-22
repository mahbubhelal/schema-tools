<?php

declare(strict_types=1);

namespace Mahbub\SchemaTools\Tests\Fixtures\Audit\Concerns;

use Illuminate\Database\Eloquent\Attributes\Connection;

#[Connection('tcb')]
trait ConnectsToTcb {}
