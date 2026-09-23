<?php

declare(strict_types=1);

namespace TriCab\Core\Onboarding\Domain\Exception;

use RuntimeException;
use Throwable;

/**
 * The email provider did not accept the invitation. Maps to 502: nothing is
 * wrong with the request, and trying again later is the remedy.
 */
final class InvitationNotDelivered extends RuntimeException
{
    public static function fromThrowable(Throwable $cause): self
    {
        return new self('The invitation could not be sent. Nothing was recorded; try again in a few minutes.', 0, $cause);
    }
}
