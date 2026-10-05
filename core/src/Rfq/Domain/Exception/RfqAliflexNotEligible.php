<?php

declare(strict_types=1);

namespace TriCab\Core\Rfq\Domain\Exception;

use RuntimeException;

/** The quote is not finished (or is already an alternative), so no ALI-FLEX alternative is made. Maps to 409. */
final class RfqAliflexNotEligible extends RuntimeException
{
}
