<?php

declare(strict_types=1);

namespace TriCab\Core\Identity\Application\Exception;

use RuntimeException;
use Throwable;

/**
 * Raised when a guest OTP email cannot be delivered.
 * Must never include the OTP in the message.
 */
final class GuestOtpEmailDeliveryFailed extends RuntimeException
{
    public static function create(): self
    {
        return new self('Guest OTP email delivery failed.');
    }

    public static function fromThrowable(Throwable $throwable): self
    {
        return new self('Guest OTP email delivery failed.', 0, $throwable);
    }
}
