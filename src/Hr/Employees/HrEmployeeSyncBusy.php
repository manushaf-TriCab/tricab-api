<?php

declare(strict_types=1);

namespace TriCab\Api\Hr\Employees;

use RuntimeException;

/** Another sync holds the application lock (a slot swap runs two WebJob hosts). Nothing was written. */
final class HrEmployeeSyncBusy extends RuntimeException
{
}
