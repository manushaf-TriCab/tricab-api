<?php

declare(strict_types=1);

namespace TriCab\Core\Customer\Application\Exception;

use RuntimeException;
use Throwable;

final class PortalUserProvisioningFailed extends RuntimeException
{
    public static function forEmail(string $email, ?Throwable $previous = null): self
    {
        return new self(
            sprintf('Failed to provision a customer-portal user for "%s".', $email),
            0,
            $previous,
        );
    }

    public static function fromThrowable(Throwable $previous): self
    {
        return new self('Failed to provision a customer-portal user.', 0, $previous);
    }
}
