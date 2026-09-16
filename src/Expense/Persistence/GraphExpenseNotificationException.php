<?php

declare(strict_types=1);

namespace TriCab\Api\Expense\Persistence;

use RuntimeException;

/**
 * Graph mail transport problem. Never carries a token, secret, or message body —
 * the message text ends up in the structured log.
 */
final class GraphExpenseNotificationException extends RuntimeException
{
}
