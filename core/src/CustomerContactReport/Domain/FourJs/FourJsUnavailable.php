<?php

declare(strict_types=1);

namespace TriCab\Core\CustomerContactReport\Domain\FourJs;

use RuntimeException;

/**
 * 4JS could not be reached, timed out, or is not configured. Callers fail
 * closed: the report still saves, the lines are simply not confirmed.
 */
final class FourJsUnavailable extends RuntimeException
{
}
