<?php

declare(strict_types=1);

namespace Mahbub\SchemaTools\Support;

enum DumpStatus
{
    case Dumped;
    case Skipped;
    case Failed;
}
