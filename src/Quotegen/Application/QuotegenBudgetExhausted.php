<?php

declare(strict_types=1);

namespace TriCab\Api\Quotegen\Application;

use RuntimeException;

/** The RFQ's assistant budget cannot cover the next model call. */
final class QuotegenBudgetExhausted extends RuntimeException
{
}
