<?php

declare(strict_types=1);

namespace TriCab\Api\Identity\Email;

use RuntimeException;
use Throwable;
use TriCab\Core\Identity\Application\Exception\GuestOtpEmailDeliveryFailed;

/**
 * Infrastructure failure while talking to an email provider.
 * Must never include OTP or provider request bodies.
 */
final class GuestOtpEmailProviderException extends RuntimeException
{
    public static function configuration(string $detail): self
    {
        return new self('Guest OTP email provider configuration is invalid: ' . $detail);
    }

    public static function transportFailure(): self
    {
        return new self('Guest OTP email provider request failed.');
    }

    public static function unexpectedStatus(int $status): self
    {
        return new self(sprintf('Guest OTP email provider returned HTTP %d.', $status));
    }

    public function toDeliveryFailed(): GuestOtpEmailDeliveryFailed
    {
        return GuestOtpEmailDeliveryFailed::fromThrowable($this);
    }

    public static function fromThrowable(Throwable $throwable): self
    {
        return new self('Guest OTP email provider request failed.', 0, $throwable);
    }
}
