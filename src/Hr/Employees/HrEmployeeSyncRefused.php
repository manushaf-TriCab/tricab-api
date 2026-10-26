<?php

declare(strict_types=1);

namespace TriCab\Api\Hr\Employees;

use RuntimeException;

/** The sync will not run: the wrong server, or a source that came back empty. Nothing was written. */
final class HrEmployeeSyncRefused extends RuntimeException
{
}
