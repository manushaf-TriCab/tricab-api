<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Shared\Mail;

use RuntimeException;

/** Base for every refusal and failure of a finance send. */
abstract class FinanceMailException extends RuntimeException
{
}
