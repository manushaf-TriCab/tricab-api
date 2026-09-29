<?php

declare(strict_types=1);

namespace TriCab\Core\Rfq\Domain\Exception;

use RuntimeException;

/**
 * The actor can see this RFQ but may not do this to it. Maps to 403.
 *
 * Distinct from "not found": an RFQ the actor cannot see is a 404, so this is
 * only ever raised about one they can.
 */
final class RfqActionForbidden extends RuntimeException
{
    public function __construct(public readonly string $what)
    {
        parent::__construct(sprintf('You do not have permission to %s.', $what));
    }
}
